/* ============================================================
 * 财务单据统一查询门户（D-14）mock 数据
 *
 * TODO(api)：后端接口按 REST 约定预留，本文件先提供 mock 供前端联调：
 *   GET /api/finance/documents?type=expense|receipt-payment|cash|adjustment
 *       统一单据查询（跨四类单据），支持筛选参数：
 *         type        单据类型，缺省/空 = 全部四类
 *         doc_no      单据号（模糊）
 *         unit        往来单位（模糊）
 *         start_date / end_date  起止日期（YYYY-MM-DD，含端点）
 *         amount_min / amount_max 金额区间（按绝对值比较，支出单同样命中）
 *         status      pending|approved|rejected，空 = 全部
 *         page / page_size       分页
 *       返回：{ list, total, counts: { all, expense, receipt-payment, cash, adjustment } }
 *       counts 为「除 type 外其余筛选」下的各类数量，供顶部类型胶囊徽标使用。
 *   GET /api/finance/documents/{id}   单据详情（公共字段 + extra 扩展字段）
 *   GET /api/finance/documents/export 同筛选参数导出（返回带 BOM 的 CSV 流）
 *
 * 金额符号约定（与日记账一致）：收入为正、支出为负，页面按符号着色。
 * extra 为各单据类型专有字段，查看弹窗按 type 分区展示。
 * ============================================================ */

/** 单据类型元数据：value 与接口 type 参数一致，tag 为 el-tag type（卷00 语义色） */
export const MOCK_DOC_TYPES = [
	{ value: 'expense', label: '费用报销', tag: 'primary' },
	{ value: 'receipt-payment', label: '收付款', tag: 'success' },
	{ value: 'cash', label: '存取款', tag: 'warning' },
	{ value: 'adjustment', label: '调账', tag: 'danger' },
]

/** 单据状态元数据 */
export const MOCK_DOC_STATUS = {
	pending: { label: '待审核', tag: 'warning' },
	approved: { label: '已审核', tag: 'success' },
	rejected: { label: '已驳回', tag: 'danger' },
}

export const MOCK_DOCUMENTS = [
	/* ── 费用报销 BX ── 支出为负 ── */
	{
		id: 'BX202600001', type: 'expense', doc_no: 'BX202600001', date: '2026-10-03', unit: '百斯特文具', amount: -428.60,
		status: 'approved', summary: '行政部门 10 月办公用品采购',
		extra: { 报销部门: '行政部', 报销人: '周敏', 费用类型: '办公费', 发票张数: 3, 审批意见: '同意报销（李经理 10-04）' },
	},
	{
		id: 'BX202600002', type: 'expense', doc_no: 'BX202600002', date: '2026-10-07', unit: '顺捷物流', amount: -1260.00,
		status: 'approved', summary: '华东线路 10 月上半月快递运费',
		extra: { 报销部门: '仓储部', 报销人: '陈磊', 费用类型: '运输费', 发票张数: 12, 审批意见: '核对运单无误，准予报销' },
	},
	{
		id: 'BX202600003', type: 'expense', doc_no: 'BX202600003', date: '2026-10-11', unit: '朝阳一号店', amount: -500.00,
		status: 'pending', summary: '门店零用金领用 · 陈列物料补货',
		extra: { 报销部门: '直营门店', 报销人: '张伟', 费用类型: '门店经费', 发票张数: 2, 审批意见: '' },
	},
	{
		id: 'BX202600004', type: 'expense', doc_no: 'BX202600004', date: '2026-10-14', unit: '如家商旅酒店', amount: -1880.00,
		status: 'rejected', summary: '华东区拜访客户差旅（缺住宿发票）',
		extra: { 报销部门: '销售部', 报销人: '李娜', 费用类型: '差旅费', 发票张数: 4, 审批意见: '住宿发票缺失，补票后重报' },
	},
	{
		id: 'BX202600005', type: 'expense', doc_no: 'BX202600005', date: '2026-10-18', unit: '国网电力', amount: -6420.00,
		status: 'approved', summary: '中心仓 10 月电费',
		extra: { 报销部门: '仓储部', 报销人: '陈磊', 费用类型: '水电费', 发票张数: 1, 审批意见: '按抄表数核销' },
	},
	{
		id: 'BX202600006', type: 'expense', doc_no: 'BX202600006', date: '2026-10-22', unit: '恒信广告', amount: -2380.00,
		status: 'pending', summary: '门店促销吊旗与堆头物料制作',
		extra: { 报销部门: '市场部', 报销人: '刘敏', 费用类型: '宣传费', 发票张数: 1, 审批意见: '' },
	},
	{
		id: 'BX202600007', type: 'expense', doc_no: 'BX202600007', date: '2026-10-26', unit: '中通快递', amount: -684.50,
		status: 'approved', summary: '退换货件回寄运费',
		extra: { 报销部门: '客服部', 报销人: '王芳', 费用类型: '运输费', 发票张数: 23, 审批意见: '与退货台账一致' },
	},

	/* ── 收付款 SK/FK ── 收款为正、付款为负 ── */
	{
		id: 'SK202600012', type: 'receipt-payment', doc_no: 'SK202600012', date: '2026-10-02', unit: '华东连锁超市', amount: 45000.00,
		status: 'approved', summary: '客户货款到账 · 10 月第一期',
		extra: { 往来类型: '客户', 结算方式: '银行转账', 结算账户: '工商银行 6222****3391', 票据号: '', 业务员: '张伟' },
	},
	{
		id: 'FK202600021', type: 'receipt-payment', doc_no: 'FK202600021', date: '2026-10-04', unit: '沈阳冷链物流', amount: -28600.00,
		status: 'approved', summary: '支付供应商 9 月冷链配送费',
		extra: { 往来类型: '供应商', 结算方式: '银行转账', 结算账户: '工商银行 6222****3391', 票据号: '', 业务员: '赵强' },
	},
	{
		id: 'SK202600013', type: 'receipt-payment', doc_no: 'SK202600013', date: '2026-10-07', unit: '恒丰超市连锁', amount: 36800.00,
		status: 'approved', summary: '客户货款 · 现结',
		extra: { 往来类型: '客户', 结算方式: '现金', 结算账户: '现金账户', 票据号: '', 业务员: '李娜' },
	},
	{
		id: 'FK202600022', type: 'receipt-payment', doc_no: 'FK202600022', date: '2026-10-09', unit: '鑫源百货商行', amount: -15000.00,
		status: 'pending', summary: '支付仓库 10 月租金',
		extra: { 往来类型: '供应商', 结算方式: '银行转账', 结算账户: '招商银行 6214****8802', 票据号: '', 业务员: '赵强' },
	},
	{
		id: 'SK202600014', type: 'receipt-payment', doc_no: 'SK202600014', date: '2026-10-12', unit: '金鼎便利店', amount: 21400.00,
		status: 'approved', summary: '客户货款 · 承兑汇票贴现到账',
		extra: { 往来类型: '客户', 结算方式: '承兑汇票', 结算账户: '工商银行 6222****3391', 票据号: 'HA20261012003', 业务员: '王强' },
	},
	{
		id: 'FK202600023', type: 'receipt-payment', doc_no: 'FK202600023', date: '2026-10-15', unit: '京津乳品配送', amount: -31200.00,
		status: 'approved', summary: '支付供应商货款 · 月结 30 天',
		extra: { 往来类型: '供应商', 结算方式: '银行转账', 结算账户: '工商银行 6222****3391', 票据号: '', 业务员: '赵强' },
	},
	{
		id: 'SK202600015', type: 'receipt-payment', doc_no: 'SK202600015', date: '2026-10-21', unit: '天天平价', amount: 18650.00,
		status: 'pending', summary: '客户货款 · 待财务复核',
		extra: { 往来类型: '客户', 结算方式: '微信商户', 结算账户: '招商银行 6214****8802', 票据号: '', 业务员: '刘敏' },
	},
	{
		id: 'FK202600024', type: 'receipt-payment', doc_no: 'FK202600024', date: '2026-10-25', unit: '光明乳业', amount: -42300.00,
		status: 'rejected', summary: '供应商付款（金额与对账单不符）',
		extra: { 往来类型: '供应商', 结算方式: '银行转账', 结算账户: '工商银行 6222****3391', 票据号: '', 业务员: '赵强' },
	},

	/* ── 存取款 CK/QK ── 存入为正、取出为负 ── */
	{
		id: 'CK202600008', type: 'cash', doc_no: 'CK202600008', date: '2026-10-05', unit: '工商银行', amount: 30000.00,
		status: 'approved', summary: '门店营业款存入基本户',
		extra: { 存取方向: '存入', 现金账户: '现金账户', 银行账户: '工商银行 6222****3391', 经办人: '孙静', 手续费: 0.00 },
	},
	{
		id: 'QK202600005', type: 'cash', doc_no: 'QK202600005', date: '2026-10-08', unit: '工商银行', amount: -12000.00,
		status: 'approved', summary: '提取备用金 · 门店找零周转',
		extra: { 存取方向: '取出', 现金账户: '现金账户', 银行账户: '工商银行 6222****3391', 经办人: '孙静', 手续费: 0.00 },
	},
	{
		id: 'CK202600009', type: 'cash', doc_no: 'CK202600009', date: '2026-10-13', unit: '招商银行', amount: 56000.00,
		status: 'approved', summary: '回款归集至招行一般户',
		extra: { 存取方向: '存入', 现金账户: '现金账户', 银行账户: '招商银行 6214****8802', 经办人: '周颖', 手续费: 5.00 },
	},
	{
		id: 'QK202600006', type: 'cash', doc_no: 'QK202600006', date: '2026-10-17', unit: '农业银行', amount: -8000.00,
		status: 'pending', summary: '农行户取款 · 支付临时用工工资',
		extra: { 存取方向: '取出', 现金账户: '现金账户', 银行账户: '农业银行 6228****1076', 经办人: '周颖', 手续费: 0.00 },
	},
	{
		id: 'CK202600010', type: 'cash', doc_no: 'CK202600010', date: '2026-10-23', unit: '工商银行', amount: 24800.00,
		status: 'approved', summary: '十一黄金周营业款集中缴存',
		extra: { 存取方向: '存入', 现金账户: '现金账户', 银行账户: '工商银行 6222****3391', 经办人: '孙静', 手续费: 0.00 },
	},

	/* ── 调账 TZ ── 增加为正、减少为负 ── */
	{
		id: 'TZ202600003', type: 'adjustment', doc_no: 'TZ202600003', date: '2026-10-06', unit: '华东连锁超市', amount: 1200.00,
		status: 'approved', summary: '客户应收调整 · 补记折让返还',
		extra: { 调整方向: '调增', 原单据号: 'SK202600012', 调整原因: '季度返利返还是前少记', 关联科目: '应收账款' },
	},
	{
		id: 'TZ202600004', type: 'adjustment', doc_no: 'TZ202600004', date: '2026-10-10', unit: '京津乳品配送', amount: -2600.00,
		status: 'approved', summary: '供应商应付调整 · 到货破损扣款',
		extra: { 调整方向: '调减', 原单据号: 'FK202600023', 调整原因: '到货破损 26 件，按单价扣减', 关联科目: '应付账款' },
	},
	{
		id: 'TZ202600005', type: 'adjustment', doc_no: 'TZ202600005', date: '2026-10-16', unit: '朝阳一号店', amount: -350.00,
		status: 'rejected', summary: '门店借出款调整（附件不足）',
		extra: { 调整方向: '调减', 原单据号: 'BX202600003', 调整原因: '零用金实际领取与申请不符', 关联科目: '其他应收款' },
	},
	{
		id: 'TZ202600006', type: 'adjustment', doc_no: 'TZ202600006', date: '2026-10-20', unit: '恒丰超市连锁', amount: 5800.00,
		status: 'pending', summary: '客户应收调整 · 跨期单据补录',
		extra: { 调整方向: '调增', 原单据号: 'SK202600013', 调整原因: '9 月发货单跨期漏记', 关联科目: '应收账款' },
	},
	{
		id: 'TZ202600007', type: 'adjustment', doc_no: 'TZ202600007', date: '2026-10-27', unit: '沈阳冷链物流', amount: -1800.00,
		status: 'approved', summary: '供应商应付调整 · 冲销重复制单',
		extra: { 调整方向: '调减', 原单据号: 'FK202600021', 调整原因: '重复付款冲回', 关联科目: '应付账款' },
	},
]

/** 金额区间按绝对值命中（支出单录入正数区间即可筛出） */
const hitAmount = (amount, min, max) => {
	const v = Math.abs(Number(amount || 0))
	if (min !== '' && min !== null && min !== undefined && v < Number(min)) return false
	if (max !== '' && max !== null && max !== undefined && v > Number(max)) return false
	return true
}

/** 除 type 外的其余筛选条件（counts 与列表共用，保证徽标口径一致） */
const matchFilters = (row, f) => {
	if (f.doc_no && !row.doc_no.includes(String(f.doc_no).trim())) return false
	if (f.unit && !row.unit.includes(String(f.unit).trim())) return false
	if (f.start_date && row.date < f.start_date) return false
	if (f.end_date && row.date > f.end_date) return false
	if (f.status && row.status !== f.status) return false
	if (!hitAmount(row.amount, f.amount_min, f.amount_max)) return false
	return true
}

const sortRows = (list) => [...list].sort((a, b) => b.date.localeCompare(a.date) || b.doc_no.localeCompare(a.doc_no))

/**
 * 统一查询（模拟 GET /api/finance/documents）：返回分页切片 + 各类型徽标数量。
 * 后端就绪后本函数整体替换为一次 request 调用即可，页面侧无需改动。
 */
export function queryDocuments(params = {}) {
	const f = { ...params }
	const scoped = MOCK_DOCUMENTS.filter((r) => matchFilters(r, f))
	const counts = { all: scoped.length }
	MOCK_DOC_TYPES.forEach((t) => {
		counts[t.value] = scoped.filter((r) => r.type === t.value).length
	})
	const typeList = f.type ? scoped.filter((r) => r.type === f.type) : scoped
	const page = Math.max(1, Number(f.page || 1))
	const pageSize = Number(f.page_size || 20)
	const start = (page - 1) * pageSize
	return {
		list: typeList.slice(start, start + pageSize),
		total: typeList.length,
		counts,
	}
}

/** 往来单位下拉候选（模拟 GET /api/finance/documents/units） */
export function queryUnits() {
	return [...new Set(MOCK_DOCUMENTS.map((r) => r.unit))]
}
