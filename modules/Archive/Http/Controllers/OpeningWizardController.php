<?php

namespace Modules\Archive\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Archive\Models\OpeningWizard;
use Modules\Archive\Services\OpeningWizardService;

/**
 * 开账向导（F6 第二批）。
 *
 * 一个「公司 + 事业部（可空）+ 期初期间」一份四步向导：
 * ①科目设置 ②期初库存 ③期初余额 ④导入/确认，确认即锁定并回写真实库存/总账期初。
 *
 * 权限：菜单与按钮权限点由迁移 2026_11_05_000009 登记（data:opening:*）。
 * 路由上仍只挂 auth.check:admin（与全仓库其它模块路由一致）——有意不把权限点写进
 * 中间件参数：AuthCheckMiddleware 的第二个参数是 $permission，而 `auth.check:admin,data:opening:lock`
 * 里的逗号会被 Laravel 解析成同一个参数值（$guard = 'admin,data:opening:lock'），
 * 直接 401。写操作的闸门放在服务层（locked 一律拒绝）+ 前端 v-permission，
 * 与其它模块同一套现状，不在这里另造一种挂法。
 */
class OpeningWizardController extends Controller
{
    use ResponseTrait;

    public function __construct(protected OpeningWizardService $service) {}

    /**
     * 打开向导（GET /data/opening?company_id=&division_id=&period=）。
     * 首次访问自动建 draft 向导 + 4 步 pending，返回的步骤状态即前端步骤条的数据源。
     */
    public function show(Request $request)
    {
        $data = $request->validate([
            'company_id' => ['required', 'integer'],
            'division_id' => ['nullable', 'integer'],
            'period' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
        ], [
            'company_id.required' => '请选择公司',
            'period.required' => '请选择期初期间',
            'period.regex' => '期初期间格式不正确，应为 YYYY-MM',
        ]);

        $wizard = $this->service->show(
            (int) $data['company_id'],
            isset($data['division_id']) ? (int) $data['division_id'] : null,
            $data['period']
        );

        return $this->success($this->service->detail($wizard));
    }

    /** 只读锁状态：别的页面判断「这个主体是否已开账」用，不需要打开整份向导 */
    public function lockStatus(Request $request)
    {
        $data = $request->validate([
            'company_id' => ['required', 'integer'],
            'division_id' => ['nullable', 'integer'],
            'period' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
        ], [
            'company_id.required' => '请选择公司',
            'period.required' => '请选择期初期间',
        ]);

        return $this->success($this->service->lockStatus(
            (int) $data['company_id'],
            isset($data['division_id']) ? (int) $data['division_id'] : null,
            $data['period']
        ));
    }

    /**
     * 保存/完成某一步（POST /data/opening/steps/{step}）。
     *
     * 规则一律写成独立数组元素（'nullable', 'integer'），不要塞成 'nullable|integer'：
     * 复合规则当单个数组元素传进去会去找 validateNullable|integer() 方法直接 500（第一批踩过）。
     */
    public function saveStep(Request $request, int $step)
    {
        $wizard = $this->findWizard($request);
        if ($wizard instanceof JsonResponse) {
            return $wizard;
        }

        $payload = $request->validate([
            'dry' => ['nullable', 'integer'],
            'rows' => ['nullable', 'array'],
            'rows.*.product_id' => ['nullable', 'integer'],
            'rows.*.warehouse_id' => ['nullable', 'integer'],
            'rows.*.quantity' => ['nullable', 'integer'],
            'rows.*.cost_price' => ['nullable', 'numeric'],
            'rows.*.subject_id' => ['nullable', 'integer'],
            'rows.*.opening_debit' => ['nullable', 'numeric'],
            'rows.*.opening_credit' => ['nullable', 'numeric'],
            'rows.*.year_debit' => ['nullable', 'numeric'],
            'rows.*.year_credit' => ['nullable', 'numeric'],
        ], [
            'rows.array' => '明细数据格式不正确',
            'rows.*.quantity.integer' => '数量必须是整数',
            'rows.*.cost_price.numeric' => '成本单价必须是数字',
        ]);

        if (! in_array($step, [1, 2, 3, 4], true)) {
            return $this->error('步骤不存在', 422);
        }

        $result = $this->service->saveStep($wizard, $step, $payload, $this->uid(), $this->uname());
        if (! $result['ok']) {
            return $this->error($result['message'], 422);
        }

        return $this->success($this->service->detail($wizard->fresh()), $result['message']);
    }

    /** Excel 导入期初库存/期初余额（POST /data/opening/import） */
    public function import(Request $request)
    {
        $data = $request->validate([
            'step' => ['required', Rule::in([2, 3])],
            'company_id' => ['required', 'integer'],
            'division_id' => ['nullable', 'integer'],
            'period' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'file' => ['required', 'file', 'mimes:xlsx,xls'],
        ], [
            'step.required' => '请选择导入到第几步',
            'step.in' => '只有第 2 步（期初库存）和第 3 步（期初余额）支持导入',
            'file.required' => '请上传文件',
            'file.mimes' => '文件格式不正确，仅支持 xlsx/xls',
            'period.required' => '请选择期初期间',
        ]);

        $wizard = $this->service->show(
            (int) $data['company_id'],
            isset($data['division_id']) ? (int) $data['division_id'] : null,
            $data['period']
        );

        $result = $this->service->import($wizard, (int) $data['step'], $request->file('file'), $this->uid(), $this->uname());
        if (! empty($result['refused'])) {
            return $this->error($result['errors'][0] ?? '导入被拒绝', 422);
        }

        return $this->success($result, "导入完成，成功 {$result['success_count']} 条，失败 {$result['error_count']} 条");
    }

    /** 下载导入模板（GET /data/opening/template/{kind}） */
    public function downloadTemplate(string $kind)
    {
        if (! in_array($kind, ['stock', 'balance'], true)) {
            return $this->error('模板类型不正确', 422);
        }

        $filename = $this->service->downloadTemplate($kind);
        $filePath = $this->service->templatePath($filename);
        if (! file_exists($filePath)) {
            return $this->error('模板生成失败，请重试', 500);
        }

        return response()->download($filePath, $filename)->deleteFileAfterSend();
    }

    /** 确认开账并锁定（POST /data/opening/lock）——唯一会把期初写进真实账的入口 */
    public function lock(Request $request)
    {
        $wizard = $this->findWizard($request);
        if ($wizard instanceof JsonResponse) {
            return $wizard;
        }

        $result = $this->service->lock($wizard, $this->uid(), $this->uname());
        if (! $result['ok']) {
            return $this->error($result['message'], 422);
        }

        return $this->success($this->service->detail($wizard->fresh()), $result['message']);
    }

    /**
     * 反开账解锁（POST /data/opening/unlock）——必须填原因。
     *
     * reason 与主体参数一次校验完：反开账是危险动作，原因缺失要在定位向导之前就挡住。
     */
    public function unlock(Request $request)
    {
        $data = $request->validate([
            'company_id' => ['required', 'integer'],
            'division_id' => ['nullable', 'integer'],
            'period' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'reason' => ['required', 'string', 'max:200'],
        ], [
            'company_id.required' => '请选择公司',
            'period.required' => '请选择期初期间',
            'reason.required' => '请填写反开账原因',
            'reason.max' => '反开账原因不能超过 200 字',
        ]);

        $wizard = $this->findWizard($request, $data);
        if ($wizard instanceof JsonResponse) {
            return $wizard;
        }

        $result = $this->service->unlock($wizard, $data['reason'], $this->uid(), $this->uname());
        if (! $result['ok']) {
            return $this->error($result['message'], 422);
        }

        return $this->success($this->service->detail($wizard->fresh()), $result['message']);
    }

    /**
     * 按 company/division/period 定位向导（与 show 同一套定位口径）。
     *
     * 有意不接受裸 wizard_id：前端各入口传的都是同一组主体参数，若允许传 id，
     * 随便拼个 id 就能对别人的主体下单子，归属校验还得再写一遍。
     * 定位不到就按同参数补建（复用 show 的幂等逻辑），而不是回「向导不存在」——
     * 那种报错用户没法自助恢复（页面一打开就会 show 出向导，正常时序根本碰不到）。
     */
    private function findWizard(Request $request, ?array $validated = null): OpeningWizard|JsonResponse
    {
        $data = $validated ?? $request->validate([
            'company_id' => ['required', 'integer'],
            'division_id' => ['nullable', 'integer'],
            'period' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
        ], [
            'company_id.required' => '请选择公司',
            'period.required' => '请选择期初期间',
            'period.regex' => '期初期间格式不正确，应为 YYYY-MM',
        ]);

        return $this->service->show(
            (int) $data['company_id'],
            ! empty($data['division_id']) ? (int) $data['division_id'] : null,
            $data['period']
        );
    }

    /** 当前登录管理员：写操作要落操作人，姓名取 real_name，没有则退回 username */
    private function uid(): ?int
    {
        $user = auth('admin')->user();

        return $user?->id ? (int) $user->id : null;
    }

    private function uname(): ?string
    {
        $user = auth('admin')->user();
        if (! $user) {
            return null;
        }

        return $user->real_name ?: ($user->username ?? null);
    }
}
