/**
 * 财务 · 总账管理 · 日记账（P0-4）
 * 路由经 modules/index.js 自动聚合，勿改 systemRoutes.js。
 */
export default [
	{
		path: '/finance/journal',
		name: 'FinanceJournal',
		component: () => import('@/views/finance/journal/index.vue'),
		meta: { title: '日记账', icon: 'Notebook', perm: 'fin:journal:view' },
	},
]
