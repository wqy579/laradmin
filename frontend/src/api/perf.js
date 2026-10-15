import request from '@/utils/request'

/**
 * 机器 C（绩效分析）接口层。
 * 统一前缀 perf，与后端 /admin/perf 逐条对齐（任务书 §九）。
 * 导出一律 responseType:'blob'，内容与界面筛选一致（服务端生成）。
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
		export: { get: (params) => request.get('perf/commission-rules/export', { params, responseType: 'blob' }) },
	},
	// 提成计算（preview 试算不落库；export 与 preview 同参导出明细）
	commission: {
		preview: { post: (params) => request.post('perf/commission/preview', params) },
		export: { post: (params) => request.post('perf/commission/export', params, { responseType: 'blob' }) },
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
	// 多维利润查询（路径版五维度，与任务书 §九 一致；trend 保留）
	profit: {
		orders: { get: (params) => request.get('perf/profit/orders', { params }) },
		customers: { get: (params) => request.get('perf/profit/customers', { params }) },
		employees: { get: (params) => request.get('perf/profit/employees', { params }) },
		products: { get: (params) => request.get('perf/profit/products', { params }) },
		brands: { get: (params) => request.get('perf/profit/brands', { params }) },
		trend: { get: (params) => request.get('perf/profit/trend', { params }) },
		export: (dimension, params) => request.get('perf/profit/export', { params: { ...params, dimension }, responseType: 'blob' }),
	},
	// 应收账龄
	ar: {
		aging: { get: (params) => request.get('perf/ar/aging', { params }) },
		details: { get: (cid, params) => request.get(`perf/ar/aging/${cid}/details`, { params }) },
		export: { get: (params) => request.get('perf/ar/aging/export', { params, responseType: 'blob' }) },
	},
	// 发货未收款
	delivery: {
		uncollected: { get: (params) => request.get('perf/delivery/uncollected', { params }) },
		export: { get: (params) => request.get('perf/delivery/uncollected/export', { params, responseType: 'blob' }) },
	},
	// 库存预警（四条路径别名 + 概览 + 一键转采购申请草稿）
	warning: {
		overview: { get: (params) => request.get('perf/warning/overview', { params }) },
		safetyStock: { get: (params) => request.get('perf/warning/safety-stock', { params }) },
		expiry: { get: (params) => request.get('perf/warning/expiry', { params }) },
		sellableDays: { get: (params) => request.get('perf/warning/sellable-days', { params }) },
		stockException: { get: (params) => request.get('perf/warning/stock-exception', { params }) },
		purchaseDraft: { post: (params) => request.post('perf/warning/safety-stock/purchase-draft', params) },
	},
	// 销售看板
	dashboard: {
		overview: { get: (params) => request.get('perf/dashboard/sales-overview', { params }) },
	},
	// 分析 + 导出
	analysis: {
		products: { get: (params) => request.get('perf/analysis/products', { params }) },
		customers: { get: (params) => request.get('perf/analysis/customers', { params }) },
		employees: { get: (params) => request.get('perf/analysis/employees', { params }) },
		growthReturn: { get: (params) => request.get('perf/analysis/growth-return', { params }) },
		productsExport: { get: (params) => request.get('perf/analysis/products/export', { params, responseType: 'blob' }) },
		customersExport: { get: (params) => request.get('perf/analysis/customers/export', { params, responseType: 'blob' }) },
		employeesExport: { get: (params) => request.get('perf/analysis/employees/export', { params, responseType: 'blob' }) },
		growthReturnExport: { get: (params) => request.get('perf/analysis/growth-return/export', { params, responseType: 'blob' }) },
	},
	// 报表
	reports: {
		returnSummary: { get: (params) => request.get('perf/reports/return-summary', { params }) },
	},
	// 销售目标
	target: {
		list: { get: (params) => request.get('perf/sales-targets', { params }) },
		save: { post: (params) => request.post('perf/sales-targets', params) },
	},
}

export default perfApi
