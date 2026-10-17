/**
 * 积分管理（P0-11）· 本地 mock 数据
 *
 * 约束：本机禁止登录 laradmin 管理端做验证（会打断订单录入自动化），
 *       故所有页面一律使用本文件 mock，不调用真实接口。
 * TODO(api): 后端接口按 design_doc 卷02「数据表建议」预留：
 *   integral_rule  /  integral_txn（积分）
 */

// ---------- 往来单位（客户） ----------
export const MOCK_CUSTOMERS = [
	{ id: 1001, code: 'C0001', name: '连凯贸易有限公司' },
	{ id: 1002, code: 'C0002', name: '华东食品配送中心' },
	{ id: 1003, code: 'C0003', name: '鑫源百货商行' },
	{ id: 1004, code: 'C0004', name: '恒丰超市连锁' },
	{ id: 1005, code: 'C0005', name: '佳美便利店' },
	{ id: 1006, code: 'C0006', name: '盛达贸易集团' },
	{ id: 1007, code: 'C0007', name: '宏源批发部' },
	{ id: 1008, code: 'C0008', name: '顺达连锁超市' },
]

export function customerName(id) {
	const c = MOCK_CUSTOMERS.find((x) => x.id === id)
	return c ? c.name : String(id)
}

// ---------- 积分规则 ----------
export const MOCK_RULES = [
	{ id: 1, name: '常规积分规则', ratio: 1, effectiveDate: '2026-01-01', status: 1, remark: '1元消费累计1积分', createdAt: '2026-01-01' },
	{ id: 2, name: '会员升级积分', ratio: 1.5, effectiveDate: '2026-06-01', status: 1, remark: '升级会员期间1.5倍累计', createdAt: '2026-06-01' },
	{ id: 3, name: '双11专项积分', ratio: 2, effectiveDate: '2026-11-01', status: 0, remark: '双十一活动期间1元2分', createdAt: '2026-10-20' },
]

/** 当前启用中的规则（发放/核销页取比例用） */
export function activeRule() {
	return MOCK_RULES.find((r) => r.status === 1) || MOCK_RULES[0]
}

// ---------- 积分流水 ----------
// type: 'grant' 发放(JF) / 'use' 核销(HX)
// points: 有符号变动（发放为正，核销为负）
// balanceBefore / balanceAfter: 变动前 / 变动后余额
export const MOCK_TXN = [
	{ no: 'JF2026090001', date: '2026-09-03', customerId: 1005, type: 'grant', points: 600, balanceBefore: 0, balanceAfter: 600, sourceOrder: 'SO2026090031', remark: '订单积分' },
	{ no: 'HX2026090001', date: '2026-09-10', customerId: 1005, type: 'use', points: -200, balanceBefore: 600, balanceAfter: 400, sourceOrder: 'SO2026091002', remark: '购物抵扣' },
	{ no: 'HX2026100001', date: '2026-10-12', customerId: 1005, type: 'use', points: -400, balanceBefore: 400, balanceAfter: 0, sourceOrder: 'SO2026101205', remark: '清仓结算' },

	{ no: 'JF2026080001', date: '2026-08-05', customerId: 1001, type: 'grant', points: 1500, balanceBefore: 500, balanceAfter: 2000, sourceOrder: 'SO2026080501', remark: '上期结转积分' },
	{ no: 'JF2026090002', date: '2026-09-05', customerId: 1001, type: 'grant', points: 2000, balanceBefore: 2000, balanceAfter: 4000, sourceOrder: 'SO2026090501', remark: '月度对账返积分' },
	{ no: 'HX2026090002', date: '2026-09-20', customerId: 1001, type: 'use', points: -500, balanceBefore: 4000, balanceAfter: 3500, sourceOrder: 'SO2026092003', remark: '抵扣运费' },
	{ no: 'JF2026100002', date: '2026-10-02', customerId: 1001, type: 'grant', points: 1200, balanceBefore: 3500, balanceAfter: 4700, sourceOrder: 'SO2026100201', remark: '节日活动返积分' },
	{ no: 'HX2026100002', date: '2026-10-10', customerId: 1001, type: 'use', points: -300, balanceBefore: 4700, balanceAfter: 4400, sourceOrder: 'SO2026101001', remark: '购物抵扣' },

	{ no: 'JF2026090003', date: '2026-09-08', customerId: 1002, type: 'grant', points: 800, balanceBefore: 0, balanceAfter: 800, sourceOrder: 'SO2026090801', remark: '订单积分' },
	{ no: 'HX2026100003', date: '2026-10-05', customerId: 1002, type: 'use', points: -800, balanceBefore: 800, balanceAfter: 0, sourceOrder: 'SO2026100502', remark: '全量核销' },

	{ no: 'JF2026080002', date: '2026-08-20', customerId: 1003, type: 'grant', points: 2000, balanceBefore: 500, balanceAfter: 2500, sourceOrder: 'SO2026082001', remark: '上期结转积分' },
	{ no: 'JF2026090004', date: '2026-09-15', customerId: 1003, type: 'grant', points: 3000, balanceBefore: 2500, balanceAfter: 5500, sourceOrder: 'SO2026091501', remark: '大额订单返积分' },
	{ no: 'HX2026090003', date: '2026-09-28', customerId: 1003, type: 'use', points: -1000, balanceBefore: 5500, balanceAfter: 4500, sourceOrder: 'SO2026092804', remark: '兑换礼品' },
	{ no: 'JF2026100003', date: '2026-10-08', customerId: 1003, type: 'grant', points: 500, balanceBefore: 4500, balanceAfter: 5000, sourceOrder: 'SO2026100801', remark: '手动补录积分' },

	{ no: 'JF2026100004', date: '2026-10-01', customerId: 1004, type: 'grant', points: 1500, balanceBefore: 0, balanceAfter: 1500, sourceOrder: 'SO2026100101', remark: '新客户首单积分' },

	{ no: 'JF2026090005', date: '2026-09-18', customerId: 1006, type: 'grant', points: 2500, balanceBefore: 0, balanceAfter: 2500, sourceOrder: 'SO2026091801', remark: '团购返积分' },
	{ no: 'JF2026100005', date: '2026-10-06', customerId: 1006, type: 'grant', points: 800, balanceBefore: 2500, balanceAfter: 3300, sourceOrder: 'SO2026100602', remark: '追加返积分' },

	{ no: 'JF2026090006', date: '2026-09-22', customerId: 1007, type: 'grant', points: 900, balanceBefore: 0, balanceAfter: 900, sourceOrder: 'SO2026092201', remark: '订单积分' },
	{ no: 'HX2026100004', date: '2026-10-09', customerId: 1007, type: 'use', points: -150, balanceBefore: 900, balanceAfter: 750, sourceOrder: 'SO2026100903', remark: '小额抵扣' },

	{ no: 'JF2026100006', date: '2026-10-11', customerId: 1008, type: 'grant', points: 400, balanceBefore: 0, balanceAfter: 400, sourceOrder: 'SO2026101101', remark: '订单积分' },
]

/** 某客户当前可用积分（按日期倒序取最后一条 balanceAfter） */
export function availablePoints(customerId) {
	const list = MOCK_TXN.filter((t) => t.customerId === customerId)
		.sort((a, b) => (a.date < b.date ? 1 : a.date > b.date ? -1 : 0))
	return list.length ? list[0].balanceAfter : 0
}
