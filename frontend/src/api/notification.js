import request from '../utils/request'

export default {
	notification: {
		list: {
			get: (params) => request.get('system/notification', { params }),
		},
		unread: {
			get: (params) => request.get('system/notification/unread', { params }),
		},
		unreadCount: {
			get: () => request.get('system/notification/unread-count'),
		},
		detail: {
			get: (id) => request.get(`system/notification/${id}`),
		},
		markRead: {
			post: (id) => request.post(`system/notification/${id}/read`),
		},
		batchRead: {
			post: (params) => request.post('system/notification/batch-read', params),
		},
		readAll: {
			post: () => request.post('system/notification/read-all'),
		},
		delete: {
			delete: (id) => request.delete(`system/notification/${id}`),
		},
		batchDelete: {
			post: (params) => request.post('system/notification/batch-delete', params),
		},
		clearRead: {
			post: () => request.post('system/notification/clear-read'),
		},
		statistics: {
			get: () => request.get('system/notification/statistics'),
		},
	},
}
