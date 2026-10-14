<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 机器 C：把 perf_ 四表对齐任务书 §八 字段命名，保留既有数据与仍被计算/
 * 展示依赖的旧列（calc_type、rate、tier_data、detail_snapshot 等）。
 *
 * - rules  ：补 type / rule / scope / employee_ids，按 calc_type 映射回填，
 *            rule JSON 打包平铺规则字段（任务书只要求这些列，多出的平铺列
 *            继续作为计算器的数据源，控制器双向同步）。
 * - orders ：补 sales_amount / received_amount / profit_amount / base_amount /
 *            rate / rule_id / deduction / actual_amount；commission_amount 语义
 *            从「实发」切换为「应发」——先把旧值拷进 actual_amount，再用旧
 *            commission_gross 覆盖，最后删除被替代的旧列。
 * - items  ：表改名 perf_commission_items，commission_order_id→order_id、
 *            qty→quantity，补 sales_amount / cost_amount / profit_amount /
 *            contribution，source_type 的 product_line 归一为 product。
 * - targets：补 employee_id（仅 employee 类目标回填）+ 唯一键(employee_id,period)。
 *
 * 干净安装路径：本迁移跑在 000001 之后，000001 仍建旧形态再由本迁移整形，
 * 两条路径收敛到同一最终 schema。全程 hasColumn/hasTable 守卫，可重复执行。
 * down() 不完全可逆（commission_gross 等旧列已物理删除），仅作开发回退。
 */
return new class extends Migration
{
    /** calc_type → 任务书 type 枚举 */
    private const TYPE_MAP = [
        'sale_rate' => 'sales',
        'profit_rate' => 'profit',
        'fixed_per_unit' => 'piece',
        'tiered' => 'ladder',
        'product_specific' => 'single',
    ];

    public function up(): void
    {
        $this->alignRules();
        $this->alignOrders();
        $this->alignItems();
        $this->alignTargets();
    }

    public function down(): void
    {
        if (Schema::hasTable('perf_sales_targets') && Schema::hasColumn('perf_sales_targets', 'employee_id')) {
            Schema::table('perf_sales_targets', function (Blueprint $t) {
                $t->dropUnique(['employee_id', 'period']);
                $t->dropColumn('employee_id');
            });
        }

        if (Schema::hasTable('perf_commission_items')) {
            Schema::table('perf_commission_items', function (Blueprint $t) {
                foreach (['sales_amount', 'cost_amount', 'profit_amount', 'contribution'] as $col) {
                    if (Schema::hasColumn('perf_commission_items', $col)) {
                        $t->dropColumn($col);
                    }
                }
                if (Schema::hasColumn('perf_commission_items', 'order_id') && ! Schema::hasColumn('perf_commission_items', 'commission_order_id')) {
                    $t->renameColumn('order_id', 'commission_order_id');
                }
                if (Schema::hasColumn('perf_commission_items', 'quantity') && ! Schema::hasColumn('perf_commission_items', 'qty')) {
                    $t->renameColumn('quantity', 'qty');
                }
            });
            if (! Schema::hasTable('perf_commission_order_items')) {
                Schema::rename('perf_commission_items', 'perf_commission_order_items');
            }
        }

        // orders 旧列已被删除且数据被覆盖，不做机械回滚；仅摘掉新增列。
        if (Schema::hasTable('perf_commission_orders')) {
            Schema::table('perf_commission_orders', function (Blueprint $t) {
                foreach (['sales_amount', 'received_amount', 'profit_amount', 'base_amount', 'deduction', 'rate', 'rule_id'] as $col) {
                    if (Schema::hasColumn('perf_commission_orders', $col)) {
                        $t->dropColumn($col);
                    }
                }
            });
        }

        if (Schema::hasTable('perf_commission_rules')) {
            Schema::table('perf_commission_rules', function (Blueprint $t) {
                foreach (['type', 'rule', 'scope', 'employee_ids'] as $col) {
                    if (Schema::hasColumn('perf_commission_rules', $col)) {
                        $t->dropColumn($col);
                    }
                }
            });
        }
    }

    private function alignRules(): void
    {
        if (! Schema::hasTable('perf_commission_rules')) {
            return;
        }

        Schema::table('perf_commission_rules', function (Blueprint $t) {
            if (! Schema::hasColumn('perf_commission_rules', 'type')) {
                $t->string('type', 20)->default('sales')->comment('sales/profit/piece/ladder/single');
            }
            if (! Schema::hasColumn('perf_commission_rules', 'rule')) {
                $t->json('rule')->nullable()->comment('比例规则 JSON（任务书 §八）');
            }
            if (! Schema::hasColumn('perf_commission_rules', 'scope')) {
                $t->string('scope', 20)->default('all')->comment('all/specified');
            }
            if (! Schema::hasColumn('perf_commission_rules', 'employee_ids')) {
                $t->json('employee_ids')->nullable()->comment('指定业务员 user_id，scope=specified 时必填');
            }
        });

        foreach (DB::table('perf_commission_rules')->orderBy('id')->get() as $r) {
            $emp = json_decode((string) $r->apply_employees, true);
            $emp = is_array($emp) ? array_values($emp) : [];
            $levels = json_decode((string) $r->apply_customer_levels, true);
            $scopeIds = json_decode((string) $r->product_scope_ids, true);

            DB::table('perf_commission_rules')->where('id', $r->id)->update([
                'type' => self::TYPE_MAP[$r->calc_type] ?? 'sales',
                'scope' => $emp !== [] ? 'specified' : 'all',
                'employee_ids' => json_encode($emp, JSON_UNESCAPED_UNICODE),
                'rule' => json_encode([
                    'rate' => (float) $r->rate,
                    'unit_base' => $r->unit_base,
                    'unit_amount' => (float) $r->unit_amount,
                    'tier_data' => is_array($json = json_decode((string) $r->tier_data, true)) ? $json : null,
                    'product_rules' => is_array($json = json_decode((string) $r->product_rules, true)) ? $json : null,
                    'apply_customer_levels' => is_array($levels) ? $levels : [],
                    'product_scope_type' => $r->product_scope_type,
                    'product_scope_ids' => is_array($scopeIds) ? $scopeIds : [],
                    'base_on_payment' => (bool) $r->base_on_payment,
                    'deduct_return' => (bool) $r->deduct_return,
                    'exclude_baddebt' => (bool) $r->exclude_baddebt,
                    'cap_amount' => (float) $r->cap_amount,
                    'floor_amount' => (float) $r->floor_amount,
                    'allow_stack' => (bool) $r->allow_stack,
                ], JSON_UNESCAPED_UNICODE),
            ]);
        }
    }

    private function alignOrders(): void
    {
        if (! Schema::hasTable('perf_commission_orders')) {
            return;
        }

        Schema::table('perf_commission_orders', function (Blueprint $t) {
            if (! Schema::hasColumn('perf_commission_orders', 'sales_amount')) {
                $t->decimal('sales_amount', 14, 2)->default(0)->comment('销售额');
            }
            if (! Schema::hasColumn('perf_commission_orders', 'received_amount')) {
                $t->decimal('received_amount', 14, 2)->default(0)->comment('回款额');
            }
            if (! Schema::hasColumn('perf_commission_orders', 'profit_amount')) {
                $t->decimal('profit_amount', 14, 2)->default(0)->comment('毛利');
            }
            if (! Schema::hasColumn('perf_commission_orders', 'base_amount')) {
                $t->decimal('base_amount', 14, 2)->default(0)->comment('提成基数');
            }
            if (! Schema::hasColumn('perf_commission_orders', 'deduction')) {
                $t->decimal('deduction', 14, 2)->default(0)->comment('扣减（退货/坏账/抹零）');
            }
            if (! Schema::hasColumn('perf_commission_orders', 'actual_amount')) {
                $t->decimal('actual_amount', 14, 2)->default(0)->comment('实发提成');
            }
            if (! Schema::hasColumn('perf_commission_orders', 'rate')) {
                $t->decimal('rate', 8, 4)->default(0)->comment('生成时命中比例（混合方案记加权或 0）');
            }
            if (! Schema::hasColumn('perf_commission_orders', 'rule_id')) {
                $t->unsignedBigInteger('rule_id')->nullable()->comment('主命中方案 id');
                $t->index('rule_id');
            }
        });

        // 旧列还在 → 首次对齐：先拷语义，再把 commission_amount 切成「应发」。
        $hasLegacy = Schema::hasColumn('perf_commission_orders', 'sale_amount');
        if ($hasLegacy) {
            DB::update('UPDATE perf_commission_orders SET sales_amount = sale_amount, received_amount = payment_amount, profit_amount = gross_profit, base_amount = commission_base, deduction = commission_deduct, actual_amount = commission_amount');
            DB::update('UPDATE perf_commission_orders SET commission_amount = commission_gross');

            Schema::table('perf_commission_orders', function (Blueprint $t) {
                foreach (['sale_amount', 'payment_amount', 'gross_profit', 'commission_base', 'commission_gross', 'commission_deduct'] as $col) {
                    if (Schema::hasColumn('perf_commission_orders', $col)) {
                        $t->dropColumn($col);
                    }
                }
            });
        }
    }

    private function alignItems(): void
    {
        if (! Schema::hasTable('perf_commission_items') && Schema::hasTable('perf_commission_order_items')) {
            Schema::rename('perf_commission_order_items', 'perf_commission_items');
        }
        if (! Schema::hasTable('perf_commission_items')) {
            return;
        }

        // 改名单独执行（rename 参数是 旧名→新名；与 addColumn 混在一个
        // Blueprint 里会在部分 MySQL 版本拼成一条出错的 ALTER）
        if (Schema::hasColumn('perf_commission_items', 'commission_order_id')) {
            Schema::table('perf_commission_items', function (Blueprint $t) {
                $t->renameColumn('commission_order_id', 'order_id');
            });
        }
        if (Schema::hasColumn('perf_commission_items', 'qty')) {
            Schema::table('perf_commission_items', function (Blueprint $t) {
                $t->renameColumn('qty', 'quantity');
            });
        }

        Schema::table('perf_commission_items', function (Blueprint $t) {
            if (! Schema::hasColumn('perf_commission_items', 'sales_amount')) {
                $t->decimal('sales_amount', 14, 2)->default(0)->comment('该行销售额');
            }
            if (! Schema::hasColumn('perf_commission_items', 'cost_amount')) {
                $t->decimal('cost_amount', 14, 2)->default(0)->comment('该行成本');
            }
            if (! Schema::hasColumn('perf_commission_items', 'profit_amount')) {
                $t->decimal('profit_amount', 14, 2)->default(0)->comment('该行毛利');
            }
            if (! Schema::hasColumn('perf_commission_items', 'contribution')) {
                $t->decimal('contribution', 14, 2)->default(0)->comment('该行提成贡献（任务书 §八）');
            }
        });

        if (Schema::hasColumn('perf_commission_items', 'amount')) {
            DB::update('UPDATE perf_commission_items SET contribution = amount');
        }
        DB::update("UPDATE perf_commission_items SET source_type = 'product' WHERE source_type = 'product_line'");
    }

    private function alignTargets(): void
    {
        if (! Schema::hasTable('perf_sales_targets')) {
            return;
        }

        $hasEmp = Schema::hasColumn('perf_sales_targets', 'employee_id');
        if (! $hasEmp) {
            Schema::table('perf_sales_targets', function (Blueprint $t) {
                $t->unsignedBigInteger('employee_id')->nullable()->comment('业务员 user_id（任务书 §八）');
            });
        }

        // 仅 employee 类目标回填，避免与 customer/product 目标 id 撞唯一键
        DB::table('perf_sales_targets')->where('target_type', 'employee')->whereNull('employee_id')
            ->update(['employee_id' => DB::raw('target_id')]);

        if (! Schema::hasIndex('perf_sales_targets', ['employee_id', 'period'])) {
            Schema::table('perf_sales_targets', function (Blueprint $t) {
                $t->unique(['employee_id', 'period']);
            });
        }
    }
};
