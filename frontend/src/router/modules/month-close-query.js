/**
 * 财务（P0-3 月结查询 / 反月结）· 路由模块
 * 基建已用 import.meta.glob 自动聚合本目录文件，无需改 systemRoutes.js。
 * 说明：与「财务月结」向导页（/finance/month-close）并列，
 *       本模块为月结历史期间的查询/反月结页，不修改向导页任何文件。
 */
export default [
	{
		path: '/finance/month-close/query',
		name: 'FinanceMonthCloseQuery',
		component: () => import('../../views/finance/month-close-query/index.vue'),
		meta: { title: '月结查询', perm: 'fin:monthclose:view' },
	},
]