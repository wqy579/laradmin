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
	// 帮助中心（F6 第三批）
	help: {
		list: { get: (params) => request.get('data/help', { params }) },
		categories: { get: (params) => request.get('data/help/categories', { params }) },
		detail: { get: (id) => request.get(`data/help/${id}`) },
		add: { post: (data) => request.post('data/help', data) },
		edit: { put: (id, data) => request.put(`data/help/${id}`, data) },
		delete: { delete: (id) => request.delete(`data/help/${id}`) },
		// 阅读数只在「阅读」这个动作上 +1，编辑时打开详情不算
		read: { post: (id) => request.post(`data/help/${id}/read`) },
		publish: { post: (id) => request.post(`data/help/${id}/publish`) },
		withdraw: { post: (id) => request.post(`data/help/${id}/withdraw`) },
		// 预览必须由后端净化后再回显：前端直接 v-html 原始正文等于管理员自 XSS，
		// 而且会渲染出「存进去根本不存在」的标签（所见非所得）
		preview: { post: (data) => request.post('data/help/preview', data) },
	},
	// 下载中心（F6 第三批）
	download: {
		list: { get: (params) => request.get('data/download', { params }) },
		categories: { get: (params) => request.get('data/download/categories', { params }) },
		detail: { get: (id) => request.get(`data/download/${id}`) },
		// 上传走手动 FormData（不用 el-upload 的 action）：action 直传带不上 JWT 头，
		// 而且后端的安全拒绝原因（"探测到 text/html"）会被 el-upload 的回调丢掉
		upload: (data) =>
			request.post('data/download', data, {
				headers: { 'Content-Type': 'multipart/form-data' },
			}),
		edit: { put: (id, data) => request.put(`data/download/${id}`, data) },
		delete: { delete: (id) => request.delete(`data/download/${id}`) },
		download: (id) => request.get(`data/download/${id}/download`, { responseType: 'blob' }),
	},
}

export default dataApi
