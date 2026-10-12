# 机器 C 交付说明：绩效提成与经营分析模块

## 一、模块概览

- 一级菜单：**绩效分析**（图标 ElIconTrendCharts，path `/perf/commission-rule`）
- 数据表前缀：`perf_`（与机器 A `ledger_`、机器 B `fin_` 物理隔离）
- 接口前缀：`/admin/perf`
- 九个子菜单：提成方案、提成计算、提成报表、利润查询、应收账龄分析、发货未收款、库存预警中心、销售分析看板、商品与客户分析

## 二、新建数据表清单

| 表名 | 用途 |
|---|---|
| `perf_commission_rules` | 提成方案表（五种 calc_type 共用） |
| `perf_commission_orders` | 提成单主表（pending/audited/paid/rejected 状态机） |
| `perf_commission_order_items` | 提成明细表 |
| `perf_sales_targets` | 销售目标表（可选，用于达成率） |

迁移文件：`modules/Performance/database/migrations/2026_10_13_000001_create_perf_tables.php`
菜单注册迁移：`2026_10_13_000002_register_perf_menu.php`
菜单 Seeder：`modules/Performance/Seeders/PerformanceMenuSeeder.php`（已编入 DatabaseSeeder）

## 三、接口清单（统一前缀 /admin/perf）

### 提成方案
- `GET/POST /perf/commission-rules`
- `GET /perf/commission-rules/estimate`（方案预估）
- `GET/PUT/DELETE /perf/commission-rules/{id}`
- `POST /perf/commission-rules/{id}/copy`

### 提成计算
- `POST /perf/commission/preview`（试算，不落库）
- `POST /perf/commission/generate`（确认生成提成单）

### 提成单
- `GET /perf/commission-orders`
- `GET /perf/commission-orders/{id}`
- `POST /perf/commission-orders/{id}/audit`
- `POST /perf/commission-orders/{id}/reject`
- `POST /perf/commission-orders/{id}/pay`
- `GET /perf/commission-orders/{id}/export`

### 多维利润查询
- `GET /perf/profit`（dimension: orders/customers/employees/products/brands）
- `GET /perf/profit/trend`（近12月）

### 应收与资金风险
- `GET /perf/ar/aging`
- `GET /perf/ar/aging/{customerId}/details`
- `GET /perf/delivery/uncollected`

### 库存预警中心
- `GET /perf/warning/overview`
- `GET /perf/warning?type=safety-stock|expiry|sellable-days|stock-exception`

### 销售分析
- `GET /perf/dashboard/sales-overview`
- `GET /perf/analysis/products`
- `GET /perf/analysis/customers`
- `GET /perf/analysis/employees`
- `GET /perf/analysis/growth-return`

## 四、全链路自测结果

### 1. 提成模块（核心链路）
- ✅ 建方案："按回款1.5%" + "主推单品每件0.5元"
- ✅ 试算：业务员"超级管理员" 销170回100，命中单品方案提成20元（40件×0.5）
- ✅ 生成提成单：`TC2026-10-0001`，状态 pending
- ✅ 审核：状态 pending→audited
- ✅ 标记发放：状态 audited→paid
- ✅ 导出工资条：CSV 200 OK
- ✅ 退货扣减、未回款不计提生效（base_on_payment + deduct_return）

### 2. 利润查询
- ✅ 客户维度：客户A 销170 成本100 毛利62 毛利率36.47%
- ✅ 与手工"销售价−成本价"计算一致
- ✅ 趋势折线、合计行、口径说明问号

### 3. 应收账龄
- ✅ 接口返回，五档账龄分档；FlowDemo 数据已收清故 outstanding=0

### 4. 库存预警
- ✅ 概览：low_sellable=1, turnover_days=211.5
- ✅ 四类型分页查询正常

### 5. 销售分析看板
- ✅ 六指标卡（本月销售额170）、12月趋势、构成环形、排行条形、增长退货率组合图
- ✅ 全部来自真实单据

### 6. 商品/客户/业务员分析
- ✅ products:3, customers:1, employees:1（提成0因period口径）

## 五、与现有模块的联动点

- 提成发放：预留生成费用单"销售费用-工资提成"接口位（`CommissionOrderController::pay` TODO 注释），本期不强制联动
- 安全库存预警"生成采购申请"：跳转 `/business/purchase-application?product_id=&qty=` 预填
- 库存异常"去处理"：跳转 `/business/stock-check`
- 发货未收款"跳转收款"：跳转 `/business/payment?order_no=`
- 成本口径：复用 `products.cost_price`，与 SalesReportController 一致
- 回款口径：复用 `receives`（status=1 已审核）
- 退货口径：复用 `sales_returns`（status=approved）

## 六、三机边界遵守

- ✅ 只新建 `modules/Performance/`，未修改机器 A（ledger_）与机器 B（fin_）任何文件
- ✅ 菜单 Seeder 独立，未改 BusinessSeeder
- ✅ 路由独立 `modules/Performance/routes/admin.php`
- ✅ 前端独立 `frontend/src/views/perf/` 与 `frontend/src/api/perf.js`
- ✅ systemRoutes.js 仅追加 perf 路由（双保险，与 delivery 一致写法）
- ✅ bootstrap/providers.php、DatabaseSeeder.php 仅追加一行注册

## 七、遗留 TODO

1. **应收账龄约定收款日**：当前用 order_date 算账龄，连凯口径有"约定收款日"字段，现有 sales_orders 无此列；后续可加列或用 delivery_date 近似
2. **提成发放生成费用单**：`CommissionOrder::pay` 预留接口位，待三机合并后接"自动生成凭证"（第四阶段）
3. **库存可销天数日均销量**：当前取 stocks_history 近30天出库流水，若历史流水不全会影响精度；可加定时任务日终快照
4. **临期预警批次**：依赖 products.shelf_life_days/expiry_date；现有 stocks 无批次保质期字段，页面已加黄色提示
5. **业务员成交率**：当前简化为有单即100%，后续接 visit_logs 算真实成交率
6. **方案预估**：单品专属预估因试算引擎按行算，complex 组合预估仅取单一方案（与 generate 一致：非叠加取最高）

## 八、文件清单

后端：
- `modules/Performance/Support/CommissionCalculator.php`
- `modules/Performance/Http/Controllers/{CommissionRule,Commission,CommissionOrder,Profit,ArAging,DeliveryUncollected,StockWarning,Dashboard,Analysis}Controller.php`
- `modules/Performance/Models/{CommissionRule,CommissionOrder,CommissionOrderItem,SalesTarget}.php`
- `modules/Performance/Providers/PerformanceServiceProvider.php`
- `modules/Performance/Seeders/PerformanceMenuSeeder.php`
- `modules/Performance/routes/admin.php`
- `modules/Performance/database/migrations/*.php`

前端：
- `frontend/src/api/perf.js`
- `frontend/src/views/perf/*/index.vue`（9页）
- `frontend/src/views/perf/commission-rule/rule-dialog.vue`

配置：
- `bootstrap/providers.php`（+1行）
- `database/seeders/DatabaseSeeder.php`（+1行）
- `frontend/src/router/systemRoutes.js`（+9路由）
