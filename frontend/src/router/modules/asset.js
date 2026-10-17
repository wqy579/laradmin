/**
 * 固定资产（P0-9）路由模块
 * 由 systemRoutes.js 经 modules/index.js 自动聚合，勿改 systemRoutes.js。
 */
export default [
	{
		path: '/asset/card',
		name: 'AssetCard',
		component: () => import('../../views/asset/card/index.vue'),
		meta: { title: '固定资产台账', perm: 'asset:view' },
	},
]
