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
	{
		path: '/asset/purchase',
		name: 'AssetPurchase',
		component: () => import('../../views/asset/purchase/index.vue'),
		meta: { title: '资产购置单', perm: 'asset:purchase' },
	},
	{
		path: '/asset/depreciation',
		name: 'AssetDepreciation',
		component: () => import('../../views/asset/depreciation/index.vue'),
		meta: { title: '资产折旧单', perm: 'asset:depreciate' },
	},
	{
		path: '/asset/sale',
		name: 'AssetSale',
		component: () => import('../../views/asset/sale/index.vue'),
		meta: { title: '资产变卖单', perm: 'asset:sale' },
	},
]
