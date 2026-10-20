/**
 * 财务（P0-7 对账单）· 路由模块
 * 基建已用 import.meta.glob 自动聚合本目录文件，无需改 systemRoutes.js。
 * 对账单：客户/供应商往来账目，按期间（月）汇总应收/应付发生额与余额，
 *           明细列表 + 对账状态标记。
 */
export default [
	{
		path: '/finance/statement',
		name: 'FinanceStatement',
		component: () => import('../../views/finance/statement/index.vue'),
		meta: { title: '对账单', perm: 'fin:statement:view' },
	},
]