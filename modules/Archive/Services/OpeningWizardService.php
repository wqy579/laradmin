<?php

namespace Modules\Archive\Services;

use App\Exports\GenericExport;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Archive\Imports\OpeningBalanceImport;
use Modules\Archive\Imports\OpeningStockImport;
use Modules\Archive\Models\OpeningBalance;
use Modules\Archive\Models\OpeningLog;
use Modules\Archive\Models\OpeningStep;
use Modules\Archive\Models\OpeningStock;
use Modules\Archive\Models\OpeningWizard;
use Modules\Ledger\Models\OpeningBalance as LedgerOpeningBalance;
use Modules\Ledger\Models\Subject;
use Modules\Stock\Models\Stock;

/**
 * 开账向导（F6 第二批）。
 *
 * 四步：①科目设置 ②期初库存 ③期初余额 ④导入/确认。整个过程只在草稿态可改，
 * lock() 是全模块唯一的验收闸门：四步做完 + 试算平衡，才允许把暂存数据回写进
 * 真实 stocks 与 ledger_opening_balances，回写完即锁定。
 *
 * 模块边界：Archive 直连 Stock/Ledger 的模型是刻意的（开账就是「系统初始化时把
 * 期初一次性写进对方主数据表」，与真实现场同库同事务；抽契约会给一次性动作引入
 * 跨事务一致性负担），已在 tests/Unit/ArchitectureTest.php 登记 Archive->Stock、
 * Archive->Ledger。ledger_periods 是否结账只用 DB::table 只读查询，
 * 有意不 import Modules\Ledger\Models\Period——A 域的表只要一个状态位，
 * 拖一个模型进来会让这条边看起来比实际更重。
 */
class OpeningWizardService
{
    /**
     * 打开向导：不存在就按「公司 + 事业部 + 期间」建一份 draft 向导 + 4 步 pending。
     *
     * (company_id, division_id, period) 的唯一性在这里保证：可空列建不了跨引擎唯一索引，
     * 见 2026_11_05_000004 迁移注释。withTrashed 是为了「反开账后又删掉重建」时仍能找回，
     * 不找回的话删除后重建会留下两份并存的脏数据。
     */
    public function show(int $companyId, ?int $divisionId, string $period): OpeningWizard
    {
        $wizard = OpeningWizard::withTrashed()
            ->where('company_id', $companyId)
            ->where('period', $period)
            ->when(
                $divisionId,
                fn ($q) => $q->where('division_id', $divisionId),
                fn ($q) => $q->whereNull('division_id')
            )
            ->orderBy('id')
            ->first();

        if ($wizard) {
            $wizard->restore();
            $wizard->load('steps');
            if ($wizard->steps->isEmpty()) {
                $this->ensureSteps($wizard);
                $wizard->load('steps');
            }

            return $wizard;
        }

        $wizard = OpeningWizard::create([
            'company_id' => $companyId,
            'division_id' => $divisionId,
            'period' => $period,
            'status' => OpeningWizard::STATUS_DRAFT,
            'step' => 1,
        ]);
        $this->ensureSteps($wizard);

        return $wizard->load('steps');
    }

    /** 补齐四步（历史数据缺步、或迁移后首次访问时兜底） */
    private function ensureSteps(OpeningWizard $wizard): void
    {
        $exists = $wizard->steps()->pluck('step')->all();
        foreach (OpeningStep::DEFS as $no => [$key, $title]) {
            if (in_array($no, $exists, true)) {
                continue;
            }
            OpeningStep::create([
                'wizard_id' => $wizard->id,
                'step' => $no,
                'key' => $key,
                'title' => $title,
                'status' => OpeningStep::STATUS_PENDING,
            ]);
        }
    }

    /**
     * 向导全量视图：步骤条状态 + 各步数据 + 试算结果，一次给全。
     *
     * 前端步骤条完全按这里下发的 steps 渲染（active 取第一个未完成步），
     * 不在前端硬编码进度，避免刷新后步骤与后端不一致。
     */
    public function detail(OpeningWizard $wizard): array
    {
        $wizard->load(['company:id,name,code', 'division:id,name,code', 'steps']);
        $trial = $this->trialBalance($wizard);
        $stocks = $this->stockRows($wizard);
        $balances = $this->balanceRows($wizard);

        $active = 4;
        foreach ($wizard->steps as $s) {
            if (! $s->isDone()) {
                $active = $s->step;
                break;
            }
        }

        return [
            'id' => $wizard->id,
            'company_id' => $wizard->company_id,
            'company_name' => $wizard->company?->name,
            'division_id' => $wizard->division_id,
            'division_name' => $wizard->division?->name,
            'period' => $wizard->period,
            'status' => $wizard->status,
            'locked' => $wizard->isLocked(),
            'step' => $wizard->step,
            'active_step' => $active,
            'locked_at' => $wizard->locked_at?->format('Y-m-d H:i:s'),
            'locked_by_name' => $wizard->locked_by_name,
            'lock_report' => $wizard->lock_report,
            'remark' => $wizard->remark,
            'steps' => $wizard->steps->map(fn (OpeningStep $s) => [
                'step' => $s->step,
                'key' => $s->key,
                'title' => $s->title,
                'status' => $s->status,
                'done_at' => $s->done_at?->format('Y-m-d H:i:s'),
                'done_by_name' => $s->done_by_name,
                'detail' => $s->detail,
            ])->values(),
            'subject_count' => Subject::where('is_leaf', true)->count(),
            'stocks' => $stocks['rows'],
            'stock_total' => $stocks['total'],
            'balances' => $balances['rows'],
            'trial' => $trial,
            'logs' => $wizard->logs()->latest('id')->limit(20)->get()
                ->map(fn (OpeningLog $l) => [
                    'action' => $l->action,
                    'operator_name' => $l->operator_name,
                    'content' => $l->content,
                    'created_at' => $l->created_at?->format('Y-m-d H:i:s'),
                ])->values(),
        ];
    }

    /** 第 2 步数据：商品/仓库名称只读回显，不写快照（档案改名后列表要跟着变） */
    private function stockRows(OpeningWizard $wizard): array
    {
        $rows = OpeningStock::where('wizard_id', $wizard->id)->orderBy('id')->get();
        // 一次联取名称/编码，不在 map 里逐行查（期初库存可能上千行）
        $products = DB::table('products')->whereIn('id', $rows->pluck('product_id')->all())
            ->get(['id', 'code', 'name'])->keyBy('id');
        $warehouses = DB::table('warehouses')->whereIn('id', $rows->pluck('warehouse_id')->all())
            ->pluck('name', 'id');

        $total = '0.00';
        $list = $rows->map(function (OpeningStock $r) use ($products, $warehouses, &$total) {
            $total = number_format((float) $total + (float) $r->total_amount, 2, '.', '');

            return [
                'id' => $r->id,
                'product_id' => $r->product_id,
                'product_code' => $products[$r->product_id]?->code,
                'product_name' => $products[$r->product_id]?->name,
                'warehouse_id' => $r->warehouse_id,
                'warehouse_name' => $warehouses[$r->warehouse_id] ?? null,
                'quantity' => $r->quantity,
                'cost_price' => (float) $r->cost_price,
                'total_amount' => (float) $r->total_amount,
                'source' => $r->source,
            ];
        })->values();

        return ['rows' => $list, 'total' => (float) $total];
    }

    /** 第 3 步数据：非叶子科目不可编辑（总账期初只认叶子科目） */
    private function balanceRows(OpeningWizard $wizard): array
    {
        $rows = OpeningBalance::where('wizard_id', $wizard->id)->orderBy('id')->get();
        $subjects = Subject::whereIn('id', $rows->pluck('subject_id')->all())
            ->get(['id', 'code', 'name', 'is_leaf'])->keyBy('id');

        $list = $rows->map(function (OpeningBalance $r) use ($subjects) {
            $subject = $subjects[$r->subject_id] ?? null;

            return [
                'id' => $r->id,
                'subject_id' => $r->subject_id,
                'subject_code' => $subject?->code,
                'subject_name' => $subject?->name,
                'is_leaf' => (bool) ($subject?->is_leaf ?? false),
                'opening_debit' => (float) $r->opening_debit,
                'opening_credit' => (float) $r->opening_credit,
                'year_debit' => (float) $r->year_debit,
                'year_credit' => (float) $r->year_credit,
            ];
        })->values();

        return ['rows' => $list];
    }

    /**
     * 试算平衡：口径与 Ledger 的 OpeningBalanceService 完全一致
     * （叶子科目 opening_debit + year_debit 合计 vs opening_credit + year_credit 合计，
     * 阈值 0.005 元），不另起一套算法，否则会出现「Ledger 说平、开账说不平」。
     */
    public function trialBalance(OpeningWizard $wizard): array
    {
        $sumDebit = 0.0;
        $sumCredit = 0.0;
        $leafCount = 0;

        $leafIds = Subject::where('is_leaf', true)->pluck('id');
        $rows = OpeningBalance::where('wizard_id', $wizard->id)
            ->whereIn('subject_id', $leafIds)
            ->get();

        foreach ($rows as $r) {
            $leafCount++;
            $sumDebit += (float) $r->opening_debit + (float) $r->year_debit;
            $sumCredit += (float) $r->opening_credit + (float) $r->year_credit;
        }

        $debit = round($sumDebit, 2);
        $credit = round($sumCredit, 2);
        $diff = round($debit - $credit, 2);

        return [
            'balanced' => abs($diff) < 0.005,
            'debit_total' => $debit,
            'credit_total' => $credit,
            'diff' => $diff,
            'leaf_count' => $leafCount,
            'period' => $wizard->period,
            'period_closed' => $this->isPeriodClosed($wizard->period),
        ];
    }

    /**
     * 期间是否已结账：只读 ledger_periods.status，不 import Ledger 的 Period 模型
     * （理由见类注释）。表里没有该期间记录时按「未结账」处理，与 Ledger
     * OpeningBalanceService::isPeriodClosed() 的判定一致。
     */
    private function isPeriodClosed(string $period): bool
    {
        $parts = explode('-', $period);
        if (count($parts) !== 2) {
            return false;
        }

        $status = DB::table('ledger_periods')
            ->where('year', (int) $parts[0])
            ->where('month', (int) $parts[1])
            ->value('status');

        return $status === 'closed';
    }

    /**
     * 保存/完成某一步。
     *
     * $payload['dry'] = true 时只落业务数据、不把步骤标记为 done（前端「下一步」用），
     * 否则校验本步要求后置 done 并写流水。锁定后一律拒绝。
     */
    public function saveStep(OpeningWizard $wizard, int $step, array $payload, ?int $operatorId = null, ?string $operatorName = null): array
    {
        if ($wizard->isLocked()) {
            return ['ok' => false, 'message' => '开账已完成并锁定，不能修改'];
        }

        $row = OpeningStep::where('wizard_id', $wizard->id)->where('step', $step)->first();
        if (! $row) {
            return ['ok' => false, 'message' => "步骤 {$step} 不存在"];
        }

        $detail = [];
        switch ($step) {
            case 1:
                $count = Subject::where('is_leaf', true)->count();
                if ($count === 0) {
                    return ['ok' => false, 'message' => '尚未维护会计科目，请先在「总账-会计科目」中录入科目'];
                }
                $detail = ['leaf_subject_count' => $count, 'confirmed' => true];
                break;

            case 2:
                $r = $this->saveStocks($wizard, $payload);
                if (! $r['ok']) {
                    return $r;
                }
                $detail = $r['detail'];
                break;

            case 3:
                $r = $this->saveBalances($wizard, $payload);
                if (! $r['ok']) {
                    return $r;
                }
                $detail = $r['detail'];
                break;

            case 4:
                $r = $this->saveConfirm($wizard);
                if (! $r['ok']) {
                    return $r;
                }
                $detail = $r['detail'];
                break;

            default:
                return ['ok' => false, 'message' => "步骤 {$step} 不存在"];
        }

        $dry = ! empty($payload['dry']);
        if (! $dry) {
            $row->update([
                'status' => OpeningStep::STATUS_DONE,
                'done_at' => now(),
                'done_by' => $operatorId,
                'done_by_name' => $operatorName,
                'detail' => $detail,
            ]);
            $this->log($wizard, OpeningLog::ACTION_STEP_DONE, $operatorId, $operatorName, "完成第 {$step} 步「{$row->title}」");
        } else {
            $row->update(['detail' => $detail]);
        }

        // 当前步指针最多推进到下一步，允许往回看
        if ($step >= $wizard->step && $step < 4 && ! $dry) {
            $wizard->update(['step' => $step + 1]);
        }

        return ['ok' => true, 'message' => $dry ? '已保存' : "第 {$step} 步已完成", 'detail' => $detail];
    }

    /**
     * 第 2 步：整表覆盖式保存期初库存。
     *
     * 采用「先删后插」而不是逐行 update：前端表格是可增可删的编辑态，
     * 逐行 diff 要额外传 deleted_ids，容易漏；向导数据量级是几千行，一次覆盖更稳。
     */
    private function saveStocks(OpeningWizard $wizard, array $payload): array
    {
        $rows = $payload['rows'] ?? [];
        if (count($rows) === 0) {
            return ['ok' => false, 'message' => '期初库存不能为空'];
        }

        $pairs = [];
        foreach ($rows as $i => $row) {
            $no = $i + 1;
            $productId = (int) ($row['product_id'] ?? 0);
            $warehouseId = (int) ($row['warehouse_id'] ?? 0);
            if ($productId <= 0 || $warehouseId <= 0) {
                return ['ok' => false, 'message' => "第 {$no} 行：商品与仓库不能为空"];
            }
            $key = $productId.'-'.$warehouseId;
            if (isset($pairs[$key])) {
                return ['ok' => false, 'message' => "第 {$no} 行：同一商品在同一仓库重复录入"];
            }
            $pairs[$key] = true;
        }

        $productIds = collect($rows)->pluck('product_id')->map(fn ($v) => (int) $v)->unique()->all();
        $warehouseIds = collect($rows)->pluck('warehouse_id')->map(fn ($v) => (int) $v)->unique()->all();
        $badProduct = DB::table('products')->whereIn('id', $productIds)->count() !== count($productIds);
        if ($badProduct) {
            return ['ok' => false, 'message' => '存在无效的商品'];
        }
        $badWarehouse = DB::table('warehouses')->whereIn('id', $warehouseIds)->count() !== count($warehouseIds);
        if ($badWarehouse) {
            return ['ok' => false, 'message' => '存在无效的仓库'];
        }

        DB::transaction(function () use ($wizard, $rows) {
            OpeningStock::where('wizard_id', $wizard->id)->delete();
            foreach ($rows as $row) {
                $quantity = (int) ($row['quantity'] ?? 0);
                $price = round((float) ($row['cost_price'] ?? 0), 2);
                OpeningStock::create([
                    'wizard_id' => $wizard->id,
                    'product_id' => (int) $row['product_id'],
                    'warehouse_id' => (int) $row['warehouse_id'],
                    'quantity' => $quantity,
                    'cost_price' => $price,
                    'total_amount' => round($quantity * $price, 2),
                    'source' => OpeningStock::SOURCE_MANUAL,
                ]);
            }
        });

        $total = (float) OpeningStock::where('wizard_id', $wizard->id)->sum('total_amount');

        return ['ok' => true, 'detail' => [
            'row_count' => count($rows),
            'total_amount' => round($total, 2),
        ]];
    }

    /** 第 3 步：整表覆盖式保存期初余额，只接受叶子科目 */
    private function saveBalances(OpeningWizard $wizard, array $payload): array
    {
        $rows = $payload['rows'] ?? [];
        if (count($rows) === 0) {
            return ['ok' => false, 'message' => '期初余额不能为空'];
        }

        $subjectIds = collect($rows)->pluck('subject_id')->map(fn ($v) => (int) $v)->all();
        if (count($subjectIds) !== count(array_unique($subjectIds))) {
            return ['ok' => false, 'message' => '同一科目重复录入'];
        }

        $subjects = Subject::whereIn('id', $subjectIds)->get(['id', 'code', 'name', 'is_leaf'])->keyBy('id');
        foreach ($subjectIds as $id) {
            $subject = $subjects[$id] ?? null;
            if (! $subject) {
                return ['ok' => false, 'message' => "科目不存在（ID {$id}）"];
            }
            if (! $subject->is_leaf) {
                return ['ok' => false, 'message' => "科目「{$subject->name}」不是末级科目，不能录入期初余额"];
            }
        }

        DB::transaction(function () use ($wizard, $rows) {
            OpeningBalance::where('wizard_id', $wizard->id)->delete();
            foreach ($rows as $row) {
                $id = (int) $row['subject_id'];
                OpeningBalance::create([
                    'wizard_id' => $wizard->id,
                    'subject_id' => $id,
                    'period' => $wizard->period,
                    'opening_debit' => round((float) ($row['opening_debit'] ?? 0), 2),
                    'opening_credit' => round((float) ($row['opening_credit'] ?? 0), 2),
                    'year_debit' => round((float) ($row['year_debit'] ?? 0), 2),
                    'year_credit' => round((float) ($row['year_credit'] ?? 0), 2),
                ]);
            }
        });

        return ['ok' => true, 'detail' => $this->trialBalance($wizard)];
    }

    /** 第 4 步：确认前重新校验前三步，避免「先做完再删数据」绕过 */
    private function saveConfirm(OpeningWizard $wizard): array
    {
        $missing = $this->missingSteps($wizard);
        if ($missing) {
            return ['ok' => false, 'message' => $missing];
        }

        return ['ok' => true, 'detail' => [
            'stock_rows' => OpeningStock::where('wizard_id', $wizard->id)->count(),
            'balance_rows' => OpeningBalance::where('wizard_id', $wizard->id)->count(),
        ]];
    }

    /** 返回未完成步骤的中文提示，全部完成返回空字符串 */
    private function missingSteps(OpeningWizard $wizard): string
    {
        $done = OpeningStep::where('wizard_id', $wizard->id)->where('status', OpeningStep::STATUS_DONE)->pluck('step')->all();
        $missing = [];
        foreach ([1, 2, 3] as $no) {
            if (! in_array($no, $done, true)) {
                $missing[] = "第 {$no} 步「".OpeningStep::DEFS[$no][1].'」';
            }
        }

        return $missing ? '请先完成 '.implode('、', $missing) : '';
    }

    /**
     * Excel 导入（第 2/3 步）。返回 success_count / error_count / errors / unmatched。
     *
     * 部分成功：坏行只记原因、不牵连好行（与 Auth 的 UserImport 同一套语义），
     * 现场几百行期初表里错两行是常态，整单回滚会让人放弃用导入。
     */
    public function import(OpeningWizard $wizard, int $step, UploadedFile $file, ?int $operatorId = null, ?string $operatorName = null): array
    {
        if ($wizard->isLocked()) {
            return $this->importRefused('开账已完成并锁定，不能修改');
        }

        if (! in_array($step, [2, 3], true)) {
            return $this->importRefused('只有第 2 步（期初库存）和第 3 步（期初余额）支持导入');
        }

        $path = $file->getRealPath();

        if ($step === 2) {
            $import = new OpeningStockImport($wizard);
            Excel::import($import, $path);
            $this->log($wizard, OpeningLog::ACTION_IMPORT, $operatorId, $operatorName,
                "导入期初库存：成功 {$import->getSuccessCount()} 条，失败 {$import->getErrorCount()} 条");

            return $this->importResult($import, $wizard, 'stock');
        }

        $import = new OpeningBalanceImport($wizard);
        Excel::import($import, $path);
        $this->log($wizard, OpeningLog::ACTION_IMPORT, $operatorId, $operatorName,
            "导入期初余额：成功 {$import->getSuccessCount()} 条，失败 {$import->getErrorCount()} 条");

        return $this->importResult($import, $wizard, 'balance');
    }

    private function importResult($import, OpeningWizard $wizard, string $kind): array
    {
        $data = [
            'step' => $kind === 'stock' ? 2 : 3,
            'success_count' => $import->getSuccessCount(),
            'error_count' => $import->getErrorCount(),
            'errors' => $import->getErrors(),
            'unmatched' => $import->getUnmatched(),
        ];
        if ($kind === 'stock') {
            $data['stock_total'] = (float) OpeningStock::where('wizard_id', $wizard->id)->sum('total_amount');
        } else {
            $data['trial'] = $this->trialBalance($wizard);
        }

        return $data;
    }

    private function importRefused(string $message): array
    {
        return ['success_count' => 0, 'error_count' => 0, 'errors' => [$message], 'unmatched' => [], 'refused' => true];
    }

    /**
     * 导入模板下载。表头必须与 OpeningStockImport/OpeningBalanceImport 的列序一致。
     *
     * 第二个参数 $withSample 供测试注入一行样例数据：GenericExport 会把 data[0]
     * 当表头再写一遍（WithHeadings + FromCollection 全量输出），所以模板文件里
     * 表头会出现两次，导入侧按内容识别并跳过，见两个 Import 类的注释。
     */
    public function downloadTemplate(string $kind, array $withSample = []): string
    {
        if ($kind === 'stock') {
            $head = ['商品编码', '仓库编码', '数量', '成本单价'];
            $filename = 'opening_stock_import_template_'.date('YmdHis').'.xlsx';
        } elseif ($kind === 'balance') {
            $head = ['科目编码', '期初借方', '期初贷方', '年初借方', '年初贷方'];
            $filename = 'opening_balance_import_template_'.date('YmdHis').'.xlsx';
        } else {
            throw new \InvalidArgumentException('模板类型不正确');
        }

        $rows = $withSample ? [$head, $withSample] : [$head];

        $dir = storage_path('app/exports');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        Excel::store(new GenericExport($rows), 'exports/'.$filename, 'public');

        return $filename;
    }

    /** 模板文件名对应的磁盘绝对路径 */
    public function templatePath(string $filename): string
    {
        return storage_path('app/public/exports/'.$filename);
    }

    /**
     * 锁定（确认开账）——全模块唯一的验收闸门，整体一个事务。
     *
     * 顺序：先校验（步骤齐全 + 试算平衡）→ 回写 stocks → 回写 ledger 期初
     * （已结账期间的科目跳过）→ 写 lock_report → 置 locked → 写流水。
     */
    public function lock(OpeningWizard $wizard, ?int $operatorId = null, ?string $operatorName = null): array
    {
        if ($wizard->isLocked()) {
            return ['ok' => false, 'message' => '该开账已锁定，无需重复操作'];
        }

        $missing = $this->missingSteps($wizard);
        if ($missing) {
            return ['ok' => false, 'message' => $missing.'才能确认开账'];
        }

        $trial = $this->trialBalance($wizard);
        if (! $trial['balanced']) {
            return ['ok' => false, 'message' => sprintf(
                '借贷不平衡，借方 %.2f，贷方 %.2f，差额 %.2f，请调整后再确认开账',
                $trial['debit_total'],
                $trial['credit_total'],
                $trial['diff']
            )];
        }

        $stockRows = OpeningStock::where('wizard_id', $wizard->id)->get();
        $balanceRows = OpeningBalance::where('wizard_id', $wizard->id)->get();

        $report = DB::transaction(function () use ($wizard, $stockRows, $balanceRows, $operatorId, $operatorName) {
            $stockWritten = 0;
            foreach ($stockRows as $r) {
                // 幂等回写：stocks 有 (product_id, warehouse_id) 唯一键，
                // 重复开账/反开账后再锁定都会覆盖成期初口径，不会翻倍。
                // 有意不走 StockService::adjust/stockIn——那会写库存变动流水，
                // 期初不是业务变动，混进流水会让对账多出说不清的一批。
                Stock::updateOrCreate(
                    ['product_id' => $r->product_id, 'warehouse_id' => $r->warehouse_id],
                    [
                        'quantity' => $r->quantity,
                        'cost_price' => $r->cost_price,
                        'total_amount' => $r->total_amount,
                        'updated_at' => now(),
                    ]
                );
                $stockWritten++;
            }

            $balanceWritten = 0;
            $balanceSkipped = 0;
            $closedCache = [];
            foreach ($balanceRows as $r) {
                $period = $r->period ?: $wizard->period;
                $closed = $closedCache[$period] ??= $this->periodClosed($period);
                if ($closed) {
                    // 已结账期间的期初不允许再由开账改写，跳过而不是报错：
                    // 一个主体往往多个期间并存，因为一个旧期间卡死整单不现实
                    $balanceSkipped++;

                    continue;
                }
                LedgerOpeningBalance::updateOrCreate(
                    ['subject_id' => $r->subject_id, 'period' => $period],
                    [
                        'opening_debit' => $r->opening_debit,
                        'opening_credit' => $r->opening_credit,
                        'year_debit' => $r->year_debit,
                        'year_credit' => $r->year_credit,
                    ]
                );
                $balanceWritten++;
            }

            $report = [
                'stock_rows' => $stockRows->count(),
                'balance_rows' => $balanceRows->count(),
                'stock_written' => $stockWritten,
                'balance_written' => $balanceWritten,
                'balance_skipped_closed' => $balanceSkipped,
            ];

            $wizard->update([
                'status' => OpeningWizard::STATUS_LOCKED,
                'step' => 4,
                'locked_at' => now(),
                'locked_by' => $operatorId,
                'locked_by_name' => $operatorName,
                'lock_report' => $report,
            ]);

            return $report;
        });

        $this->log($wizard, OpeningLog::ACTION_LOCK, $operatorId, $operatorName,
            "确认开账锁定：写入库存 {$report['stock_written']} 行，期初余额 {$report['balance_written']} 行".
            ($report['balance_skipped_closed'] > 0 ? "，跳过已结账期间 {$report['balance_skipped_closed']} 行" : ''));

        return ['ok' => true, 'message' => '开账已确认并锁定', 'report' => $report];
    }

    /** lock() 内部用的期间结账判定（与 isPeriodClosed 同一 SQL，单独留一个可读名字） */
    private function periodClosed(string $period): bool
    {
        return $this->isPeriodClosed($period);
    }

    /**
     * 反开账解锁。必须显式给出 reason，权限由 data:opening:unlock 控制。
     *
     * 有意**不删除**已写进 stocks / ledger_opening_balances 的数据：这两张表是真金白银的
     * 账，静默冲销比留在原地更危险——现场已经按期初开账录了凭证，程序一刀切删掉期初，
     * 操作员看不见任何痕迹，事后无从追查。解锁只把状态放回 draft 让人改，
     * 改完再 lock()，两条 updateOrCreate 都是幂等覆盖，账会自己回到正确口径。
     */
    public function unlock(OpeningWizard $wizard, string $reason, ?int $operatorId = null, ?string $operatorName = null): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            return ['ok' => false, 'message' => '请填写反开账原因'];
        }
        if (! $wizard->isLocked()) {
            return ['ok' => false, 'message' => '该开账尚未锁定，无需反开账'];
        }

        $wizard->update(['status' => OpeningWizard::STATUS_DRAFT]);
        $this->log($wizard, OpeningLog::ACTION_UNLOCK, $operatorId, $operatorName, '反开账解锁，原因：'.$reason);

        return ['ok' => true, 'message' => '已反开账，期初数据可继续修改；已写入的库存与期初余额未被删除，请修正后重新确认开账'];
    }

    /** 只读锁状态，给别的页面用（不用改 A/B 域文件就能判断是否已开账） */
    public function lockStatus(int $companyId, ?int $divisionId, string $period): array
    {
        $wizard = OpeningWizard::query()
            ->where('company_id', $companyId)
            ->whereNull('division_id')
            ->where('period', $period)
            ->when($divisionId, fn ($q) => $q->where('division_id', $divisionId))
            ->latest('id')
            ->first();

        if (! $wizard) {
            return ['exists' => false, 'locked' => false, 'status' => null, 'wizard_id' => null];
        }

        return [
            'exists' => true,
            'locked' => $wizard->isLocked(),
            'status' => $wizard->status,
            'wizard_id' => $wizard->id,
            'locked_at' => $wizard->locked_at?->format('Y-m-d H:i:s'),
            'locked_by_name' => $wizard->locked_by_name,
        ];
    }

    private function log(OpeningWizard $wizard, string $action, ?int $operatorId, ?string $operatorName, string $content): void
    {
        OpeningLog::create([
            'wizard_id' => $wizard->id,
            'action' => $action,
            'operator_id' => $operatorId,
            'operator_name' => $operatorName,
            'content' => mb_substr($content, 0, 500),
            'created_at' => now(),
        ]);
    }
}
