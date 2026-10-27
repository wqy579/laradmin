/* ============================================================
 * P0-10 刷卡收款记录查询 · 本地 mock 数据
 *
 * 约束：本机禁止登录 laradmin 管理端做验证（会打断订单录入自动化），
 *       故页面一律使用本文件 mock，不调用真实接口。
 * TODO(api): 后端接口按 design_doc 卷02「数据表建议」REST 约定预留：
 *   cash_card_txn（刷卡记录表）
 *   GET  /api/cashier/card           刷卡收款记录列表（筛选：日期起止/客户/业务员/卡号后四位/状态）
 *   POST /api/cashier/card/{id}/refund  刷卡退款（仅「成功且当日」可退）
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

/* 刷卡收款状态（成功绿 / 失败灰 / 退款警告） */
export const CARD_STATUS = {
	success: { label: '成功', tag: 'success' },
	fail: { label: '失败', tag: 'info' },
	refund: { label: '退款', tag: 'warning' },
}

export const CARD_TYPE_LABEL = { debit: '借记', credit: '贷记' }

export const SM_LABEL = Object.fromEntries(SALESMEN.map((s) => [s.value, s.label]))
export const CU_LABEL = Object.fromEntries(CUSTOMERS.map((c) => [c.value, c.label]))

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
 * 生成刷卡收款记录
 * 前 3 条落在当日，保证「成功且当日 → 可退款」可演示。
 * 金额/手续费等宽对齐；退款记录金额与手续费取反。
 */
export function mockCardTxns(count = 34) {
	const rows = []
	for (let i = 0; i < count; i++) {
		const d = new Date()
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

/* ---------- 模块级单例状态（mock） ---------- */

const state = { cardTxns: mockCardTxns(34) }

/** 读取刷卡收款记录（筛选在页面内做） */
export function listCardTxns() {
	return state.cardTxns
}

/** 刷卡退款：状态改「退款」，金额与手续费取反 */
export function refundCardTxn(id) {
	const row = state.cardTxns.find((t) => t.id === id)
	if (!row || row.status !== 'success') return null
	row.status = 'refund'
	row.amount = Math.round(-Number(row.amount) * 100) / 100
	row.fee = Math.round(-Number(row.fee || 0) * 100) / 100
	return row
}