/**
 * 客户盘点（QA4 B-08）路由模块
 * 由 systemRoutes.js 经 modules/index.js 自动聚合，勿改 systemRoutes.js。
 */
export default [
	{
		path: '/business/visit/customer-stock',
		name: 'CustomerStock',
		component: () => import('../../views/business/visit/customer-stock/index.vue'),
		meta: { title: '客户盘点', perm: '' },
	},
]
