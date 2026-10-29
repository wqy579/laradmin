<template>
	<div class="perf-list">
		<!-- 筛选栏 -->
		<div class="perf-filter">
			<el-input
				v-model="filters.keyword"
				placeholder="移库单号 / 订单号"
				clearable
				style="width: 220px"
				@keyup.enter="onSearch"
				@clear="onSearch"
			/>
			<el-select v-model="filters.status" placeholder="状态" clearable style="width: 140px" @change="onSearch">
				<el-option label="已移库" value="done" />
				<el-option label="已作废" value="cancelled" />
			</el-select>
			<el-button type="primary" :loading="loading" @click="onSearch">查询</el-button>
			<el-button @click="resetFilters">重置</el-button>
			<el-button type="success" @click="openCreate">新建移库单</el-button>
		</div>

		<!-- 提示条 -->
		<el-alert
			type="info"
			:closable="false"
			show-icon
			title="移库规则：移库量不得超过该商品的订货量；超量前端拦截、后端二次校验并拒绝落库。"
			style="margin: 0 20px"
		/>

		<!-- 列表 -->
		<el-table
			v-loading="loading"
			:data="list"
			class="perf-table"
			height="100%"
			:header-cell-style="{ background: '#F5F7FA', color: '#606266', fontWeight: 600 }"
		>
			<el-table-column prop="transfer_no" label="移库单号" min-width="150" />
			<el-table-column prop="order_no" label="订单号" min-width="150" />
			<el-table-column prop="customer" label="客户" min-width="120" show-overflow-tooltip />
			<el-table-column prop="from_warehouse" label="移出仓库" min-width="110" show-overflow-tooltip />
			<el-table-column prop="to_warehouse" label="移入仓库" min-width="110" show-overflow-tooltip />
			<el-table-column prop="transfer_date" label="移库日期" width="110" align="center" />
			<el-table-column prop="reason" label="移库原因" min-width="140" show-overflow-tooltip />
			<el-table-column label="状态" width="90" align="center">
				<template #default="{ row }">
					<el-tag :type="row.status === 'done' ? 'success' : 'info'" size="small">
						{{ row.status === 'done' ? '已移库' : '已作废' }}
					</el-tag>
				</template>
			</el-table-column>
			<el-table-column prop="created_at" label="创建时间" width="160" align="center" />
			<el-table-column label="操作" width="90" align="center" fixed="right">
				<template #default="{ row }">
					<el-button link type="primary" size="small" @click="openDetail(row)">历史</el-button>
				</template>
			</el-table-column>
			<template #empty>
				<el-empty description="暂无移库单" />
			</template>
		</el-table>

		<!-- 分页 -->
		<div class="perf-pager">
			<el-pagination
				v-model:current-page="page"
				v-model:page-size="pageSize"
				:total="total"
				:page-sizes="[20, 50, 100]"
				layout="total, sizes, prev, pager, next"
				background
				@current-change="load"
				@size-change="load"
			/>
		</div>

		<!-- 新建移库弹窗 -->
		<el-dialog v-model="formVisible" title="新建订单移库" width="760px" top="6vh">
			<el-form :model="form" label-width="96px">
				<el-form-item label="订货单" required>
					<el-select
						v-model="form.order_id"
						filterable
						remote
						:remote-method="searchOrders"
						:loading="orderLoading"
						placeholder="输入订单号 / 客户搜索（待处理或已审核订单）"
						style="width: 100%"
						@change="onOrderChange"
					>
						<el-option
							v-for="o in orderOptions"
							:key="o.id"
							:label="`${o.order_no}　${o.customer_name || ''}　${o.warehouse_name || ''}`"
							:value="o.id"
						/>
					</el-select>
				</el-form-item>
				<el-form-item label="移出仓库">
					<el-input :model-value="currentWarehouse || '—'" disabled />
				</el-form-item>
				<el-form-item label="移入仓库" required>
					<el-select v-model="form.to_warehouse_id" filterable placeholder="请选择移入仓库" style="width: 100%">
						<el-option
							v-for="w in warehouseOptions"
							:key="w.id"
							:label="w.name"
							:value="w.id"
							:disabled="w.id === currentWarehouseId"
						/>
					</el-select>
				</el-form-item>
				<el-form-item label="移库日期" required>
					<el-date-picker v-model="form.transfer_date" type="date" value-format="YYYY-MM-DD" style="width: 100%" />
				</el-form-item>
				<el-form-item label="移库原因">
					<el-input v-model="form.reason" type="textarea" :rows="2" maxlength="500" show-word-limit placeholder="选填" />
				</el-form-item>

				<el-form-item label="移库明细" required>
					<el-table :data="form.items" size="small" border max-height="300" style="width: 100%">
						<el-table-column prop="product" label="商品" min-width="160" show-overflow-tooltip />
						<el-table-column label="订货量（上限）" width="130" align="right">
							<template #default="{ row }">{{ formatNumber(row.ordered_qty) }}</template>
						</el-table-column>
						<el-table-column label="移库量" width="180" align="center">
							<template #default="{ row }">
								<el-input-number
									v-model="row.qty"
									:min="0"
									:max="Number(row.ordered_qty)"
									:precision="2"
									:step="1"
									size="small"
									style="width: 140px"
								/>
							</template>
						</el-table-column>
						<template #empty>
							<el-empty :description="form.order_id ? '该订单暂无明细' : '请先选择订货单'" :image-size="50" />
						</template>
					</el-table>
				</el-form-item>
			</el-form>
			<template #footer>
				<el-button @click="formVisible = false">取消</el-button>
				<el-button type="primary" :loading="submitting" @click="submitForm">提交移库</el-button>
			</template>
		</el-dialog>

		<!-- 详情弹窗：移库信息 + 明细 + 操作留痕 -->
		<el-dialog v-model="detailVisible" :title="`移库单 · ${detail.transfer_no || ''}`" width="680px" top="6vh">
			<el-descriptions :column="2" border size="small" style="margin-bottom: 12px">
				<el-descriptions-item label="移库单号">{{ detail.transfer_no }}</el-descriptions-item>
				<el-descriptions-item label="订单号">{{ detail.order_no }}</el-descriptions-item>
				<el-descriptions-item label="移出仓库">{{ detail.from_warehouse || '—' }}</el-descriptions-item>
				<el-descriptions-item label="移入仓库">{{ detail.to_warehouse || '—' }}</el-descriptions-item>
				<el-descriptions-item label="移库日期">{{ detail.transfer_date }}</el-descriptions-item>
				<el-descriptions-item label="状态">
					<el-tag :type="detail.status === 'done' ? 'success' : 'info'" size="small">
						{{ detail.status === 'done' ? '已移库' : '已作废' }}
					</el-tag>
				</el-descriptions-item>
				<el-descriptions-item label="移库原因" :span="2">{{ detail.reason || '—' }}</el-descriptions-item>
			</el-descriptions>

			<div class="detail-sub-title">移库明细</div>
			<el-table v-if="detail.items && detail.items.length" :data="detail.items" size="small" border>
				<el-table-column prop="product" label="商品" min-width="180" show-overflow-tooltip />
				<el-table-column label="移库量" width="120" align="right">
					<template #default="{ row }">{{ formatNumber(row.qty) }}</template>
				</el-table-column>
			</el-table>
			<el-empty v-else description="暂无明细" :image-size="50" />

			<div class="detail-sub-title">操作留痕</div>
			<el-timeline v-if="detail.history && detail.history.length" style="padding-left: 4px">
				<el-timeline-item v-for="log in detail.history" :key="log.id" :timestamp="log.created_at" type="primary">
					<span class="history-action">{{ log.action_label || log.action }}</span>
					<span class="history-operator">{{ log.operator }}</span>
					<div v-if="log.detail" class="history-detail">{{ log.detail }}</div>
					<div v-if="log.remark" class="history-remark">备注：{{ log.remark }}</div>
				</el-timeline-item>
			</el-timeline>
			<el-empty v-else description="暂无操作记录" :image-size="60" />
		</el-dialog>
	</div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import api from '@/api/order'
import businessApi from '@/api/business'

// 已审核（approved）订单仍可移库，比调整单多一档
const TRANSFERABLE_STATUSES = ['draft', 'pending', 'approved']

const loading = ref(false)
const list = ref([])
const page = ref(1)
const pageSize = ref(20)
const total = ref(0)
const filters = reactive({ keyword: '', status: '' })

const formatNumber = (v) => (v === null || v === undefined || v === '' ? '—' : Number(v).toFixed(2).replace(/\.?0+$/, ''))
const today = () => new Date().toLocaleDateString('sv-SE')

const load = async () => {
	loading.value = true
	try {
		const res = await api.transfer.list({
			page: page.value,
			page_size: pageSize.value,
			keyword: filters.keyword,
			status: filters.status,
		})
		list.value = res.data?.list || []
		total.value = res.data?.total || 0
	} finally {
		loading.value = false
	}
}

const onSearch = () => {
	page.value = 1
	load()
}

const resetFilters = () => {
	filters.keyword = ''
	filters.status = ''
	page.value = 1
	load()
}

// ---------------- 订单 / 仓库候选 ----------------
const orderLoading = ref(false)
const orderOptions = ref([])
const warehouses = ref([])

const searchOrders = async (keyword = '') => {
	orderLoading.value = true
	try {
		const res = await businessApi.salesOrder.list.get({ page: 1, per_page: 50, keyword })
		orderOptions.value = (res.data?.list || []).filter((o) => TRANSFERABLE_STATUSES.includes(o.status))
	} finally {
		orderLoading.value = false
	}
}

const loadWarehouses = async () => {
	const res = await businessApi.warehouse.list.get({ page_size: 200, type: 'normal' })
	warehouses.value = res.data?.list || []
}

// ---------------- 新建 ----------------
const formVisible = ref(false)
const submitting = ref(false)
const form = reactive({ order_id: null, to_warehouse_id: null, transfer_date: '', reason: '', items: [] })

// 移出仓库固定为订单当前仓库，取订单详情回填（不可选）
const currentWarehouseId = ref(null)
const currentWarehouse = ref('')

const openCreate = () => {
	form.order_id = null
	form.to_warehouse_id = null
	currentWarehouseId.value = null
	currentWarehouse.value = ''
	form.transfer_date = today()
	form.reason = ''
	form.items = []
	formVisible.value = true
	if (!orderOptions.value.length) searchOrders()
	if (!warehouses.value.length) loadWarehouses()
}

/** 明细行取订单实时数量作为移库上限（前后端双校验的前端一侧）。 */
const onOrderChange = async (orderId) => {
	form.to_warehouse_id = null
	currentWarehouseId.value = null
	currentWarehouse.value = ''
	if (!orderId) {
		form.items = []
		return
	}
	const res = await businessApi.salesOrder.detail.get(orderId)
	currentWarehouseId.value = res.data?.warehouse_id ? Number(res.data.warehouse_id) : null
	currentWarehouse.value = res.data?.warehouse?.name || res.data?.warehouse_name || ''
	form.items = (res.data?.items || []).map((it) => ({
		product_id: it.product_id,
		product: it.product?.name || `商品 #${it.product_id}`,
		ordered_qty: Number(it.quantity),
		qty: 0,
	}))
}

const submitForm = async () => {
	if (!form.order_id) {
		ElMessage.warning('请选择订货单')
		return
	}
	if (!form.to_warehouse_id) {
		ElMessage.warning('请选择移入仓库')
		return
	}
	if (form.to_warehouse_id === currentWarehouseId.value) {
		ElMessage.warning('目标仓库与订单当前仓库相同，无需移库')
		return
	}
	if (!form.transfer_date) {
		ElMessage.warning('请选择移库日期')
		return
	}

	const items = form.items.filter((it) => Number(it.qty) > 0)
	if (!items.length) {
		ElMessage.warning('请填写至少一行移库量')
		return
	}
	// 前端硬拦：任一行移库量 > 订货量即拒绝提交（后端仍会二次校验并拒绝落库）
	const over = items.find((it) => Number(it.qty) > Number(it.ordered_qty) + 0.000001)
	if (over) {
		ElMessage.error(`移库量超过订货量：${over.product} 移库 ${over.qty} > 订货 ${over.ordered_qty}`)
		return
	}

	submitting.value = true
	try {
		await api.transfer.create({
			order_id: form.order_id,
			to_warehouse_id: form.to_warehouse_id,
			transfer_date: form.transfer_date,
			reason: form.reason || null,
			items: items.map((it) => ({ product_id: it.product_id, qty: Number(it.qty) })),
		})
		ElMessage.success('移库单已创建')
		formVisible.value = false
		onSearch()
	} catch {
		// 失败提示由请求拦截器统一弹出，弹窗保持打开便于修改
	} finally {
		submitting.value = false
	}
}

// ---------------- 详情 ----------------
const detailVisible = ref(false)
const detail = reactive({ transfer_no: '', order_no: '', from_warehouse: '', to_warehouse: '', transfer_date: '', status: '', reason: '', items: [], history: [] })

const openDetail = async (row) => {
	detailVisible.value = true
	const res = await api.transfer.detail(row.id)
	Object.assign(detail, { items: [], history: [] }, res.data || {})
}

onMounted(() => {
	load()
	searchOrders()
	loadWarehouses()
})
</script>

<style scoped>
/* 沿用 perf 域视觉规范：表体 14px #606266，行高 48px，表头 #F5F7FA */
.perf-list {
	height: 100%;
	display: flex;
	flex-direction: column;
	overflow: auto;
}
.perf-filter {
	display: flex;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
	padding: 16px 20px;
}
.perf-table {
	flex: 1;
	margin: 12px 20px 0;
}
.perf-pager {
	display: flex;
	justify-content: flex-end;
	padding: 12px 20px;
}
.detail-sub-title {
	margin: 16px 0 8px;
	font-size: 14px;
	font-weight: 600;
	color: #303133;
}
.history-action {
	font-weight: 600;
	color: #303133;
	margin-right: 8px;
}
.history-operator {
	color: #909399;
}
.history-detail {
	font-size: 13px;
	color: #606266;
	margin-top: 2px;
}
.history-remark {
	font-size: 13px;
	color: #909399;
	margin-top: 2px;
}
</style>
