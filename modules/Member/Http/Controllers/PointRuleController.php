<?php

namespace Modules\Member\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Modules\Member\Models\PointRule;

/**
 * 积分规则
 *
 * 规则表是 key-value 配置（rule_key 唯一），前后端按固定 key 读取。
 * 新增规则（自定义 rule_key）暂不支持——积分逻辑目前只识别预置的 10 个 key。
 */
class PointRuleController extends Controller
{
    use ResponseTrait;

    public function index()
    {
        $list = PointRule::orderBy('id')->get();

        return $this->success($list);
    }

    /** 按 key 读单个规则值（前端渲染表单用） */
    public function show(string $key)
    {
        $rule = PointRule::where('rule_key', $key)->first();
        if (! $rule) {
            return $this->notFound('规则不存在');
        }

        return $this->success($rule);
    }

    /**
     * 批量保存：接收 rules: [{rule_key, rule_value}]，逐条 upsert。
     * 只更新已存在规则的 rule_value，不允许新增/删除 rule_key。
     */
    public function update(Request $request)
    {
        $validated = $request->validate([
            'rules' => 'required|array|min:1',
            // 不加 exists：循环里用 if (! $existing) continue 静默跳过不存在的 key，
            // 避免前端传了新 key 就报 422——重置回默认值时不应被拒。
            'rules.*.rule_key' => 'required|string|max:100',
            'rules.*.rule_value' => 'required|array',
            'rules.*.rule_name' => 'nullable|string|max:100',
            'rules.*.description' => 'nullable|string|max:1000',
        ]);

        $updated = 0;
        foreach ($validated['rules'] as $rule) {
            $existing = PointRule::where('rule_key', $rule['rule_key'])->first();
            if (! $existing) {
                continue;
            }
            $existing->update([
                'rule_value' => $rule['rule_value'],
                'rule_name' => $rule['rule_name'] ?? $existing->rule_name,
                'description' => $rule['description'] ?? $existing->description,
            ]);
            $updated++;
        }

        return $this->success(['updated' => $updated], "已保存 {$updated} 条规则");
    }

    /**
     * 重置为系统默认值。
     * 用户改坏规则时可一键回滚，避免积分业务配置错误长期生效。
     */
    public function reset()
    {
        $defaults = [
            'consume_ratio' => ['amount' => 1, 'points' => 1],
            'category_ratio' => [],
            'register_gift' => ['points' => 0],
            'birthday_gift' => ['points' => 0],
            'invite_gift' => ['inviter' => 0, 'invitee' => 0],
            'deduct_ratio' => ['points' => 100, 'amount' => 1],
            'deduct_limit' => ['type' => 'unlimited', 'value' => 0],
            'deduct_threshold' => ['amount' => 0],
            'expire_type' => ['type' => 'forever', 'value' => ''],
            'expire_remind' => ['days' => []],
        ];

        $reset = 0;
        foreach ($defaults as $key => $value) {
            $rule = PointRule::where('rule_key', $key)->first();
            if ($rule) {
                $rule->update(['rule_value' => $value]);
                $reset++;
            }
        }

        return $this->success(['reset' => $reset], "已重置 {$reset} 条规则为默认值");
    }
}
