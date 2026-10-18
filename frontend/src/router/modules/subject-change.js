/**
 * 财务 · 总账管理 · 科目变化明细表（P0-5）
 * 路由经 modules/index.js 自动聚合，勿改 systemRoutes.js。
 */
export default [
	{
		path: '/finance/subject-change',
		name: 'FinanceSubjectChange',
		component: () => import('@/views/finance/subject-change/index.vue'),
		meta: { title: '科目变化明细表', icon: 'Folder', perm: 'fin:subjectchange:view' },
	},
]
