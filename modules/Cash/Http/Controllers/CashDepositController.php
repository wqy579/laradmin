<?php

namespace Modules\Cash\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Cash\Models\CashAccount;
use Modules\Cash\Models\CashDeposit;
use Modules\Cash\Models\CashFlow;

/**
 * 存取款转账单控制器（cash/deposits 前缀）
 *
 * 保存即同步写 cash_flow 流水（related_type=cash_deposit），账户余额不落表，
 * 与现金账户查询共用「期初 + Σincome − Σexpense」口径。
 * 取款/互转转出前校验实时余额，不足 422（对齐页面提示文案）。
 */
class CashDepositController extends Controller
{
    use ResponseTrait;

    public function options()
    {
        $list = CashAccount::query()
            ->where('status', CashAccount::STATUS_ENABLED)
            ->orderBy('sort')->orderBy('id')
            ->get(['id', 'name', 'type', 'opening_balance'])
            ->map(fn (CashAccount $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'type' => $a->type,
                'type_label' => CashAccount::typeLabel($a->type),
                'balance' => $this->accountBalance($a),
            ]);

        return $this->success(['list' => $list]);
    }

    /** 单据号：CQ + Ymd + 当日序号（按当日已有单据数 +1） */
    public function genNo()
    {
        $prefix = 'CQ'.date('Ymd');
        $count = CashDeposit::query()->where('doc_no', 'like', $prefix.'%')->count();

        return $this->success(['no' => $prefix.str_pad((string) ($count + 1), 4, '0', STR_PAD_LEFT)]);
    }

    /** 分页列表：start_date/end_date/type/account_id 筛选，biz_date+id 倒序 */
    public function index(Request $request)
    {
        $q = CashDeposit::query()->with('account:id,name');
        if ($startDate = trim((string) $request->input('start_date', ''))) {
            $q->whereDate('biz_date', '>=', $startDate);
        }
        if ($endDate = trim((string) $request->input('end_date', ''))) {
            $q->whereDate('biz_date', '<=', $endDate);
        }
        if (in_array($request->input('type'), [CashDeposit::TYPE_DEPOSIT, CashDeposit::TYPE_WITHDRAW, CashDeposit::TYPE_TRANSFER], true)) {
            $q->where('type', $request->input('type'));
        }
        if ($accountId = (int) $request->input('account_id', 0)) {
            $q->where('account_id', $accountId);
        }

        $page = max(1, (int) $request->input('page', 1));
        $pageSize = min(200, max(1, (int) $request->input('page_size', 20)));
        $pager = $q->orderByDesc('biz_date')->orderByDesc('id')
            ->paginate($pageSize, ['*'], 'page', $page);

        return $this->success([
            'list' => collect($pager->items())->map(fn (CashDeposit $d) => $this->transform($d))->values(),
            'total' => $pager->total(),
            'page' => $pager->currentPage(),
            'page_size' => $pager->perPage(),
            'last_page' => $pager->lastPage(),
        ]);
    }

    /** 新建：事务内写单据 + 同步流水；取款/转出余额不足 422 */
    public function store(Request $request)
    {
        $data = $this->validated($request);

        try {
            $doc = DB::transaction(function () use ($data, $request) {
                $this->assertBalanceEnough($data);

                $doc = CashDeposit::create(array_merge($data, [
                    'status' => CashDeposit::STATUS_CONFIRMED,
                    'created_by' => optional($request->input('auth_user'))->id,
                ]));

                $this->writeFlows($doc);

                return $doc;
            });
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success($this->transform($doc->load('account:id,name')), '存取款单已保存');
    }

    /** 仅当日单可删：删单据 + 同步删关联流水 */
    public function destroy(Request $request, int $id)
    {
        $doc = CashDeposit::findOrFail($id);
        if ($doc->biz_date->toDateString() !== date('Y-m-d')) {
            return $this->error('仅当日单据可删除', 422);
        }
        if ($doc->status !== CashDeposit::STATUS_CONFIRMED) {
            return $this->error('仅未审核单据可删除', 422);
        }

        DB::transaction(function () use ($doc) {
            CashFlow::query()
                ->where('related_type', 'cash_deposit')
                ->where('related_id', $doc->id)
                ->delete();
            $doc->delete();
        });

        return $this->success(['id' => $doc->id], '已删除');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $request->validate([
            'doc_no' => 'required|string|max:50|unique:cash_deposit,doc_no',
            'biz_date' => 'required|date',
            'type' => 'required|in:deposit,withdraw,transfer',
            'account_id' => 'required|integer|exists:cash_account,id',
            'to_account_id' => 'nullable|integer|exists:cash_account,id|required_if:type,transfer|different:account_id',
            'counterpart' => 'nullable|string|max:100',
            'amount' => 'required|numeric|min:0.01|max:999999999999.99',
            'fee' => 'nullable|numeric|min:0|max:999999999999.99',
            'handler' => 'nullable|string|max:50',
            'department' => 'nullable|string|max:50',
            'remark' => 'nullable|string|max:255',
            'memo' => 'nullable|string|max:255',
        ], [
            'doc_no.unique' => '单据号已存在，请重新生成',
            'to_account_id.required_if' => '请选择转入账户',
            'to_account_id.different' => '转入账户不能与转出账户相同',
        ]);

        return [
            'doc_no' => (string) $request->input('doc_no'),
            'biz_date' => (string) $request->input('biz_date'),
            'type' => (string) $request->input('type'),
            'account_id' => (int) $request->input('account_id'),
            'to_account_id' => $request->input('to_account_id') ? (int) $request->input('to_account_id') : null,
            'counterpart' => $request->input('counterpart') !== null ? (string) $request->input('counterpart') : null,
            'amount' => (float) $request->input('amount'),
            'fee' => (float) $request->input('fee', 0),
            'handler' => $request->input('handler') !== null ? (string) $request->input('handler') : null,
            'department' => $request->input('department') !== null ? (string) $request->input('department') : null,
            'remark' => $request->input('remark') !== null ? (string) $request->input('remark') : null,
            'memo' => $request->input('memo') !== null ? (string) $request->input('memo') : null,
        ];
    }

    /** 取款/互转转出：实时余额 ≥ amount+fee，不足报「账户余额不足」 */
    private function assertBalanceEnough(array $data): void
    {
        if (! in_array($data['type'], [CashDeposit::TYPE_WITHDRAW, CashDeposit::TYPE_TRANSFER], true)) {
            return;
        }

        $account = CashAccount::lockForUpdate()->findOrFail($data['account_id']);
        if ($this->accountBalance($account) < $data['amount'] + $data['fee']) {
            throw new \DomainException('账户余额不足');
        }
    }

    /** 按 type 生成 cash_flow：deposit 1 条 income；withdraw 1 条 expense；transfer 出 1 expense + 入 1 income */
    private function writeFlows(CashDeposit $doc): void
    {
        $base = [
            'flow_no' => $doc->doc_no,
            'flow_date' => $doc->biz_date,
            'related_type' => 'cash_deposit',
            'related_id' => $doc->id,
            'created_by' => $doc->created_by,
        ];
        $label = CashDeposit::typeLabel($doc->type);

        if ($doc->type === CashDeposit::TYPE_DEPOSIT) {
            CashFlow::create(array_merge($base, [
                'account_id' => $doc->account_id,
                'direction' => CashFlow::DIRECTION_INCOME,
                'summary' => "存入款（{$label}）",
                'amount' => $doc->amount - $doc->fee,
            ]));
        } elseif ($doc->type === CashDeposit::TYPE_WITHDRAW) {
            CashFlow::create(array_merge($base, [
                'account_id' => $doc->account_id,
                'direction' => CashFlow::DIRECTION_EXPENSE,
                'summary' => "支取款（{$label}）",
                'amount' => $doc->amount + $doc->fee,
            ]));
        } else {
            CashFlow::create(array_merge($base, [
                'account_id' => $doc->account_id,
                'direction' => CashFlow::DIRECTION_EXPENSE,
                'summary' => "互转转出（{$label}）",
                'amount' => $doc->amount + $doc->fee,
            ]));
            CashFlow::create(array_merge($base, [
                'account_id' => $doc->to_account_id,
                'direction' => CashFlow::DIRECTION_INCOME,
                'summary' => "互转转入（{$label}）",
                'amount' => $doc->amount - $doc->fee,
            ]));
        }
    }

    /** 实时余额 = opening_balance + Σincome − Σexpense（与 CashFlowService 口径一致） */
    private function accountBalance(CashAccount $account): float
    {
        $net = (float) CashFlow::query()
            ->where('account_id', $account->id)
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'income' THEN amount ELSE -amount END), 0) as net")
            ->value('net');

        return round((float) $account->opening_balance + $net, 2);
    }

    private function transform(CashDeposit $d): array
    {
        return [
            'id' => $d->id,
            'no' => $d->doc_no,
            'doc_no' => $d->doc_no,
            'biz_date' => $d->biz_date->toDateString(),
            'date' => $d->biz_date->toDateString(),
            'type' => $d->type,
            'type_label' => CashDeposit::typeLabel($d->type),
            'account_id' => $d->account_id,
            'account' => $d->account?->only(['id', 'name']),
            'account_name' => $d->account?->name,
            'to_account_id' => $d->to_account_id,
            'counterpart' => $d->counterpart,
            'amount' => (float) $d->amount,
            'fee' => (float) $d->fee,
            'handler' => $d->handler,
            'department' => $d->department,
            'remark' => $d->remark,
            'memo' => $d->memo,
            'status' => $d->status,
            'created_by' => $d->created_by,
        ];
    }
}
