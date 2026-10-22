/* ============================================================
 * D-21 供应商余额调整单 · 本地 mock 数据
 *
 * 约束：本机禁止登录 laradmin 管理端做验证，
 *       故页面一律使用本文件 mock，不调用真实接口。
 *
 * TODO(api)：后端接口按 REST 约定预留：
 *   GET    /api/finance/supplier-balance-adjustments                调整单列表
 *   POST   /api/finance/supplier-balance-adjustments                新增调整单
 *   GET    /api/finance/supplier-balance-adjustments/{id}           调整单详情
 *   POST   /api/finance/supplier-balance-adjustments/{id}/approve    审核调整单
 *   POST   /api/finance/supplier-balance-adjustments/{id}/reject    驳回调整单（body: { reason }）
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

/* 供应商（往来单位） */
export const SUPPLIERS = [
	{ value: 's-1', label: '丰乐食品' },
	{ value: 's-2', label: '顺发批发部' },
	{ value: 's-3', label: '宏图商行' },
	{ value: 's-4', label: '百汇源商贸' },
	{ value: 's-5', label: '金桂粮油厂' },
]

export const SUPPLIER_LABEL = Object.fromEntries(SUPPLIERS.map((p) => [p.value, p.label]))

/* 明细行可挂的科目 */
export const ADJUST_SUBJECTS = [
	{ value: '5505', label: '资产减值损失-坏账准备' },
	{ value: '2202', label: '应付账款' },
	{ value: '220201', label: '应付账款-供应商' },
	{ value: '6601', label: '营业外支出-抹零' },
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

export function mockSupplierBalanceAdjustments() {
	const rows = [
		{
			id: 'sb-20261001',
			no: 'SB202610001',
			type: 'bad_debt',
			partner: 's-1',
			currency: 'CNY',
			date: daysAgo(1),
			abstract: '供应商注销，应付余额坏账核销',
			creator: '陈静',
			created_at: daysAgo(1),
			status: 'pending',
			reject_reason: '',
			items: [item('220201', '贷', 3600, '核销应付'), item('5505', '借', 3600, '坏账核销')],
		},
		{
			id: 'sb-20261002',
			no: 'SB202610002',
			type: 'rounding',
			partner: 's-2',
			currency: 'CNY',
			date: daysAgo(4),
			abstract: '月末对账尾差抹零',
			creator: '王强',
			created_at: daysAgo(4),
			status: 'approved',
			reject_reason: '',
			items: [item('6601', '借', 1.2, '抹零支出'), item('220201', '贷', 1.2, '冲减应付')],
		},
		{
			id: 'sb-20261003',
			no: 'SB202610003',
			type: 'other',
			partner: 's-3',
			currency: 'CNY',
			date: daysAgo(6),
			abstract: '供应商余额录入差错调整',
			creator: '李娜',
			created_at: daysAgo(6),
			status: 'rejected',
			reject_reason: '调整金额未附原始凭证，请补充',
			items: [item('2202', '借', 880, '调减应付'), item('220201', '贷', 880, '科目调整')],
		},
	]

	rows.forEach((r) => {
		r.amount = r.items.filter((i) => i.direction === '借').reduce((s, i) => s + Number(i.amount || 0), 0)
	})
	return rows
}
