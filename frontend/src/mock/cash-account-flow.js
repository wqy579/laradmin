/* ============================================================
 * 出纳资金 · 现金账户收支查询（D-29）mock 数据
 *
 * TODO(api)：后端接口按 REST 约定预留，本文件先提供 mock 供前端联调：
 *   GET /api/cash/accounts             账户列表（现金/工商银行/招商银行/农业银行）
 *   GET /api/cash/accounts/{id}/flows  账户流水（支持 start_date/end_date 与收支类型 type=income|expense 筛选）
 *
 * 单据号格式与既有 cash 域页面保持一致（shared.js 约定：前缀 + 202600001 起的 9 位序号，如 SC202600001）。
 * 余额为查询页按时间顺序滚动计算（首行 = 期初余额 + 收入 − 支出，后续行递推），
 * 故 mock 仅存期初余额与每笔收入/支出，不存逐行余额。
 * ============================================================ */

export const MOCK_CASH_ACCOUNTS = [
	{ id: 'acc-1', name: '现金' },
	{ id: 'acc-2', name: '工商银行' },
	{ id: 'acc-3', name: '招商银行' },
	{ id: 'acc-4', name: '农业银行' },
]

/* 各账户期初余额（2026-10-01 之前累计，滚动计算流水余额的起点） */
export const MOCK_ACCOUNT_OPENING = {
	'acc-1': 2600.0,
	'acc-2': 158000.0,
	'acc-3': 86000.0,
	'acc-4': 42500.0,
}

export const MOCK_ACCOUNT_FLOWS = [
	/* 现金账户 acc-1 */
	{ id: 'f-01', acc_id: 'acc-1', date: '2026-10-02', no: 'SC202600001', summary: '上交款 · 张伟（华东连锁超市）', income: 3200.00, expense: 0 },
	{ id: 'f-02', acc_id: 'acc-1', date: '2026-10-03', no: 'FK202600001', summary: '门店零用金领用 · 朝阳一号店', income: 0, expense: 500.00 },
	{ id: 'f-03', acc_id: 'acc-1', date: '2026-10-05', no: 'SC202600005', summary: '上交款 · 李娜（好又多卖场）', income: 4580.50, expense: 0 },
	{ id: 'f-04', acc_id: 'acc-1', date: '2026-10-08', no: 'FK202600002', summary: '快递运费 · 顺捷物流', income: 0, expense: 260.00 },
	{ id: 'f-05', acc_id: 'acc-1', date: '2026-10-10', no: 'SC202600009', summary: '上交款 · 王强（金鼎便利店）', income: 1890.00, expense: 0 },
	{ id: 'f-06', acc_id: 'acc-1', date: '2026-10-13', no: 'FK202600003', summary: '办公用品 · 百斯特文具', income: 0, expense: 428.60 },
	{ id: 'f-07', acc_id: 'acc-1', date: '2026-10-16', no: 'SC202600015', summary: '上交款 · 刘敏（天天平价）', income: 5230.00, expense: 0 },
	{ id: 'f-08', acc_id: 'acc-1', date: '2026-10-18', no: 'FK202600004', summary: '门店补货周转金 · 海淀三号店', income: 0, expense: 1200.00 },

	/* 工商银行 acc-2 */
	{ id: 'f-09', acc_id: 'acc-2', date: '2026-10-01', no: 'SR202600010', summary: '客户货款 · 华东连锁超市', income: 45000.00, expense: 0 },
	{ id: 'f-10', acc_id: 'acc-2', date: '2026-10-04', no: 'ZC202600010', summary: '供应商货款 · 沈阳冷链物流', income: 0, expense: 28600.00 },
	{ id: 'f-11', acc_id: 'acc-2', date: '2026-10-07', no: 'SR202600011', summary: '客户货款 · 恒丰超市连锁', income: 36800.00, expense: 0 },
	{ id: 'f-12', acc_id: 'acc-2', date: '2026-10-09', no: 'ZC202600011', summary: '仓库租金 · 10 月', income: 0, expense: 15000.00 },
	{ id: 'f-13', acc_id: 'acc-2', date: '2026-10-12', no: 'SR202600012', summary: '客户货款 · 鑫源百货商行', income: 21400.00, expense: 0 },
	{ id: 'f-14', acc_id: 'acc-2', date: '2026-10-15', no: 'ZC202600012', summary: '供应商货款 · 京津乳品配送', income: 0, expense: 31200.00 },
	{ id: 'f-15', acc_id: 'acc-2', date: '2026-10-17', no: 'SR202600013', summary: '客户货款 · 佳美便利店', income: 6800.00, expense: 0 },

	/* 招商银行 acc-3 */
	{ id: 'f-16', acc_id: 'acc-3', date: '2026-10-03', no: 'SR202600014', summary: '客户货款 · 百姓大药房', income: 18600.00, expense: 0 },
	{ id: 'f-17', acc_id: 'acc-3', date: '2026-10-06', no: 'ZC202600013', summary: '员工工资 · 9 月', income: 0, expense: 42500.00 },
	{ id: 'f-18', acc_id: 'acc-3', date: '2026-10-11', no: 'SR202600015', summary: '客户货款 · 宏图商行', income: 27600.00, expense: 0 },
	{ id: 'f-19', acc_id: 'acc-3', date: '2026-10-14', no: 'ZC202600014', summary: '水电费 · 仓库 10 月', income: 0, expense: 3200.00 },
	{ id: 'f-20', acc_id: 'acc-3', date: '2026-10-19', no: 'SR202600016', summary: '客户货款 · 丰乐食品', income: 12800.00, expense: 0 },
]
