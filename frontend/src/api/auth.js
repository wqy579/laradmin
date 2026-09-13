import request from '../utils/request'

export default {
	// 认证相关
	login: {
		post: (params) => request.post('auth/login', params),
	},
	logout: {
		post: () => request.post('auth/logout'),
	},
	me: {
		get: () => request.get('auth/me'),
		put: (params) => request.put('auth/me', params),
	},
	changePassword: {
		post: (params) => request.post('auth/change-password', params),
	},
	// 用户管理
	user: {
		list: {
			get: (params) => request.get('auth/users', { params }),
		},
		detail: {
			get: (id) => request.get(`auth/users/${id}`),
		},
		add: {
			post: (params) => request.post('auth/users', params),
		},
		edit: {
			put: (id, params) => request.put(`auth/users/${id}`, params),
		},
		delete: {
			delete: (id) => request.delete(`auth/users/${id}`),
		},
		batchDelete: {
			post: (params) => request.post('auth/users/batch-delete', params),
		},
		batchStatus: {
			post: (params) => request.post('auth/users/batch-status', params),
		},
		batchDepartment: {
			post: (params) => request.post('auth/users/batch-department', params),
		},
		batchRoles: {
			post: (params) => request.post('auth/users/batch-roles', params),
		},
		resetPassword: {
			post: (id) => request.post(`auth/users/${id}/reset-password`),
		},
		export: {
			post: (params) => request.post('auth/users/export', params),
		},
	},

	// 角色管理
	role: {
		list: {
			get: (params) => request.get('auth/roles', { params }),
		},
		all: {
			get: () => request.get('auth/roles/all'),
		},
		detail: {
			get: (id) => request.get(`auth/roles/${id}`),
		},
		add: {
			post: (params) => request.post('auth/roles', params),
		},
		edit: {
			put: (id, params) => request.put(`auth/roles/${id}`, params),
		},
		delete: {
			delete: (id) => request.delete(`auth/roles/${id}`),
		},
		batchDelete: {
			post: (params) => request.post('auth/roles/batch-delete', params),
		},
		batchStatus: {
			post: (params) => request.post('auth/roles/batch-status', params),
		},
	},

	// 部门管理
	department: {
		list: {
			get: (params) => request.get('auth/departments', { params }),
		},
		tree: {
			get: (params) => request.get('auth/departments/tree', { params }),
		},
		all: {
			get: () => request.get('auth/departments/all'),
		},
		detail: {
			get: (id) => request.get(`auth/departments/${id}`),
		},
		add: {
			post: (params) => request.post('auth/departments', params),
		},
		edit: {
			put: (id, params) => request.put(`auth/departments/${id}`, params),
		},
		delete: {
			delete: (id) => request.delete(`auth/departments/${id}`),
		},
	},

	// 权限管理
	permission: {
		list: {
			get: (params) => request.get('auth/permissions', { params }),
		},
		tree: {
			get: () => request.get('auth/permissions/tree'),
		},
		menu: {
			get: () => request.get('auth/permissions/menu'),
		},
		detail: {
			get: (id) => request.get(`auth/permissions/${id}`),
		},
		add: {
			post: (params) => request.post('auth/permissions', params),
		},
		edit: {
			put: (id, params) => request.put(`auth/permissions/${id}`, params),
		},
		delete: {
			delete: (id) => request.delete(`auth/permissions/${id}`),
		},
	},
}
