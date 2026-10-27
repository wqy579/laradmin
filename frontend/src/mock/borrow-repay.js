/* ============================================================
 * D-19 借款/还款单 + 逾期提醒 · 本地 mock 数据
 *
 * 约束：本机禁止登录 laradmin 管理端做验证（会打断订单录入自动化），
 *       故页面一律使用本文件 mock，不调用真实接口。
 *
 * 说明：
 *   - 借款单：保存后状态「未结清」，可多次登记还款（已还 ≥ 金额 → 已结清，否则部分偿还）。
 *   - 还款单：简化处理，保存即「已结清」（直接冲销）。
 *   - 逾期：预计还款日 < 今天 且 未清金额 > 0，由页面实时计算并提示。
 *
 * TODO(api)：后端接口按 REST 约定预留：
 *   GET  /api/finance/borrow-repay-orders            借款/还款单列表（筛选：单据类型/往来单位/日期范围 + 分页）
 *   POST /api/finance/borrow-repay-orders            新增借款/还款单
 *   GET  /api/finance/borrow-repay-orders/{id}       单据详情（含还款记录列表）
 *   POST /api/finance/borrow-repay-orders/{id}/repay 登记还款（更新已还金额与状态）
 * ============================================================ */

/* 单据类型：借款 / 还款 */
export const ORDER_TYPES = [
	{ value: 'borrow', label: '借款' },
	{ value: 'repay', label: '还款' },
]

export const ORDER_TYPE_LABEL = Object.fromEntries(ORDER_TYPES.map((t) => [t.value, t.label]))

/* 往来单位（客户/供应商/员工） */
export const PARTNERS = [
	{ value: 'p-1', label: '华东连锁超市' },
	{ value: 'p-2', label: '好又多卖场' },
	{ value: 'p-3', label: '天天平价' },
	{ value: 'p-4', label: '金鼎便利店' },
	{ value: 'p-5', label: '百姓大药房' },
	{ value: 'emp-1', label: '张伟（员工借款）' },
	{ value: 'emp-2', label: '李娜（员工借款）' },
]

export const PARTNER_LABEL = Object.fromEntries(PARTNERS.map((p) => [p.value, p.label]))

/* 状态字典（逾期为运行时计算态，不入库） */
export const ORDER_STATUS = {
	unsettled: { label: '未结清', tag: 'info' },
	partial: { label: '部分偿还', tag: 'warning' },
	settled: { label: '已结清', tag: 'success' },
	overdue: { label: '已逾期', tag: 'danger' },
}

export function fmtMoney(v) {
	const n = Number(v || 0)
	const neg = n < 0
	const s = Math.abs(n).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',')
	return (neg ? '-' : '') + s
}

function pad(n, w = 2) {
	return String(n).padStart(w, '0')
}

export function today() {
	const d = new Date()
	return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}

/* 相对今天偏移 n 天（负数 = 未来） */
function offsetDays(n) {
	const d = new Date()
	d.setDate(d.getDate() + n)
	return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}

/* 单据号：BR + 年月日 + 3 位流水 */
const ORDER_NO_SEQ = [1, 2, 3, 4, 5, 6, 7, 8]
function buildOrderNo(dateStr, seq) {
	const ymd = dateStr.replace(/-/g, '')
	return `BR${ymd}${pad(seq, 3)}`
}

/* 由已还金额推导状态（借款单） */
function borrowStatus(amount, paid) {
	if (paid >= amount) return 'settled'
	if (paid > 0) return 'partial'
	return 'unsettled'
}

/**
 * 生成借款/还款单列表（含 ≥2 笔逾期借款单）。
 * 借款单下挂 0-N 条还款记录 repays。
 */
export function mockBorrowRepayOrders() {
	const rows = [
		{
			id: 'br-1',
			type: 'borrow',
			partner: 'p-1',
			amount: 50000,
			date: offsetDays(-45),
			due_date: offsetDays(-15),
			paid: 20000,
			abstract: '旺季备货周转借款',
			creator: '陈静',
			created_at: offsetDays(-45),
			repays: [
				{ date: offsetDays(-15), amount: 20000, abstract: '首批回款冲抵' },
			],
		},
		{
			id: 'br-2',
			type: 'borrow',
			partner: 'p-2',
			amount: 30000,
			date: offsetDays(-30),
			due_date: offsetDays(-7),
			paid: 0,
			abstract: '新店开业垫款',
			creator: '李娜',
			created_at: offsetDays(-30),
			repays: [],
		},
		{
			id: 'br-3',
			type: 'borrow',
			partner: 'emp-1',
			amount: 8000,
			date: offsetDays(-10),
			due_date: offsetDays(-5),
			paid: 8000,
			abstract: '员工差旅借款（已归还）',
			creator: '张伟',
			created_at: offsetDays(-10),
			repays: [
				{ date: offsetDays(-5), amount: 8000, abstract: '差旅结束后全额归还' },
			],
		},
		{
			id: 'br-4',
			type: 'borrow',
			partner: 'p-3',
			amount: 25000,
			date: offsetDays(-5),
			due_date: offsetDays(25),
			paid: 0,
			abstract: '节令商品备货借款',
			creator: '王强',
			created_at: offsetDays(-5),
			repays: [],
		},
		{
			id: 'br-5',
			type: 'borrow',
			partner: 'p-4',
			amount: 12000,
			date: offsetDays(-20),
			due_date: offsetDays(10),
			paid: 5000,
			abstract: '设备采购分期垫付',
			creator: '刘敏',
			created_at: offsetDays(-20),
			repays: [
				{ date: offsetDays(-8), amount: 5000, abstract: '第一批还款' },
			],
		},
		{
			id: 'br-6',
			type: 'repay',
			partner: 'p-1',
			amount: 20000,
			date: offsetDays(-15),
			due_date: '',
			paid: 20000,
			abstract: '华东超市还款（冲销 BR 借款）',
			creator: '陈静',
			created_at: offsetDays(-15),
			repays: [
				{ date: offsetDays(-15), amount: 20000, abstract: '银行转账还款' },
			],
		},
		{
			id: 'br-7',
			type: 'repay',
			partner: 'p-2',
			amount: 3000,
			date: offsetDays(-3),
			due_date: '',
			paid: 3000,
			abstract: '好又多卖场还款',
			creator: '李娜',
			created_at: offsetDays(-3),
			repays: [
				{ date: offsetDays(-3), amount: 3000, abstract: '现金还款' },
			],
		},
		{
			id: 'br-8',
			type: 'borrow',
			partner: 'p-5',
			amount: 6000,
			date: offsetDays(-2),
			due_date: offsetDays(28),
			paid: 0,
			abstract: '药房货款短期周转',
			creator: '陈静',
			created_at: offsetDays(-2),
			repays: [],
		},
	]

	rows.forEach((r, i) => {
		r.no = buildOrderNo(r.date, ORDER_NO_SEQ[i] || i + 1)
		// 未清金额：借款单 = 金额 - 已还；还款单 = 0
		r.unpaid = r.type === 'borrow' ? Math.max(0, r.amount - r.paid) : 0
		r.status = borrowStatus(r.amount, r.paid)
	})
	return rows
}
