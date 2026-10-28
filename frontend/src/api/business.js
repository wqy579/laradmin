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

	// 品牌
	brand: {
		list: { get: (params) => request.get('business/brand', { params }) },
		options: { get: (params) => request.get('business/brand/options', { params }) },
		detail: { get: (id) => request.get(`business/brand/${id}`) },
		add: { post: (params) => request.post('business/brand', params) },
		edit: { put: (id, params) => request.put(`business/brand/${id}`, params) },
		delete: { delete: (id) => request.delete(`business/brand/${id}`) },
		batchUpdateStatus: { post: (params) => request.post('business/brand/batch-status', params) },
		// CSV 下载走 blob；响应头 content-disposition 带文件名，downloadApiResponse 优先用它
		export: (params) => request.get('business/brand/export', { params, responseType: 'blob' }),
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
		batchUpdate: { post: (params) => request.post('business/sales-order/batch-update', params) },
		recentPrices: { get: (params) => request.get('business/sales-order/recent-prices', { params }) },
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

	// 库存调整单
	stockAdjust: {
		list: { get: (params) => request.get('business/stock-adjust', { params }) },
		detail: { get: (id) => request.get(`business/stock-adjust/${id}`) },
		create: { post: (params) => request.post('business/stock-adjust', params) },
		update: { put: (id, params) => request.put(`business/stock-adjust/${id}`, params) },
		delete: { delete: (id) => request.delete(`business/stock-adjust/${id}`) },
		submit: { post: (id) => request.post(`business/stock-adjust/${id}/submit`) },
		approve: { post: (id, params) => request.post(`business/stock-adjust/${id}/approve`, params) },
		reject: { post: (id, params) => request.post(`business/stock-adjust/${id}/reject`, params) },
		cancel: { post: (id) => request.post(`business/stock-adjust/${id}/cancel`) },
		batchApprove: { post: (params) => request.post('business/stock-adjust/batch-approve', params) },
		warehouseProducts: { get: (params) => request.get('business/stock-adjust/warehouse-products', { params }) },
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

	// 采购申请（采购流程起点：草稿→提交→审批→转采购入库）
	purchaseApplication: {
		list: { get: (params) => request.get('business/purchase-application', { params }) },
		detail: { get: (id) => request.get(`business/purchase-application/${id}`) },
		create: { post: (params) => request.post('business/purchase-application', params) },
		update: { put: (id, params) => request.put(`business/purchase-application/${id}`, params) },
		delete: { delete: (id) => request.delete(`business/purchase-application/${id}`) },
		submit: { post: (id) => request.post(`business/purchase-application/${id}/submit`) },
		approve: { post: (id, params) => request.post(`business/purchase-application/${id}/approve`, params) },
		reject: { post: (id, params) => request.post(`business/purchase-application/${id}/reject`, params) },
		cancel: { post: (id) => request.post(`business/purchase-application/${id}/cancel`) },
		transfer: { post: (id) => request.post(`business/purchase-application/${id}/transfer`) },
		batchSubmit: { post: (params) => request.post('business/purchase-application/batch-submit', params) },
		batchApprove: { post: (params) => request.post('business/purchase-application/batch-approve', params) },
		export: (params) => request.get('business/purchase-application/export', { params, responseType: 'blob' }),
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
		update: { put: (id, params) => request.put(`business/transfer/${id}`, params) },
		delete: { delete: (id) => request.delete(`business/transfer/${id}`) },
		approve: { post: (id) => request.post(`business/transfer/${id}/approve`) },
		execute: { post: (id) => request.post(`business/transfer/${id}/execute`) },
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

	// 付款单
	payment: {
		list: { get: (params) => request.get('business/payment', { params }) },
		statistics: { get: (params) => request.get('business/payment/statistics', { params }) },
	},
	// 供应商付款
	supplierPayment: {
		list: { get: (params) => request.get('business/supplier-payment', { params }) },
		statistics: { get: (params) => request.get('business/supplier-payment/statistics', { params }) },
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

	// ============ 配送管理 ============
	// 配货单
	deliveryPicking: {
		list: { get: (params) => request.get('business/delivery-picking', { params }) },
		detail: { get: (id) => request.get(`business/delivery-picking/${id}`) },
		add: { post: (params) => request.post('business/delivery-picking', params) },
		edit: { put: (id, params) => request.put(`business/delivery-picking/${id}`, params) },
		delete: { delete: (id) => request.delete(`business/delivery-picking/${id}`) },
		confirm: { post: (id) => request.post(`business/delivery-picking/${id}/confirm`) },
		cancel: { post: (id) => request.post(`business/delivery-picking/${id}/cancel`) },
		pendingOrders: { get: (params) => request.get('business/delivery-picking/pending-orders', { params }) },
	},
	// 拣货单
	deliveryPick: {
		list: { get: (params) => request.get('business/delivery-pick', { params }) },
		detail: { get: (id) => request.get(`business/delivery-pick/${id}`) },
		start: { post: (id, params) => request.post(`business/delivery-pick/${id}/start`, params) },
		confirm: { post: (id, params) => request.post(`business/delivery-pick/${id}/confirm`, params) },
		cancel: { post: (id) => request.post(`business/delivery-pick/${id}/cancel`) },
	},
	// 验货单
	deliveryCheck: {
		list: { get: (params) => request.get('business/delivery-check', { params }) },
		detail: { get: (id) => request.get(`business/delivery-check/${id}`) },
		start: { post: (id) => request.post(`business/delivery-check/${id}/start`) },
		confirm: { post: (id, params) => request.post(`business/delivery-check/${id}/confirm`, params) },
		unchecked: { get: (params) => request.get('business/delivery-check/unchecked', { params }) },
	},
	// 装车单
	deliveryLoad: {
		list: { get: (params) => request.get('business/delivery-load', { params }) },
		detail: { get: (id) => request.get(`business/delivery-load/${id}`) },
		add: { post: (params) => request.post('business/delivery-load', params) },
		confirm: { post: (id) => request.post(`business/delivery-load/${id}/confirm`) },
		delete: { delete: (id) => request.delete(`business/delivery-load/${id}`) },
	},
	// 配送任务
	deliveryTask: {
		list: { get: (params) => request.get('business/delivery-task', { params }) },
		detail: { get: (id) => request.get(`business/delivery-task/${id}`) },
		start: { post: (id) => request.post(`business/delivery-task/${id}/start`) },
		deliver: { post: (id) => request.post(`business/delivery-task/${id}/deliver`) },
		exception: { post: (id, params) => request.post(`business/delivery-task/${id}/exception`, params) },
		cancel: { post: (id, params) => request.post(`business/delivery-task/${id}/cancel`, params) },
	},
	// 配送收款
	deliveryCollection: {
		list: { get: (params) => request.get('business/delivery-collection', { params }) },
		detail: { get: (id) => request.get(`business/delivery-collection/${id}`) },
		add: { post: (params) => request.post('business/delivery-collection', params) },
		pendingTasks: { get: (params) => request.get('business/delivery-collection/pending-tasks', { params }) },
	},
	// 上交货款
	deliveryRemit: {
		list: { get: (params) => request.get('business/delivery-remit', { params }) },
		detail: { get: (id) => request.get(`business/delivery-remit/${id}`) },
		add: { post: (params) => request.post('business/delivery-remit', params) },
		confirm: { post: (id) => request.post(`business/delivery-remit/${id}/confirm`) },
		unremitSummary: { get: (params) => request.get('business/delivery-remit/unremit-summary', { params }) },
	},

	// 车销业务：要货申请
	vanRequisition: {
		list: { get: (params) => request.get('business/van-requisition', { params }) },
		detail: { get: (id) => request.get(`business/van-requisition/${id}`) },
		create: { post: (params) => request.post('business/van-requisition', params) },
		update: { put: (id, params) => request.put(`business/van-requisition/${id}`, params) },
		delete: { delete: (id) => request.delete(`business/van-requisition/${id}`) },
		submit: { post: (id) => request.post(`business/van-requisition/${id}/submit`) },
		approve: { post: (id, params) => request.post(`business/van-requisition/${id}/approve`, params) },
		reject: { post: (id, params) => request.post(`business/van-requisition/${id}/reject`, params) },
		cancel: { post: (id) => request.post(`business/van-requisition/${id}/cancel`) },
		warehouseProducts: { get: (params) => request.get('business/van-requisition/warehouse-products', { params }) },
		export: (params) => request.get('business/van-requisition/export', { params, responseType: 'blob' }),
	},

	// 车销业务：拣货（含验货）
	vanPicking: {
		list: { get: (params) => request.get('business/van-picking', { params }) },
		detail: { get: (id) => request.get(`business/van-picking/${id}`) },
		create: { post: (params) => request.post('business/van-picking', params) },
		update: { put: (id, params) => request.put(`business/van-picking/${id}`, params) },
		delete: { delete: (id) => request.delete(`business/van-picking/${id}`) },
		submit: { post: (id) => request.post(`business/van-picking/${id}/submit`) },
		approve: { post: (id) => request.post(`business/van-picking/${id}/approve`) },
		check: { post: (id, params) => request.post(`business/van-picking/${id}/check`, params) },
		cancel: { post: (id) => request.post(`business/van-picking/${id}/cancel`) },
	},

	// 车销业务：销售单
	vanSaleOrder: {
		list: { get: (params) => request.get('business/van-sale-order', { params }) },
		detail: { get: (id) => request.get(`business/van-sale-order/${id}`) },
		create: { post: (params) => request.post('business/van-sale-order', params) },
		update: { put: (id, params) => request.put(`business/van-sale-order/${id}`, params) },
		delete: { delete: (id) => request.delete(`business/van-sale-order/${id}`) },
		approve: { post: (id) => request.post(`business/van-sale-order/${id}/approve`) },
		cancel: { post: (id) => request.post(`business/van-sale-order/${id}/cancel`) },
		vehicleProducts: { get: (params) => request.get('business/van-sale-order/vehicle-products', { params }) },
		export: (params) => request.get('business/van-sale-order/export', { params, responseType: 'blob' }),
	},

	// 车销业务：车上库存管理
	vanStock: {
		list: { get: (params) => request.get('business/van-stock', { params }) },
		history: { get: (params) => request.get('business/van-stock/history', { params }) },
		warning: { get: (params) => request.get('business/van-stock/warning', { params }) },
	},

	// 车销业务：退货单
	vanReturnOrder: {
		list: { get: (params) => request.get('business/van-return-order', { params }) },
		detail: { get: (id) => request.get(`business/van-return-order/${id}`) },
		create: { post: (params) => request.post('business/van-return-order', params) },
		update: { put: (id, params) => request.put(`business/van-return-order/${id}`, params) },
		delete: { delete: (id) => request.delete(`business/van-return-order/${id}`) },
		approve: { post: (id) => request.post(`business/van-return-order/${id}/approve`) },
		cancel: { post: (id) => request.post(`business/van-return-order/${id}/cancel`) },
		vehicleProducts: { get: (params) => request.get('business/van-return-order/vehicle-products', { params }) },
		export: (params) => request.get('business/van-return-order/export', { params, responseType: 'blob' }),
	},

	// 车销业务：借货单
	vanBorrowOrder: {
		list: { get: (params) => request.get('business/van-borrow-order', { params }) },
		detail: { get: (id) => request.get(`business/van-borrow-order/${id}`) },
		create: { post: (params) => request.post('business/van-borrow-order', params) },
		update: { put: (id, params) => request.put(`business/van-borrow-order/${id}`, params) },
		delete: { delete: (id) => request.delete(`business/van-borrow-order/${id}`) },
		approve: { post: (id) => request.post(`business/van-borrow-order/${id}/approve`) },
		cancel: { post: (id) => request.post(`business/van-borrow-order/${id}/cancel`) },
		vehicleProducts: { get: (params) => request.get('business/van-borrow-order/vehicle-products', { params }) },
		balances: { get: (params) => request.get('business/van-borrow-order/balances', { params }) },
		export: (params) => request.get('business/van-borrow-order/export', { params, responseType: 'blob' }),
	},

	// 车销业务：还货单
	vanReturnBorrowOrder: {
		list: { get: (params) => request.get('business/van-return-borrow-order', { params }) },
		detail: { get: (id) => request.get(`business/van-return-borrow-order/${id}`) },
		create: { post: (params) => request.post('business/van-return-borrow-order', params) },
		update: { put: (id, params) => request.put(`business/van-return-borrow-order/${id}`, params) },
		delete: { delete: (id) => request.delete(`business/van-return-borrow-order/${id}`) },
		approve: { post: (id) => request.post(`business/van-return-borrow-order/${id}/approve`) },
		cancel: { post: (id) => request.post(`business/van-return-borrow-order/${id}/cancel`) },
		export: (params) => request.get('business/van-return-borrow-order/export', { params, responseType: 'blob' }),
	},

	// 车销业务：换货单
	vanExchangeOrder: {
		list: { get: (params) => request.get('business/van-exchange-order', { params }) },
		detail: { get: (id) => request.get(`business/van-exchange-order/${id}`) },
		create: { post: (params) => request.post('business/van-exchange-order', params) },
		update: { put: (id, params) => request.put(`business/van-exchange-order/${id}`, params) },
		delete: { delete: (id) => request.delete(`business/van-exchange-order/${id}`) },
		approve: { post: (id) => request.post(`business/van-exchange-order/${id}/approve`) },
		cancel: { post: (id) => request.post(`business/van-exchange-order/${id}/cancel`) },
		vehicleProducts: { get: (params) => request.get('business/van-exchange-order/vehicle-products', { params }) },
		export: (params) => request.get('business/van-exchange-order/export', { params, responseType: 'blob' }),
	},

	// ============ 借还货管理（库存管理下） ============
	// 借货单
	borrowOrder: {
		list: { get: (params) => request.get('business/borrow-order', { params }) },
		detail: { get: (id) => request.get(`business/borrow-order/${id}`) },
		create: { post: (params) => request.post('business/borrow-order', params) },
		update: { put: (id, params) => request.put(`business/borrow-order/${id}`, params) },
		delete: { delete: (id) => request.delete(`business/borrow-order/${id}`) },
		confirm: { post: (id) => request.post(`business/borrow-order/${id}/confirm`) },
		cancel: { post: (id, params) => request.post(`business/borrow-order/${id}/cancel`, params) },
		convert: { post: (id) => request.post(`business/borrow-order/${id}/convert`) },
		warehouseProducts: { get: (params) => request.get('business/borrow-order/warehouse-products', { params }) },
		export: (params) => request.get('business/borrow-order/export', { params, responseType: 'blob' }),
	},
	// 还货单
	returnOrder: {
		list: { get: (params) => request.get('business/return-order', { params }) },
		detail: { get: (id) => request.get(`business/return-order/${id}`) },
		create: { post: (params) => request.post('business/return-order', params) },
		update: { put: (id, params) => request.put(`business/return-order/${id}`, params) },
		delete: { delete: (id) => request.delete(`business/return-order/${id}`) },
		approve: { post: (id) => request.post(`business/return-order/${id}/approve`) },
		cancel: { post: (id, params) => request.post(`business/return-order/${id}/cancel`, params) },
		pendingBorrowItems: { get: (params) => request.get('business/return-order/pending-borrow-items', { params }) },
		export: (params) => request.get('business/return-order/export', { params, responseType: 'blob' }),
	},
	// 借还货查询
	borrowSummary: {
		index: { get: (params) => request.get('business/borrow-summary', { params }) },
		customerDetail: { get: (params) => request.get('business/borrow-summary/customer-detail', { params }) },
		trend: { get: (params) => request.get('business/borrow-summary/trend', { params }) },
	},

	// ============ 换货管理（库存管理下） ============
	// 换货单
	exchangeOrder: {
		list: { get: (params) => request.get('business/exchange-order', { params }) },
		detail: { get: (id) => request.get(`business/exchange-order/${id}`) },
		create: { post: (params) => request.post('business/exchange-order', params) },
		update: { put: (id, params) => request.put(`business/exchange-order/${id}`, params) },
		delete: { delete: (id) => request.delete(`business/exchange-order/${id}`) },
		submit: { post: (id, params) => request.post(`business/exchange-order/${id}/submit`, params) },
		approve: { post: (id) => request.post(`business/exchange-order/${id}/approve`) },
		reject: { post: (id, params) => request.post(`business/exchange-order/${id}/reject`, params) },
		cancel: { post: (id, params) => request.post(`business/exchange-order/${id}/cancel`, params) },
		warehouseProducts: { get: (params) => request.get('business/exchange-order/warehouse-products', { params }) },
		// 选原销售单后带出该单商品明细（自动填充「原商品」表格）
		salesOrderItems: { get: (params) => request.get('business/exchange-order/sales-order-items', { params }) },
		export: (params) => request.get('business/exchange-order/export', { params, responseType: 'blob' }),
	},
	// 换货查询
	exchangeSummary: {
		index: { get: (params) => request.get('business/exchange-summary', { params }) },
		reasonDistribution: { get: (params) => request.get('business/exchange-summary/reason-distribution', { params }) },
		productRank: { get: (params) => request.get('business/exchange-summary/product-rank', { params }) },
		customerDetail: { get: (params) => request.get('business/exchange-summary/customer-detail', { params }) },
	},

	// ============ 会员管理 ============
	// 会员档案
	member: {
		list: { get: (params) => request.get('business/member', { params }) },
		detail: { get: (id) => request.get(`business/member/${id}`) },
		add: { post: (params) => request.post('business/member', params) },
		edit: { put: (id, params) => request.put(`business/member/${id}`, params) },
		delete: { delete: (id) => request.delete(`business/member/${id}`) },
		freeze: { post: (id) => request.post(`business/member/${id}/freeze`) },
		unfreeze: { post: (id) => request.post(`business/member/${id}/unfreeze`) },
		batchStatus: { post: (params) => request.post('business/member/batch-status', params) },
		search: { get: (params) => request.get('business/member/search', { params }) },
		byCustomer: { get: (params) => request.get('business/member/by-customer', { params }) },
		logs: { get: (id, params) => request.get(`business/member/${id}/logs`, { params }) },
		statistics: { get: () => request.get('business/member/statistics') },
		export: (params) => request.get('business/member/export', { params, responseType: 'blob' }),
	},
	// 会员等级
	memberLevel: {
		list: { get: (params) => request.get('business/member-level', { params }) },
		add: { post: (params) => request.post('business/member-level', params) },
		edit: { put: (id, params) => request.put(`business/member-level/${id}`, params) },
		delete: { delete: (id) => request.delete(`business/member-level/${id}`) },
		enable: { post: (id) => request.post(`business/member-level/${id}/enable`) },
		disable: { post: (id) => request.post(`business/member-level/${id}/disable`) },
	},
	// 积分规则
	pointRule: {
		list: { get: () => request.get('business/point-rule') },
		detail: { get: (key) => request.get(`business/point-rule/${key}`) },
		save: { put: (params) => request.put('business/point-rule', params) },
		reset: { post: () => request.post('business/point-rule/reset') },
	},
	// 储值充值
	memberRecharge: {
		list: { get: (params) => request.get('business/member-recharge', { params }) },
		detail: { get: (id) => request.get(`business/member-recharge/${id}`) },
		add: { post: (params) => request.post('business/member-recharge', params) },
		edit: { put: (id, params) => request.put(`business/member-recharge/${id}`, params) },
		delete: { delete: (id) => request.delete(`business/member-recharge/${id}`) },
		approve: { post: (id) => request.post(`business/member-recharge/${id}/approve`) },
		reject: { post: (id, params) => request.post(`business/member-recharge/${id}/reject`, params) },
		cancel: { post: (id) => request.post(`business/member-recharge/${id}/cancel`) },
		statistics: { get: (params) => request.get('business/member-recharge/statistics', { params }) },
	},
	// 积分兑换商品
	pointProduct: {
		list: { get: (params) => request.get('business/point-product', { params }) },
		onShelf: { get: (params) => request.get('business/point-product/on-shelf', { params }) },
		detail: { get: (id) => request.get(`business/point-product/${id}`) },
		add: { post: (params) => request.post('business/point-product', params) },
		edit: { put: (id, params) => request.put(`business/point-product/${id}`, params) },
		delete: { delete: (id) => request.delete(`business/point-product/${id}`) },
		turnOn: { post: (id) => request.post(`business/point-product/${id}/on-shelf`) },
		turnOff: { post: (id) => request.post(`business/point-product/${id}/off-shelf`) },
	},
	// 积分兑换订单
	pointOrder: {
		list: { get: (params) => request.get('business/point-order', { params }) },
		detail: { get: (id) => request.get(`business/point-order/${id}`) },
		add: { post: (params) => request.post('business/point-order', params) },
		approve: { post: (id) => request.post(`business/point-order/${id}/approve`) },
		reject: { post: (id, params) => request.post(`business/point-order/${id}/reject`, params) },
		cancel: { post: (id, params) => request.post(`business/point-order/${id}/cancel`, params) },
		ship: { post: (id, params) => request.post(`business/point-order/${id}/ship`, params) },
		complete: { post: (id) => request.post(`business/point-order/${id}/complete`) },
		statistics: { get: (params) => request.get('business/point-order/statistics', { params }) },
	},
	// 会员消费记录
	memberConsumeLog: {
		list: { get: (params) => request.get('business/member-consume-log', { params }) },
		detail: { get: (id) => request.get(`business/member-consume-log/${id}`) },
		statistics: { get: (params) => request.get('business/member-consume-log/statistics', { params }) },
	},

	// ============ 智慧大屏 ============
	dashboard: {
		metrics: { get: (params) => request.get('dashboard/metrics', { params }) },
		realtimeOrders: { get: (params) => request.get('dashboard/realtime-orders', { params }) },
		categoryProportion: { get: (params) => request.get('dashboard/category-proportion', { params }) },
		salesTrend: { get: (params) => request.get('dashboard/sales-trend', { params }) },
		customerRank: { get: (params) => request.get('dashboard/customer-rank', { params }) },
		inventoryOverview: { get: (params) => request.get('dashboard/inventory-overview', { params }) },
		inventoryWarning: { get: (params) => request.get('dashboard/inventory-warning', { params }) },
		salesmanRank: { get: (params) => request.get('dashboard/salesman-rank', { params }) },
		coreMetrics: { get: (params) => request.get('dashboard/core-metrics', { params }) },
		productRank: { get: (params) => request.get('dashboard/product-rank', { params }) },
		regionSales: { get: (params) => request.get('dashboard/region-sales', { params }) },
		deliveryStatus: { get: (params) => request.get('dashboard/delivery-status', { params }) },
		financeOverview: { get: (params) => request.get('dashboard/finance-overview', { params }) },
		brandProportion: { get: (params) => request.get('dashboard/brand-proportion', { params }) },
		brandTop10: { get: (params) => request.get('dashboard/brand-top10', { params }) },
	},

	// ============ 消息中心 ============
	messages: {
		// 路径带 business/ 前缀而非顶层 messages：线上 nginx 白名单只代理
		// auth/system/business/dashboard/permissions，新顶层前缀会被兜成 SPA 的
		// index.html（见 modules/Office/routes/admin.php 的说明）。
		unreadCount: { get: () => request.get('business/messages/unread-count') },
		list: { get: (params) => request.get('business/messages', { params }) },
		read: { post: (id) => request.post(`business/messages/${id}/read`) },
		readAll: { post: () => request.post('business/messages/read-all') },
		delete: { delete: (id) => request.delete(`business/messages/${id}`) },
	},

	// 公司公告（Office 模块）
	notice: {
		list: { get: (params) => request.get('business/notice', { params }) },
		// 登录弹窗：取当前用户最新一条未读的弹窗公告（is_popup + 已发布 + 对我可见）
		popupPending: { get: () => request.get('business/notice/popup-pending') },
		detail: { get: (id) => request.get(`business/notice/${id}`) },
		create: { post: (params) => request.post('business/notice', params) },
		update: { put: (id, params) => request.put(`business/notice/${id}`, params) },
		delete: { delete: (id) => request.delete(`business/notice/${id}`) },
		publish: { post: (id) => request.post(`business/notice/${id}/publish`) },
	},

	// ============ 财务管理（B1） ============
	// finance/voucher 为查询视图：凭证录入/审核统一走 ledger 视角（BUG-10）
	finance: {
		// 日记账：现金/银行科目流水（收入红/支出绿/余额逐行重排）
		journal: { get: (params) => request.get('business/finance-journal', { params }) },
		// 凭证查询视图：分组展示（含红冲分组）
		voucher: { get: (params) => request.get('business/finance-voucher', { params }) },
		// 月结向导 + 月结查询（B2）：复用 ledger PeriodService
		monthClose: {
			periods: { get: (params) => request.get('business/finance-month-close', { params }) },
			check: { get: (period) => request.get(`business/finance-month-close/check/${period}`) },
			close: { post: (period) => request.post(`business/finance-month-close/close/${period}`) },
			reopen: { post: (period) => request.post(`business/finance-month-close/reopen/${period}`) },
		},
		// 对账单（B3）：客户应收 / 供应商应付
		statement: { get: (params) => request.get('business/finance-statement', { params }) },
		// 余额查询（B3）：客户 / 品牌 / 辅助单位
		balance: { get: (params) => request.get('business/finance-balance', { params }) },
		// 科目变化明细（B3）：逐科目流水 + 滚动余额
		subjectChange: { get: (params) => request.get('business/finance-subject-change', { params }) },
		// 资金看板（B8）：4 卡 + 近 7 日收支折线 + 待确认上交款（与资金流水同源）
		fundDashboard: {
			get: (params) => request.get('business/finance-fund-dashboard', { params }),
			pendingHandover: { get: (params) => request.get('business/finance-fund-dashboard/pending-handover', { params }) },
		},
		// 资金单族（B4）：6 类单据共用一套接口，type 判别
		// iou 欠条确认 / borrow_repay 借款还款 / fund_io 资金增减 /
		// arap_adjust 应收应付调整 / customer_balance 客户余额调整 / supplier_balance 供应商余额调整
		fundOrder: {
			list: { get: (type, params) => request.get(`business/finance-fund-orders/${type}`, { params }) },
			detail: { get: (type, id) => request.get(`business/finance-fund-orders/${type}/${id}`) },
			add: { post: (type, params) => request.post(`business/finance-fund-orders/${type}`, params) },
			confirm: { post: (type, id) => request.post(`business/finance-fund-orders/${type}/${id}/confirm`) },
			approve: { post: (type, id) => request.post(`business/finance-fund-orders/${type}/${id}/approve`) },
			reject: { post: (type, id, params) => request.post(`business/finance-fund-orders/${type}/${id}/reject`, params) },
			repay: { post: (type, id, params) => request.post(`business/finance-fund-orders/${type}/${id}/repay`, params) },
		},
	},

	// 内部邮件（Office 模块）
	mail: {
		list: { get: (params) => request.get('business/mail', { params }) },
		detail: { get: (id) => request.get(`business/mail/${id}`) },
		unreadCount: { get: () => request.get('business/mail/unread-count') },
		contacts: { get: () => request.get('business/mail/contacts') },
		send: { post: (params) => request.post('business/mail', params) },
		delete: { delete: (id) => request.delete(`business/mail/${id}`) },
		read: { post: (id) => request.post(`business/mail/${id}/read`) },
		unread: { post: (id) => request.post(`business/mail/${id}/unread`) },
		batchRead: { post: (params) => request.post('business/mail/batch-read', params) },
		batchUnread: { post: (params) => request.post('business/mail/batch-unread', params) },
		batchDelete: { post: (params) => request.post('business/mail/batch-delete', params) },
	},

	// ============ 报表管理 ============
	// 后端路由前缀 business/report/*（见 modules/Report/routes/admin.php），
	// 与促销报表 promotion.report 无关，勿混淆。export 是 { get } 对象而非裸函数——
	// 四个报表视图调的是 report.sales.export.get(...)，必须带 .get 层。
	report: {
		// 筛选项下拉数据源：商品/客户大表只在传 keyword 时模糊匹配，字典级小表整表返回
		options: { get: (params) => request.get('business/report/options', { params }) },

		// 查询模版：存一套查询条件供复用，按 report 类型隔离
		templates: {
			list: { get: (params) => request.get('business/report/templates', { params }) },
			save: { post: (params) => request.post('business/report/templates', params) },
			delete: { delete: (id) => request.delete(`business/report/templates/${id}`) },
		},

		// 列配置持久化：每用户每报表一份列显隐/排序偏好（E1 基座）
		columns: {
			get: { get: (params) => request.get('business/report/columns', { params }) },
			save: { post: (params) => request.post('business/report/columns', params) },
		},

		sales: {
			get: (params) => request.get('business/report/sales', { params }),
			export: { get: (params) => request.get('business/report/sales/export', { params, responseType: 'blob' }) },
			// 穿透：双击行 → 源单明细抽屉（传 order_no 或 order_id）
			drill: { get: (params) => request.get('business/report/sales/drill', { params }) },
},

		customer: {
			get: (params) => request.get('business/report/customer', { params }),
			export: { get: (params) => request.get('business/report/customer/export', { params, responseType: 'blob' }) },
			// 穿透：客户维度双击 → 该客户订单列表抽屉（传 customer_id 或 customer_code）
			drill: { get: (params) => request.get('business/report/customer/drill', { params }) },
		},

		stock: {
			get: (params) => request.get('business/report/stock', { params }),
			export: { get: (params) => request.get('business/report/stock/export', { params, responseType: 'blob' }) },
		},

		salesman: {
			get: (params) => request.get('business/report/salesman', { params }),
			export: { get: (params) => request.get('business/report/salesman/export', { params, responseType: 'blob' }) },
		},

		combined: {
			get: (params) => request.get('business/report/combined', { params }),
			export: { get: (params) => request.get('business/report/combined/export', { params, responseType: 'blob' }) },
		},

		// 销售分析族报表（E3，7 个维度：业务员/商品/排行/特价/促销/品项销售/品项铺市）
		salesAnalysis: {
			get: (params) => request.get('business/report/sales-analysis', { params }),
			export: { get: (params) => request.get('business/report/sales-analysis/export', { params, responseType: 'blob' }) },
		},

		// 排行+价格类报表（E4，4 个维度：销售排行总表/商品价格/客户特价价格/促销价格）
		priceRank: {
			get: (params) => request.get('business/report/price-rank', { params }),
			export: { get: (params) => request.get('business/report/price-rank/export', { params, responseType: 'blob' }) },
		},

		// 库存报表族（E5，8 个维度：超期/安全库存/可销天数/变动/明细/盘盈盘亏/拆装/库存分析）
		stockAnalysis: {
			get: (params) => request.get('business/report/stock-analysis', { params }),
			export: { get: (params) => request.get('business/report/stock-analysis/export', { params, responseType: 'blob' }) },
		},
	},
}

export default businessApi
