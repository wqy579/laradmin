import request from '@/utils/request'

/**
 * 固定资产模块接口（/admin/asset 前缀，JWT Bearer 鉴权）
 *
 * D3：购置单 + 资产台账。台账双视图共用 cards.list 一个接口，
 * 条数与金额口径由后端保证一致，前端只切渲染层。
 */
const assetApi = {
	// 资产购置单
	purchase: {
		options: { get: (params) => request.get('asset/purchases/options', { params }) },
		list: { get: (params) => request.get('asset/purchases', { params }) },
		save: (data) => request.post('asset/purchases', data),
	},
	// 固定资产台账（卡片视图 / 列表视图同一数据源）
	card: {
		options: { get: (params) => request.get('asset/cards/options', { params }) },
		list: { get: (params) => request.get('asset/cards', { params }) },
	},
	// 资产折旧（本期应折明细 + 执行流水 + 计提）
	depreciation: {
		preview: { get: (params) => request.get('asset/depreciations/preview', { params }) },
		runs: { get: (params) => request.get('asset/depreciations/runs', { params }) },
		records: { get: (params) => request.get('asset/depreciations/records', { params }) },
		run: (data) => request.post('asset/depreciations', data),
	},
	// 资产变卖（变卖流水 + 保存）
	sale: {
		options: { get: (params) => request.get('asset/sales/options', { params }) },
		list: { get: (params) => request.get('asset/sales', { params }) },
		save: (data) => request.post('asset/sales', data),
	},
}

export default assetApi
