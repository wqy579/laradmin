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
