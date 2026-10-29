import request from '@/utils/request'

/**
 * 订货中心（F 域 订货闭环）
 * baseURL 已含 /admin 前缀，此处相对路径即为 admin/business/order-check
 */
export default {
	// T5.1 客户订货核对
	check: {
		list: (params) => request.get('business/order-check', { params }),
		detail: (orderId) => request.get(`business/order-check/${orderId}`),
	},
	// T5.2 订货调整
	adjust: {
		list: (params) => request.get('business/order-adjustment', { params }),
		detail: (id) => request.get(`business/order-adjustment/${id}`),
		create: (data) => request.post('business/order-adjustment', data),
		update: (id, data) => request.put(`business/order-adjustment/${id}`, data),
		void: (id) => request.delete(`business/order-adjustment/${id}`),
		approve: (id) => request.post(`business/order-adjustment/${id}/approve`),
		reject: (id) => request.post(`business/order-adjustment/${id}/reject`),
	},
	// T5.3 订单移库
	transfer: {
		list: (params) => request.get('business/order-transfer', { params }),
		detail: (id) => request.get(`business/order-transfer/${id}`),
		create: (data) => request.post('business/order-transfer', data),
	},
	// T5.4 业务员拜访提醒
	visitReminder: {
		list: (params) => request.get('business/visit-reminder', { params }),
		detail: (id) => request.get(`business/visit-reminder/${id}`),
		create: (data) => request.post('business/visit-reminder', data),
		send: (id) => request.post(`business/visit-reminder/${id}/send`),
		cancel: (id) => request.delete(`business/visit-reminder/${id}`),
		// F3：4 KPI 卡统计 + 批量提醒
		stats: () => request.get('business/visit-reminder/stats'),
		batchSend: (ids) => request.post('business/visit-reminder/batch-send', { ids }),
	},
}
