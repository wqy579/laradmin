<?php

namespace Modules\Performance\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Performance\Models\CommissionOrder;
use Modules\Performance\Models\CommissionOrderItem;
use Modules\Performance\Models\CommissionRule;
use Modules\Performance\Models\SalesTarget;

/**
 * 演示/验证用数据（C 域 BUG-02「9 页无数据」）。
 *
 * 业务表（sales_orders/customers/products/stocks 等）由其它机器在真实业务流
 * 中写入；本容器为验证 9 页贯通，补一份最小演示数据。仅用于本地验证，
 * 不是生产业务数据源。幂等：先删同名再插，重复运行安全。
 */
class PerfDemoSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        // 业务员（auth_user 里找真实销售员；无则回退 admin 第一条）
        $empId = (int) DB::table('auth_user')->whereNotNull('real_name')->where('id', '<>', 1)->value('id') ?: 1;
        $empName = DB::table('auth_user')->where('id', $empId)->value('real_name') ?: '业务员一';
        $deptId = (int) DB::table('auth_user')->where('id', $empId)->value('department_id') ?: 1;
        $deptName = DB::table('auth_department')->where('id', $deptId)->value('name') ?: '销售部';
        $customerId = (int) DB::table('customers')->orderBy('id')->value('id') ?: 1;
        $productIds = DB::table('products')->pluck('id')->all();

        // ============ 1. 提成方案 ============
        $rules = [
            [
                'code' => 'DEMO-RULE-SALES', 'name' => '销售额提成方案（演示）',
                'type' => 'sales', 'calc_type' => 'sale_rate', 'rule' => ['type' => 'sale_rate', 'rate' => 1.5],
                'scope' => 'all', 'employee_ids' => [], 'rate' => 1.5, 'unit_amount' => 0,
                'base_on_payment' => 1, 'deduct_return' => 1, 'exclude_baddebt' => 1,
                'cap_amount' => 0, 'floor_amount' => 0, 'allow_stack' => 0,
                'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
                'status' => 'active', 'remark' => '演示：按回款 1.5% 计提',
            ],
            [
                'code' => 'DEMO-RULE-PROFIT', 'name' => '毛利提成方案（演示）',
                'type' => 'profit', 'calc_type' => 'profit_rate', 'rule' => ['type' => 'profit_rate', 'rate' => 5.0],
                'scope' => 'all', 'employee_ids' => [], 'rate' => 5.0, 'unit_amount' => 0,
                'base_on_payment' => 1, 'deduct_return' => 1, 'exclude_baddebt' => 1,
                'cap_amount' => 0, 'floor_amount' => 0, 'allow_stack' => 0,
                'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
                'status' => 'active', 'remark' => '演示：按毛利 5% 计提',
            ],
            [
                'code' => 'DEMO-RULE-PIECE', 'name' => '固定件数提成（演示）',
                'type' => 'piece', 'calc_type' => 'fixed_per_unit', 'rule' => ['type' => 'fixed_per_unit', 'unit_amount' => 2.0],
                'scope' => 'all', 'employee_ids' => [], 'rate' => 0, 'unit_amount' => 2.0,
                'base_on_payment' => 1, 'deduct_return' => 1, 'exclude_baddebt' => 1,
                'cap_amount' => 0, 'floor_amount' => 0, 'allow_stack' => 0,
                'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
                'status' => 'inactive', 'remark' => '演示：停用方案（不可被计算引用）',
            ],
        ];
        CommissionRule::where('code', 'like', 'DEMO-%')->delete();
        foreach ($rules as $r) {
            CommissionRule::create($r);
        }

        // ============ 2. 提成单 + 明细 ============
        CommissionOrder::where('bill_no', 'like', 'DEMO-%')->delete();
        $rule1 = CommissionRule::where('code', 'DEMO-RULE-SALES')->first();
        $orders = [
            [
                'bill_no' => 'DEMO-CM-'.$now->format('Ym').'-01', 'period' => $now->format('Y-m'),
                'employee_id' => $empId, 'employee_name' => $empName,
                'department_id' => $deptId, 'department_name' => $deptName,
                'sales_amount' => 120000, 'received_amount' => 90000, 'return_amount' => 5000, 'profit_amount' => 36000,
                'rule_id' => $rule1->id, 'rate' => 1.5, 'base_amount' => 90000,
                'commission_amount' => 1350, 'deduction' => 75, 'actual_amount' => 1275,
                'status' => 'paid', 'detail_snapshot' => ['demo' => true],
                'maker_id' => 1, 'maker_name' => '系统', 'auditor_id' => 1, 'auditor_name' => '审核员',
                'audited_at' => $now, 'paid_at' => $now, 'remark' => '演示已发放提成单',
            ],
            [
                'bill_no' => 'DEMO-CM-'.$now->format('Ym').'-02', 'period' => $now->format('Y-m'),
                'employee_id' => $empId, 'employee_name' => $empName,
                'department_id' => $deptId, 'department_name' => $deptName,
                'sales_amount' => 60000, 'received_amount' => 30000, 'return_amount' => 0, 'profit_amount' => 18000,
                'rule_id' => $rule1->id, 'rate' => 1.5, 'base_amount' => 30000,
                'commission_amount' => 450, 'deduction' => 0, 'actual_amount' => 450,
                'status' => 'audited', 'detail_snapshot' => ['demo' => true],
                'maker_id' => 1, 'maker_name' => '系统', 'auditor_id' => 1, 'auditor_name' => '审核员',
                'audited_at' => $now, 'paid_at' => null, 'remark' => '演示待发放提成单',
            ],
            [
                'bill_no' => 'DEMO-CM-'.$now->format('Ym').'-03', 'period' => $now->format('Y-m'),
                'employee_id' => $empId, 'employee_name' => $empName,
                'department_id' => $deptId, 'department_name' => $deptName,
                'sales_amount' => 30000, 'received_amount' => 0, 'return_amount' => 0, 'profit_amount' => 9000,
                'rule_id' => $rule1->id, 'rate' => 1.5, 'base_amount' => 0,
                'commission_amount' => 0, 'deduction' => 0, 'actual_amount' => 0,
                'status' => 'pending', 'detail_snapshot' => ['demo' => true],
                'maker_id' => 1, 'maker_name' => '系统', 'auditor_id' => null, 'auditor_name' => null,
                'audited_at' => null, 'paid_at' => null, 'remark' => '演示待审核提成单',
            ],
        ];
        foreach ($orders as $o) {
            $order = CommissionOrder::create($o);
            if ($productIds) {
                $pid = $productIds[0];
                CommissionOrderItem::create([
                    'order_id' => $order->id, 'source_type' => 'sales_order', 'source_id' => $order->id,
                    'source_no' => 'DEMO-SO-001', 'product_id' => $pid, 'product_name' => '演示商品',
                    'quantity' => 100, 'sales_amount' => 30000, 'cost_amount' => 21000, 'profit_amount' => 9000,
                    'base_amount' => 30000, 'contribution' => 1, 'rule_id' => $rule1->id,
                    'rule_name' => $rule1->name, 'calc_type' => 'sale_rate', 'rate_or_unit' => '1.5%', 'amount' => 450,
                ]);
            }
        }

        // ============ 3. 销售目标 ============
        SalesTarget::where('period', $now->format('Y-m'))->where('employee_id', $empId)->delete();
        SalesTarget::create([
            'employee_id' => $empId, 'target_type' => 'employee', 'target_id' => $empId,
            'period' => $now->format('Y-m'), 'target_amount' => 200000,
        ]);

        // ============ 4. 发货记录（发货未收款页） ============
        $orderRow = DB::table('sales_orders')->first();
        if ($orderRow) {
            DB::table('deliveries')->where('delivery_no', 'like', 'DEMO-%')->delete();
            $delivered = (float) $orderRow->total_amount;
            DB::table('deliveries')->insert([
                'delivery_no' => 'DEMO-DLV-'.$now->format('Ym').'-01',
                'order_id' => $orderRow->id,
                'warehouse_id' => 1,
                'customer_id' => $orderRow->customer_id ?? $customerId,
                'total_amount' => $delivered,
                'paid_amount' => $delivered * 0.5,
                'delivery_date' => $now->toDateString(),
                'status' => 2, // deliveries.status int: 2=已完成
                'remark' => '演示：发货未收全款',
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $this->command?->info('PerfDemoSeeder: 演示数据已写入（提成方案/提成单/目标/发货未收款）。');
    }
}
