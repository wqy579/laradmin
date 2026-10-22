/* ============================================================
 * D-18 应收应付调整单 · 本地 mock 数据
 *
 * 约束：本机禁止登录 laradmin 管理端做验证（会打断订单录入自动化），
 *       故页面一律使用本文件 mock，不调用真实接口。
 *
 * TODO(api)：后端接口按 REST 约定预留：
 *   GET    /api/finance/arap-adjustments                调整单列表（筛选：调整类型/往来单位/起止日期 + 分页）
 *   POST   /api/finance/arap-adjustments                新增调整单（状态待审核）
 *   GET    /api/finance/arap-adjustments/{id}           调整单详情（含明细行）
 *   POST   /api/finance/arap-adjustments/{id}/approve    审核调整单
 *   POST   /api/finance/arap-adjustments/{id}/reject    驳回调整单（body: { reason }）
 * ============================================================ */

/* 调整类型：互调（应收/应付互转）或重分类 */
export const ADJUST_TYPES = [
	{ value: 'ar2ap', label: '应收转应付' },
	{ value: 'ap2ar', label: '应付转应收' },
	{ value: 'reclass', label: '重分类' },
]

export const ADJUST_TYPE_LABEL = Object.fromEntries(ADJUST_TYPES.map((t) => [t.value, t.label]))

/* 状态：待审核 / 已审核 / 已驳回 */
export const ADJUST_STATUS = {
	pending: { label: '待审核', tag: 'warning' },
	approved: { label: '已审核', tag: 'success' },
	rejected: { label: '已驳回', tag: 'danger' },
}

/* 往来单位（调整单基本信息系统） */
export const PARTNERS = [
	{ value: 'p-1', label: '华东连锁超市' },
	{ value: 'p-2', label: '好又多卖场' },
	{ value: 'p-3', label: '天天平价' },
	{ value: 'p-4', label: '金鼎便利店' },
	{ value: 'p-5', label: '百姓大药房' },
	{ value: 'p-6', label: '丰乐食品' },
	{ value: 'p-7', label: '宏图商行' },
	{ value: 'p-8', label: '顺发批发部' },
]

export const PARTNER_LABEL = Object.fromEntries(PARTNERS.map((p) => [p.value, p.label]))

/* 明细行可挂的科目（应收/应付互调相关） */
export const ADJUST_SUBJECTS = [
	{ value: '1122', label: '应收账款' },
	{ value: '112201', label: '应收账款-客户' },
	{ value: '2202', label: '应付账款' },
	{ value: '1123', label: '预付账款' },
	{ value: '2203', label: '预收账款' },
]

export const SUBJECT_LABEL = Object.fromEntries(ADJUST_SUBJECTS.map((s) => [s.value, s.label]))

/* 金额千分位 + 两位小数 */
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

/* 明细行构造：借/贷一对，金额按方向累计进 amount */
const item = (subject, direction, amount, abstract = '') => ({ subject, direction, amount, abstract })

/**
 * 生成 mock 调整单列表（覆盖 三种调整类型 × 三种状态）。
 * amount = |借方合计|（按明细方向算出的调整金额）。
 */
export function mockArapAdjustments() {
	const rows = [
		{
			id: 'aa-20261001',
			no: 'AA202610001',
			type: 'ar2ap',
			partner: 'p-1',
			currency: 'CNY',
			date: daysAgo(1),
			abstract: '客兼供应商往来对冲，应收转应付',
			creator: '陈静',
			created_at: daysAgo(1),
			status: 'pending',
			reject_reason: '',
			items: [item('112201', '贷', 12500, '应收转出'), item('2202', '借', 12500, '应付转入')],
		},
		{
			id: 'aa-20261002',
			no: 'AA202610002',
			type: 'ap2ar',
			partner: 'p-6',
			currency: 'CNY',
			date: daysAgo(2),
			abstract: '供应商转客户，应付重挂应收',
			creator: '周慧',
			created_at: daysAgo(2),
			status: 'approved',
			reject_reason: '',
			items: [item('2202', '贷', 8600, '应付转出'), item('112201', '借', 8600, '应收转入')],
		},
		{
			id: 'aa-20261003',
			no: 'AA202610003',
			type: 'reclass',
			partner: 'p-3',
			currency: 'CNY',
			date: daysAgo(3),
			abstract: '应收账款明细科目重分类（户→门店）',
			creator: '李娜',
			created_at: daysAgo(3),
			status: 'rejected',
			reject_reason: '金额与业务单据不一致，请核对后重新提交',
			items: [item('112201', '贷', 5400, '客户明细转出'), item('1122', '借', 5400, '科目重分类')],
		},
		{
			id: 'aa-20261004',
			no: 'AA202610004',
			type: 'ar2ap',
			partner: 'p-8',
			currency: 'CNY',
			date: daysAgo(5),
			abstract: '同一主体既有应收又有应付，互调冲抵',
			creator: '吴桐',
			created_at: daysAgo(5),
			status: 'pending',
			reject_reason: '',
			items: [item('112201', '贷', 23000, '应收冲抵'), item('2202', '借', 23000, '应付冲抵')],
		},
		{
			id: 'aa-20261005',
			no: 'AA202610005',
			type: 'reclass',
			partner: 'p-5',
			currency: 'CNY',
			date: daysAgo(8),
			abstract: '预收账款重分类至应收账款',
			creator: '陈静',
			created_at: daysAgo(8),
			status: 'approved',
			reject_reason: '',
			items: [item('2203', '贷', 7800, '预收转出'), item('112201', '借', 7800, '应收转入')],
		},
		{
			id: 'aa-20261006',
			no: 'AA202610006',
			type: 'ap2ar',
			partner: 'p-2',
			currency: 'CNY',
			date: daysAgo(12),
			abstract: '应付账款调整为应收账款',
			creator: '王强',
			created_at: daysAgo(12),
			status: 'approved',
			reject_reason: '',
			items: [item('2202', '贷', 15600, '应付转出'), item('112201', '借', 15600, '应收转入')],
		},
	]

	// amount = 调整金额（借方合计）
	rows.forEach((r) => {
		r.amount = r.items.filter((i) => i.direction === '借').reduce((s, i) => s + Number(i.amount || 0), 0)
	})
	return rows
}
