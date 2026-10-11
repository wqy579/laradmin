<?php

namespace Modules\Member\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Modules\Member\Models\Member;
use Modules\Member\Models\MemberLevel;

/**
 * 会员等级
 *
 * 与 Stock 模块的 customer_levels（客户等级价，驱动取价）是两套独立概念。
 * member_levels 只驱动积分/储值权益与自动升级判定。
 * discount_rate 第一期仅作展示（用户决策：不接入 PriceService 取价链）。
 */
class MemberLevelController extends Controller
{
    use ResponseTrait;

    public function index()
    {
        // 每个等级的会员数与储值/积分汇总，便于运营查看等级健康度
        $list = MemberLevel::query()
            ->select([
                'member_levels.*',
            ])
            ->withCount(['members as member_count' => fn ($q) => $q->where('status', Member::STATUS_ACTIVE)])
            ->orderBy('sort')
            ->get()
            ->map(function ($level) {
                $sum = Member::where('level_id', $level->id)->selectRaw(
                    'COALESCE(SUM(balance), 0) as balance_total, COALESCE(SUM(points), 0) as points_total, COALESCE(SUM(total_consume), 0) as consume_total'
                )->first();
                $level->balance_total = (float) $sum->balance_total;
                $level->points_total = (int) $sum->points_total;
                $level->consume_total = (float) $sum->consume_total;

                return $level;
            });

        return $this->success($list);
    }

    public function store(Request $request)
    {
        $validated = $this->validateLevel($request);

        $level = MemberLevel::create($validated);

        return $this->created($level, '等级创建成功');
    }

    public function update(Request $request, $id)
    {
        $level = MemberLevel::find($id);
        if (! $level) {
            return $this->notFound('等级不存在');
        }

        $validated = $this->validateLevel($request, $id);

        // 系统内置等级不可删除，但可编辑
        $level->update($validated);

        return $this->success(MemberLevel::find($id), '等级已更新');
    }

    public function destroy($id)
    {
        $level = MemberLevel::find($id);
        if (! $level) {
            return $this->notFound('等级不存在');
        }
        if ($level->is_system) {
            return $this->error('系统内置等级不能删除，请改为禁用', 422);
        }

        $memberCount = Member::where('level_id', $id)->count();
        if ($memberCount > 0) {
            return $this->error("该等级下有 {$memberCount} 位会员，请先调整会员等级", 422);
        }

        $level->delete();

        return $this->success(null, '等级已删除');
    }

    /** 启用 */
    public function enable($id)
    {
        return $this->setStatus($id, 1, '等级已启用');
    }

    /** 禁用（禁用后不再参与自动升级判定） */
    public function disable($id)
    {
        $level = MemberLevel::find($id);
        if (! $level) {
            return $this->notFound('等级不存在');
        }
        if ($level->is_system && (int) $level->upgrade_amount === 0) {
            return $this->error('默认等级（LV0）不能禁用', 422);
        }

        return $this->setStatus($id, 0, '等级已禁用');
    }

    private function setStatus($id, int $status, string $message)
    {
        $level = MemberLevel::find($id);
        if (! $level) {
            return $this->notFound('等级不存在');
        }
        $level->status = $status;
        $level->save();

        return $this->success($level, $message);
    }

    private function validateLevel(Request $request, ?int $id = null): array
    {
        // level_no 唯一性：编辑时排除自身（字符串拼接优先级高于 ?:，须显式加括号）
        $levelNoUnique = static function (?int $ignoreId) {
            return $ignoreId
                ? ['unique:member_levels,level_no,'.$ignoreId]
                : ['unique:member_levels,level_no'];
        };

        $rules = [
            'level_no' => ['required', 'string', 'max:32', $levelNoUnique($id)],
            'name' => ['required', 'string', 'max:50'],
            'upgrade_amount' => ['nullable', 'numeric', 'min:0'],
            'discount_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'color' => ['nullable', 'string', 'max:20'],
            'description' => ['nullable', 'string', 'max:1000'],
            'sort' => ['nullable', 'integer', 'min:0'],
            'status' => ['nullable', 'integer', 'in:0,1'],
        ];

        $data = $request->validate($rules);

        $data['upgrade_amount'] = $data['upgrade_amount'] ?? 0;
        $data['discount_rate'] = $data['discount_rate'] ?? 100;
        $data['color'] = $data['color'] ?? '#909399';
        $data['sort'] = $data['sort'] ?? 0;
        $data['status'] = $data['status'] ?? 1;
        // 新建的等级不是系统内置，可删除
        $data['is_system'] = false;

        return $data;
    }
}
