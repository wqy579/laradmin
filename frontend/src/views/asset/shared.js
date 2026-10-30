/* ============================================================
 * 固定资产域 · 共享常量 / mock 数据 / 格式化工具
 *
 * TODO(api)：后端接口按 REST 约定预留，本文件先提供 mock 供前端联调：
 *   GET  /api/asset/card            资产台账列表（支持 category/department/status/kw 筛选）
 *   POST /api/asset/purchase        购置单保存（生成资产卡片）
 *   POST /api/asset/depreciation    执行月折旧（批量计提）
 *   POST /api/asset/sale            变卖单保存（更新状态 + 出损益）
 * ============================================================ */

/* ---------- 基础字典 ---------- */

export const ASSET_CATEGORIES = [
	{ value: 'device', label: '生产设备' },
	{ value: 'transport', label: '运输工具' },
	{ value: 'office', label: '办公设备' },
	{ value: 'house', label: '房屋建筑物' },
	{ value: 'other', label: '其他' },
]

export const DEPARTMENTS = [
	{ value: 'dept-1', label: '仓储部' },
	{ value: 'dept-2', label: '销售部' },
	{ value: 'dept-3', label: '财务部' },
	{ value: 'dept-4', label: '行政部' },
	{ value: 'dept-5', label: '生产部' },
]

export const EMPLOYEES = [
	{ value: 'emp-1', label: '张伟' },
	{ value: 'emp-2', label: '李娜' },
	{ value: 'emp-3', label: '王强' },
	{ value: 'emp-4', label: '刘敏' },
	{ value: 'emp-5', label: '赵磊' },
]

export const SUPPLIERS = [
	{ value: 'sup-1', label: '华东机械设备' },
	{ value: 'sup-2', label: '顺通车辆经销' },
	{ value: 'sup-3', label: '办公设备城' },
	{ value: 'sup-4', label: '恒建工程' },
]

export const DEP_METHODS = [
	{ value: 'straight', label: '直线法' },
	{ value: 'average', label: '平均年限法' },
]

/* 资产状态：tag 值对齐 Element Plus light 效果色板（卷00 5.6） */
export const ASSET_STATUS = {
	inuse: { label: '在用', tag: 'primary' },
	idle: { label: '闲置', tag: 'info' },
	scrap: { label: '报废', tag: 'danger' },
	sold: { label: '已售', tag: 'success' },
}

export const CAT_LABEL = Object.fromEntries(ASSET_CATEGORIES.map((c) => [c.value, c.label]))
export const DEPT_LABEL = Object.fromEntries(DEPARTMENTS.map((d) => [d.value, d.label]))
export const EMP_LABEL = Object.fromEntries(EMPLOYEES.map((e) => [e.value, e.label]))
export const SUP_LABEL = Object.fromEntries(SUPPLIERS.map((s) => [s.value, s.label]))

/* ---------- 格式化 ---------- */

/* 金额：两位小数 + 千分位，负数带负号（用于原值/折旧/净值/损益） */
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

/* 日期：YYYY-MM-DD，空值占位 */
export function fmtDay(v) {
	return v ? String(v).slice(0, 10) : '—'
}

/* 年月文本：2026-10 */
export function fmtMonth(v) {
	return v ? String(v).slice(0, 7) : '—'
}

/* 当前年月（YYYY-MM） */
export function curMonth() {
	const d = new Date()
	return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`
}

/* ---------- mock 数据生成（确定性，刷新不变） ---------- */

const NAME_POOL = {
	device: ['注塑机', '冲床', '叉车', '打包机', '贴标机', '包装机', '切割机'],
	transport: ['厢式货车', '冷链车', '叉车', '电动三轮车', '牵引车'],
	office: ['办公桌椅', '打印机', '服务器', '空调', '投影仪', '文件柜'],
	house: ['仓库主体', '办公用房', '员工宿舍', '门卫室'],
	other: ['模具', '工装夹具', '检测仪器'],
}

const STATUS_POOL = ['inuse', 'inuse', 'inuse', 'inuse', 'idle', 'inuse', 'sold', 'scrap']

/* 伪随机（LCG），保证 mock 稳定可复现 */
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

/**
 * 生成资产台账 mock 数据
 * @param {number} count
 * @returns {Array}
 */
export function mockAssetCards(count = 28) {
	const rows = []
	for (let i = 0; i < count; i++) {
		const category = pick(ASSET_CATEGORIES).value
		const name = pick(NAME_POOL[category])
		const buyYear = 2019 + Math.floor(rnd() * 7) // 2019~2025
		const buyMonth = 1 + Math.floor(rnd() * 12)
		const buyDay = 1 + Math.floor(rnd() * 28)
		const buyDate = `${buyYear}-${pad(buyMonth)}-${pad(buyDay)}`

		let original = Math.round((8000 + rnd() * 580000) / 100) * 100
		if (category === 'house') original = Math.round((original + 300000) / 10000) * 10000
		if (category === 'office') original = Math.round((original * 0.12) / 100) * 100

		// 已使用年限（截至 2026-10），累计折旧随年限线性增长
		const NOW_M = 2026 * 12 + 10
		const buyM = buyYear * 12 + buyMonth
		const years = Math.max(1, Math.floor((NOW_M - buyM) / 12))
		// 累计折旧：随使用年限线性增长，封顶 90% 原值
		const depreciated = Math.min(Math.round(original * 0.9), Math.round(original * years * 0.12))
		const net = original - depreciated

		const status = STATUS_POOL[i % STATUS_POOL.length]

		rows.push({
			id: `AST${pad(i + 1, 5)}`,
			code: `ZC-${buyYear}${pad(buyMonth)}-${pad(i + 1, 4)}`,
			name,
			category,
			buy_date: buyDate,
			original,
			depreciated,
			net,
			department: pick(DEPARTMENTS).value,
			status,
			handler: pick(EMPLOYEES).value,
			months: 60 + Math.floor(rnd() * 36),
			salvage_rate: 5 + Math.floor(rnd() * 5),
			dep_method: pick(DEP_METHODS).value,
		})
	}
	return rows
}

/**
 * 生成某月的应折资产明细（供折旧单使用）
 * 本月折旧额 = 原值 × (1 - 残值率) / 预计月数，封顶剩余净值
 */
export function mockDepreciationItems(cards, month) {
	return cards
		.filter((c) => c.status === 'inuse')
		.map((c) => {
			const base = c.original * (1 - (c.salvage_rate || 5) / 100)
			const monthAmount = Math.max(0, Math.min(c.net, Math.round((base / Math.max(1, c.months)) * 100) / 100))
			return {
				...c,
				month_amount: monthAmount,
				after_depreciated: c.depreciated + monthAmount,
				after_net: c.net - monthAmount,
				period: month,
			}
		})
}

/**
 * 生成变卖损益 mock（变卖金额 - 净值）
 */
export function salePnl(net, saleAmount) {
	return Number((saleAmount - net).toFixed(2))
}
