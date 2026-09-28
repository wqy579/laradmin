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
		approve: { post: (id) => request.post(`business/sales-order/${id}/approve`) },
		summary: { get: (params) => request.get('business/sales-order/summary', { params }) },
	},

	// 采购订单
	purchaseOrder: {
		list: { get: (params) => request.get('business/purchase-order', { params }) },
		detail: { get: (id) => request.get(`business/purchase-order/${id}`) },
		add: { post: (params) => request.post('business/purchase-order', params) },
		edit: { put: (id, params) => request.put(`business/purchase-order/${id}`, params) },
		delete: { delete: (id) => request.delete(`business/purchase-order/${id}`) },
		approve: { post: (id) => request.post(`business/purchase-order/${id}/approve`) },
		// 入库会把每张明细的数量加进收货仓库；取消仅限草稿/已审批
		receive: { post: (id) => request.post(`business/purchase-order/${id}/receive`) },
		cancel: { post: (id) => request.post(`business/purchase-order/${id}/cancel`) },
	},

	// 退货
	returnOrder: {
		list: { get: (params) => request.get('business/return', { params }) },
		detail: { get: (id) => request.get(`business/return/${id}`) },
		add: { post: (params) => request.post('business/return', params) },
		edit: { put: (id, params) => request.put(`business/return/${id}`, params) },
		delete: { delete: (id) => request.delete(`business/return/${id}`) },
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

	// 成本价格
	costPrice: {
		list: { get: (params) => request.get('business/cost-price', { params }) },
		edit: { put: (id, params) => request.put(`business/cost-price/${id}`, params) },
		batch: { post: (params) => request.post('business/cost-price/batch', params) },
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
		statistics: { get: (params) => request.get('business/receive/statistics', { params }) },
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
