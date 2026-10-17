/**
 * 财务（P0-6 余额查询）· 路由模块
 * 基建已用 import.meta.glob 自动聚合本目录文件，无需改 systemRoutes.js。
 * 说明：本次仅实现 Tab1 客户余额查询；Tab2 辅助单位余额查询按任务要求留占位。
 */
export default [
	{
		path: '/finance/balance',
		name: 'FinanceBalance',
		component: () => import('../../views/finance/balance/index.vue'),
		meta: { title: '余额查询', perm: 'fin:balance:view' },
	},
]
