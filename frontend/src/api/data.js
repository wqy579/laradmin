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
	// 开账向导（F6 第二批）：一个「公司 + 事业部（可空）+ 期初期间」一份四步向导
	opening: {
		// 首次打开后端会自动建 draft 向导 + 4 步 pending，返回体里的 steps 就是步骤条的数据源
		show: (params) => request.get('data/opening', { params }),
		// 别的页面判断是否已开账（只读，不会创建向导）
		lockStatus: (params) => request.get('data/opening/lock-status', { params }),
		saveStep: (step, data) => request.post(`data/opening/steps/${step}`, data),
		lock: (data) => request.post('data/opening/lock', data),
		unlock: (data) => request.post('data/opening/unlock', data),
		// 导入：走 multipart，onProgress 由 sImport 传进来显示进度条
		importFile: (data, onProgress) =>
			request.post('data/opening/import', data, {
				headers: { 'Content-Type': 'multipart/form-data' },
				onUploadProgress: onProgress,
			}),
		template: (kind) => request.get(`data/opening/template/${kind}`, { responseType: 'blob' }),
	},
}

export default dataApi
