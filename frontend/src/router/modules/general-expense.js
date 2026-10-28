/**
 * 一般费用单（B9）· 路由模块
 * 基建用 import.meta.glob 自动聚合本目录，无需改 systemRoutes.js。
 * 本文件在 systemRoutes 尾部 push，同 path 后注册者生效，覆盖旧的无权限 meta 条目。
 */
export default [
	{
		path: '/business/general-expense',
		name: 'BusinessGeneralExpense',
		component: () => import('../../views/business/general-expense/index.vue'),
		meta: { title: '一般费用单', icon: 'Money', perm: 'fin:general-expense:view' },
	},
]
