import request from '@/utils/request'

/**
 * 机器 C（绩效分析）接口层。
 * 统一前缀 perf，与后端 /admin/perf 对齐。
 */
const perfApi = {
	// 提成方案
	rule: {
		list: { get: (params) => request.get('perf/commission-rules', { params }) },
		detail: { get: (id) => request.get(`perf/commission-rules/${id}`) },
		add: { post: (params) => request.post('perf/commission-rules', params) },
		edit: { put: (id, params) => request.put(`perf/commission-rules/${id}`, params) },
		delete: { delete: (id) => request.delete(`perf/commission-rules/${id}`) },
		copy: { post: (id) => request.post(`perf/commission-rules/${id}/copy`) },
		estimate: { get: (params) => request.get('perf/commission-rules/estimate', { params }) },
	},
	// 提成计算
	commission: {
		preview: { post: (params) => request.post('perf/commission/preview', params) },
		generate: { post: (params) => request.post('perf/commission/generate', params) },
	},
	// 提成单
	order: {
		list: { get: (params) => request.get('perf/commission-orders', { params }) },
		detail: { get: (id) => request.get(`perf/commission-orders/${id}`) },
		audit: { post: (id) => request.post(`perf/commission-orders/${id}/audit`) },
		reject: { post: (id) => request.post(`perf/commission-orders/${id}/reject`) },
		pay: { post: (id) => request.post(`perf/commission-orders/${id}/pay`) },
		export: (id) => request.get(`perf/commission-orders/${id}/export`, { responseType: 'blob' }),
	},
	// 利润查询
	profit: {
		index: { get: (params) => request.get('perf/profit', { params }) },
		trend: { get: (params) => request.get('perf/profit/trend', { params }) },
	},
	// 应收账龄
	ar: {
		aging: { get: (params) => request.get('perf/ar/aging', { params }) },
		details: { get: (cid) => request.get(`perf/ar/aging/${cid}/details`) },
	},
	// 发货未收款
	delivery: {
		uncollected: { get: (params) => request.get('perf/delivery/uncollected', { params }) },
	},
	// 库存预警
	warning: {
		overview: { get: () => request.get('perf/warning/overview') },
		list: { get: (params) => request.get('perf/warning', { params }) },
	},
	// 销售看板
	dashboard: {
		overview: { get: (params) => request.get('perf/dashboard/sales-overview', { params }) },
	},
	// 分析
	analysis: {
		products: { get: (params) => request.get('perf/analysis/products', { params }) },
		customers: { get: (params) => request.get('perf/analysis/customers', { params }) },
		employees: { get: (params) => request.get('perf/analysis/employees', { params }) },
		growthReturn: { get: (params) => request.get('perf/analysis/growth-return', { params }) },
	},
}

export default perfApi
