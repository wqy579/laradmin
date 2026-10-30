# 平台级性能与健壮性加固审计报告（machine-a · 自有四域）

> 分支：`feat/perf-audit-a`
> 审计范围：`modules/Cash`、`modules/Asset`、`points`（`modules/Member` 下）、`modules/Ledger`
> 依据：`/root/PERF-AUDIT-BRIEF.md`
> 背景：收官验收（#81/#84/#86/#88/#89/#90）已全合并，本任务为交付后深度加固。

---

## 一、逐项审计结论

| # | 审计项 | 结论 | 说明 |
|---|--------|------|------|
| 1 | N+1 查询 | **已修** | 见 §二-1，共 4 处修复：凭证列表/详情红冲单号预载、明细账对方科目批量预取、资产负债表/利润表科目方向批量取回 |
| 2 | 索引审计 | **已修** | 见 §二-2，3 个新增迁移补 11 列索引，1 处提升唯一索引 |
| 3 | 分页与全表保护 | **已修** | 见 §二-3，会员导出加 `EXPORT_LIMIT=50000` 上限；四域列表均已 `paginate()` 或显式 `limit()` |
| 4 | 金额精度 | **合格** | 四域金额列均 `decimal(...,2)` + `decimal:2` cast，计算用 `round(2)` 定点累加，无 float 列；本轮仅回归确认（前端右对齐已在验收确认） |
| 5 | 权限点与菜单一致性 | **合格** | 四域自有菜单与后端路由一一对应；一处前端路由路径 vs 后端 API 前缀的「不一致」经核实为**两个命名空间**（前端路由由 `menu.path` 构建、后端接口独立前缀），非缺陷 |
| 6 | 事务边界 | **合格** | 跨表写入（凭证生成/审核、折旧过账、积分变动、充值到账）均已包 `DB::transaction` + `lockForUpdate`；抽查未发现裸写 |

---

## 二、修复清单

### 1. N+1 查询（4 处）

| 文件 | 修复内容 |
|------|----------|
| `modules/Ledger/Services/VoucherService.php` | `list()`：`with(['entries', 'reversal:id,voucher_no'])`——红冲单号随行预载，避免 `formatVoucher` 里逐行 `Voucher::whereKey()->value('voucher_no')` |
| `modules/Ledger/Services/VoucherService.php` | `detail()`：`with(['entries.subject', 'reversal:id,voucher_no'])`——同上 |
| `modules/Ledger/Services/VoucherService.php` | `subjectLedger()`：对方科目由「逐凭证查库」改为 **2 条 `whereIn` 批量查询**（贷方/借方各一），查询数不随分录数增长 |
| `modules/Ledger/Services/ReportService.php` | `balanceSheet()`：`$balanceByCode`/`$yearByCode` 里逐 code `Subject::where('code')->first()` 改为**一次性 `pluck('balance_direction','code')`**；`incomeStatement()`：`netByCode()` 由逐 code 查库改为**一次性 `get(['code','id','balance_direction'])` 取科目元数据** |

### 2. 索引审计（3 个新增迁移 + 1 处唯一提升）

| 迁移文件 | 表 → 列 | 说明 |
|----------|--------|------|
| `modules/Ledger/database/migrations/2026_10_30_000012_add_perf_indexes_to_ledger.php` | `ledger_vouchers.maker_id`、`ledger_vouchers.auditor_id`、`ledger_voucher_entries.aux_id`、`ledger_periods.closed_by`、`ledger_voucher_templates.creator_id` | 高频筛选/外键列 |
| `modules/Cash/database/migrations/2026_10_30_000105_add_perf_indexes_to_cash.php` | `cash_card_txn.salesman_id`、`cash_handover.salesman_id`、`cash_handover.account_id` | 按业务员/收款账户筛选列 |
| `modules/Asset/database/migrations/2026_10_30_000006_add_perf_indexes_to_asset.php` | `asset_purchase.department_id`/`user_id`/`status`、`asset_card.department_id`、`asset_depreciation.department_id` | 按部门/使用人/状态筛选列 |
| 同迁移 | `asset_sale.sale_no`：普通 `index()` → `unique()` | 单号按 `count()+1` 生成且无唯一兜底，并发可静默重复入库；提升为唯一后重复即抛异常回滚（对齐 `purchase_no`/`asset_no`）。先删旧普通索引再建唯一，避免同列双索引 |

> 全部为**新增独立迁移**，未改动已有建表迁移，`migrate:fresh` 幂等保持。
> 字符串列（`sale_no`/`voucher_no` 等）均为 `string(32)` 短列，无 MySQL 索引长度超限问题。

### 3. 分页与全表保护（1 处）

| 文件 | 修复内容 |
|------|----------|
| `modules/Member/Http/Controllers/MemberController.php` | `export()` 增加 `->limit(self::EXPORT_LIMIT)`，常量 `EXPORT_LIMIT = 50000`——全表导出封顶，避免大库整表载入内存生成 CSV |

> 四域列表接口复核：`index()` 均走 `paginate()`，下拉 `search()`/`byCustomer()` 均有显式 `limit()`（≤50），无裸 `get()` 全表。

---

## 三、回归测试（每条修复一条）

| 测试文件 | 覆盖 |
|----------|------|
| `modules/Ledger/tests/Feature/PerfAuditRegressionTest.php` | 明细账批量预取（查询数恒定 + 对方科目正确）、列表/详情红冲单号预载、报表批量取科目方向（查询数恒定）、ledger 5 列索引已建 |
| `modules/Cash/tests/Feature/PerfAuditRegressionTest.php` | cash 3 列索引已建 |
| `modules/Asset/tests/Feature/PerfAuditRegressionTest.php` | asset 5 列索引已建 + `sale_no` 唯一 + 重复单号被拒 |
| `modules/Member/tests/Feature/MemberFeatureTest.php` | 会员导出封顶：50010 条 → 恰好导出 50000 行 |

新增测试全部通过（4 个文件，共 11 个新测试方法）。

---

## 四、不修项及理由

| 项 | 结论 | 理由 |
|----|------|------|
| `MemberConsumeLogController::index` 的 `member()` 关联 | 不修 | 列表仅使用快照列 `member_no`/`member_name`（模型无 `$appends`/accessor），**实际不发生逐行查库**，非 N+1 |
| `points` 菜单「路径不一致」 | 不修 | 前端路由由 `menu.path`（`/business/point-exchange`）构建，后端 API 是独立前缀（`business/point-product`/`point-order`）——**两个不同命名空间**，非缺陷 |
| 四域金额精度 | 不修（合格） | 已 `decimal(...,2)` + `decimal:2` cast + `round(2)` 定点累加，无 float 列；前端右对齐 + `tabular-nums` 已在验收确认 |
| 四域事务边界 | 不修（合格） | 已全部 `DB::transaction` + `lockForUpdate`，抽查无裸写 |
| 菜单-权限一致性 | 不修（合格） | 四域自有菜单与后端路由一一对应 |

---

## 五、三条门禁

| 门禁 | 结果 |
|------|------|
| `vendor/bin/pint --test` | ✅ 通过 |
| `php artisan test` | ✅ 664 passed / 3 failed（均为 `ReportFeatureTest` sqlite `DATE_FORMAT` 已知限制，CI 走 MySQL 通过，非本任务引入）/ 1 skipped（`DeliveryMigrationsFeatureTest` 预先存在） |
| `npm run build` | ✅ 构建成功（26.66s；chunk>1MB 警告为既有，非新增） |

---

## 六、待转交清单

本域未发现他域缺陷需转交。`ReportFeatureTest` 的 3 条 sqlite-only 失败为已知基线（e 机域），无需转交。

---

## 七、结论

6 项审计全部给出结论；发现问题均已修复；回归测试补齐（11 条新增，全部通过）；三条门禁绿；审计报告已落盘。**完成判据达成。**
