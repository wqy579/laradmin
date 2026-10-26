/**
 * 财务 · 供应商余额调整单（D-21）· 路由模块
 * 基建已用 import.meta.glob 自动聚合本目录文件，无需改 systemRoutes.js。
 */
export default [
	{
		path: '/finance/supplier-balance-adjustment',
		name: 'FinanceSupplierBalanceAdjustment',
		component: () => import('../../views/finance/supplier-balance-adjustment/index.vue'),
		meta: { title: '供应商余额调整', perm: 'fin:balance:adjust' },
	},
]
