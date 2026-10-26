/**
 * 出纳资金 · 现金账户收支查询（D-29）路由模块
 * 由 systemRoutes.js 经 modules/index.js 自动聚合，勿改 systemRoutes.js。
 */
export default [
	{
		path: '/cash/account-flow',
		name: 'CashAccountFlow',
		component: () => import('../../views/cash/account-flow/index.vue'),
		meta: { title: '现金账户收支查询', perm: 'cash:query' },
	},
]
