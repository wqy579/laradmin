/* ============================================================
 * D-24 欠条确认/驳回 · 路由模块
 * ============================================================ */

export default [
	{
		path: '/finance/iou-confirm',
		name: 'FinanceIouConfirm',
		component: () => import('../../views/finance/iou-confirm/index.vue'),
		meta: { title: '欠条确认', perm: 'fin:iou:confirm' },
	},
]
