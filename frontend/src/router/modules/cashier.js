/**
 * 出纳资金单族（P0-8）· 路由模块
 * 由 systemRoutes.js 经 modules/index.js 自动聚合，勿改 systemRoutes.js。
 * 前缀 /cashier，权限前缀 cash:。
 */
export default [
	{
		path: '/cashier/deposit',
		name: 'CashierDeposit',
		component: () => import('../../views/cashier/deposit/index.vue'),
		meta: { title: '存取款转账单', perm: 'cash:deposit' },
	},
]