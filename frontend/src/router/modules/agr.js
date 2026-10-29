/**
 * 协议管理（F4）路由模块
 * 由 systemRoutes.js 经 modules/index.js 自动聚合，勿改 systemRoutes.js。
 * path 须与 agr 菜单迁移注册的路径一致。
 */
export default [
	{
		path: '/agr/list',
		name: 'AgrList',
		component: () => import('../../views/agr/list/index.vue'),
		meta: { title: '协议档案', perm: 'agr:list:view' },
	},
	{
		path: '/agr/stats',
		name: 'AgrStats',
		component: () => import('../../views/agr/stats/index.vue'),
		meta: { title: '陈列协议统计', perm: 'agr:stats:view' },
	},
]
