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
	// T5.4 业务员拜访提醒
	visitReminder: {
		list: (params) => request.get('business/visit-reminder', { params }),
		detail: (id) => request.get(`business/visit-reminder/${id}`),
		create: (data) => request.post('business/visit-reminder', data),
		send: (id) => request.post(`business/visit-reminder/${id}/send`),
		cancel: (id) => request.delete(`business/visit-reminder/${id}`),
	},
}
