/**
 * 资金看板（B8）· 路由模块
 * 基建用 import.meta.glob 自动聚合本目录，无需改 systemRoutes.js。
 */
export default [
	{
		path: '/finance/fund-dashboard',
		name: 'FinanceFundDashboard',
		component: () => import('../../views/finance/fund-dashboard/index.vue'),
		meta: { title: '资金看板', icon: 'TrendCharts', perm: 'fin:fund-dashboard:view' },
	},
]
