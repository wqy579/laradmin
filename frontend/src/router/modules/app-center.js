/**
 * 功能应用中心（QA4 B-10/B-11）· 路由模块
 * 由 modules/index.js 的 import.meta.glob 自动聚合，勿改 systemRoutes.js。
 * 收纳全站功能入口并支持 localStorage 收藏「常用菜单」，纯前端导航页。
 */
export default [
	{
		path: '/system/app-center',
		name: 'AppCenter',
		component: () => import('../../views/system/app-center/index.vue'),
		meta: { title: '功能应用中心', perm: '' },
	},
]
