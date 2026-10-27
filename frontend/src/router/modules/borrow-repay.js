
/**
 * 财务 · 借款还款 · 路由模块
 * 基建已用 import.meta.glob 自动聚合本目录文件，无需改 systemRoutes.js。
 */
export default [
	{
		path: '/finance/borrow-repay',
		name: 'FinanceBorrowRepay',
		component: () => import('../../views/finance/borrow-repay/index.vue'),
		meta: { title: '借款还款', perm: 'fin:borrow:manage' },
	},
]
