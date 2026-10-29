import request from '@/utils/request'

/**
 * 协议管理模块接口（/admin/agr 前缀，JWT Bearer 鉴权）
 *
 * F4：协议档案（增删改查 + 终止 + 登记兑现）与陈列协议统计（只读聚合）。
 * 兑现率 = 已兑现额 / 协议额，由服务端算好返回 redemption_rate，前端不再自算。
 */
const agrApi = {
	agreement: {
		options: { get: (params) => request.get('agr/agreements/options', { params }) },
		list: { get: (params) => request.get('agr/agreements', { params }) },
		detail: { get: (id) => request.get(`agr/agreements/${id}`) },
		add: { post: (data) => request.post('agr/agreements', data) },
		edit: { put: (id, data) => request.put(`agr/agreements/${id}`, data) },
		delete: { delete: (id) => request.delete(`agr/agreements/${id}`) },
		terminate: { post: (id, data) => request.post(`agr/agreements/${id}/terminate`, data) },
		redeem: { post: (id, data) => request.post(`agr/agreements/${id}/redeem`, data) },
		export: (params) => request.get('agr/agreements/export', { params, responseType: 'blob' }),
	},
	stats: {
		get: (params) => request.get('agr/agreements/stats', { params }),
	},
}

export default agrApi
