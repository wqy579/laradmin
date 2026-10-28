/**
 * 其它收入单（B9）· 路由模块
 * 规范路径收敛为单数 /business/other-income（与后端路由、菜单一致）；
 * systemRoutes 里旧的复数 /business/other-incomes 条目保留，兼容历史链接。
 */
export default [
	{
		path: '/business/other-income',
		name: 'BusinessOtherIncome',
		component: () => import('../../views/business/other-income/index.vue'),
		meta: { title: '其它收入单', icon: 'Wallet', perm: 'fin:other-income:view' },
	},
]
