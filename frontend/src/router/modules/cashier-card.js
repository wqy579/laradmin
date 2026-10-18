/**
 * 出纳资金 · 刷卡收款记录（P0-10）路由模块
 * 由 systemRoutes.js 经 modules/index.js 自动聚合，勿改 systemRoutes.js。
 */
export default [
	{
		path: '/cashier/card',
		name: 'CashierCard',
		component: () => import('../../views/cashier/card/index.vue'),
		meta: { title: '刷卡收款记录', perm: 'cash:card:view' },
	},
]