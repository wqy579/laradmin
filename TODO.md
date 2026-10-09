# 任务追踪（多会话防抢任务）

> 每个 Claude Code 会话新开会话时，第一件事就是读这个文件，按分配的任务做，不要自己猜。

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

---

## 待办任务池

| 任务 | 优先级 | 说明 |
|------|--------|------|
| 一般费用单 | P1 | 挂客户/供应商，跟现金费用单类似 |
| 其它收入单 | P1 | 跟费用单对称，收入科目 |
| 月度利润 | P2 |  |
| 资产利润表 | P2 | 利润表 + 资产负债表 |

---

## 规则

1. 不要抢任务：只做分配给你的任务
2. 做完更新：完成后把状态改成 ✅
3. 新任务从池里领：从待办任务池里选下一个
4. 分支隔离：每个任务用独立分支，不要在 main 上直接改
