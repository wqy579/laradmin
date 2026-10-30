import request from '@/utils/request'

/**
 * F5 分销增值（行程地图 + 分销商城 + 短信）
 * baseURL 已含 /admin 前缀，此处相对路径即为 admin/f5/...
 */
export default {
	// —— 行程地图 ——
	map: {
		salesmen: (params) => request.get('f5/map/salesmen', { params }),
		trajectory: (params) => request.get('f5/map/trajectory', { params }),
		customers: () => request.get('f5/map/customers'),
	},

	// —— 分销商城 · 商品（后台上架 + 前台目录）——
	product: {
		list: (params) => request.get('f5/mall/products', { params }),
		options: (params) => request.get('f5/mall/products/options', { params }),
		add: (data) => request.post('f5/mall/products', data),
		update: (id, data) => request.put(`f5/mall/products/${id}`, data),
		remove: (id) => request.delete(`f5/mall/products/${id}`),
		toggle: (id, data) => request.post(`f5/mall/products/${id}/toggle`, data),
		syncPrice: () => request.post('f5/mall/products/sync-price'),
		catalog: (params) => request.get('f5/mall/shop/catalog', { params }),
		shopCustomers: (params) => request.get('f5/mall/shop/customers', { params }),
	},

	// —— 分销商城 · 订单 ——
	order: {
		list: (params) => request.get('f5/mall/orders', { params }),
		create: (data) => request.post('f5/mall/orders', data),
		detail: (id) => request.get(`f5/mall/orders/${id}`),
		accept: (id, data) => request.post(`f5/mall/orders/${id}/accept`, data),
		reject: (id, data) => request.post(`f5/mall/orders/${id}/reject`, data),
		cancel: (id, data) => request.post(`f5/mall/orders/${id}/cancel`, data),
		warehouses: () => request.get('f5/mall/orders/warehouses'),
	},

	// —— 短信 ——
	sms: {
		list: (params) => request.get('f5/sms/templates', { params }),
		add: (data) => request.post('f5/sms/templates', data),
		update: (id, data) => request.put(`f5/sms/templates/${id}`, data),
		remove: (id) => request.delete(`f5/sms/templates/${id}`),
		toggle: (id, data) => request.post(`f5/sms/templates/${id}/toggle`, data),
		preview: (data) => request.post('f5/sms/preview', data),
		records: (params) => request.get('f5/sms/records', { params }),
		send: (data) => request.post('f5/sms/records/send', data),
		resend: (id) => request.post(`f5/sms/records/${id}/resend`),
	},
}
