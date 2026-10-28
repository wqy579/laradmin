/**
 * 财务单据统一查询门户（D-14）· 路由模块
 * 由 modules/index.js 的 import.meta.glob 自动聚合，勿改 systemRoutes.js。
 * 四类单据（费用报销 / 收付款 / 存取款 / 调账）的统一查询入口，纯前端 mock。
 */
export default [
	{
		path: '/finance/document-portal',
		name: 'FinanceDocumentPortal',
		component: () => import('@/views/finance/document-portal/index.vue'),
		meta: { title: '财务单据查询', icon: 'Search', perm: 'fin:document:query' },
	},
]
