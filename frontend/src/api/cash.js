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
}

export default cashApi
