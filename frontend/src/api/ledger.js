import request from '@/utils/request'

/**
 * 总账管理模块接口（/admin/ledger 前缀，JWT Bearer 鉴权）
 *
 * 三机物理隔离：A 用 ledger_ 表与 /admin/ledger 接口，独立菜单与路由，互不修改其他机器文件。
 */
const ledgerApi = {
	// 会计科目
	subject: {
		tree: { get: (params) => request.get('ledger/subjects/tree', { params }) },
		options: { get: (params) => request.get('ledger/subjects/options', { params }) },
		list: { get: (params) => request.get('ledger/subjects', { params }) },
		detail: { get: (id) => request.get(`ledger/subjects/${id}`) },
		add: { post: (params) => request.post('ledger/subjects', params) },
		edit: { put: (id, params) => request.put(`ledger/subjects/${id}`, params) },
		delete: { delete: (id) => request.delete(`ledger/subjects/${id}`) },
		toggleStatus: { put: (id) => request.put(`ledger/subjects/${id}/status`) },
		export: (params) => request.get('ledger/subjects/export', { params, responseType: 'blob' }),
	},
	// 期初余额
	opening: {
		list: { get: (params) => request.get('ledger/opening-balances', { params }) },
		save: { post: (params) => request.post('ledger/opening-balances', params) },
		balanceCheck: { post: (params) => request.post('ledger/opening-balances/balance-check', params) },
	},
	// 记账凭证
	voucher: {
		list: { get: (params) => request.get('ledger/vouchers', { params }) },
		detail: { get: (id) => request.get(`ledger/vouchers/${id}`) },
		add: { post: (params) => request.post('ledger/vouchers', params) },
		edit: { put: (id, params) => request.put(`ledger/vouchers/${id}`, params) },
		delete: { delete: (id) => request.delete(`ledger/vouchers/${id}`) },
		audit: { post: (id) => request.post(`ledger/vouchers/${id}/audit`) },
		batchAudit: { post: (params) => request.post('ledger/vouchers/batch-audit', params) },
		batchReverse: { post: (params) => request.post('ledger/vouchers/batch-reverse', params) },
		reverseAudit: { post: (id) => request.post(`ledger/vouchers/${id}/reverse-audit`) },
		reverse: { post: (id) => request.post(`ledger/vouchers/${id}/reverse`) },
		redReverse: { post: (id) => request.post(`ledger/vouchers/${id}/red-reverse`) },
		subjectLedger: { get: (params) => request.get('ledger/vouchers/subject-ledger', { params }) },
		// 业务单据手动补生成凭证（QA-05）：params = { bill_type, bill_id }
		generateFromBusiness: { post: (params) => request.post('ledger/vouchers/generate-from-business', params) },
		templates: { get: () => request.get('ledger/vouchers/templates') },
		saveTemplate: { post: (params) => request.post('ledger/vouchers/templates', params) },
		export: (params) => request.get('ledger/vouchers/export', { params, responseType: 'blob' }),
	},
	// 财务月结
	period: {
		list: { get: () => request.get('ledger/periods') },
		bootStatus: { get: () => request.get('ledger/periods/boot-status') },
		check: { post: (period) => request.post(`ledger/periods/${period}/check`) },
		carryForward: { post: (period) => request.post(`ledger/periods/${period}/carry-forward`) },
		close: { post: (period) => request.post(`ledger/periods/${period}/close`) },
		reopen: { post: (period) => request.post(`ledger/periods/${period}/reopen`) },
	},
	// 科目余额表
	balance: {
		list: { get: (params) => request.get('ledger/balance', { params }) },
		export: (params) => request.get('ledger/balance/export', { params, responseType: 'blob' }),
	},
	// 账簿报表
	report: {
		subjectBalance: { get: (params) => request.get('ledger/reports/subject-balance', { params }) },
		balanceSheet: { get: (params) => request.get('ledger/reports/balance-sheet', { params }) },
		incomeStatement: { get: (params) => request.get('ledger/reports/income-statement', { params }) },
		journal: { get: (params) => request.get('ledger/reports/journal', { params }) },
		export: (params) => request.get('ledger/reports/export', { params, responseType: 'blob' }),
	},
	// 总账驾驶舱（A8 首页 dashboard）
	dashboard: {
		overview: { get: () => request.get('ledger/dashboard/overview') },
		recentVouchers: { get: (params) => request.get('ledger/dashboard/recent-vouchers', { params }) },
	},
}

export default ledgerApi
