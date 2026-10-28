import request from '@/utils/request'

/**
 * 出纳资金模块接口（/admin/cash 前缀，JWT Bearer 鉴权）
 *
 * D 域物理隔离：Cash 模块独立表（cash_）与接口前缀（/admin/cash）。
 */
const cashApi = {
	// 现金账户收支查询
	account: {
		options: { get: (params) => request.get('cash/accounts/options', { params }) },
		flows: { get: (id, params) => request.get(`cash/accounts/${id}/flows`, { params }) },
		export: (id, params) => request.get(`cash/accounts/${id}/export`, { params, responseType: 'blob' }),
	},
	// 刷卡收款记录
	card: {
		options: { get: (params) => request.get('cash/card-txns/options', { params }) },
		list: { get: (params) => request.get('cash/card-txns', { params }) },
		refund: { post: (id) => request.post(`cash/card-txns/${id}/refund`) },
		export: (params) => request.get('cash/card-txns/export', { params, responseType: 'blob' }),
	},
	// 积分（D5）：规则 + 发放
	integral: {
		rule: {
			list: { get: (params) => request.get('cash/integral/rules', { params }) },
			detail: { get: (id) => request.get(`cash/integral/rules/${id}`) },
			add: { post: (data) => request.post('cash/integral/rules', data) },
			edit: { put: (id, data) => request.put(`cash/integral/rules/${id}`, data) },
			toggle: { post: (id) => request.post(`cash/integral/rules/${id}/toggle`) },
			delete: { delete: (id) => request.delete(`cash/integral/rules/${id}`) },
			export: (params) => request.get('cash/integral/rules/export', { params, responseType: 'blob' }),
		},
		grant: {
			list: { get: (params) => request.get('cash/integral/grants', { params }) },
			detail: { get: (id) => request.get(`cash/integral/grants/${id}`) },
			add: { post: (data) => request.post('cash/integral/grants', data) },
			edit: { put: (id, data) => request.put(`cash/integral/grants/${id}`, data) },
			submit: { post: (id) => request.post(`cash/integral/grants/${id}/submit`) },
			audit: { post: (id) => request.post(`cash/integral/grants/${id}/audit`) },
			void: { post: (id) => request.post(`cash/integral/grants/${id}/void`) },
			export: (params) => request.get('cash/integral/grants/export', { params, responseType: 'blob' }),
		},
	},

	// 收入上交款 + 上交款查询
	handover: {
		options: { get: (params) => request.get('cash/handover/options', { params }) },
		list: { get: (params) => request.get('cash/handover', { params }) },
		detail: { get: (id) => request.get(`cash/handover/${id}`) },
		create: (data) => request.post('cash/handover', data),
		orders: { get: (params) => request.get('cash/handover/orders', { params }) },
		confirm: { post: (id) => request.post(`cash/handover/${id}/confirm`) },
		batchConfirm: (ids) => request.post('cash/handover/batch-confirm', { ids }),
		export: (params) => request.get('cash/handover/export', { params, responseType: 'blob' }),
	},
	// 出纳工作台（只读聚合）
	dashboard: {
		summary: { get: () => request.get('cash/dashboard/summary') },
		trend: { get: (params) => request.get('cash/dashboard/trend', { params }) },
	},
}

export default cashApi
