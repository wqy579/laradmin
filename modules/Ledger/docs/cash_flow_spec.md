# 财务资金流水写入与经营历程衔接规范 (T1.5)

## 1. 核心原则
所有涉及现金、银行、第三方支付的资金变动，必须通过 `Modules\Ledger\Services\CashFlowService` 统一落库 `cash_flows` 表。

## 2. 资金流水 (`cash_flows`) 写入规范
### 2.1 字段约定
- **flow_no**: 由服务统一生成 `CF + YmdHis + 4位随机大写字母`。
- **flow_type**: 必须在 `receive` (收入), `pay` (支出), `expense` (费用), `red_flush` (红冲) 之中。
- **amount**: **严格执行 [收入为正，支出为负]**。服务内部会自动根据 `flow_type` 强制转换正负号，调用方传入绝对值即可。
- **related_id / related_type**: 必须关联至触发资金变动的业务单据模型类名及 ID。
- **payment_method**: 建议统一为：`现金`, `微信`, `支付宝`, `银行卡`, `其他`。

### 2.2 幂等性保证
- 服务通过 `(related_id, related_type, flow_type)` 唯一性进行幂等校验。
- 若同一单据已存在相同类型的流水，则不再重复插入，返回 `EXISTING`。

## 3. 与经营历程 (`business/history`) 的衔接
### 3.1 关系定义
- **Business History** 是一个【全量业务审计日志】，通过 `UNION ALL` 动态聚合多张业务表（如 `sales_orders`, `deliveries`, `expenses` 等）。
- **Cash Flows** 是一个【财务资金快照表】，仅记录产生资金流转的特定事件。

### 3.2 写入逻辑
- **无需重复写入**：由于 `BusinessHistoryController` 是通过 `UNION ALL` 直接从业务表取数，因此只要业务单据（如 `expenses` 表）本身保存成功，经营历程会自动显示该记录。
- ** Ledger 侧职责**：`CashFlowService` 仅负责维护 `cash_flows` 表，用于日记账、现金余额计算等财务核算场景。

## 4. 日记账取数逻辑
- **排序**: `flow_date` ASC $\to$ `id` ASC。
- **余额**: 从筛选区起始点开始，对 `amount` 逐行累加计算滚动余额。
- **符号**: 接口返回原始 `amount`（正/负），前端通过符号决定涨红跌绿。
