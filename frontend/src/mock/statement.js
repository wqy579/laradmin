/**
 * P0-7 对账单 · 本地 mock 数据
 *
 * 约束：本机禁止登录 laradmin 管理端做验证（会打断订单录入自动化），
 *       故页面一律使用本文件 mock，不调用真实接口。
 * TODO(api): 后端接口按 design_doc 卷01「数据表建议」预留：
 *   fin_statement / fin_statement_item / fin_reconciliation（对账状态）
 * 对账口径：应收/应付发生额与余额按「期间（月）」汇总，往来明细逐笔展示，
 *           支持对账状态标记（未对账 / 已对账 / 部分对账）。
 */

// 往来单位：客户与供应商
export const MOCK_UNITS = [
	{ id: 1, kind: 'customer', name: '连凯贸易有限公司' },
	{ id: 2, kind: 'customer', name: '华东食品配送中心' },
	{ id: 3, kind: 'customer', name: '鑫源百货商行' },
	{ id: 4, kind: 'customer', name: '恒丰超市连锁' },
	{ id: 5, kind: 'supplier', name: '沈阳冷链物流' },
	{ id: 6, kind: 'supplier', name: '京津乳品配送' },
	{ id: 7, kind: 'supplier', name: '石家庄包装材料厂' },
]

// 往来单位类型字典
export const MOCK_UNIT_KINDS = [
	{ value: 'customer', label: '客户', subject: '应收账款' },
	{ value: 'supplier', label: '供应商', subject: '应付账款' },
]

// 期间（月度）对账汇总：按单位 + 月份归集
//   opening_balance 期初余额（与余额符号一致：客户为正 = 客户欠款；供应商为正 = 欠供应商）
//   receivable 本期应收发生额 / payable 本期应付发生额
//   received 本期已收 / paid 本期已付
//   closing_balance 期末余额
export const MOCK_PERIODS = [
	// 客户 连凯贸易
	{ unit_id: 1, year: 2026, month: 8, opening_balance: 32000, receivable: 58600, received: 44000, closing_balance: 46600, recon_status: 'settled', recon_date: '2026-09-06' },
	{ unit_id: 1, year: 2026, month: 9, opening_balance: 46600, receivable: 61200, received: 53800, closing_balance: 54000, recon_status: 'settled', recon_date: '2026-10-08' },
	{ unit_id: 1, year: 2026, month: 10, opening_balance: 54000, receivable: 32500, received: 20100, closing_balance: 66400, recon_status: 'partial', recon_date: '2026-10-16' },
	// 客户 华东食品
	{ unit_id: 2, year: 2026, month: 8, opening_balance: 15000, receivable: 28900, received: 24000, closing_balance: 19900, recon_status: 'settled', recon_date: '2026-09-05' },
	{ unit_id: 2, year: 2026, month: 9, opening_balance: 19900, receivable: 32400, received: 26000, closing_balance: 26300, recon_status: 'partial', recon_date: '2026-10-10' },
	{ unit_id: 2, year: 2026, month: 10, opening_balance: 26300, receivable: 18600, received: 12300, closing_balance: 32600, recon_status: 'unsettled', recon_date: null },
	// 客户 鑫源百货
	{ unit_id: 3, year: 2026, month: 9, opening_balance: 8000, receivable: 15200, received: 9600, closing_balance: 13600, recon_status: 'settled', recon_date: '2026-10-07' },
	{ unit_id: 3, year: 2026, month: 10, opening_balance: 13600, receivable: 9800, received: 4200, closing_balance: 19200, recon_status: 'unsettled', recon_date: null },
	// 客户 恒丰超市
	{ unit_id: 4, year: 2026, month: 8, opening_balance: 9000, receivable: 41000, received: 38000, closing_balance: 12000, recon_status: 'settled', recon_date: '2026-09-09' },
	{ unit_id: 4, year: 2026, month: 10, opening_balance: 12000, receivable: 23800, received: 15100, closing_balance: 20700, recon_status: 'partial', recon_date: '2026-10-15' },
	// 供应商 沈阳冷链
	{ unit_id: 5, year: 2026, month: 8, opening_balance: 21000, payable: 45000, paid: 38000, closing_balance: 28000, recon_status: 'settled', recon_date: '2026-09-04' },
	{ unit_id: 5, year: 2026, month: 9, opening_balance: 28000, payable: 52300, paid: 46000, closing_balance: 34300, recon_status: 'partial', recon_date: '2026-10-08' },
	{ unit_id: 5, year: 2026, month: 10, opening_balance: 34300, payable: 26800, paid: 14500, closing_balance: 46600, recon_status: 'unsettled', recon_date: null },
	// 供应商 京津乳品
	{ unit_id: 6, year: 2026, month: 9, opening_balance: 12000, payable: 31200, paid: 27500, closing_balance: 15700, recon_status: 'settled', recon_date: '2026-10-06' },
	{ unit_id: 6, year: 2026, month: 10, opening_balance: 15700, payable: 19400, paid: 11200, closing_balance: 23900, recon_status: 'unsettled', recon_date: null },
	// 供应商 石家庄包装
	{ unit_id: 7, year: 2026, month: 8, opening_balance: 0, payable: 18600, paid: 9600, closing_balance: 9000, recon_status: 'partial', recon_date: '2026-09-12' },
	{ unit_id: 7, year: 2026, month: 9, opening_balance: 9000, payable: 22400, paid: 0, closing_balance: 31400, recon_status: 'unsettled', recon_date: null },
]

// 往来明细：按单位 + 月份，逐笔单据
//   date 单据日期 doc_no 单据编号 doc_type 单据类型 summary 摘要
//   amount 发生金额（应收/应付方向）settle 已收/已付 matched 是否已核对入账
// 明细发生额 reconcile 到对应期间汇总：销售/采购行 amount 之和 = 本期应收/应付，
// 各行 settle 之和 = 本期已收/已付；期初 + 发生 − 收付 = 期末。
export const MOCK_DETAILS = [
	// 客户 连凯贸易 · 2026-10（应收 32500 / 已收 20100）
	{ unit_id: 1, year: 2026, month: 10, date: '2026-10-08', doc_no: 'SO20261008001', doc_type: '销售订单', summary: '十月蒙牛常温奶一批', amount: 18600, settle: 12000, matched: true },
	{ unit_id: 1, year: 2026, month: 10, date: '2026-10-15', doc_no: 'SO20261015007', doc_type: '销售订单', summary: '伊利低温奶补货', amount: 13900, settle: 8100, matched: false },
	// 客户 华东食品 · 2026-10（应收 18600 / 已收 12300）
	{ unit_id: 2, year: 2026, month: 10, date: '2026-10-09', doc_no: 'SO20261009012', doc_type: '销售订单', summary: '康师傅饮品一批', amount: 11200, settle: 7600, matched: true },
	{ unit_id: 2, year: 2026, month: 10, date: '2026-10-17', doc_no: 'SO20261017003', doc_type: '销售订单', summary: '旺旺雪饼特惠装', amount: 7400, settle: 4700, matched: false },
	// 供应商 沈阳冷链 · 2026-10（应付 26800 / 已付 14500）
	{ unit_id: 5, year: 2026, month: 10, date: '2026-10-10', doc_no: 'PO20261010002', doc_type: '采购订单', summary: '冷链运输服务费', amount: 16800, settle: 9800, matched: true },
	{ unit_id: 5, year: 2026, month: 10, date: '2026-10-16', doc_no: 'PO20261016001', doc_type: '采购订单', summary: '冷藏仓库租赁费', amount: 10000, settle: 4700, matched: false },
]

// 对账状态字典
export const MOCK_RECON_STATUS = {
	unsettled: { label: '未对账', type: 'info' },
	partial: { label: '部分对账', type: 'warning' },
	settled: { label: '已对账', type: 'success' },
}