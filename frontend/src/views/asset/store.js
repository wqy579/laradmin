/* ============================================================
 * 固定资产域 · mock 状态仓库（模块级单例）
 *
 * 台账 / 购置 / 折旧 / 变卖四个页面共享同一份内存数据，
 * 便于前端联调：购置单新增的资产会出现在台账中，
 * 折旧单计提后净值减少，变卖后状态变为「已售」。
 *
 * TODO(api)：接入后端后整体替换为 API 调用（见 shared.js 顶部路径约定）。
 * ============================================================ */

import { reactive } from 'vue'
import { mockAssetCards, mockDepreciationItems, salePnl, curMonth } from './shared'

const state = reactive({
	cards: mockAssetCards(28),
	purchases: [], // 购置单流水
	depRuns: [], // 折旧执行流水
	sales: [], // 变卖单流水
})

let purchaseSeq = 1000

/** 读取台账（只读快照） */
export function listCards() {
	return state.cards
}

/** 按 id 读取单张资产卡 */
export function getCard(id) {
	return state.cards.find((c) => c.id === id) || null
}

/** 读取购置流水 */
export function listPurchases() {
	return state.purchases
}

/**
 * 新增购置单：写流水 + 生成资产卡片
 * @returns {{ purchase, card }}
 */
export function addPurchase(form) {
	const amount = Number(form.original) || 0
	const seq = ++purchaseSeq
	const card = {
		id: `AST${String(state.cards.length + 1).padStart(5, '0')}`,
		code: `ZC-${new Date(form.buy_date).getFullYear()}${String(new Date(form.buy_date).getMonth() + 1).padStart(2, '0')}-${String(seq).padStart(4, '0')}`,
		name: form.name,
		category: form.category,
		buy_date: form.buy_date,
		original: amount,
		depreciated: 0,
		net: amount,
		department: form.department,
		status: 'inuse',
		handler: form.handler,
		months: Number(form.months) || 60,
		salvage_rate: Number(form.salvage_rate) || 5,
		dep_method: form.dep_method || 'straight',
		supplier: form.supplier,
		spec: form.spec,
		quantity: Number(form.quantity) || 1,
		price: Number(form.price) || 0,
	}
	const purchase = {
		id: `GZ${String(seq).padStart(6, '0')}`,
		card_id: card.id,
		create_at: new Date().toISOString(),
		...form,
		amount,
	}
	state.cards.unshift(card)
	state.purchases.unshift(purchase)
	return { purchase, card }
}

/**
 * 执行月折旧：批量计提，累加累计折旧、扣减净值，写流水
 * @param {string} month YYYY-MM
 * @returns {{ id, month, count, amount, items }}
 */
export function runDepreciation(month) {
	const items = mockDepreciationItems(state.cards, month)
	const amount = items.reduce((s, it) => s + Number(it.month_amount || 0), 0)
	for (const it of items) {
		const card = state.cards.find((c) => c.id === it.id)
		if (!card || Number(it.month_amount || 0) <= 0) continue
		card.depreciated = Math.round((card.depreciated + Number(it.month_amount)) * 100) / 100
		card.net = Math.max(0, Math.round((card.original - card.depreciated) * 100) / 100)
	}
	const run = {
		id: `ZJ${month.replace('-', '')}${String(state.depRuns.length + 1).padStart(2, '0')}`,
		month,
		count: items.length,
		amount: Math.round(amount * 100) / 100,
		items,
		create_at: new Date().toISOString(),
	}
	state.depRuns.unshift(run)
	return run
}

/** 读取折旧执行流水 */
export function listDepRuns() {
	return state.depRuns
}

/**
 * 保存变卖单：状态改「已售」，生成损益，写流水
 * @returns {{ sale, pnl }}
 */
export function addSale(form) {
	const card = state.cards.find((c) => c.id === form.card_id)
	if (!card) return { sale: null, pnl: 0 }
	const net = Number(card.net) || 0
	const saleAmount = Number(form.sale_amount) || 0
	const fee = Number(form.closing_fee) || 0
	const pnl = salePnl(net, saleAmount)
	card.status = 'sold'
	const sale = {
		id: `BM${Date.now().toString().slice(-8)}`,
		card_id: card.id,
		card_code: card.code,
		card_name: card.name,
		card_net: net,
		sale_date: form.sale_date,
		sale_amount: saleAmount,
		closing_fee: fee,
		buyer: form.buyer,
		handler: form.handler,
		pnl,
		create_at: new Date().toISOString(),
	}
	state.sales.unshift(sale)
	return { sale, pnl }
}

/** 读取变卖流水 */
export function listSales() {
	return state.sales
}

export function currentMonth() {
	return curMonth()
}
