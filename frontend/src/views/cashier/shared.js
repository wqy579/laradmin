/* ============================================================
 * 出纳资金单族（P0-8）· 共享常量 / mock 数据 / 格式化工具
 *
 * TODO(api)：后端接口按 REST 约定预留，本文件先提供 mock 供前端联调：
 *   GET  /api/cashier/account        资金账户列表
 *   GET  /api/cashier/flow           现金流水（cash_flow）
 *   GET  /api/cashier/deposit        存取款单列表
 *   POST /api/cashier/deposit        存取款单保存
 *   GET  /api/cashier/borrow         借款/还款单列表
 *   POST /api/cashier/borrow         借款/还款单保存
 *   GET  /api/cashier/adjust         应收应付调整单列表
 *   POST /api/cashier/adjust         应收应付调整单保存
 *   GET  /api/cashier/iou            欠条列表
 *   POST /api/cashier/iou            欠条确认/驳回
 * ============================================================ */

/* ---------- 基础字典 ---------- */

/* 资金账户：类型 account_kind = 现金/银行 */
export const ACCOUNTS = [
	{ id: 'acc-cash', kind: 'cash', name: '库存现金' },
	{ id: 'acc-icbc', kind: 'bank', name: '银行存款-工行' },
	{ id: 'acc-cbc', kind: 'bank', name: '银行存款-建行' },
	{ id: 'acc-boc', kind: 'bank', name: '银行存款-中行' },
]

/* 往来单位：客户 / 供应商 / 业务员（设计文档 8.1 往来单位 Select 含三类） */
export const CUSTOMERS = [
	{ id: 'cus-1001', name: '连凯贸易有限公司' },
	{ id: 'cus-1002', name: '华东食品配送中心' },
	{ id: 'cus-1003', name: '鑫源百货商行' },
	{ id: 'cus-1004', name: '恒丰超市连锁' },
	{ id: 'cus-1005', name: '佳美便利店' },
	{ id: 'cus-1006', name: '盛达贸易集团' },
	{ id: 'cus-1007', name: '宏源批发部' },
	{ id: 'cus-1008', name: '顺达连锁超市' },
]

export const SUPPLIERS = [
	{ id: 'sup-2001', name: '华东机械设备' },
	{ id: 'sup-2002', name: '顺通车辆经销' },
	{ id: 'sup-2003', name: '办公设备城' },
	{ id: 'sup-2004', name: '恒建工程' },
]

export const SALESMEN = [
	{ id: 'emp-301', name: '张伟' },
	{ id: 'emp-302', name: '李娜' },
	{ id: 'emp-303', name: '王强' },
	{ id: 'emp-304', name: '刘敏' },
	{ id: 'emp-305', name: '赵磊' },
]

export const DEPARTMENTS = [
	{ id: 'dept-1', name: '仓储部' },
	{ id: 'dept-2', name: '销售部' },
	{ id: 'dept-3', name: '财务部' },
	{ id: 'dept-4', name: '行政部' },
	{ id: 'dept-5', name: '生产部' },
]

export const ACC_LABEL = Object.fromEntries(ACCOUNTS.map((a) => [a.id, a.name]))
export const CUS_LABEL = Object.fromEntries(CUSTOMERS.map((c) => [c.id, c.name]))
export const SUP_LABEL = Object.fromEntries(SUPPLIERS.map((s) => [s.id, s.name]))
export const EMP_LABEL = Object.fromEntries(SALESMEN.map((e) => [e.id, e.name]))
export const DEPT_LABEL = Object.fromEntries(DEPARTMENTS.map((d) => [d.id, d.name]))

/* 单据状态：设计文档 8.x 状态 Tag */
export const DOC_STATUS = {
	draft: { label: '草稿', tag: 'info' },
	audited: { label: '已审核', tag: 'success' },
	rejected: { label: '已驳回', tag: 'danger' },
	flush: { label: '已红冲', tag: 'warning' },
}

export const STATUS_LABEL = Object.fromEntries(Object.entries(DOC_STATUS).map(([k, v]) => [k, v.label]))

/* ---------- 格式化 ---------- */

/* 金额：两位小数 + 千分位，负数带负号（资金流水收入/支出） */
export function fmtMoney(v) {
	const n = Number(v || 0)
	const neg = n < 0
	const s = Math.abs(n).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',')
	return (neg ? '-' : '') + s
}

/* 日期：YYYY-MM-DD，空值占位 */
export function fmtDay(v) {
	return v ? String(v).slice(0, 10) : '—'
}

/* 当前日期 YYYY-MM-DD */
export function today() {
	const d = new Date()
	return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

/* ---------- 确定性 mock 数据生成（LCG，刷新不变） ---------- */

let _seed = 20261018
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

/* 生成单据号：前缀 + 日期 + 序号，如 CQ202610180001 */
export function docNo(prefix, date, seq) {
	const d = String(date || today()).replace(/-/g, '')
	return `${prefix}${d}${String(seq).padStart(4, '0')}`
}

/* 资金账户初始余额（mock 期初） */
export function mockAccounts() {
	return [
		{ ...ACCOUNTS[0], balance: 85630.5 },
		{ ...ACCOUNTS[1], balance: 482105.0 },
		{ ...ACCOUNTS[2], balance: 120400.75 },
		{ ...ACCOUNTS[3], balance: 68990.0 },
	]
}

/* ---------- 流水 mock：现金流水（所有资金单汇总落此） ---------- */
/* type: in 收入 / out 支出；direction 供余额列计算 */
export function mockFlows() {
	const rows = [
		{ id: 1, no: 'CQ202610100001', date: '2026-10-10', docType: 'deposit', account: 'acc-cash', direction: 'in', amount: 20000, counterpart: 'cus-1001', handler: 'emp-301', remark: '销售回款存入', status: 'audited' },
		{ id: 2, no: 'JK202610110001', date: '2026-10-11', docType: 'loan', account: 'acc-cash', direction: 'out', amount: 30000, counterpart: 'cus-1005', handler: 'emp-302', remark: '借款给客户', status: 'audited' },
		{ id: 3, no: 'HK202610120001', date: '2026-10-12', docType: 'repay', account: 'acc-cash', direction: 'in', amount: 12000, counterpart: 'cus-1005', handler: 'emp-302', remark: '客户还款', status: 'audited' },
		{ id: 4, no: 'ZJ202610130001', date: '2026-10-13', docType: 'fund-in', account: 'acc-icbc', direction: 'in', amount: 80000, counterpart: 'sup-2001', handler: 'emp-301', remark: '股东增资', status: 'audited' },
		{ id: 5, no: 'CQ202610140002', date: '2026-10-14', docType: 'deposit', account: 'acc-cash', direction: 'in', amount: 5000, counterpart: 'cus-1003', handler: 'emp-303', remark: '现金存款', status: 'audited' },
		{ id: 6, no: 'ZJ202610150001', date: '2026-10-15', docType: 'fund-out', account: 'acc-cash', direction: 'out', amount: 3500, counterpart: 'sup-2004', handler: 'emp-304', remark: '零星支出', status: 'audited' },
		{ id: 7, no: 'TZ202610160001', date: '2026-10-16', docType: 'adjust', account: 'acc-icbc', direction: 'in', amount: 6600, counterpart: 'cus-1007', handler: 'emp-305', remark: '应收调增', status: 'audited' },
		{ id: 8, no: 'QT202610170001', date: '2026-10-17', docType: 'iou', account: 'acc-cash', direction: 'in', amount: 8800, counterpart: 'cus-1002', handler: 'emp-301', remark: '欠条确认', status: 'audited' },
	]
	return rows
}