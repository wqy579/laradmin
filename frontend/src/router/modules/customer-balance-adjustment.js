/**
 * 财务 · 客户余额调整单（D-20）· 路由模块
 * 基建已用 import.meta.glob 自动聚合本目录文件，无需改 systemRoutes.js。
 */
export default [
	{
		path: '/finance/customer-balance-adjustment',
		name: 'FinanceCustomerBalanceAdjustment',
		component: () => import('../../views/finance/customer-balance-adjustment/index.vue'),
		meta: { title: '客户余额调整', perm: 'fin:balance:adjust' },
	},
]
