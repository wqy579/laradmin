/**
 * 薪酬管理（F4）路由模块
 * 由 modules/index.js 自动聚合，勿改聚合入口。
 * path 与 hr 菜单迁移注册的路径逐条一致。
 */
export default [
	{
		path: '/hr/payroll',
		name: 'HrPayroll',
		component: () => import('../../views/hr/payroll/index.vue'),
		meta: { title: '工资核算', perm: 'hr:payroll:view' },
	},
	{
		path: '/hr/commission-flow',
		name: 'HrCommissionFlow',
		component: () => import('../../views/hr/commission-flow/index.vue'),
		meta: { title: '提成流水', perm: 'hr:commission-flow:view' },
	},
]
