import request from '@/utils/request'

const businessApi = {
	// 产品
	product: {
		list: { get: (params) => request.get('business/product', { params }) },
		detail: { get: (id) => request.get(`business/product/${id}`) },
		add: { post: (params) => request.post('business/product', params) },
		edit: { put: (id, params) => request.put(`business/product/${id}`, params) },
		delete: { delete: (id) => request.delete(`business/product/${id}`) },
	units: { get: (params) => request.get('business/product/units', { params }) },
	batchDelete: { post: (params) => request.post('business/product/batch-delete', params) },
	batchUpdateStatus: { post: (params) => request.post('business/product/batch-status', params) },
		category: {
			list: { get: (params) => request.get('business/product/categories', { params }) },
			add: { post: (params) => request.post('business/product/categories', params) },
			edit: { put: (id, params) => request.put(`business/product/categories/${id}`, params) },
			delete: { delete: (id) => request.delete(`business/product/categories/${id}`) },
		},
		categories: {
			get: (params) => request.get('business/product/categories', { params }),
		},
		bom: {
			get: (id) => request.get(`business/product/${id}/bom`),
			save: { put: (id, params) => request.put(`business/product/${id}/bom`, params) },
		},
	},

	// 客户
	customer: {
		list: { get: (params) => request.get('business/customers', { params }) },
		detail: { get: (id) => request.get(`business/customers/${id}`) },
		add: { post: (params) => request.post('business/customers', params) },
		edit: { put: (id, params) => request.put(`business/customers/${id}`, params) },
		delete: { delete: (id) => request.delete(`business/customers/${id}`) },
	},

	// 供应商
	supplier: {
		list: { get: (params) => request.get('business/supplier', { params }) },
		detail: { get: (id) => request.get(`business/supplier/${id}`) },
		add: { post: (params) => request.post('business/supplier', params) },
		edit: { put: (id, params) => request.put(`business/supplier/${id}`, params) },
		delete: { delete: (id) => request.delete(`business/supplier/${id}`) },
	},

	// 仓库
	warehouse: {
		list: { get: (params) => request.get('business/warehouse', { params }) },
		detail: { get: (id) => request.get(`business/warehouse/${id}`) },
		add: { post: (params) => request.post('business/warehouse', params) },
		edit: { put: (id, params) => request.put(`business/warehouse/${id}`, params) },
		delete: { delete: (id) => request.delete(`business/warehouse/${id}`) },
	},

	// 车辆
	vehicle: {
		list: { get: (params) => request.get('business/vehicle', { params }) },
		detail: { get: (id) => request.get(`business/vehicle/${id}`) },
		add: { post: (params) => request.post('business/vehicle', params) },
		edit: { put: (id, params) => request.put(`business/vehicle/${id}`, params) },
		delete: { delete: (id) => request.delete(`business/vehicle/${id}`) },
	},

	// 路线
	route: {
		list: { get: (params) => request.get('business/route', { params }) },
		detail: { get: (id) => request.get(`business/route/${id}`) },
		add: { post: (params) => request.post('business/route', params) },
		edit: { put: (id, params) => request.put(`business/route/${id}`, params) },
		delete: { delete: (id) => request.delete(`business/route/${id}`) },
	},

	// 员工
	employee: {
		list: { get: (params) => request.get('business/employee', { params }) },
		detail: { get: (id) => request.get(`business/employee/${id}`) },
		add: { post: (params) => request.post('business/employee', params) },
		edit: { put: (id, params) => request.put(`business/employee/${id}`, params) },
		delete: { delete: (id) => request.delete(`business/employee/${id}`) },
	batchDelete: { post: (params) => request.post('business/employee/batch-delete', params) },
	batchUpdateStatus: { post: (params) => request.post('business/employee/batch-status', params) },
	},

	// 销售订单
	salesOrder: {
		list: { get: (params) => request.get('business/sales-order', { params }) },
		detail: { get: (id) => request.get(`business/sales-order/${id}`) },
		add: { post: (params) => request.post('business/sales-order', params) },
		edit: { put: (id, params) => request.put(`business/sales-order/${id}`, params) },
		delete: { delete: (id) => request.delete(`business/sales-order/${id}`) },
		approve: { post: (id, params) => request.post(`business/sales-order/${id}/approve`, params) },
		cancel: { post: (id) => request.post(`business/sales-order/${id}/cancel`) },
		print: { post: (id) => request.post(`business/sales-order/${id}/print`) },
		summary: { get: (params) => request.get('business/sales-order/summary', { params }) },
		batchRedFlush: { post: (params) => request.post('business/sales-order/batch-red-flush', params) },
	},

	// 库存流水
	stockHistory: {
		list: { get: (params) => request.get('business/stock-history', { params }) },
	},

	// 现金流水
	cashFlow: {
		list: { get: (params) => request.get('business/cash-flow', { params }) },
	},

	// 经营历程
	businessHistory: {
		list: { get: (params) => request.get('business/history', { params }) },
		summary: { get: (params) => request.get('business/history/summary', { params }) },
	},

	// 月度利润
	profit: {
		list: { get: (params) => request.get('business/profit', { params }) },
	},

	// 退货（采购退货）
	returnOrder: {
		list: { get: (params) => request.get('business/return', { params }) },
		detail: { get: (id) => request.get(`business/return/${id}`) },
		add: { post: (params) => request.post('business/return', params) },
		edit: { put: (id, params) => request.put(`business/return/${id}`, params) },
		delete: { delete: (id) => request.delete(`business/return/${id}`) },
		approve: { post: (id) => request.post(`business/return/${id}/approve`) },
		process: { post: (id) => request.post(`business/return/${id}/process`) },
	},

	// 销售退货
	salesReturn: {
		list: { get: (params) => request.get('business/sales-return', { params }) },
		detail: { get: (id) => request.get(`business/sales-return/${id}`) },
		create: { post: (params) => request.post('business/sales-return', params) },
		update: { put: (id, params) => request.put(`business/sales-return/${id}`, params) },
		delete: { delete: (id) => request.delete(`business/sales-return/${id}`) },
		submit: { post: (id) => request.post(`business/sales-return/${id}/submit`) },
		approve: { post: (id, params) => request.post(`business/sales-return/${id}/approve`, params) },
		reject: { post: (id, params) => request.post(`business/sales-return/${id}/reject`, params) },
		cancel: { post: (id) => request.post(`business/sales-return/${id}/cancel`) },
		orderProducts: { get: (params) => request.get('business/sales-return/order-products', { params }) },
	},

	// 配送
	delivery: {
		list: { get: (params) => request.get('business/delivery', { params }) },
		detail: { get: (id) => request.get(`business/delivery/${id}`) },
		add: { post: (params) => request.post('business/delivery', params) },
		edit: { put: (id, params) => request.put(`business/delivery/${id}`, params) },
		delete: { delete: (id) => request.delete(`business/delivery/${id}`) },
		dispatch: { post: (id) => request.post(`business/delivery/${id}/dispatch`) },
		complete: { post: (id) => request.post(`business/delivery/${id}/complete`) },
	},

	// 库存
	stock: {
		list: { get: (params) => request.get('business/stock', { params }) },
		detail: { get: (id) => request.get(`business/stock/${id}`) },
	},

	// 库存监控
	stockMonitor: {
		list: { get: (params) => request.get('business/stock-monitor', { params }) },
	},

	// 库存核对
	stockCheck: {
		list: { get: (params) => request.get('business/stock-check', { params }) },
	},

	// 库存盘点（盘点单全流程）
	stocktaking: {
		list: { get: (params) => request.get('business/stocktaking', { params }) },
		detail: { get: (id) => request.get(`business/stocktaking/${id}`) },
		create: { post: (params) => request.post('business/stocktaking', params) },
		update: { put: (id, params) => request.put(`business/stocktaking/${id}`, params) },
		delete: { delete: (id) => request.delete(`business/stocktaking/${id}`) },
		submit: { post: (id) => request.post(`business/stocktaking/${id}/submit`) },
		approve: { post: (id, params) => request.post(`business/stocktaking/${id}/approve`, params) },
		reject: { post: (id, params) => request.post(`business/stocktaking/${id}/reject`, params) },
		cancel: { post: (id) => request.post(`business/stocktaking/${id}/cancel`) },
		warehouseProducts: { get: (params) => request.get('business/stocktaking/warehouse-products', { params }) },
		ledger: { get: (params) => request.get('business/stocktaking/ledger', { params }) },
	},

	// 促销管理
	promotion: {
		list: { get: (params) => request.get('business/promotion', { params }) },
		detail: { get: (id) => request.get(`business/promotion/${id}`) },
		create: { post: (params) => request.post('business/promotion', params) },
		update: { put: (id, params) => request.put(`business/promotion/${id}`, params) },
		delete: { delete: (id) => request.delete(`business/promotion/${id}`) },
		enable: { post: (id) => request.post(`business/promotion/${id}/enable`) },
		disable: { post: (id) => request.post(`business/promotion/${id}/disable`) },
		active: { get: (params) => request.get('business/promotion/active', { params }) },
		calculate: { post: (params) => request.post('business/promotion/calculate', params) },
		products: { get: (params) => request.get('business/promotion/products', { params }) },
		report: { get: (params) => request.get('business/promotion/report', { params }) },
	},

	// 价格体系（客户等级 + 商品等级价 + 批量调价）
	priceSystem: {
		levelList: { get: () => request.get('business/customer-level') },
		levelCreate: { post: (params) => request.post('business/customer-level', params) },
		levelUpdate: { put: (id, params) => request.put(`business/customer-level/${id}`, params) },
		levelDelete: { delete: (id) => request.delete(`business/customer-level/${id}`) },
		productList: { get: (params) => request.get('business/product-price', { params }) },
		productSave: { put: (id, params) => request.put(`business/product-price/${id}`, params) },
		batch: { post: (params) => request.post('business/product-price/batch', params) },
		history: { get: (params) => request.get('business/product-price/history', { params }) },
		calculate: { post: (params) => request.post('business/product-price/calculate', params) },
	},

	// 成本价格
	costPrice: {
		list: { get: (params) => request.get('business/cost-price', { params }) },
		edit: { put: (id, params) => request.put(`business/cost-price/${id}`, params) },
		batch: { post: (params) => request.post('business/cost-price/batch', params) },
	},

	// 采购退货
	purchaseReturn: {
		list: { get: (params) => request.get('business/purchase-return', { params }) },
		detail: { get: (id) => request.get(`business/purchase-return/${id}`) },
		create: { post: (params) => request.post('business/purchase-return', params) },
		update: { put: (id, params) => request.put(`business/purchase-return/${id}`, params) },
		delete: { delete: (id) => request.delete(`business/purchase-return/${id}`) },
		submit: { post: (id) => request.post(`business/purchase-return/${id}/submit`) },
		approve: { post: (id, params) => request.post(`business/purchase-return/${id}/approve`, params) },
		reject: { post: (id, params) => request.post(`business/purchase-return/${id}/reject`, params) },
		cancel: { post: (id) => request.post(`business/purchase-return/${id}/cancel`) },
		stockInProducts: { get: (params) => request.get('business/purchase-return/stock-in-products', { params }) },
		batchApprove: { post: (params) => request.post('business/purchase-return/batch-approve', params) },
		export: (params) => request.get('business/purchase-return/export', { params, responseType: 'blob' }),
	},

	// 商品组装
	assembly: {
		list: { get: (params) => request.get('business/assembly', { params }) },
		detail: { get: (id) => request.get(`business/assembly/${id}`) },
		create: { post: (params) => request.post('business/assembly', params) },
		update: { put: (id, params) => request.put(`business/assembly/${id}`, params) },
		delete: { delete: (id) => request.delete(`business/assembly/${id}`) },
		submit: { post: (id) => request.post(`business/assembly/${id}/submit`) },
		approve: { post: (id, params) => request.post(`business/assembly/${id}/approve`, params) },
		reject: { post: (id, params) => request.post(`business/assembly/${id}/reject`, params) },
		cancel: { post: (id) => request.post(`business/assembly/${id}/cancel`) },
		bomByProduct: { get: (params) => request.get('business/assembly/bom-by-product', { params }) },
		batchApprove: { post: (params) => request.post('business/assembly/batch-approve', params) },
	},

	// 商品拆分
	disassembly: {
		list: { get: (params) => request.get('business/disassembly', { params }) },
		detail: { get: (id) => request.get(`business/disassembly/${id}`) },
		create: { post: (params) => request.post('business/disassembly', params) },
		update: { put: (id, params) => request.put(`business/disassembly/${id}`, params) },
		delete: { delete: (id) => request.delete(`business/disassembly/${id}`) },
		submit: { post: (id) => request.post(`business/disassembly/${id}/submit`) },
		approve: { post: (id, params) => request.post(`business/disassembly/${id}/approve`, params) },
		reject: { post: (id, params) => request.post(`business/disassembly/${id}/reject`, params) },
		cancel: { post: (id) => request.post(`business/disassembly/${id}/cancel`) },
		bomByProduct: { get: (params) => request.get('business/disassembly/bom-by-product', { params }) },
		batchApprove: { post: (params) => request.post('business/disassembly/batch-approve', params) },
	},

	// 入库
	// 只保留后端真实存在的两个接口（列表 / 新建入库）。此前这里还声明了 detail、
	// edit、delete、approve 四个——后端 StockInController 只有 index + store，
	// 一旦有 view 接上就是静默 404。库存明细/编辑走 business/stock，审批流程不存在。
	stockIn: {
		list: { get: (params) => request.get('business/stock-in', { params }) },
		add: { post: (params) => request.post('business/stock-in', params) },
	},

	// 出库
	// 同上：detail / edit / delete / approve 后端都没有，已删除。
	// StockService::stockOut() 直接扣库存，没有单据状态机可审批。
	stockOut: {
		list: { get: (params) => request.get('business/stock-out', { params }) },
		add: { post: (params) => request.post('business/stock-out', params) },
	},

	// 调拨
	transfer: {
		list: { get: (params) => request.get('business/transfer', { params }) },
		detail: { get: (id) => request.get(`business/transfer/${id}`) },
		add: { post: (params) => request.post('business/transfer', params) },
	},

	// 拜访
	visit: {
		log: {
			list: { get: (params) => request.get('business/visit/logs', { params }) },
			detail: { get: (id) => request.get(`business/visit/logs/${id}`) },
			add: { post: (params) => request.post('business/visit/logs', params) },
			edit: { put: (id, params) => request.put(`business/visit/logs/${id}`, params) },
			delete: { delete: (id) => request.delete(`business/visit/logs/${id}`) },
		},
		achievement: { get: (params) => request.get('business/visit/achievement', { params }) },
		schedule: { get: (params) => request.get('business/visit/schedule', { params }) },
	},

	// 收款管理
	receive: {
		list: { get: (params) => request.get('business/receive', { params }) },
		detail: { get: (id) => request.get(`business/receive/${id}`) },
		create: { post: (params) => request.post('business/receive', params) },
		update: { put: (id, params) => request.put(`business/receive/${id}`, params) },
		approve: { post: (id) => request.post(`business/receive/${id}/approve`) },
		delete: { delete: (id) => request.delete(`business/receive/${id}`) },
		unpaidOrders: { get: (params) => request.get('business/receive/unpaid-orders', { params }) },
		redFlush: { post: (id) => request.post(`business/receive/${id}/red-flush`, { reason: '' }) },
		statistics: { get: (params) => request.get('business/receive/statistics', { params }) },
		receivable: { get: (params) => request.get('business/receive/receivable', { params }) },
	},
	customerStatement: {
		get: (params) => request.get('business/customer-statement', { params }),
		customers: { get: (params) => request.get('business/customer-statement/customers', { params }) },
	},

	// 付款管理
	pay: {
		list: { get: (params) => request.get('business/pay', { params }) },
		detail: { get: (id) => request.get(`business/pay/${id}`) },
		create: { post: (params) => request.post('business/pay', params) },
		update: { put: (id, params) => request.put(`business/pay/${id}`, params) },
		approve: { post: (id) => request.post(`business/pay/${id}/approve`) },
		delete: { delete: (id) => request.delete(`business/pay/${id}`) },
		statistics: { get: (params) => request.get('business/pay/statistics', { params }) },
		payable: { get: (params) => request.get('business/pay/payable', { params }) },
	},

	// 费用管理
	expense: {
		list: { get: (params) => request.get('business/expense', { params }) },
		detail: { get: (id) => request.get(`business/expense/${id}`) },
		create: { post: (params) => request.post('business/expense', params) },
		update: { put: (id, params) => request.put(`business/expense/${id}`, params) },
		approve: { post: (id) => request.post(`business/expense/${id}/approve`) },
		delete: { delete: (id) => request.delete(`business/expense/${id}`) },
		statistics: { get: (params) => request.get('business/expense/statistics', { params }) },
	},

	// 考勤管理
	attendance: {
		list: { get: (params) => request.get('business/attendance', { params }) },
		detail: { get: (id) => request.get(`business/attendance/${id}`) },
		create: { post: (params) => request.post('business/attendance', params) },
		update: { put: (id, params) => request.put(`business/attendance/${id}`, params) },
		delete: { delete: (id) => request.delete(`business/attendance/${id}`) },
		statistics: { get: (params) => request.get('business/attendance/statistics', { params }) },
	},
}

export default businessApi
