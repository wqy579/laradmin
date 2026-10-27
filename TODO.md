# 任务追踪（多会话防抢任务）

> 每个 Claude Code 会话新开会话时，第一件事就是读这个文件，按分配的任务做，不要自己猜。
> 核对日期：2026-10-22

---

## 全局分工（编排层 2026-10-22 定，六机已压缩为两台）

- **机 f = 财务域**：分支 feat/finance-batch*。财务总账（大块2）、出纳资金资产（大块3）、科目 Seeder/取数服务（T1.3/T1.5）归 f。
- **机 c = 销售/库存/报表域**：三个开发会话见下方「会话 C1/C2/C3」。经营分析报表（大块4）、订货闭环（大块5）、库存/价格/外围（大块6）归机 c。
- **共用前置（大块1）**：T1.1 tokens.css 已完成；T1.2 通用组件 + T1.4 权限菜单骨架由机 c 会话 C1（dev-base）负责，产出后各域复用，其他会话不要自行封装重复组件。
- **跨域依赖**：报表域要账数据（大块2）时，在 TODO.md 记录需求等 f，不要自己造账表。

---

## 当前任务分配

### 会话 A
- 分支：feat/van-sales-module
- 任务：车销模块开发（退货/换货/出货/换货 + 借贷余额台账）
- 状态：🔄 进行中
- 已完成：车销模块地基——车辆伪装成仓库 + 模块骨架

### 会话 B
- 分支：fix/purchase-application-test-bugs
- 任务：采购申请模块测试问题修复
- 状态：✅ 已完成（PR #14）
- 下一步：待分配

### 会话 C
- 分支：feat/van-sales-completion
- 任务：VanSales 测试套件 + 全新安装菜单缺陷修复 + CI autoload 红灯
- 状态：🔄 进行中
- 已完成：
  - 修复 CI 红灯——composer.json autoload-dev 补齐 Miniapp/Office/Report/VanSales 映射（commit b4ef8f4）
  - 新增 VanSales 测试套件（50 测试/333 断言）：迁移契约 + 全新安装菜单完整性 + 车上退仓流程 + 上交货款流程
  - 修复 BusinessSeeder 漏写全部 van 菜单（全新安装后车销页面不可达）
  - 修复 register_van_menu 图标 'Van' → 'ElIconVan'（前端渲染空图标）
  - 车上退仓（VRW）：vehicle→warehouse 反向调拨，迁移/模型/控制器/路由/菜单/前端占位 + 流程测试
  - 车销上交货款（VRM，纯台账型）：van_remit 表，pending→confirmed/rejected，confirm 写 cash_flow(related_type=VanRemit)，pendingSummary 实时计算
  - 扩展迁移契约测试常量纳入 VRW/VRM 两表
  - 更新路由基线快照（routes.json）
- 下一步：考虑要货单 approved 冻结泄漏（P0）、decreaseBorrowBalance 负数下限（P3）

### 会话 C1 · dev-base（机 c · tmux window dev-base）
- 分支：feat/base-components
- 任务：大块1 剩余——T1.2 通用组件封装 + T1.4 权限与菜单骨架
- 状态：🔄 待启动（2026-10-22 编排层分配）
- 交付：FinTable(合计行/紧凑行高/金额等宽右对齐)、资金流水金额列(收入红/支出绿)、StatusTag(五态)、占比进度条、空/加载态组件；fin/cash/asset/rpt/ps/price/st/hr/agr/map/mall/sms/mail/base 权限点 + 「总账/出纳/固定资产/订货中心」菜单分组
- 验收：既有页面无色偏；变量/组件可被大块2~6 统一调用

### 会话 C2 · dev-order（机 c · tmux window dev-order）
- 分支：feat/order-closure
- 任务：大块5 订货闭环域——T5.1 客户订货核对 / T5.2 订货调整 / T5.3 订单移库 / T5.4 业务员拜访提醒
- 状态：🔄 待启动（2026-10-22 编排层分配）
- 验收：差异率>±30% 红；全程留痕 history；移库量≤订货量；提醒发后状态变已提醒

### 会话 C3 · dev-report（机 c · tmux window dev-report）
- 分支：feat/report-analysis
- 任务：大块4 经营分析报表域（13 项）。先做不依赖账的：T4.1 绩效9页挂载 / T4.11 访店达成率 / T4.12 库存决策分析 / T4.13 库存可销天数；依赖账数据的大块2 项（T4.2~T4.10 等）先搭取数结构，等 f 财务就绪后填数
- 状态：🔄 待启动（2026-10-22 编排层分配）
- 验收：菜单可见 9 项且有真实数据；抽查 3 页数字对；可双击穿透

### 会话 F
- 分支：feat/finance-batch7
- 任务：财务域第七批 —— D-04 凭证多条件查询后端修复 + D-05 defineExpose bug 修复
- 状态：✅ 已完成（2026-10-22，修复提交已推送：24a90e8 D-04 + c6eb95b D-05，待编排层合并）
- 已完成：
  - 第一批（feat/finance-batch1，已合并）：D-08 余额查询品牌余额 Tab2（commit 8d00914）+ D-18 应收应付调整单（commit 0a3913d）
  - 第二批（feat/finance-batch2，已合并）：D-20 客户余额调整单 + D-21 供应商余额调整单
  - 第三批（feat/finance-batch3）：D-22+D-23 资金增减单（路由/mock/单头单据页面实现）
  - 第四批（feat/finance-batch4）：D-19 借款/还款单 + 逾期提醒实现
  - 第五批（feat/finance-batch5）：D-24 欠条确认/驳回 + 签字图（列表/新增含签字图上传/查看/确认驳回交互，纯前端 mock）
  - 第七批（feat/finance-batch7，已推送待合并）：D-04 凭证多条件查询后端修复（24a90e8）+ D-05 科目树 defineExpose 缺陷修复 + 科目导出筛选（c6eb95b）

### 会话 G · fin-b8（机 f · tmux worker）
- 分支：feat/finance-batch8
- 任务：T1.5 财务公共取数服务 —— history 经营历程写入规范 + cash_flow 统一落库服务 + 日记账取数
- 状态：✅ 已完成（2026-10-22 21:4x 完工，已推送待合并；CashFlowService 幂等落库+flow_no 碰撞重试+日记账滚动余额，4 测试全过；commit cd2f2f1 + e5de462）
- 注意：cash_flows 表在机 c 的 Order 模块（共享基础设施），只读写不改 Order 模块

### 会话 H · fin-b9（机 f · tmux worker）
- 分支：feat/finance-batch9
- 任务：T1.3 总账科目 Seeder —— fin_subject 预置科目补全 + 品牌辅助核算标记
- 状态：✅ 已完成（2026-10-22 22:0x 完工，已推送待合并；Seeder 幂等 updateOrCreate + 主营收入/成本品牌辅助标记 + VoucherService 品牌禁存校验，11 测试全过；commit d468548）
- 注意：T1.2 通用组件封装归机 c 会话 C1，本会话不碰通用组件（避免重复封装）；应收票据 1121/应付票据 2201 未入预置表，留作后续小补丁

---

## 已完成

| 任务 | 优先级 | 说明 | Commit |
|------|--------|------|--------|
| 一般费用单 | P1 | 挂客户/供应商，跟现金费用单类似 | feat(finance): 一般费用单——三区布局+往来单位+多行明细(费用科目+金额+摘要)+自动合计+启用菜单 |
| 其它收入单 | P1 | 跟费用单对称，收入科目 | feat(finance): 其它收入单——三区布局(基本信息+多行明细+合计);收入科目下拉;往来单位;启用菜单 |
| 月度利润 | P2 |  |  |
| 资产利润表 | P2 | 利润表 + 资产负债表 | feat(finance): 资产负债表完善 + 红冲财务联动 |

| 已实现但未入表（证据: git log） | 优先级 | 说明 | Commit |

| 辅助单位余额查询 Tab3 | P2 | 填充 Tab3 辅助单位维度余额查询 (feat/finance-batch6) | |
| 现金账户收支查询 | P2 | 实现账户流水查询、滚动余额计算 (feat/finance-batch6) | |
|------|--------|------|--------|
| 财务凭证页 | P0 | 财务凭证页 | feat(voucher): P0-1 财务凭证页 |
| 业务员订单汇总表 | P1 | 业务员订单汇总表 - 3视图报表 | feat(report): P1-1 业务员订单汇总表 - 3视图报表 |
| 月结查询/反月结页面 | P0 | 月结向导框架与步骤1-5+报表 | feat(finance): P0-3 月结查询/反月结页面 |
| 刷卡收款记录查询页 | P0 | 刷卡收款记录查询页 | feat(cash): 刷卡收款记录查询页 |
| 上交款单与查询 | P0 | 上交款单与查询 | feat(cash): 上交款单与查询 |
| 资产台账卡片与列表双视图 | P2 | 资产台账卡片与列表双视图 | feat(asset): 资产台账卡片与列表双视图 |
| 积分查询与流水 | P2 | 积分查询与流水 | feat(points): 积分查询与流水 |
| 发放与核销单据 | P2 | 发放与核销单据 | feat(points): 发放与核销单据 |
| 积分规则 | P2 | 积分规则 | feat(points): 积分规则 |
| 凭证分录增加品牌辅助核算列 | P2 | 凭证分录增加品牌辅助核算列 (#27) | feat(voucher): 凭证分录增加品牌辅助核算列 (#27) |
| 新增退货汇总单 | P2 | 新增退货汇总单（退货类型×渠道×仓库×日期） | feat(report): 新增退货汇总单 |
| 业务员销售分析 | P2 | 业务员销售分析支持双击查看折线走势 | feat(sales): 业务员销售分析支持双击查看折线走势 |
| 销售分析看板补占比列 | P2 | 销售分析看板补占比列 | feat(sales): 销售分析看板补占比列 |
| 总账管理模块（机器 A） | P0 | 总账管理模块（机器 A） | feat(ledger): 总账管理模块（机器 A）——科目/凭证/月结/账簿报表 |
| 绩效提成与经营分析模块（机器 C） | P2 | 绩效提成与经营分析模块（机器 C） | feat(perf): 绩效提成与经营分析模块（机器 C） |
| 单据打印预览 | P2 | 单据打印预览——订单/配送单/收款单三种打印模板 | feat(print): 单据打印预览——订单/配送单/收款单三种打印模板 |
| 登录弹窗公告 | P2 | 登录弹窗公告——is_popup 标记 + popup-pending 接口 + 弹窗组件 | feat(notice): 登录弹窗公告——is_popup 标记 + popup-pending 接口 + 弹窗组件 |
| 品牌档案管理对齐规格 | P2 | 品牌档案管理对齐规格——查询区/操作区/导出/编码校验 | feat(brand): 品牌档案管理对齐规格——查询区/操作区/导出/编码校验 |
| 品牌余额查询 Tab2 | P1 | 余额查询补【品牌余额查询】Tab2 (feat/finance-batch1) | 8d00914 |
| 应收应付调整单 | P1 | 完成 D-18 页面实现 (feat/finance-batch1) | 0a3913d |
| 客户余额调整单 | P1 | 完成 D-20 页面实现 (feat/finance-batch2) | |
| 供应商余额调整单 | P1 | 完成 D-21 页面实现 (feat/finance-batch2) | |
| 资金增减单（两个方向） | P1 | 完成 D-22+D-23 页面实现——单头单据，增加/减少两个方向，无审核流保存即生效 (feat/finance-batch3) | 517b014 |
| 借款/还款单 + 逾期提醒 | P1 | 完成 D-19 页面实现——借款/还款两类单据，逾期提醒实时计算 (feat/finance-batch4) | |
| 欠条确认/驳回 + 签字图 | P1 | 完成 D-24 页面实现——欠条列表/新增含签字图上传/确认驳回二次确认交互 (feat/finance-batch5) | |
| 财务公共取数服务（T1.5） | P1 | CashFlowService：收款/付款/红冲幂等落库 + flow_no 唯一碰撞重试 + 日记账滚动余额取数 (feat/finance-batch8) | cd2f2f1 + e5de462 |
| 总账科目 Seeder 补全与品牌辅助核算（T1.3） | P1 | LedgerSubjectSeeder 按 code updateOrCreate 幂等 + 6001/5401 品牌辅助标记 + VoucherService 品牌禁存校验 (feat/finance-batch9) | d468548 |

---

## 待办任务池

---

## 规则

1. 不要抢任务：只做分配给你的任务
2. 做完更新：完成后把状态改成 ✅
3. 新任务从池里领：从待办任务池里选下一个
4. 分支隔离：每个任务用独立分支，不要在 main 上直接改
---

## 基建与环境（编排层 2026-10-22 14:30 记录）

1. **worktree 隔离**：三会话曾共享 `/workspace/laradmin` 导致分支互相 checkout 踩踏，已改为 git worktree：`/workspace/wt-base`(feat/base-components)、`/workspace/wt-order`(feat/order-closure)、`/workspace/wt-report`(feat/report-analysis)。各自独立工作区+独立数据库。
2. **本机环境从零搭好**（machine c 原是纯 git 副本）：MariaDB 10.11(127.0.0.1:3306, root 空密码) + Redis(6379) + 三库 `laradmin_base/order/report`（各 135 表全量迁移）；各 worktree 已装 vendor/node_modules/.env/JWT_SECRET。
3. **修复仓库级 bug**（commit 097d697，已推 main）：`modules/Order/.../2026_10_15_000001_widen_order_type_on_sales_orders.php` 用 MySQL 专有 `SHOW COLUMNS`，sqlite 测试连接下炸掉全部 RefreshDatabase 测试（357 failed）；已加驱动守卫，三 worktree 基线均 **370 passed / 1 skipped / 0 failed**。
4. 测试命令注意：项目无 pest 二进制，用 `php artisan test`（phpunit + pest-plugin）；并行需 paratest 未装，顺序跑约 27s。
5. laradmin-explorer 子代理模型已从 machine-c 改为 amd-best（400 unknown provider 修复）。

---

## 网关架构升级（编排层 2026-10-23 00:40 记录）

1. **来源**：远程网关机器（3100-ba91f7e5f6195d03.monkeycode-ai.online）的 CLIProxyAPI 配置 `config.68.yaml`——AMD Radeon 上游（developer.amd.com.cn/radeon）8 key + 7 模型 + round-robin。
2. **本机落地**（/root/cpa/config.yaml 重建，原文件 21:34 后丢失且那版误删 management.secret-key 导致管理 API 全禁、watchdog 空转）：
   - AMD 上游**只用 2 个 key**（远程 8 个取前 2），round-robin 轮询 + retry-on [403,408,429,500,502,503,504] + 429/5xx 冷却换 key。
   - 模型 12 个：9 个 AMD（4 个 watchdog 动态档 machine-c/claude-opus-4-1/claude-sonnet-4-5/claude-3-5-haiku + 5 个 amd-* 固定档）+ sensenova-6.8-flash-lite（备用上游，6 key）。
   - 恢复 management.secret-key（bcrypt: cpa-mgmt-c-5f3a9e2b7c1d），管理 API 重新可用。
3. **智能选模**（model-watchdog.py，每 15 分钟）：直连 AMD 上游探测全部候选模型，跑 7 个判例 + 乱码检测 + TTFT/总时分级 A/B/C；A 级最快者 → 主档别名（machine-c/opus/sonnet），TTFT 最小非主档 → 小模型档（haiku）；经管理 API 热更新别名，**不重启网关**；迟滞 30% + 最短保持 30 分钟防抖。watchdog.json：blocklist 清空（让数据说话，默认把 DeepSeek-V4.1-Flash 标污染是旧结论，bench 显示其输出最优）、primary_prefer=[DeepSeek-V4, Qwen3.8-27B]（复杂任务质量优先）。
4. **Claude 侧档位**（settings.json / launch-claude.sh / guard.sh / laradmin-explorer.md）：主档全部指向 machine-c（watchdog 动态最优），HAIKU/SMALL_FAST 指向 claude-3-5-haiku-20241022。
5. 已知限制：AMD 免费额度有账号级并发上限（process_concurrency_rate_limit_exceeded），多会话并发时 429——CPA 自动换 key 重试兜底；watchdog 探测期间也会挤占额度（它串行探测，影响有限）。

## machine-c 掉线与恢复（2026-10-25 10:00–10:30）

- **现象**：用户 SSH root@machine-c (100.73.149.95) 失败——`kex_exchange_identification: Connection closed by remote host`；tailscale 视图 rx=0。
- **根因**：平台容器进程重启后 **tailscaled 未自启**（容器无 systemd、无 cron、无任何自启机制）。磁盘未丢：`/var/lib/tailscale/tailscaled.state` 保留，设备授权有效。
- **误判澄清**：从网关机（4809a52c）实测，machine-a/b/d/e/f 同样"22 端口 TCP 通但 SSH 秒关 + 网页终端 521"——但 machine-c 是唯一能从平台 agent 通道内部修复的（本会话就在 machine-c 容器内）。
- **修复动作**：
  1. `tailscaled --tun=userspace-networking --socket=/tmp/tailscaled.sock --state=/var/lib/tailscale/tailscaled.state` 上线（无需重新授权）；
  2. `tailscale up --ssh --hostname=machine-c --accept-dns=false`（RunSSH=true）；
  3. 新增 `/root/cpa/ts-keeper.sh` 保活守护（60s 轮询探活 tailscaled + BackendState，flock 单实例，日志 `/root/cpa/ts-keeper.log`），钩子挂 `.bashrc/.zshrc`（新 shell 唤醒，与 keeper.sh 同模式）。
- **验证**：tailscaled BackendState=Running、Online=true、`tailscale ping` 到网关通（DERP 中继 235ms）；从网关机反向 SSH 仍 kex 失败——判定为网关侧平台定制版 tailscaled 的限制，**用户客户端重试为准**。
- **遗留**：① 用户侧 SOCKSv5 代理失败需本地排查（建议直连 100.73.149.95，不走代理；若 SOCKS 出口是某台掉线机器则需等其恢复）；② machine-a/b/d/e/f 五台仍需平台控制台重启（无远程通道，无法内部修复）；③ ping 不通是 userspace 无 TUN 模式的正常现象，不影响 SSH。
