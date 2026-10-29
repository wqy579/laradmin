import request from '@/utils/request'

/**
 * F5 分销增值（行程地图）—— 第 1 块。
 * baseURL 已含 /admin 前缀，此处相对路径即为 admin/f5/...
 */
export default {
	// —— 行程地图 ——
	map: {
		salesmen: (params) => request.get('f5/map/salesmen', { params }),
		trajectory: (params) => request.get('f5/map/trajectory', { params }),
		customers: () => request.get('f5/map/customers'),
	},
}
