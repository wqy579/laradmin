import request from '../utils/request'

export default {
	// 系统配置
	config: {
		list: {
			get: (params) => request.get('system/setting', { params }),
		},
		tree: {
			get: () => request.get('system/setting/tree'),
		},
		groups: {
			get: () => request.get('system/setting/groups'),
		},
		all: {
			get: (params) => request.get('system/setting/all', { params }),
		},
		detail: {
			get: (id) => request.get(`system/setting/${id}`),
		},
		add: {
			post: (params) => request.post('system/setting', params),
		},
		edit: {
			put: (id, params) => request.put(`system/setting/${id}`, params),
		},
		delete: {
			delete: (id) => request.delete(`system/setting/${id}`),
		},
		batchDelete: {
			post: (params) => request.post('system/setting/batch-delete', params),
		},
		batchStatus: {
			post: (params) => request.post('system/setting/batch-status', params),
		},
		batchSave: {
			post: (params) => request.post('system/setting/batch-save', params),
		},
	},

	// 操作日志
	log: {
		list: {
			get: (params) => request.get('system/log', { params }),
		},
		detail: {
			get: (id) => request.get(`system/log/${id}`),
		},
		delete: {
			delete: (id) => request.delete(`system/log/${id}`),
		},
		batchDelete: {
			post: (params) => request.post('system/log/batch-delete', params),
		},
		clear: {
			post: (params) => request.post('system/log/clear', params),
		},
		statistics: {
			get: (params) => request.get('system/log/statistics', { params }),
		},
	},

	// 数据字典
	dictionary: {
		list: {
			get: (params) => request.get('system/dictionary', { params }),
		},
		all: {
			get: () => request.get('system/dictionary/all'),
		},
		detail: {
			get: (id) => request.get(`system/dictionary/${id}`),
		},
		add: {
			post: (params) => request.post('system/dictionary', params),
		},
		edit: {
			put: (id, params) => request.put(`system/dictionary/${id}`, params),
		},
		delete: {
			delete: (id) => request.delete(`system/dictionary/${id}`),
		},
	},

	// 字典项
	dictionaryItem: {
		all: { get: () => request.get('system/dictionary-item/all') },
		list: {
			get: (params) => request.get('system/dictionary-item', { params }),
		},
		detail: {
			get: (id) => request.get(`system/dictionary-item/${id}`),
		},
		add: {
			post: (params) => request.post('system/dictionary-item', params),
		},
		edit: {
			put: (id, params) => request.put(`system/dictionary-item/${id}`, params),
		},
		delete: {
			delete: (id) => request.delete(`system/dictionary-item/${id}`),
		},
		batchDelete: {
			post: (params) => request.post('system/dictionary-item/batch-delete', params),
		},
		batchStatus: {
			post: (params) => request.post('system/dictionary-item/batch-status', params),
		},
	},

	// 定时调度
	scheduled: {
		list: {
			get: (params) => request.get('system/scheduled', { params }),
		},
		all: {
			get: () => request.get('system/scheduled/all'),
		},
		detail: {
			get: (id) => request.get(`system/scheduled/${id}`),
		},
		add: {
			post: (params) => request.post('system/scheduled', params),
		},
		edit: {
			put: (id, params) => request.put(`system/scheduled/${id}`, params),
		},
		delete: {
			delete: (id) => request.delete(`system/scheduled/${id}`),
		},
		batchDelete: {
			post: (params) => request.post('system/scheduled/batch-delete', params),
		},
		start: {
			post: (id) => request.post(`system/scheduled/${id}/start`),
		},
		pause: {
			post: (id) => request.post(`system/scheduled/${id}/pause`),
		},
		resume: {
			post: (id) => request.post(`system/scheduled/${id}/resume`),
		},
		stop: {
			post: (id) => request.post(`system/scheduled/${id}/stop`),
		},
		run: {
			post: (id) => request.post(`system/scheduled/${id}/run`),
		},
		logs: {
			get: (id, params) => request.get(`system/scheduled/${id}/logs`, { params }),
		},
		clearLogs: {
			delete: (id) => request.delete(`system/scheduled/${id}/logs`),
		},
		statistics: {
			get: () => request.get('system/scheduled/statistics'),
		},
	},

	// 附件管理
	attachment: {
		directories: {
			get: (params) => request.get('system/attachment/directories', { params }),
		},
		list: {
			get: (params) => request.get('system/attachment', { params }),
		},
		detail: {
			get: (id) => request.get(`system/attachment/${id}`),
		},
		getByIds: {
			post: (params) => request.post('system/attachment/get-by-ids', params),
		},
		edit: {
			put: (id, params) => request.put(`system/attachment/${id}`, params),
		},
		delete: {
			delete: (id) => request.delete(`system/attachment/${id}`),
		},
		batchDelete: {
			post: (params) => request.post('system/attachment/batch-delete', params),
		},
		statistics: {
			get: (params) => request.get('system/attachment/statistics', { params }),
		},
		typeDistribution: {
			get: () => request.get('system/attachment/type-distribution'),
		},
	},

	// 站内通知
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

	// 上传
	upload: {
		post: (formData, onProgress) =>
			request.post('system/upload', formData, {
				headers: { 'Content-Type': 'multipart/form-data' },
				onUploadProgress: onProgress,
			}),
		multiple: (formData) =>
			request.post('system/upload/multiple', formData, {
				headers: { 'Content-Type': 'multipart/form-data' },
			}),
		base64: (params) => request.post('system/upload/base64', params),
		delete: (params) => request.post('system/upload/delete', params),
		// 分片上传
		initChunk: (params) => request.post('system/upload/chunk/init', params),
		uploadChunk: (formData, onProgress) =>
			request.post('system/upload/chunk/upload', formData, {
				headers: { 'Content-Type': 'multipart/form-data' },
				onUploadProgress: onProgress,
			}),
		mergeChunks: (params) => request.post('system/upload/chunk/merge', params),
		getUploadedChunks: (params) => request.get('system/upload/chunk/uploaded', { params }),
		cancelChunk: (params) => request.post('system/upload/chunk/cancel', params),
	},
}
