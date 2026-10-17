/**
 * 出纳资金 · 收入上交款族（P0-10）路由模块
 * 由 systemRoutes.js 经 modules/index.js 自动聚合，勿改 systemRoutes.js。
 */
export default [
	{
		path: '/cash/handover',
		name: 'CashHandover',
		component: () => import('../../views/cash/handover/index.vue'),
		meta: { title: '收入上交款单', perm: 'cash:handover' },
	},
	{
		path: '/cash/handover-query',
		name: 'CashHandoverQuery',
		component: () => import('../../views/cash/handover-query/index.vue'),
		meta: { title: '上交款查询', perm: 'cash:handover' },
	},
	{
		path: '/cash/card-txn',
		name: 'CashCardTxn',
		component: () => import('../../views/cash/card-txn/index.vue'),
		meta: { title: '刷卡收款记录', perm: 'cash:card:view' },
	},
]
