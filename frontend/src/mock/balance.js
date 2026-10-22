/**
 * P0-6 余额查询 · 本地 mock 数据
 *
 * 约束：本机禁止登录 laradmin 管理端做验证（会打断订单录入自动化），
 *       故页面一律使用本文件 mock，不调用真实接口。
 * TODO(api): 统一余额查询接口（按 dimension 切换度）：
 *   GET /api/finance/balances?dimension=customer|brand  余额列表
 *     - dimension=customer：按往来单位维度（Tab1 客户余额查询），支持 keyword/subject/unitType/balanceDate 筛选
 *     - dimension=brand：按品牌维度（Tab2 品牌余额查询），支持 brand/keyword 筛选
 *   后端表按 design_doc 卷01「数据表建议」预留：
 *   fin_balance_snapshot / fin_subject / fin_voucher_entry
 */

// 按往来单位类型：客户 / 业务员 / 供应商
export const MOCK_BALANCE_UNIT = [
	{ unitType: '客户', category: '客户', unit: '连凯贸易有限公司', brand: '蒙牛', subject: '应收账款', prepaid: 12000, receivable: 58600, balance: 46600, date: '2026-10-17' },
	{ unitType: '客户', category: '客户', unit: '华东食品配送中心', brand: '伊利', subject: '应收账款', prepaid: 0, receivable: 32400, balance: -32400, date: '2026-10-17' },
	{ unitType: '客户', category: '客户', unit: '鑫源百货商行', brand: '蒙牛', subject: '预收账款', prepaid: 8000, receivable: 15200, balance: -7200, date: '2026-10-17' },
	{ unitType: '客户', category: '客户', unit: '恒丰超市连锁', brand: '伊利', subject: '应收账款', prepaid: 20000, receivable: 41000, balance: 21000, date: '2026-10-17' },
	{ unitType: '客户', category: '客户', unit: '佳美便利店', brand: '康师傅', subject: '应收账款', prepaid: 0, receivable: 6800, balance: -6800, date: '2026-10-17' },
	{ unitType: '业务员', category: '业务员', unit: '张伟', brand: '蒙牛', subject: '应收账款', prepaid: 0, receivable: 26500, balance: -26500, date: '2026-10-17' },
	{ unitType: '业务员', category: '业务员', unit: '李芳', brand: '伊利', subject: '应收账款', prepaid: 3000, receivable: 18800, balance: -15800, date: '2026-10-17' },
	{ unitType: '业务员', category: '业务员', unit: '王强', brand: '康师傅', subject: '应收账款', prepaid: 5000, receivable: 12300, balance: -7300, date: '2026-10-17' },
	{ unitType: '供应商', category: '供应商', unit: '沈阳冷链物流', brand: '蒙牛', subject: '应收账款', prepaid: 0, receivable: 45000, balance: 45000, date: '2026-10-17' },
	{ unitType: '供应商', category: '供应商', unit: '京津乳品配送', brand: '伊利', subject: '应收账款', prepaid: 0, receivable: 31200, balance: 31200, date: '2026-10-17' },
]

// 科目字典（余额查询筛选用）
export const MOCK_SUBJECTS = ['应收账款', '预收账款', '门店借出款', '门店借入款']

// ---------- D-08 品牌余额查询（Tab2，按品牌维度） ----------
// 品牌字典（Tab2 筛选 Select 用）
export const MOCK_BRANDS = ['蒙牛', '伊利', '康师傅', '统一', '娃哈哈', '农夫山泉', '光明', '君乐宝', '达利园', '元气森林']

// 按品牌维度的余额数据（覆盖 10 个品牌，每行 = 品牌下某往来单位的余额快照）
export const MOCK_BALANCE_BRAND = [
	{ brand: '蒙牛', category: '客户', unit: '连凯贸易有限公司', subject: '应收账款', prepaid: 12000, receivable: 58600, total: 46600 },
	{ brand: '蒙牛', category: '供应商', unit: '沈阳冷链物流', subject: '应付账款', prepaid: 0, receivable: 45000, total: -45000 },
	{ brand: '蒙牛', category: '客户', unit: '鑫源百货商行', subject: '预收账款', prepaid: 8000, receivable: 15200, total: 7200 },
	{ brand: '伊利', category: '客户', unit: '华东食品配送中心', subject: '应收账款', prepaid: 0, receivable: 32400, total: -32400 },
	{ brand: '伊利', category: '客户', unit: '恒丰超市连锁', subject: '应收账款', prepaid: 20000, receivable: 41000, total: 21000 },
	{ brand: '伊利', category: '供应商', unit: '京津乳品配送', subject: '应付账款', prepaid: 0, receivable: 31200, total: -31200 },
	{ brand: '康师傅', category: '客户', unit: '佳美便利店', subject: '应收账款', prepaid: 0, receivable: 6800, total: -6800 },
	{ brand: '康师傅', category: '业务员', unit: '王强', subject: '应收账款', prepaid: 5000, receivable: 12300, total: 7300 },
	{ brand: '统一', category: '客户', unit: '宏图商行', subject: '应收账款', prepaid: 3500, receivable: 27600, total: 24100 },
	{ brand: '统一', category: '供应商', unit: '顺发批发部', subject: '应付账款', prepaid: 0, receivable: 18900, total: -18900 },
	{ brand: '娃哈哈', category: '客户', unit: '金鼎便利店', subject: '应收账款', prepaid: 1200, receivable: 9600, total: 8400 },
	{ brand: '娃哈哈', category: '客户', unit: '天天平价', subject: '预收账款', prepaid: 6000, receivable: 4300, total: 1700 },
	{ brand: '农夫山泉', category: '客户', unit: '好又多卖场', subject: '应收账款', prepaid: 0, receivable: 52300, total: -52300 },
	{ brand: '农夫山泉', category: '业务员', unit: '张伟', subject: '应收账款', prepaid: 2600, receivable: 26500, total: 23900 },
	{ brand: '光明', category: '客户', unit: '华东连锁超市', subject: '应收账款', prepaid: 9000, receivable: 35800, total: 26800 },
	{ brand: '光明', category: '供应商', unit: '丰乐食品', subject: '应付账款', prepaid: 0, receivable: 22400, total: -22400 },
	{ brand: '君乐宝', category: '客户', unit: '百姓大药房', subject: '应收账款', prepaid: 4800, receivable: 13600, total: 8800 },
	{ brand: '达利园', category: '客户', unit: '百姓大药房', subject: '应收账款', prepaid: 0, receivable: 7900, total: -7900 },
	{ brand: '达利园', category: '业务员', unit: '李芳', subject: '应收账款', prepaid: 3000, receivable: 18800, total: 15800 },
	{ brand: '元气森林', category: '客户', unit: '好又多卖场', subject: '预收账款', prepaid: 15000, receivable: 2100, total: 12900 },
	{ brand: '元气森林', category: '客户', unit: '天天平价', subject: '应收账款', prepaid: 0, receivable: 5400, total: -5400 },
]

// ---------- D-28 辅助单位余额查询（Tab3，按辅助单位维度：门店/部门/其他） ----------
// 按辅助单位类型的余额数据，字段与 MOCK_BALANCE_UNIT 保持一致（unitType/category/unit/subject/amount/date）
// TODO(api): GET /api/finance/balances?dimension=aux（支持 unitType/subject/keyword 筛选）
export const MOCK_BALANCE_AUX = [
	{ unitType: '门店', category: '门店', unit: '朝阳区一号店', brand: '蒙牛', subject: '门店借出款', balance: 15000, date: '2026-10-17' },
	{ unitType: '门店', category: '门店', unit: '朝阳区一号店', brand: '伊利', subject: '门店借入款', balance: -2000, date: '2026-10-17' },
	{ unitType: '门店', category: '门店', unit: '海淀区三号店', brand: '康师傅', subject: '门店借出款', balance: 8500, date: '2026-10-17' },
	{ unitType: '门店', category: '门店', unit: '海淀区三号店', brand: '统一', subject: '应收账款', balance: 4200, date: '2026-10-17' },
	{ unitType: '门店', category: '门店', unit: '丰台区五号店', brand: '蒙牛', subject: '门店借出款', balance: 11000, date: '2026-10-17' },
	{ unitType: '门店', category: '门店', unit: '丰台区五号店', brand: '农夫山泉', subject: '预收账款', balance: -3600, date: '2026-10-17' },
	{ unitType: '部门', category: '部门', unit: '销售一部', brand: '伊利', subject: '应收账款', balance: 12400, date: '2026-10-17' },
	{ unitType: '部门', category: '部门', unit: '销售一部', brand: '蒙牛', subject: '预收账款', balance: -3000, date: '2026-10-17' },
	{ unitType: '部门', category: '部门', unit: '销售二部', brand: '康师傅', subject: '应收账款', balance: 9200, date: '2026-10-17' },
	{ unitType: '部门', category: '部门', unit: '财务部', brand: '统一', subject: '门店借入款', balance: -1000, date: '2026-10-17' },
	{ unitType: '其他', category: '其他', unit: '临时项目组A', brand: '娃哈哈', subject: '应收账款', balance: 6700, date: '2026-10-17' },
	{ unitType: '其他', category: '其他', unit: '专项审计组', brand: '光明', subject: '门店借入款', balance: -5000, date: '2026-10-17' },
]
