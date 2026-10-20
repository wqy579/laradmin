/**
 * P0-6 余额查询 · 本地 mock 数据
 *
 * 约束：本机禁止登录 laradmin 管理端做验证（会打断订单录入自动化），
 *       故页面一律使用本文件 mock，不调用真实接口。
 * TODO(api): 后端接口按 design_doc 卷01「数据表建议」预留：
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
