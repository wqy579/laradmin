/**
 * 财务 · 总账管理 · 财务凭证（P0-1）路由模块
 * 基建已用 import.meta.glob 自动聚合本目录文件，无需改 systemRoutes.js。
 */
export default [
	{
		path: '/finance/voucher',
		name: 'FinanceVoucher',
		component: () => import('../../views/finance/voucher/index.vue'),
		meta: { title: '财务凭证', perm: 'fin:voucher:view' },
	},
]