/**
 * 财务月结 5 步向导 · mock 数据
 *
 * TODO(api)：后端接口就绪后，用 REST 约定替换：
 *   GET  /finance/month-close/periods              会计期间账套列表
 *   GET  /finance/month-close/checks?period=       结账前检查（Step2）
 *   GET  /finance/month-close/balance-sheet        资产负债表（Step3）
 *   GET  /finance/month-close/income-statement     利润表（Step4）
 *   POST /finance/month-close/execute              执行月结（Step5）
 * 当前全部为本地 mock，页面可独立自测（不连管理端，避免打断录单）。
 */

// ---- 期间选项 ----
export const YEARS = [2026, 2025, 2024]
export const MONTHS = Array.from({ length: 12 }, (_, i) => i + 1)

// ---- 期间账套（key: YYYY-MM）----
// status: open=未结账 / closed=已结账
export const PERIODS = {
	'2026-08': {
		period: '2026-08',
		year: 2026,
		month: 8,
		status: 'closed',
		status_label: '已结账',
		voucher_count: 126,
		total_debit: 8642000.0,
		total_credit: 8642000.0,
		closed_by_name: '王会计',
		closed_at: '2026-09-02 10:24:31',
	},
	'2026-09': {
		period: '2026-09',
		year: 2026,
		month: 9,
		status: 'closed',
		status_label: '已结账',
		voucher_count: 143,
		total_debit: 9183500.5,
		total_credit: 9183500.5,
		closed_by_name: '王会计',
		closed_at: '2026-10-08 09:51:07',
	},
	'2026-10': {
		period: '2026-10',
		year: 2026,
		month: 10,
		status: 'open',
		status_label: '未结账',
		voucher_count: 98,
		total_debit: 6231400.5,
		total_credit: 6231400.5,
		closed_by_name: '',
		closed_at: '',
	},
}

export function periodKey(year, month) {
	return `${year}-${String(month).padStart(2, '0')}`
}

// ---- Step2：结账前检查（关键拦截）----
// ① 未生成凭证的业务单据（阻断）  ② 未确认上交款（仅警告）  ③ 借贷不平（阻断）
// flags: { audited, docsHandled, remitHandled } —— 由向导内操作驱动
export function getChecks(period, flags = {}) {
	const base = {
		'2026-10': { unbilled_docs: 3, unconfirmed_remit: 4, unbalanced: 2, draft_vouchers: 12 },
		'2026-09': { unbilled_docs: 0, unconfirmed_remit: 0, unbalanced: 0, draft_vouchers: 0 },
		'2026-08': { unbilled_docs: 0, unconfirmed_remit: 0, unbalanced: 0, draft_vouchers: 0 },
	}[period] || { unbilled_docs: 0, unconfirmed_remit: 0, unbalanced: 0, draft_vouchers: 0 }

	// 用户点「全部审核」后，草稿凭证被审核 → ③ 归零
	if (flags.audited) base.unbalanced = 0
	// 用户点「去处理」后，业务单据已生成凭证 → ① 归零
	if (flags.docsHandled) base.unbilled_docs = 0
	// 用户确认上交款后 → ② 归零
	if (flags.remitHandled) base.unconfirmed_remit = 0
	return base
}

// ---- Step3：资产负债表 ----
// type: group=大类标题 / row=明细 / total=小计·合计
export function getBalanceSheet(period) {
	const rows = [
		{ type: 'group', name: '流动资产' },
		{ type: 'row', name: '货币资金', line: 1, year: 1820000.0, period: 2315000.0 },
		{ type: 'row', name: '应收账款', line: 2, year: 960000.0, period: 1248000.0 },
		{ type: 'row', name: '预付账款', line: 3, year: 150000.0, period: 98000.0 },
		{ type: 'row', name: '存货', line: 4, year: 1540000.0, period: 1705500.0 },
		{ type: 'row', name: '其他应收款', line: 5, year: 80000.0, period: 66000.0 },
		{ type: 'total', name: '流动资产合计', line: '小计', year: 4550000.0, period: 5432500.0 },
		{ type: 'group', name: '非流动资产' },
		{ type: 'row', name: '固定资产', line: 6, year: 3200000.0, period: 3200000.0 },
		{ type: 'row', name: '减：累计折旧', line: 7, year: 980000.0, period: 1120000.0 },
		{ type: 'row', name: '固定资产净额', line: 8, year: 2220000.0, period: 2080000.0 },
		{ type: 'row', name: '在建工程', line: 9, year: 120000.0, period: 180000.0 },
		{ type: 'row', name: '无形资产', line: 10, year: 360000.0, period: 330000.0 },
		{ type: 'total', name: '非流动资产合计', line: '小计', year: 2700000.0, period: 2590000.0 },
		{ type: 'total', name: '资产总计', line: '合计', year: 7250000.0, period: 8022500.0 },
		{ type: 'group', name: '流动负债' },
		{ type: 'row', name: '短期借款', line: 1, year: 800000.0, period: 800000.0 },
		{ type: 'row', name: '应付账款', line: 2, year: 1240000.0, period: 1462000.0 },
		{ type: 'row', name: '应付职工薪酬', line: 3, year: 320000.0, period: 386000.0 },
		{ type: 'row', name: '应交税费', line: 4, year: 210000.0, period: 268000.0 },
		{ type: 'row', name: '其他应付款', line: 5, year: 145000.0, period: 173000.0 },
		{ type: 'total', name: '流动负债合计', line: '小计', year: 2715000.0, period: 3089000.0 },
		{ type: 'group', name: '非流动负债' },
		{ type: 'row', name: '长期借款', line: 6, year: 600000.0, period: 600000.0 },
		{ type: 'total', name: '非流动负债合计', line: '小计', year: 600000.0, period: 600000.0 },
		{ type: 'total', name: '负债合计', line: '合计', year: 3315000.0, period: 3689000.0 },
		{ type: 'group', name: '所有者权益' },
		{ type: 'row', name: '实收资本', line: 1, year: 3000000.0, period: 3000000.0 },
		{ type: 'row', name: '资本公积', line: 2, year: 500000.0, period: 500000.0 },
		{ type: 'row', name: '盈余公积', line: 3, year: 300000.0, period: 300000.0 },
		{ type: 'row', name: '未分配利润', line: 4, year: 135000.0, period: 533500.0 },
		{ type: 'total', name: '所有者权益合计', line: '小计', year: 3935000.0, period: 4333500.0 },
		{ type: 'total', name: '负债和所有者权益总计', line: '合计', year: 7250000.0, period: 8022500.0 },
	]
	return { period, report_date: `${period}-31`, rows, companyName: '连凯商贸有限公司' }
}

// ---- Step4：利润表 ----
// bold=合计行 / is_total=大类合计 / code 用于特殊着色
export function getIncomeStatement(period) {
	const rows = [
		{ code: 'revenue', name: '一、营业收入', month: 3285000.0, year: 28410000.0, bold: true },
		{ code: 'cost', name: '减：营业成本', month: 2412000.0, year: 21028000.0 },
		{ code: 'tax', name: '税金及附加', month: 38000.0, year: 326000.0 },
		{ code: 'sell', name: '销售费用', month: 265000.0, year: 2180000.0 },
		{ code: 'admin', name: '管理费用', month: 182000.0, year: 1524000.0 },
		{ code: 'rd', name: '研发费用', month: 0.0, year: 0.0 },
		{ code: 'fin', name: '财务费用', month: 42000.0, year: 356000.0 },
		{ code: 'op_profit', name: '二、营业利润', month: 346000.0, year: 3096000.0, bold: true, is_total: true },
		{ code: 'non_op_in', name: '加：营业外收入', month: 12000.0, year: 96000.0 },
		{ code: 'non_op_out', name: '减：营业外支出', month: 8000.0, year: 72000.0 },
		{ code: 'total_profit', name: '三、利润总额', month: 350000.0, year: 3120000.0, bold: true, is_total: true },
		{ code: 'tax_exp', name: '减：所得税费用', month: 87500.0, year: 780000.0 },
		{ code: 'net_profit', name: '四、净利润', month: 262500.0, year: 2340000.0, bold: true, is_total: true },
		{ code: 'parent_net', name: '归属母公司所有者净利润', month: 262500.0, year: 2340000.0 },
	]
	return { period, report_date: `${period}-31`, companyName: '连凯商贸有限公司', rows }
}

// ---- Step5：月结报告（结账快照）----
export function getCloseReport(period, balance, income) {
	const assetTotal = (balance.rows.find((r) => r.name === '资产总计') || {}).period
	const liabilityTotal = (balance.rows.find((r) => r.name === '负债和所有者权益总计') || {}).period
	const netProfit = (income.rows.find((r) => r.code === 'net_profit') || {}).month
	return {
		period,
		balance_ok: assetTotal === liabilityTotal,
		diff: assetTotal - liabilityTotal,
		net_profit: netProfit,
	}
}
