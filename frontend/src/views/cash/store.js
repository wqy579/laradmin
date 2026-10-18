/* ============================================================
 * 出纳 · 收入上交款族 mock 状态仓库（模块级单例）
 *
 * 上交款单保存后进入查询列表；查询页确认后不可再改。
 * 刷卡退款仅允许「成功且当日」的流水。
 *
 * TODO(api)：接入后端后整体替换为 API 调用（见 shared.js 顶部路径约定）。
 * ============================================================ */

import { reactive } from 'vue'
import { mockHandovers, mockCardTxns, mockOrders, today } from './shared'

const state = reactive({
	handovers: mockHandovers(22),
	cardTxns: mockCardTxns(34),
})

let seq = 1000

/** 读取上交款列表 */
export function listHandovers() {
	return state.handovers
}

/** 读取可选订单池 */
export function listOrders() {
	return mockOrders()
}

/**
 * 保存上交款单
 * @param {object} payload { date, salesman, customer, pay_way, amount, cashier, remark, items }
 * @returns {object} 新建的上交款单
 */
export function saveHandover(payload) {
	seq += 1
	const items = (payload.items || []).map((it) => ({ ...it }))
	const amount = items.reduce((s, it) => s + Number(it.handover_amount || 0), 0)
	const row = {
		id: `SC${String(seq).padStart(8, '0')}`,
		no: `SC${String(seq).padStart(8, '0')}`,
		date: payload.date || today(),
		salesman: payload.salesman,
		pay_way: payload.pay_way,
		amount: Math.round(amount * 100) / 100,
		order_count: items.length,
		cashier: payload.cashier,
		status: 'pending',
		remark: payload.remark || '',
		items,
		create_at: new Date().toISOString(),
	}
	state.handovers.unshift(row)
	return row
}

/**
 * 修改已保存的上交款单（仅待确认）
 * @param {string} id
 * @param {object} payload 同 saveHandover
 * @returns {object|null} 更新后的单据，已确认返回 null
 */
export function updateHandover(id, payload) {
	const row = state.handovers.find((h) => h.id === id)
	if (!row || row.status === 'confirmed') return null
	const items = (payload.items || []).map((it) => ({ ...it }))
	const amount = items.reduce((s, it) => s + Number(it.handover_amount || 0), 0)
	Object.assign(row, {
		date: payload.date || row.date,
		salesman: payload.salesman || row.salesman,
		customer: payload.customer || row.customer,
		pay_way: payload.pay_way || row.pay_way,
		cashier: payload.cashier || row.cashier,
		remark: payload.remark || '',
		amount: Math.round(amount * 100) / 100,
		order_count: items.length,
		items,
	})
	return row
}

/** 确认上交款：状态改「已确认」并进财务，不可再改 */
export function confirmHandover(id) {
	const row = state.handovers.find((h) => h.id === id)
	if (!row || row.status === 'confirmed') return null
	row.status = 'confirmed'
	return row
}

/** 读取刷卡收款记录 */
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
