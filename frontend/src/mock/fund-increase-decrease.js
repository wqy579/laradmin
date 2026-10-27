/* ============================================================
 * D-22+D-23 资金增减单（资金增加/资金减少两个方向）· 本地 mock 数据
 *
 * 约束：本机禁止登录 laradmin 管理端做验证，
 *       故页面一律使用本文件 mock，不调用真实接口。
 *
 * 说明：本单为单头单据（无明细行），无审核流，保存即生效。
 *
 * TODO(api)：后端接口按 REST 约定预留：
 *   GET  /api/finance/fund-increase-decrease-orders        资金增减单列表（筛选：增减类型/资金账户/日期范围 + 分页）
 *   POST /api/finance/fund-increase-decrease-orders        新增资金增减单（保存即生效）
 *   GET  /api/finance/fund-increase-decrease-orders/{id}   资金增减单详情
 *   GET  /api/finance/fund-accounts                        资金账户列表（现金/工商银行/招商银行/建设银行）
 * ============================================================ */

/* 增减类型：资金增加 / 资金减少 */
export const FUND_TYPES = [
	{ value: 'increase', label: '资金增加' },
	{ value: 'decrease', label: '资金减少' },
]

export const FUND_TYPE_LABEL = Object.fromEntries(FUND_TYPES.map((t) => [t.value, t.label]))

/* 资金账户 */
export const FUND_ACCOUNTS = [
	{ value: 'cash', label: '现金' },
	{ value: 'icbc', label: '工商银行' },
	{ value: 'cmb', label: '招商银行' },
	{ value: 'ccb', label: '建设银行' },
]

export const FUND_ACCOUNT_LABEL = Object.fromEntries(FUND_ACCOUNTS.map((a) => [a.value, a.label]))

/* 往来单位（可选） */
export const PARTNERS = [
	{ value: 'p-1', label: '华东连锁超市' },
	{ value: 'p-2', label: '好又多卖场' },
	{ value: 'p-3', label: '天天平价' },
	{ value: 'emp-1', label: '张伟（员工报销）' },
	{ value: 'emp-2', label: '李娜（员工借款）' },
]

export const PARTNER_LABEL = Object.fromEntries(PARTNERS.map((p) => [p.value, p.label]))

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

/* 单据号：FUND + 年月日 + 3 位流水 */
const FUND_NO_SEQ = [1, 2, 3, 4, 5, 6, 7, 8]
function buildFundNo(dateStr, seq) {
	const ymd = dateStr.replace(/-/g, '')
	return `FUND${ymd}${pad(seq, 3)}`
}

export function mockFundIncreaseDecreaseOrders() {
	const rows = [
		{
			id: 'fund-1',
			type: 'increase',
			account: 'icbc',
			partner: 'p-1',
			amount: 58600,
			abstract: '销售回款存入工行',
			creator: '陈静',
			date: daysAgo(1),
			created_at: daysAgo(1),
		},
		{
			id: 'fund-2',
			type: 'decrease',
			account: 'cash',
			partner: 'emp-1',
			amount: 1250,
			abstract: '差旅费报销，现金支付',
			creator: '李娜',
			date: daysAgo(2),
			created_at: daysAgo(2),
		},
		{
			id: 'fund-3',
			type: 'increase',
			account: 'cmb',
			partner: '',
			amount: 3280,
			abstract: '招商银行利息收入',
			creator: '陈静',
			date: daysAgo(4),
			created_at: daysAgo(4),
		},
		{
			id: 'fund-4',
			type: 'decrease',
			account: 'ccb',
			partner: 'p-2',
			amount: 22000,
			abstract: '采购货款银行转账',
			creator: '王强',
			date: daysAgo(6),
			created_at: daysAgo(6),
		},
		{
			id: 'fund-5',
			type: 'increase',
			account: 'cash',
			partner: 'p-3',
			amount: 860,
			abstract: '门店零星现金收款',
			creator: '刘敏',
			date: daysAgo(8),
			created_at: daysAgo(8),
		},
		{
			id: 'fund-6',
			type: 'decrease',
			account: 'icbc',
			partner: '',
			amount: 8600,
			abstract: '工资代发扣款',
			creator: '陈静',
			date: daysAgo(10),
			created_at: daysAgo(10),
		},
		{
			id: 'fund-7',
			type: 'increase',
			account: 'cmb',
			partner: 'p-2',
			amount: 15000,
			abstract: '收到好又多卖场货款',
			creator: '李娜',
			date: daysAgo(12),
			created_at: daysAgo(12),
		},
		{
			id: 'fund-8',
			type: 'decrease',
			account: 'cash',
			partner: 'emp-2',
			amount: 300,
			abstract: '办公用品采购现金支出',
			creator: '王强',
			date: daysAgo(14),
			created_at: daysAgo(14),
		},
	]

	rows.forEach((r, i) => {
		r.no = buildFundNo(r.date, FUND_NO_SEQ[i] || i + 1)
	})
	return rows
}
