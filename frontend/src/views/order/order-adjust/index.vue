<template>
	<div class="perf-list">
		<!-- 筛选栏 -->
		<div class="perf-filter">
			<el-input
				v-model="filters.keyword"
				placeholder="调整单号 / 订单号"
				clearable
				style="width: 220px"
				@keyup.enter="onSearch"
				@clear="onSearch"
			/>
			<el-select v-model="filters.status" placeholder="状态" clearable style="width: 140px" @change="onSearch">
				<el-option label="待审核" value="pending" />
				<el-option label="已通过" value="approved" />
				<el-option label="已驳回" value="rejected" />
				<el-option label="已作废" value="cancelled" />
			</el-select>
			<el-select v-model="filters.type" placeholder="类型" clearable style="width: 130px" @change="onSearch">
				<el-option label="数量调整" value="modify" />
				<el-option label="订单作废" value="void" />
			</el-select>
			<el-button type="primary" :loading="loading" @click="onSearch">查询</el-button>
			<el-button @click="resetFilters">重置</el-button>
			<el-button type="success" @click="openCreate">新建调整单</el-button>
		</div>

		<!-- 列表 -->
		<el-table
			v-loading="loading"
			:data="list"
			class="perf-table"
			height="100%"
			:header-cell-style="{ background: '#F5F7FA', color: '#606266', fontWeight: 600 }"
		>
			<el-table-column prop="adjust_no" label="调整单号" min-width="150" />
			<el-table-column prop="order_no" label="订单号" min-width="150" />
			<el-table-column prop="customer" label="客户" min-width="120" show-overflow-tooltip />
			<el-table-column label="类型" width="100" align="center">
				<template #default="{ row }">
					<el-tag v-if="row.type === 'modify'" size="small">数量调整</el-tag>
					<el-tag v-else type="danger" size="small">订单作废</el-tag>
				</template>
			</el-table-column>
			<el-table-column prop="reason" label="调整原因" min-width="160" show-overflow-tooltip />
			<el-table-column label="状态" width="90" align="center">
				<template #default="{ row }">
					<el-tag :type="statusTag(row.status)" size="small">{{ statusLabel(row.status) }}</el-tag>
				</template>
			</el-table-column>
			<el-table-column prop="creator" label="创建人" width="100" />
			<el-table-column prop="created_at" label="创建时间" width="160" align="center" />
			<el-table-column prop="applied_at" label="生效时间" width="160" align="center" />
			<el-table-column label="操作" width="230" align="center" fixed="right">
				<template #default="{ row }">
					<el-button link type="primary" size="small" @click="openDetail(row)">历史</el-button>
					<el-button v-if="row.status === 'pending'" link type="primary" size="small" @click="openEdit(row)">修改</el-button>
					<el-button v-if="row.status === 'pending'" link type="success" size="small" @click="onApprove(row)">通过</el-button>
					<el-button v-if="row.status === 'pending'" link type="warning" size="small" @click="onReject(row)">驳回</el-button>
					<el-button v-if="row.status === 'pending'" link type="danger" size="small" @click="onVoid(row)">作废</el-button>
				</template>
			</el-table-column>
			<template #empty>
				<el-empty description="暂无订货调整单" />
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

		<!-- 新建/修改弹窗（modify：调明细；void：整单作废） -->
		<el-dialog v-model="formVisible" :title="form.id ? `修改调整单 · ${form.adjust_no}` : '新建订货调整单'" width="760px" top="6vh">
			<el-form :model="form" label-width="96px">
				<el-form-item label="订货单" required>
					<el-select
						v-model="form.order_id"
						filterable
						remote
						:remote-method="searchOrders"
						:loading="orderLoading"
						placeholder="输入订单号 / 客户搜索（仅待处理订单）"
						style="width: 100%"
						:disabled="!!form.id"
						@change="onOrderChange"
					>
						<el-option
							v-for="o in orderOptions"
							:key="o.id"
							:label="`${o.order_no}　${o.customer_name || ''}`"
							:value="o.id"
						/>
					</el-select>
				</el-form-item>
				<el-form-item label="调整类型" required>
					<el-radio-group v-model="form.type" :disabled="!!form.id" @change="onTypeChange">
						<el-radio value="modify">数量调整</el-radio>
						<el-radio value="void">订单作废</el-radio>
					</el-radio-group>
				</el-form-item>
				<el-form-item label="调整原因">
					<el-input v-model="form.reason" type="textarea" :rows="2" maxlength="500" show-word-limit placeholder="选填" />
				</el-form-item>

				<template v-if="form.type === 'modify'">
					<el-alert
						type="info"
						:closable="false"
						show-icon
						title="调整前数量以订单当前数量为准（只读）；只提交数量有变化的行。"
						style="margin: 0 0 10px 96px; width: calc(100% - 96px)"
					/>
					<el-table :data="form.items" size="small" border max-height="300" style="margin-left: 96px; width: calc(100% - 96px)">
						<el-table-column prop="product" label="商品" min-width="160" show-overflow-tooltip />
						<el-table-column label="订货量（调整前）" width="130" align="right">
							<template #default="{ row }">{{ formatNumber(row.old_qty) }}</template>
						</el-table-column>
						<el-table-column label="调整后数量" width="180" align="center">
							<template #default="{ row }">
								<el-input-number v-model="row.new_qty" :min="0" :precision="2" :step="1" size="small" style="width: 140px" />
							</template>
						</el-table-column>
						<el-table-column label="变化" width="100" align="right">
							<template #default="{ row }">
								<span :class="{ 'text-red': Number(row.new_qty) !== Number(row.old_qty) }">
									{{ formatNumber(Number(row.new_qty) - Number(row.old_qty)) }}
								</span>
							</template>
						</el-table-column>
						<template #empty>
							<el-empty :description="form.order_id ? '该订单暂无明细' : '请先选择订货单'" :image-size="50" />
						</template>
					</el-table>
				</template>
				<el-alert
					v-else
					type="warning"
					:closable="false"
					show-icon
					title="审核通过后，该订货单将整单作废（数量全部清零并留痕）。"
					style="margin: 0 0 10px 96px; width: calc(100% - 96px)"
				/>
			</el-form>
			<template #footer>
				<el-button @click="formVisible = false">取消</el-button>
				<el-button type="primary" :loading="submitting" @click="submitForm">提交</el-button>
			</template>
		</el-dialog>

		<!-- 详情弹窗：调整信息 + 明细 + 操作留痕 -->
		<el-dialog v-model="detailVisible" :title="`订货调整单 · ${detail.adjust_no || ''}`" width="720px" top="6vh">
			<el-descriptions :column="2" border size="small" style="margin-bottom: 12px">
				<el-descriptions-item label="调整单号">{{ detail.adjust_no }}</el-descriptions-item>
				<el-descriptions-item label="订单号">{{ detail.order_no }}</el-descriptions-item>
				<el-descriptions-item label="类型">
					<el-tag v-if="detail.type === 'modify'" size="small">数量调整</el-tag>
					<el-tag v-else type="danger" size="small">订单作废</el-tag>
				</el-descriptions-item>
				<el-descriptions-item label="状态">
					<el-tag :type="statusTag(detail.status)" size="small">{{ statusLabel(detail.status) }}</el-tag>
				</el-descriptions-item>
				<el-descriptions-item label="创建人">{{ detail.creator || '—' }}</el-descriptions-item>
				<el-descriptions-item label="生效时间">{{ detail.applied_at || '—' }}</el-descriptions-item>
				<el-descriptions-item label="调整原因" :span="2">{{ detail.reason || '—' }}</el-descriptions-item>
			</el-descriptions>

			<div class="detail-sub-title">调整明细</div>
			<el-table v-if="detail.items && detail.items.length" :data="detail.items" size="small" border>
				<el-table-column prop="product" label="商品" min-width="160" show-overflow-tooltip />
				<el-table-column label="调整前" width="110" align="right">
					<template #default="{ row }">{{ formatNumber(row.old_qty) }}</template>
				</el-table-column>
				<el-table-column label="调整后" width="110" align="right">
					<template #default="{ row }">{{ formatNumber(row.new_qty) }}</template>
				</el-table-column>
				<el-table-column label="变化" width="110" align="right">
					<template #default="{ row }">
						<span class="text-red">{{ formatNumber(Number(row.new_qty) - Number(row.old_qty)) }}</span>
					</template>
				</el-table-column>
			</el-table>
			<el-empty v-else description="作废单无数量明细" :image-size="50" />

			<div class="detail-sub-title">操作留痕</div>
			<el-timeline v-if="detail.history && detail.history.length" style="padding-left: 4px">
				<el-timeline-item
					v-for="log in detail.history"
					:key="log.id"
					:timestamp="log.created_at"
					:type="log.to_status === 'rejected' || log.to_status === 'cancelled' ? 'danger' : 'primary'"
				>
					<span class="history-action">{{ log.action_label || log.action }}</span>
					<span class="history-operator">{{ log.operator }}</span>
					<span v-if="log.from_status && log.to_status" class="history-status">
						{{ statusLabel(log.from_status) }} → {{ statusLabel(log.to_status) }}
					</span>
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
import { ElMessage, ElMessageBox } from 'element-plus'
import api from '@/api/order'
import businessApi from '@/api/business'

const ADJUSTABLE_STATUSES = ['draft', 'pending']

const loading = ref(false)
const list = ref([])
const page = ref(1)
const pageSize = ref(20)
const total = ref(0)
const filters = reactive({ keyword: '', status: '', type: '' })

const statusLabel = (s) => ({ pending: '待审核', approved: '已通过', rejected: '已驳回', cancelled: '已作废' })[s] || s || '—'
const statusTag = (s) => ({ pending: 'warning', approved: 'success', rejected: 'danger', cancelled: 'info' })[s] || 'info'
const formatNumber = (v) => (v === null || v === undefined || v === '' ? '—' : Number(v).toFixed(2).replace(/\.?0+$/, ''))

const load = async () => {
	loading.value = true
	try {
		const res = await api.adjust.list({
			page: page.value,
			page_size: pageSize.value,
			keyword: filters.keyword,
			status: filters.status,
			type: filters.type,
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
	filters.type = ''
	page.value = 1
	load()
}

// ---------------- 订单候选（仅待处理订单可发起调整） ----------------
const orderLoading = ref(false)
const orderOptions = ref([])

const searchOrders = async (keyword = '') => {
	orderLoading.value = true
	try {
		const res = await businessApi.salesOrder.list.get({ page: 1, per_page: 50, keyword })
		orderOptions.value = (res.data?.list || []).filter((o) => ADJUSTABLE_STATUSES.includes(o.status))
	} finally {
		orderLoading.value = false
	}
}

// ---------------- 新建 / 修改 ----------------
const formVisible = ref(false)
const submitting = ref(false)
const form = reactive({ id: null, adjust_no: '', order_id: null, type: 'modify', reason: '', items: [] })

const openCreate = () => {
	form.id = null
	form.adjust_no = ''
	form.order_id = null
	form.type = 'modify'
	form.reason = ''
	form.items = []
	formVisible.value = true
	if (!orderOptions.value.length) searchOrders()
}

const onTypeChange = () => {
	if (form.type === 'void') form.items = []
	else if (form.order_id) loadOrderItems(form.order_id)
}

/** 读订单当前明细：old_qty 必须等于订单实时数量，否则后端 422 拒绝。 */
const loadOrderItems = async (orderId) => {
	const res = await businessApi.salesOrder.detail.get(orderId)
	const items = res.data?.items || []
	form.items = items.map((it) => ({
		product_id: it.product_id,
		product: it.product?.name || `商品 #${it.product_id}`,
		old_qty: Number(it.quantity),
		new_qty: Number(it.quantity),
	}))
}

const onOrderChange = async (orderId) => {
	if (!orderId) {
		form.items = []
		return
	}
	if (form.type === 'modify') await loadOrderItems(orderId)
}

/** 修改：明细的 old_qty 同样取订单实时数量，new_qty 沿用已保存的调整值。 */
const openEdit = async (row) => {
	const adjRes = await api.adjust.detail(row.id)
	const adj = adjRes.data || {}
	form.id = adj.id
	form.adjust_no = adj.adjust_no
	form.order_id = adj.order_id
	form.type = adj.type
	form.reason = adj.reason || ''
	form.items = []
	if (adj.type === 'modify') await loadOrderItems(adj.order_id)
	const saved = new Map((adj.items || []).map((it) => [Number(it.product_id), Number(it.new_qty)]))
	form.items.forEach((it) => {
		if (saved.has(it.product_id)) it.new_qty = saved.get(it.product_id)
	})
	if (!orderOptions.value.some((o) => o.id === adj.order_id)) {
		orderOptions.value = [{ id: adj.order_id, order_no: adj.order_no, customer_name: '' }, ...orderOptions.value]
	}
	formVisible.value = true
}

const submitForm = async () => {
	if (!form.order_id) {
		ElMessage.warning('请选择订货单')
		return
	}
	const payload = { reason: form.reason || null }
	if (form.type === 'modify') {
		// 只提交数量有变化的行（后端按明细与订单实时数量比对）
		const changed = form.items.filter((it) => Number(it.new_qty) !== Number(it.old_qty))
		if (!changed.length) {
			ElMessage.warning('未检测到数量变更')
			return
		}
		payload.items = changed.map((it) => ({ product_id: it.product_id, old_qty: it.old_qty, new_qty: Number(it.new_qty) }))
	}

	submitting.value = true
	try {
		if (form.id) {
			await api.adjust.update(form.id, payload)
			ElMessage.success('调整单已修改')
		} else {
			await api.adjust.create({ ...payload, order_id: form.order_id, type: form.type })
			ElMessage.success('调整单已创建，待审核')
		}
		formVisible.value = false
		onSearch()
	} catch {
		// 失败提示由请求拦截器统一弹出，弹窗保持打开便于修改
	} finally {
		submitting.value = false
	}
}

// ---------------- 审核 / 作废 ----------------
const onApprove = async (row) => {
	await ElMessageBox.confirm(`确认通过调整单「${row.adjust_no}」？通过后将写入订单并留痕。`, '审核通过', { type: 'warning' })
	await api.adjust.approve(row.id)
	ElMessage.success('已通过')
	load()
}

const onReject = async (row) => {
	await ElMessageBox.confirm(`确认驳回调整单「${row.adjust_no}」？`, '审核驳回', { type: 'warning' })
	await api.adjust.reject(row.id)
	ElMessage.success('已驳回')
	load()
}

const onVoid = async (row) => {
	await ElMessageBox.confirm('确定作废该调整单？仅待审核的调整单可作废。', '作废调整单', { type: 'warning' })
	await api.adjust.void(row.id)
	ElMessage.success('已作废')
	load()
}

// ---------------- 详情 ----------------
const detailVisible = ref(false)
const detail = reactive({ adjust_no: '', order_no: '', type: '', status: '', reason: '', creator: '', applied_at: '', items: [], history: [] })

const openDetail = async (row) => {
	detailVisible.value = true
	const res = await api.adjust.detail(row.id)
	Object.assign(detail, { items: [], history: [] }, res.data || {})
}

onMounted(() => {
	load()
	searchOrders()
})
</script>

<style scoped>
/* 沿用 perf 域视觉规范：表体 14px #606266，行高 48px，表头 #F5F7FA */
.perf-filter {
	display: flex;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
	padding: 16px 20px;
}
.perf-table {
	flex: 1;
	margin: 0 20px;
}
.perf-pager {
	display: flex;
	justify-content: flex-end;
	padding: 12px 20px;
}
.text-red {
	color: #f56c6c;
	font-weight: 600;
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
.history-status {
	margin-left: 8px;
	font-size: 13px;
	color: #606266;
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
