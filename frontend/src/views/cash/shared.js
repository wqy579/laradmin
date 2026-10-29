/* ============================================================
 * 出纳 · 收入上交款族（P0-10）共享常量与格式化工具
 *
 * 接口见 @/api/cash（/admin/cash 前缀）；页面已接真实数据，mock 已移除。
 * ============================================================ */

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

export const CARD_TYPE_LABEL = { debit: '借记', credit: '贷记' }

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
