/* ============================================================
 * D-20 客户余额调整单 · 本地 mock 数据
 *
 * 约束：本机禁止登录 laradmin 管理端做验证，
 *       故页面一律使用本文件 mock，不调用真实接口。
 *
 * TODO(api)：后端接口按 REST 约定预留：
 *   GET    /api/finance/customer-balance-adjustments                调整单列表
 *   POST   /api/finance/customer-balance-adjustments                新增调整单
 *   GET    /api/finance/customer-balance-adjustments/{id}           调整单详情
 *   POST   /api/finance/customer-balance-adjustments/{id}/approve    审核调整单
 *   POST   /api/finance/customer-balance-adjustments/{id}/reject    驳回调整单（body: { reason }）
 * ============================================================ */

/* 调整类型：坏账/抹零/其他 */
export const ADJUST_TYPES = [
	{ value: 'bad_debt', label: '坏账' },
	{ value: 'rounding', label: '抹零' },
	{ value: 'other', label: '其他' },
]

export const ADJUST_TYPE_LABEL = Object.fromEntries(ADJUST_TYPES.map((t) => [t.value, t.label]))

/* 状态：待审核 / 已审核 / 已驳回 */
export const ADJUST_STATUS = {
	pending: { label: '待审核', tag: 'warning' },
	approved: { label: '已审核', tag: 'success' },
	rejected: { label: '已驳回', tag: 'danger' },
}

/* 客户（往来单位） */
export const CUSTOMERS = [
	{ value: 'c-1', label: '华东连锁超市' },
	{ value: 'c-2', label: '好又多卖场' },
	{ value: 'c-3', label: '天天平价' },
	{ value: 'c-4', label: '金鼎便利店' },
	{ value: 'c-5', label: '百姓大药房' },
]

export const CUSTOMER_LABEL = Object.fromEntries(CUSTOMERS.map((p) => [p.value, p.label]))

/* 明细行可挂的科目 */
export const ADJUST_SUBJECTS = [
	{ value: '5505', label: '资产减值损失-坏账准备' },
	{ value: '112201', label: '应收账款-客户' },
	{ value: '6601', label: '营业外支出-抹零' },
	{ value: '1122', label: '应收账款' },
]

export const SUBJECT_LABEL = Object.fromEntries(ADJUST_SUBJECTS.map((s) => [s.value, s.label]))

export function fmtMoney(v) {
	const n = Number(v || 0)
	const neg = n < 0
	const s = Math.abs(n).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',')
	return (neg ? '-' : '') + s
}

function pad(n, w = 2) {
	return String(n).padStart(w, '0')
}

function daysAgo(n) {
	const d = new Date()
	d.setDate(d.getDate() - n)
	return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}

const item = (subject, direction, amount, abstract = '') => ({ subject, direction, amount, abstract })

export function mockCustomerBalanceAdjustments() {
	const rows = [
		{
			id: 'cb-20261001',
			no: 'CB202610001',
			type: 'bad_debt',
			partner: 'c-1',
			currency: 'CNY',
			date: daysAgo(1),
			abstract: '客户破产，申请坏账核销',
			creator: '陈静',
			created_at: daysAgo(1),
			status: 'pending',
			reject_reason: '',
			items: [item('5505', '借', 5000, '核销坏账'), item('112201', '贷', 5000, '冲减应收')],
		},
		{
			id: 'cb-20261002',
			no: 'CB202610002',
			type: 'rounding',
			partner: 'c-2',
			currency: 'CNY',
			date: daysAgo(3),
			abstract: '月末余额抹零处理',
			creator: '周慧',
			created_at: daysAgo(3),
			status: 'approved',
			reject_reason: '',
			items: [item('6601', '借', 0.45, '抹零支出'), item('112201', '贷', 0.45, '冲减应收')],
		},
		{
			id: 'cb-20261003',
			no: 'CB202610003',
			type: 'other',
			partner: 'c-3',
			currency: 'CNY',
			date: daysAgo(5),
			abstract: '余额调整-录入错误修正',
			creator: '李娜',
			created_at: daysAgo(5),
			status: 'rejected',
			reject_reason: '请提供原始单据复印件',
			items: [item('112201', '借', 1200, '调增应收'), item('1122', '贷', 1200, '科目调整')],
		},
	]

	rows.forEach((r) => {
		r.amount = r.items.filter((i) => i.direction === '借').reduce((s, i) => s + Number(i.amount || 0), 0)
	})
	return rows
}
