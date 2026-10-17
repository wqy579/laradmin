/**
 * 财务月结 5 步向导（机 B / P0-2）
 * 双保险静态路由，菜单来自后端 auth_permission。
 * 路由文件独立成模块，勿改 systemRoutes.js。
 */
export default [
	{
		path: '/finance/month-close',
		name: 'FinanceMonthClose',
		component: () => import('@/views/finance/month-close/index.vue'),
		meta: { title: '财务月结' },
	},
]
