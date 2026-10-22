<?php

namespace Modules\Ledger\Seeders;

use Illuminate\Database\Seeder;
use Modules\Ledger\Models\Subject;

/**
 * 预置《小企业会计准则》标准科目表（资产/负债/权益/成本/损益五类）。
 *
 * 编码与名称按准则写死，预置到二级或三级。aux_types 标记末级科目是否挂辅助核算
 * （应收账款挂客户、应付账款挂供应商、主营收入/成本挂品牌）。
 *
 * 幂等：全部按 code updateOrCreate，可重复执行不报错不重复；
 * 2026_10_14_000008 迁移在生产 migrate-only 场景也调用本 seeder，二者口径一致。
 */
class LedgerSubjectSeeder extends Seeder
{
    public function run(): void
    {
        $rows = $this->subjects();
        $idByCode = [];

        foreach ($rows as $row) {
            // 按 code updateOrCreate：已存在则对齐预置口径，不存在则新建
            $subject = Subject::updateOrCreate(
                ['code' => $row['code']],
                [
                    'name' => $row['name'],
                    'category' => $row['category'],
                    'balance_direction' => $row['balance_direction'],
                    'is_leaf' => $row['is_leaf'],
                    'level' => $row['level'],
                    'aux_types' => $row['aux_types'] ?? null,
                    'sort' => 0,
                    'status' => Subject::STATUS_ENABLED,
                ]
            );
            $idByCode[$row['code']] = $subject->id;
        }

        // 第二遍回填 parent_id（用 parent_code 解析；顶级科目保持默认 0）
        foreach ($rows as $row) {
            if (! empty($row['parent_code'])) {
                $parentId = $idByCode[$row['parent_code']] ?? 0;
                Subject::where('code', $row['code'])->update([
                    'parent_id' => $parentId,
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /**
     * 科目定义：code/name/category/balance_direction/level/parent_code/is_leaf/aux_types(可选)
     */
    private function subjects(): array
    {
        return [
            // ===== 资产类 asset =====
            ['code' => '1001', 'name' => '库存现金', 'category' => 'asset', 'balance_direction' => 'debit', 'level' => 1, 'parent_code' => null, 'is_leaf' => true],
            ['code' => '1002', 'name' => '银行存款', 'category' => 'asset', 'balance_direction' => 'debit', 'level' => 1, 'parent_code' => null, 'is_leaf' => true],
            ['code' => '1012', 'name' => '其他货币资金', 'category' => 'asset', 'balance_direction' => 'debit', 'level' => 1, 'parent_code' => null, 'is_leaf' => true],
            ['code' => '1122', 'name' => '应收账款', 'category' => 'asset', 'balance_direction' => 'debit', 'level' => 1, 'parent_code' => null, 'is_leaf' => true, 'aux_types' => ['customer']],
            ['code' => '1123', 'name' => '预付账款', 'category' => 'asset', 'balance_direction' => 'debit', 'level' => 1, 'parent_code' => null, 'is_leaf' => true, 'aux_types' => ['supplier']],
            ['code' => '1221', 'name' => '其他应收款', 'category' => 'asset', 'balance_direction' => 'debit', 'level' => 1, 'parent_code' => null, 'is_leaf' => true],
            ['code' => '1401', 'name' => '材料采购', 'category' => 'asset', 'balance_direction' => 'debit', 'level' => 1, 'parent_code' => null, 'is_leaf' => true],
            ['code' => '1403', 'name' => '原材料', 'category' => 'asset', 'balance_direction' => 'debit', 'level' => 1, 'parent_code' => null, 'is_leaf' => true],
            ['code' => '1405', 'name' => '库存商品', 'category' => 'asset', 'balance_direction' => 'debit', 'level' => 1, 'parent_code' => null, 'is_leaf' => true],
            ['code' => '1411', 'name' => '周转材料', 'category' => 'asset', 'balance_direction' => 'debit', 'level' => 1, 'parent_code' => null, 'is_leaf' => true],
            ['code' => '1471', 'name' => '存货跌价准备', 'category' => 'asset', 'balance_direction' => 'credit', 'level' => 1, 'parent_code' => null, 'is_leaf' => true],
            ['code' => '1601', 'name' => '固定资产', 'category' => 'asset', 'balance_direction' => 'debit', 'level' => 1, 'parent_code' => null, 'is_leaf' => true],
            ['code' => '1602', 'name' => '累计折旧', 'category' => 'asset', 'balance_direction' => 'credit', 'level' => 1, 'parent_code' => null, 'is_leaf' => true],
            ['code' => '1604', 'name' => '在建工程', 'category' => 'asset', 'balance_direction' => 'debit', 'level' => 1, 'parent_code' => null, 'is_leaf' => true],
            ['code' => '1606', 'name' => '固定资产清理', 'category' => 'asset', 'balance_direction' => 'debit', 'level' => 1, 'parent_code' => null, 'is_leaf' => true],

            // ===== 负债类 liability =====
            ['code' => '2001', 'name' => '短期借款', 'category' => 'liability', 'balance_direction' => 'credit', 'level' => 1, 'parent_code' => null, 'is_leaf' => true],
            ['code' => '2202', 'name' => '应付账款', 'category' => 'liability', 'balance_direction' => 'credit', 'level' => 1, 'parent_code' => null, 'is_leaf' => true, 'aux_types' => ['supplier']],
            ['code' => '2203', 'name' => '预收账款', 'category' => 'liability', 'balance_direction' => 'credit', 'level' => 1, 'parent_code' => null, 'is_leaf' => true, 'aux_types' => ['customer']],
            ['code' => '2211', 'name' => '应付职工薪酬', 'category' => 'liability', 'balance_direction' => 'credit', 'level' => 1, 'parent_code' => null, 'is_leaf' => true],
            ['code' => '2221', 'name' => '应交税费', 'category' => 'liability', 'balance_direction' => 'credit', 'level' => 1, 'parent_code' => null, 'is_leaf' => false],
            ['code' => '222101', 'name' => '应交增值税', 'category' => 'liability', 'balance_direction' => 'credit', 'level' => 2, 'parent_code' => '2221', 'is_leaf' => true],
            ['code' => '222102', 'name' => '应交所得税', 'category' => 'liability', 'balance_direction' => 'credit', 'level' => 2, 'parent_code' => '2221', 'is_leaf' => true],
            ['code' => '2241', 'name' => '其他应付款', 'category' => 'liability', 'balance_direction' => 'credit', 'level' => 1, 'parent_code' => null, 'is_leaf' => true],
            ['code' => '2501', 'name' => '长期借款', 'category' => 'liability', 'balance_direction' => 'credit', 'level' => 1, 'parent_code' => null, 'is_leaf' => true],

            // ===== 所有者权益类 equity =====
            ['code' => '4001', 'name' => '实收资本', 'category' => 'equity', 'balance_direction' => 'credit', 'level' => 1, 'parent_code' => null, 'is_leaf' => true],
            ['code' => '4002', 'name' => '资本公积', 'category' => 'equity', 'balance_direction' => 'credit', 'level' => 1, 'parent_code' => null, 'is_leaf' => true],
            ['code' => '4103', 'name' => '本年利润', 'category' => 'equity', 'balance_direction' => 'credit', 'level' => 1, 'parent_code' => null, 'is_leaf' => true],
            ['code' => '4104', 'name' => '利润分配', 'category' => 'equity', 'balance_direction' => 'credit', 'level' => 1, 'parent_code' => null, 'is_leaf' => true],

            // ===== 成本类 cost =====
            ['code' => '5401', 'name' => '主营业务成本', 'category' => 'cost', 'balance_direction' => 'debit', 'level' => 1, 'parent_code' => null, 'is_leaf' => true, 'aux_types' => ['brand']],
            ['code' => '5402', 'name' => '其他业务成本', 'category' => 'cost', 'balance_direction' => 'debit', 'level' => 1, 'parent_code' => null, 'is_leaf' => true],
            ['code' => '5403', 'name' => '税金及附加', 'category' => 'cost', 'balance_direction' => 'debit', 'level' => 1, 'parent_code' => null, 'is_leaf' => true],

            // ===== 损益类 profit_loss =====
            ['code' => '6001', 'name' => '主营业务收入', 'category' => 'profit_loss', 'balance_direction' => 'credit', 'level' => 1, 'parent_code' => null, 'is_leaf' => true, 'aux_types' => ['brand']],
            ['code' => '6051', 'name' => '其他业务收入', 'category' => 'profit_loss', 'balance_direction' => 'credit', 'level' => 1, 'parent_code' => null, 'is_leaf' => true],
            ['code' => '6111', 'name' => '投资收益', 'category' => 'profit_loss', 'balance_direction' => 'credit', 'level' => 1, 'parent_code' => null, 'is_leaf' => true],
            ['code' => '6301', 'name' => '营业外收入', 'category' => 'profit_loss', 'balance_direction' => 'credit', 'level' => 1, 'parent_code' => null, 'is_leaf' => true],
            ['code' => '6601', 'name' => '销售费用', 'category' => 'profit_loss', 'balance_direction' => 'debit', 'level' => 1, 'parent_code' => null, 'is_leaf' => true],
            ['code' => '6602', 'name' => '管理费用', 'category' => 'profit_loss', 'balance_direction' => 'debit', 'level' => 1, 'parent_code' => null, 'is_leaf' => true],
            ['code' => '6603', 'name' => '财务费用', 'category' => 'profit_loss', 'balance_direction' => 'debit', 'level' => 1, 'parent_code' => null, 'is_leaf' => true],
            ['code' => '6711', 'name' => '营业外支出', 'category' => 'profit_loss', 'balance_direction' => 'debit', 'level' => 1, 'parent_code' => null, 'is_leaf' => true],
            ['code' => '6801', 'name' => '所得税费用', 'category' => 'profit_loss', 'balance_direction' => 'debit', 'level' => 1, 'parent_code' => null, 'is_leaf' => true],
        ];
    }
}
