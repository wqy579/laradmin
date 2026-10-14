# 机器 A · 总账管理模块（Ledger）交付文档

## 一、模块定位

本模块是整套系统的「正规会计账」，补齐连凯对标中财务核算深度的最大短板：会计科目、复式记账凭证、期初余额、财务月结、科目余额表、资产负债表、利润表、日记账/明细账，并预留「业务单据自动生成凭证」的接口位。

三机物理隔离：A 用 `ledger_` 表前缀、`/admin/ledger` 接口、「总账管理」一级菜单，独立后端目录与前端路由文件，不修改其他机器负责的文件。

## 二、新建数据表清单（6 张）

| 表名 | 用途 |
|------|------|
| `ledger_subjects` | 会计科目表，自关联树形，预置《小企业会计准则》标准科目 |
| `ledger_opening_balances` | 期初余额表，subject_id+period 唯一 |
| `ledger_vouchers` | 记账凭证主表，凭证字号/借贷合计/状态/制单审核人 |
| `ledger_voucher_entries` | 凭证分录表，行号/科目/辅助核算/摘要/借贷金额 |
| `ledger_periods` | 会计期间表，open/closed，结账锁定 |
| `ledger_voucher_templates` | 凭证模板表（可选） |

所有表带主键 id、created_at、updated_at；单据主表带单据编号、状态、制单人、审核人、审核时间、备注；明细表带主表外键、科目外键、摘要、金额借贷方向。

## 三、接口清单（REST，统一前缀 /admin/ledger，JWT Bearer 鉴权）

### 会计科目
- `GET /ledger/subjects` 科目树（支持编码/名称/类别/状态筛选）
- `GET /ledger/subjects/tree` 全量科目树
- `GET /ledger/subjects/options` 末级科目下拉
- `GET /ledger/subjects/export` 导出 CSV
- `POST /ledger/subjects` 新增科目
- `GET /ledger/subjects/{id}` 科目详情
- `PUT /ledger/subjects/{id}` 编辑科目
- `DELETE /ledger/subjects/{id}` 删除科目
- `PUT /ledger/subjects/{id}/status` 停用/启用

### 期初余额
- `GET /ledger/opening-balances?period=` 期间期初清单
- `POST /ledger/opening-balances` 批量保存
- `POST /ledger/opening-balances/balance-check` 试算平衡

### 记账凭证
- `GET /ledger/vouchers` 分页筛选列表
- `GET /ledger/vouchers/{id}` 凭证详情
- `POST /ledger/vouchers` 新建凭证
- `PUT /ledger/vouchers/{id}` 编辑凭证
- `DELETE /ledger/vouchers/{id}` 删除凭证
- `POST /ledger/vouchers/{id}/audit` 审核
- `POST /ledger/vouchers/batch-audit` 批量审核
- `POST /ledger/vouchers/batch-reverse` 批量作废
- `POST /ledger/vouchers/{id}/reverse-audit` 反审核
- `POST /ledger/vouchers/{id}/reverse` 作废
- `GET /ledger/vouchers/subject-ledger` 明细账（subject_id, period）
- `GET /ledger/vouchers/templates` 模板列表
- `POST /ledger/vouchers/templates` 保存模板
- `GET /ledger/vouchers/export` 导出

### 财务月结
- `GET /ledger/periods` 期间列表
- `POST /ledger/periods/{period}/check` 结账检查
- `POST /ledger/periods/{period}/carry-forward` 结转损益生成凭证
- `POST /ledger/periods/{period}/close` 月结
- `POST /ledger/periods/{period}/reopen` 反结账

### 账簿报表
- `GET /ledger/balance` 科目余额表
- `GET /ledger/balance/export` 导出
- `GET /ledger/reports/subject-balance` 科目余额表
- `GET /ledger/reports/balance-sheet` 资产负债表
- `GET /ledger/reports/income-statement` 利润表
- `GET /ledger/reports/journal` 日记账/明细账
- `GET /ledger/reports/export` 报表导出

### 预留服务
- `VoucherGenerateService::generateFromBusiness(billType, billId, context = [])` 业务单据生成凭证占位服务（本期空实现，机器 B 以 try 方式调用，不报错）

## 四、菜单与权限

### 一级菜单
- 名称：总账管理　path：`/ledger/subject`（指向第一个子菜单，非空）　图标：`ElIconNotebook`

### 八个子菜单（顺序固定）
1. 会计科目 `/ledger/subject`
2. 期初余额 `/ledger/opening`
3. 记账凭证 `/ledger/voucher`
4. 凭证查询 `/ledger/voucher-query`
5. 凭证审核 `/ledger/voucher-audit`
6. 财务月结 `/ledger/period`
7. 科目余额表 `/ledger/balance`
8. 财务报表 `/ledger/report`（含日记账/资产负债表/利润表三标签）

### 权限节点（按钮，查询/新增/编辑/删除/审核/导出）
- `ledger.subject.{view,create,update,delete,export}`
- `ledger.opening.update`
- `ledger.voucher.{view,create,update,delete,audit,reverse,export}`
- `ledger.period.{view,close,reopen}`
- `ledger.balance.{view,export}`
- `ledger.report.{view,export}`

全部写入 `auth_permission` 表，super_admin 角色默认授权。

## 五、自测用例与结果（6 项全过）

| 用例 | 结果 | 截图 |
|------|------|------|
| 预置《小企业会计准则》科目，试算平衡 | ✅ 1001/4103 等科目存在，无发生额时试算平衡 | `02-subjects.png` |
| 借贷不平不能保存（借1000/贷500） | ✅ 抛「借贷不平衡，借方合计 1000，贷方合计 500」 | 表单态 `05-voucher-form.png` |
| 录「收到客户货款」多借多贷凭证（借银行3000+现金500，贷应收3500），审核后科目余额表/资产负债表/利润表数据正确 | ✅ 借贷合计 3500.00 平衡；审核后余额表/资产负债表/利润表见右列 | `04`→`05`→`06`→`07`→`08`→`09`→`12`→`13`→`14` |
| 结转损益并月结，月结后不能再改该月凭证 | ✅ 月结后 update 抛「已月结」 | `16-period.png`、`17-period-check.png` |
| 反结账后可改（反审核 → 编辑） | ✅ 反结账 → 反审核 → 修改 remark 成功 | `09-audit-done.png`（已审核态可反审核） |
| 金额转中文大写（0/1000/1234.56/-100） | ✅ 零元整/壹仟/伍角陆分/含「负」 | — |
| VoucherGenerateService 占位调用不报错 | ✅ 返回 null | — |

测试命令：`php vendor/bin/phpunit --testsuite=Ledger`（6 tests, 22 assertions，全过）

基线回归：`RouteBaselineTest`（快照已更新）、`MigrationSmokeTest`、`MenuIconTest` 全过，不影响现有菜单与模块。
全量回归：`php vendor/bin/phpunit` → 1933 tests / 0 failures / 1 skip（既有 `DeliveryMigrationsFeatureTest` 跳过，与 Ledger 无关）；`vendor/bin/pint --test` 通过。
`tests/Unit/ArchitectureTest.php` 的 `ALLOWED_MODULE_EDGES` 已登记 `Ledger->Auth`（凭证制单人/审核人外键）。

### 全链路浏览器实拍（sqlite 截图环境，capture run 5，EXIT=0 / DONE errors=[]）

Playwright 驱动本地构建（`127.0.0.1:8001`，`DB_CONNECTION=sqlite`）跑完「登录 → 菜单 → 建单 → 提交 → 审核 → 查询 → 导出 → 余额表 → 三张报表 → 月结检查」，产物在 `modules/Ledger/docs/screenshots/`：

| 文件 | 内容 |
|------|------|
| `01-menu-ledger-expanded.png` | 左侧菜单展开总账管理（整页，双栏布局落在会计科目） |
| `01-menu-ledger-sidebar.png` | 侧栏特写：总账管理 + 8 个子菜单（会计科目/期初余额/记账凭证/凭证查询/凭证审核/财务月结/科目余额表/财务报表） |
| `02-subjects.png` | 会计科目树（1001 库存现金…） |
| `03-opening.png` | 期初余额 |
| `04-voucher-list.png` | 记账凭证列表 |
| `05-voucher-form.png` | 新增凭证弹窗：收到客户货款，借 1002/3000 + 1001/500，贷 1122/3500，借贷合计平 |
| `06-voucher-list-submitted.png` | 提交成功后列表（记-2026-10-000N 待审核） |
| `07-audit-list.png` | 凭证审核列表（待审核） |
| `08-audit-confirm.png` | 「操作确认」二次确认弹窗 |
| `09-audit-done.png` | 审核成功 toast + 已审核态 |
| `10-voucher-query.png` | 凭证查询列表 |
| `11-export.png` / `11-voucher-export.csv` | 导出动作 + 下载的 CSV（3 行凭证，借贷合计 3500.00，含制单人/审核人/状态） |
| `12-balance.png` | 科目余额表 |
| `13-report-balance.png` | 资产负债表 |
| `14-report-income.png` | 利润表 |
| `15-report-journal.png` | 明细账/日记账（选 1002 银行存款） |
| `16-period.png` | 财务月结 |
| `17-period-check.png` | 结账检查（试算平衡）弹窗——截图后未确认结账，不污染环境 |

无 `00-login-failed.png`、无 `xx-fail-*.png`。`01-menu-ledger-expanded.png` 与 `02-subjects.png` 字节相同属预期：双栏布局展开菜单即导航到 `/ledger/subject`，菜单证据以 `01-menu-ledger-sidebar.png` 特写为准。

## 六、与现有模块的联动点

- **完全隔离**：不修改任何现有业务单据表结构，不修改其他机器的菜单/路由文件。
- **沿用鉴权**：JWT `Authorization: Bearer`、权限节点、`auth.check:admin` + `log.request` + `stock.snapshot` 内核信封。
- **公司名**：凭证详情/报表打印取公司档案/系统配置里的公司名（当前占位「公司名称」，接 System 模块 system_setting 后补充）。
- **辅助核算对象**：应收账款挂客户、应付账款挂供应商，客户/供应商下拉从 Order 模块 `business/customer`、`business/supplier` 接口取（只读）。
- **预留接口**：`VoucherGenerateService` 已建好，机器 B 在资金/往来/资产单据审核时以 try 方式调用 `generateFromBusiness(billType, billId, context)`，A 未合并前 B 的调用正常工作不报错。

## 七、遗留 TODO

1. **业务单据自动生成凭证（第四阶段，三机合并后联调）**：把 B 的存取款、借款、调账、固定资产折旧、C 的提成发放费用单，逐步接上 `VoucherGenerateService::generateFromBusiness`。本期只留空实现 + 事件监听器占位。
2. **附件上传**：凭证附件上传接现有 `sUpload` 组件（本期弹窗已留字段位，未接上传）。
3. **公司名/单位名称**：报表与凭证打印的「单位名称」当前占位「公司名称」，待 System 模块 `system_setting` 接入后补充取值。
4. **凭证金额栏逐位输入**：任务书提到「从个位起逐位向左填写的凭证金额栏样式更佳」，本期用普通两位小数数字框，后续可升级。
5. **结账检查③业务单据是否都已生成凭证**：本期只提示性检查（显示黄色警告），待第四阶段自动凭证打通后可实打实统计。
6. ~~菜单截图、建单到审核全链路截图~~ → **已完成**：sqlite 截图服务（`php -S 127.0.0.1:8001` + 自定义 router + Playwright）跑通，18 个 PNG + 1 个 CSV 已落在 `modules/Ledger/docs/screenshots/`，清单见第五节；复跑命令 `python3 /tmp/ledger-shots/capture_flow.py`（先清登录限流缓存 `storage/framework/cache/data`，避免 429）。
