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
}
