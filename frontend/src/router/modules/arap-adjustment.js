/**
 * 财务 · 应收应付调整单（D-18）· 路由模块
 * 基建已用 import.meta.glob 自动聚合本目录文件，无需改 systemRoutes.js。
 */
export default [
	{
		path: '/finance/arap-adjustment',
		name: 'FinanceArapAdjustment',
		component: () => import('../../views/finance/arap-adjustment/index.vue'),
		meta: { title: '应收应付调整', perm: 'fin:arap:adjust' },
	},
]
