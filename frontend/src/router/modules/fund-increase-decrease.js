
/**
 * 财务 · 资金增减单（D-22+D-23）· 路由模块
 * 基建已用 import.meta.glob 自动聚合本目录文件，无需改 systemRoutes.js。
 */
export default [
	{
		path: '/finance/fund-increase-decrease',
		name: 'FinanceFundIncreaseDecrease',
		component: () => import('../../views/finance/fund-increase-decrease/index.vue'),
		meta: { title: '资金增减', perm: 'fin:fund:adjust' },
	},
]
