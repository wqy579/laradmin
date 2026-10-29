import request from '@/utils/request'

/**
 * 薪酬模块接口层（F4）。
 * 路径与后端 modules/Hr/routes/admin.php 逐条对齐（统一前缀 hr）。
 * 工资单不提供新增接口——只能按期间从 perf 提成单「生成」。
 */
const hrApi = {
	// 工资核算
	payroll: {
		options: { get: (params) => request.get('hr/payrolls/options', { params }) },
		list: { get: (params) => request.get('hr/payrolls', { params }) },
		detail: { get: (id) => request.get(`hr/payrolls/${id}`) },
		generate: (data) => request.post('hr/payrolls/generate', data),
		edit: { put: (id, data) => request.put(`hr/payrolls/${id}`, data) },
		delete: { delete: (id) => request.delete(`hr/payrolls/${id}`) },
		pay: { post: (id, data) => request.post(`hr/payrolls/${id}/pay`, data) },
		export: (params) => request.get('hr/payrolls/export', { params, responseType: 'blob' }),
	},
	// 提成流水（只读，来自发放时对 perf 提成单的快照）
	commissionFlow: {
		options: { get: (params) => request.get('hr/commission-flows/options', { params }) },
		list: { get: (params) => request.get('hr/commission-flows', { params }) },
		reconcile: { get: (params) => request.get('hr/commission-flows/reconcile', { params }) },
		export: (params) => request.get('hr/commission-flows/export', { params, responseType: 'blob' }),
	},
}

export default hrApi
