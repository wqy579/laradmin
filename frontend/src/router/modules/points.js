/**
 * 积分管理（P0-11）· 路由模块
 * 基建已用 import.meta.glob 自动聚合本目录文件，无需改 systemRoutes.js。
 * 说明：sidebar 菜单来自后端 auth_permission（需菜单 Seeder 才能在侧边栏可见），
 *       本文件保证路由可直达（URL 可访问）。
 */
export default [
	{
		path: '/points/rule',
		name: 'PointsRule',
		component: () => import('../../views/points/rule/index.vue'),
		meta: { title: '积分规则', perm: 'cash:integral:rule' },
	},
]
