import request from '@/utils/request'

/**
 * 基础档案接口层（F6）。
 * 路径与后端 modules/Archive/routes/admin.php 逐条对齐（统一前缀 data）。
 * 状态是字符串枚举 enabled / disabled（不是品牌档案的 is_active 布尔），
 * 与公司/事业部表里的 status 列一致，批量启停也走同一字段。
 */
const dataApi = {
	// 公司档案
	company: {
		list: { get: (params) => request.get('data/company', { params }) },
		options: { get: (params) => request.get('data/company/options', { params }) },
		detail: { get: (id) => request.get(`data/company/${id}`) },
		add: { post: (data) => request.post('data/company', data) },
		edit: { put: (id, data) => request.put(`data/company/${id}`, data) },
		delete: { delete: (id) => request.delete(`data/company/${id}`) },
		batchUpdateStatus: { post: (data) => request.post('data/company/batch-status', data) },
		export: (params) => request.get('data/company/export', { params, responseType: 'blob' }),
	},
	// 事业部档案
	division: {
		list: { get: (params) => request.get('data/division', { params }) },
		options: { get: (params) => request.get('data/division/options', { params }) },
		detail: { get: (id) => request.get(`data/division/${id}`) },
		add: { post: (data) => request.post('data/division', data) },
		edit: { put: (id, data) => request.put(`data/division/${id}`, data) },
		delete: { delete: (id) => request.delete(`data/division/${id}`) },
		batchUpdateStatus: { post: (data) => request.post('data/division/batch-status', data) },
		export: (params) => request.get('data/division/export', { params, responseType: 'blob' }),
	},
}

export default dataApi
