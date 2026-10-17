/* ============================================================
 * 出纳 · 收入上交款族（P0-10）共享常量 / mock 数据 / 格式化工具
 *
 * TODO(api)：后端接口按 REST 约定预留，本文件先提供 mock 供前端联调：
 *   GET  /api/cash/handover            上交款单列表（查询页）
 *   POST /api/cash/handover            上交款单保存
 *   POST /api/cash/handover/{id}/confirm  确认上交款
 *   GET  /api/cash/card-txn            刷卡收款记录
 *   POST /api/cash/card-txn/{id}/refund  刷卡退款
 * ============================================================ */

/* ---------- 基础字典 ---------- */

export const SALESMEN = [
	{ value: 'sm-1', label: '张伟' },
	{ value: 'sm-2', label: '李娜' },
	{ value: 'sm-3', label: '王强' },
	{ value: 'sm-4', label: '刘敏' },
	{ value: 'sm-5', label: '赵磊' },
	{ value: 'sm-6', label: '陈静' },
]

export const CUSTOMERS = [
	{ value: 'c-1', label: '华东连锁超市' },
	{ value: 'c-2', label: '好又多卖场' },
	{ value: 'c-3', label: '天天平价' },
	{ value: 'c-4', label: '金鼎便利店' },
	{ value: 'c-5', label: '百姓大药房' },
	{ value: 'c-6', label: '丰乐食品' },
	{ value: 'c-7', label: '宏图商行' },
	{ value: 'c-8', label: '顺发批发部' },
]

export const CASHIERS = [
	{ value: 'cs-1', label: '周慧' },
	{ value: 'cs-2', label: '吴桐' },
	{ value: 'cs-3', label: '郑爽' },
]

export const PAY_WAYS = [
	{ value: 'cash', label: '现金' },
	{ value: 'card', label: '刷卡' },
	{ value: 'transfer', label: '转账' },
]

/* 上交款确认状态 */
export const HANDOVER_STATUS = {
	pending: { label: '待确认', tag: 'warning' },
	confirmed: { label: '已确认', tag: 'success' },
}

/* 刷卡收款状态（成功绿 / 失败灰 / 退款警告） */
export const CARD_STATUS = {
	success: { label: '成功', tag: 'success' },
	fail: { label: '失败', tag: 'info' },
	refund: { label: '退款', tag: 'warning' },
}

export const SM_LABEL = Object.fromEntries(SALESMEN.map((s) => [s.value, s.label]))
export const CU_LABEL = Object.fromEntries(CUSTOMERS.map((c) => [c.value, c.label]))
export const CS_LABEL = Object.fromEntries(CASHIERS.map((c) => [c.value, c.label]))
export const PAY_LABEL = Object.fromEntries(PAY_WAYS.map((p) => [p.value, p.label]))

/* ---------- 格式化 ---------- */

/* 金额：两位小数 + 千分位 */
export function fmtMoney(v) {
	const n = Number(v || 0)
	const neg = n < 0
	const s = Math.abs(n).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',')
	return (neg ? '-' : '') + s
}

/* 整数计数：千分位 */
export function fmtCount(v) {
	const n = Number(v || 0)
	const neg = n < 0
	const s = Math.abs(n).toFixed(0).replace(/\B(?=(\d{3})+(?!\d))/g, ',')
	return (neg ? '-' : '') + s
}

/* 日期 YYYY-MM-DD */
export function fmtDay(v) {
	return v ? String(v).slice(0, 10) : '—'
}

/* 日期时间 YYYY-MM-DD HH:mm:ss */
export function fmtDateTime(v) {
	if (!v) return '—'
	const d = new Date(v)
	if (Number.isNaN(d.getTime())) return String(v).slice(0, 19)
	const p = (n, w = 2) => String(n).padStart(w, '0')
	return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}:${p(d.getSeconds())}`
}

/* 脱敏卡号：****1234 */
export function maskCard(full) {
	const s = String(full || '')
	return s.length <= 4 ? s : '****' + s.slice(-4)
}

/* 今天（YYYY-MM-DD） */
export function today() {
	const d = new Date()
	return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

/* 距今天数 */
export function daysAgo(n) {
	const d = new Date()
	d.setDate(d.getDate() - n)
	return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

/* ---------- mock 数据生成（确定性，刷新不变） ---------- */

let _seed = 20261017
function rnd() {
	_seed = (_seed * 1664525 + 1013904223) >>> 0
	return _seed / 4294967296
}
function pick(arr) {
	return arr[Math.floor(rnd() * arr.length)]
}
function pad(n, w = 2) {
	return String(n).padStart(w, '0')
}
function randBetween(min, max) {
	return Math.round((min + rnd() * (max - min)) * 100) / 100
}

/**
 * 生成可关联的订单池（供上交款单「关联订单」多选弹窗）
 */
export function mockOrders(count = 26) {
	const rows = []
	for (let i = 0; i < count; i++) {
		const salesman = pick(SALESMEN)
		const customer = pick(CUSTOMERS)
		const amount = Math.round(randBetween(320, 28000) * 100) / 100
		rows.push({
			id: `SO${pad(2026000001 + i, 10)}`,
			order_no: `XS${pad(2026000001 + i, 10)}`,
			customer: customer.value,
			customer_name: customer.label,
			salesman: salesman.value,
			salesman_name: salesman.label,
			order_date: daysAgo(1 + Math.floor(rnd() * 30)),
			amount,
			paid_amount: Math.round(amount * (0.3 + rnd() * 0.6) * 100) / 100,
		})
	}
	return rows
}

/**
 * 生成上交款单流水（供查询页 + 单据初始列表）
 */
export function mockHandovers(count = 22) {
	const rows = []
	for (let i = 0; i < count; i++) {
		const salesman = pick(SALESMEN)
		const customer = pick(CUSTOMERS)
		const cashier = pick(CASHIERS)
		const payWay = pick(PAY_WAYS)
		const status = rnd() < 0.55 ? 'pending' : 'confirmed'
		const orderCount = 1 + Math.floor(rnd() * 4)
		const amount = Math.round(randBetween(800, 42000) * 100) / 100
		rows.push({
			id: `SC${pad(202600001 + i, 8)}`,
			no: `SC${pad(202600001 + i, 8)}`,
			date: daysAgo(Math.floor(rnd() * 40)),
			salesman: salesman.value,
			salesman_name: salesman.label,
			customer: customer.value,
			customer_name: customer.label,
			pay_way: payWay.value,
			amount,
			order_count: orderCount,
			cashier: cashier.value,
			cashier_name: cashier.label,
			status,
			remark: '',
			items: [],
		})
	}
	return rows.sort((a, b) => (a.date < b.date ? 1 : -1))
}

/**
 * 生成刷卡收款记录
 */
export function mockCardTxns(count = 34) {
	const rows = []
	for (let i = 0; i < count; i++) {
		const d = new Date()
		/* 前 3 条落在当日，保证「当日成功流水可退款」可演示 */
		const daysBack = i < 3 ? 0 : Math.floor(rnd() * 45)
		d.setDate(d.getDate() - daysBack)
		d.setHours(8 + Math.floor(rnd() * 12), Math.floor(rnd() * 60), Math.floor(rnd() * 60), 0)
		const isRefund = daysBack > 0 && rnd() < 0.08
		const isFail = !isRefund && daysBack > 0 && rnd() < 0.1
		const status = isRefund ? 'refund' : isFail ? 'fail' : 'success'
		const amount = Math.round(randBetween(60, 8800) * 100) / 100
		const fee = Math.round(amount * 0.0055 * 100) / 100
		/* 完整卡号（借记 62 开头 / 贷记 6 位 BIN + 10 位卡号），列表仅展示脱敏 */
		const cardType = rnd() < 0.55 ? 'debit' : 'credit'
		const bin = cardType === 'debit' ? '6222' : '54' + pad(Math.floor(rnd() * 100), 2)
		const cardNo = bin + Array.from({ length: 16 - bin.length }, () => String(Math.floor(rnd() * 10))).join('')
		const customer = pick(CUSTOMERS)
		const salesman = pick(SALESMEN)
		rows.push({
			id: `SX${pad(20260001 + i, 8)}`,
			serial_no: `SX${pad(20260001 + i, 8)}`,
			txn_time: `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`,
			customer: customer.value,
			customer_name: customer.label,
			salesman: salesman.value,
			salesman_name: salesman.label,
			card_type: cardType,
			card_no: cardNo,
			card_last4: cardNo.slice(-4),
			amount: status === 'refund' ? -amount : amount,
			fee: status === 'fail' ? 0 : fee,
			status,
			rel_no: status === 'fail' ? '' : `SC${pad(202600001 + Math.floor(rnd() * 22), 8)}`,
		})
	}
	return rows.sort((a, b) => (a.txn_time < b.txn_time ? 1 : -1))
}

export const CARD_TYPE_LABEL = { debit: '借记', credit: '贷记' }
