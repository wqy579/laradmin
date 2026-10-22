import request from '@/utils/request'

// T5.4 业务员拜访提醒（独立文件，避免动 business.js 共享接口）
const visitRemindApi = {
	// KPI 四卡 + 待提醒列表（分页）
	list: (params) => request.get('business/visit-remind', { params }),
	// 手工新建提醒
	create: (params) => request.post('business/visit-remind', params),
	// 单条提醒（标记已提醒）
	remind: (id) => request.post('business/visit-remind/remind', { id }),
	// 批量提醒
	batchRemind: (ids) => request.post('business/visit-remind/batch-remind', { ids }),
}

export default visitRemindApi
