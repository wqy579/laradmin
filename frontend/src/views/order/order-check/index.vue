<template>
	<div class="perf-list">
		<!-- 筛选栏 -->
		<div class="perf-filter">
			<el-input
				v-model="filters.keyword"
				placeholder="订单号 / 客户"
				clearable
				style="width: 220px"
				@keyup.enter="load"
				@clear="load"
			/>
			<el-date-picker
				v-model="dateRange"
				type="daterange"
				range-separator="至"
				start-placeholder="下单开始"
				end-placeholder="下单结束"
				value-format="YYYY-MM-DD"
				style="width: 260px"
			/>
			<el-switch v-model="onlyRisk" active-text="只看风险" @change="load" />
			<el-button type="primary" :loading="loading" @click="load">查询</el-button>
			<el-button @click="resetFilters">重置</el-button>
		</div>

		<!-- 提示条 -->
		<el-alert
			type="info"
			:closable="false"
			style="margin: 0 20px"
			title="核对规则：差异率 =（实际数量 − 订货量）÷ 订货量 × 100%；差异率超出 ±30% 标红为风险行。"
		/>

		<!-- 列表 -->
		<el-table
			v-loading="loading"
			:data="list"
			class="perf-table"
			height="100%"
			:header-cell-style="{ background: '#F5F7FA', color: '#606266', fontWeight: 600 }"
		>
			<el-table-column prop="order_no" label="订单号" min-width="150" />
			<el-table-column prop="order_date" label="下单日期" width="110" />
			<el-table-column prop="customer" label="客户" min-width="120" show-overflow-tooltip />
			<el-table-column prop="product" label="商品" min-width="120" show-overflow-tooltip />
			<el-table-column prop="ordered_qty" label="订货量" width="90" align="right" />
			<el-table-column prop="actual_qty" label="实际数量" width="90" align="right" />
			<el-table-column label="差异" width="90" align="right">
				<template #default="{ row }">
					<span :class="{ 'text-red': row.risk }">{{ formatNumber(row.diff) }}</span>
				</template>
			</el-table-column>
			<el-table-column label="差异率(%)" width="110" align="right">
				<template #default="{ row }">
					<span :class="{ 'text-red': row.risk }">{{ formatNumber(row.diff_rate) }}%</span>
				</template>
			</el-table-column>
			<el-table-column label="风险" width="80" align="center">
				<template #default="{ row }">
					<el-tag v-if="row.risk" type="danger" size="small">超阈值</el-tag>
					<span v-else style="color: #909399">—</span>
				</template>
			</el-table-column>
			<el-table-column label="操作" width="90" align="center" fixed="right">
				<template #default="{ row }">
					<el-button link type="primary" size="small" @click="openDetail(row)">历史</el-button>
				</template>
			</el-table-column>
			<template #empty>
				<el-empty description="暂无核对数据" />
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

		<!-- 详情弹窗：订单小计 + 明细 + 操作留痕 -->
		<el-dialog v-model="detailVisible" :title="`订货核对历史 · ${detail.order_no || ''}`" width="760px" top="6vh">
			<el-descriptions :column="3" border size="small" style="margin-bottom: 12px">
				<el-descriptions-item label="订单号">{{ detail.order_no }}</el-descriptions-item>
				<el-descriptions-item label="客户">{{ detail.customer }}</el-descriptions-item>
				<el-descriptions-item label="下单日期">{{ detail.order_date }}</el-descriptions-item>
				<el-descriptions-item label="状态">{{ detail.status }}</el-descriptions-item>
				<el-descriptions-item label="订货合计">{{ formatNumber(detail.total_qty) }}</el-descriptions-item>
				<el-descriptions-item label="订单金额">{{ formatNumber(detail.total_amount) }}</el-descriptions-item>
			</el-descriptions>

			<el-table :data="detail.items" size="small" border max-height="220">
				<el-table-column prop="product" label="商品" min-width="120" />
				<el-table-column prop="ordered_qty" label="订货量" width="90" align="right" />
				<el-table-column prop="actual_qty" label="实际数量" width="90" align="right" />
				<el-table-column label="差异" width="90" align="right">
					<template #default="{ row }">
						<span :class="{ 'text-red': row.risk }">{{ formatNumber(row.diff) }}</span>
					</template>
				</el-table-column>
				<el-table-column label="差异率(%)" width="100" align="right">
					<template #default="{ row }">
						<span :class="{ 'text-red': row.risk }">{{ formatNumber(row.diff_rate) }}%</span>
					</template>
				</el-table-column>
			</el-table>

			<div class="detail-sub-title">操作留痕</div>
			<el-timeline v-if="detail.history && detail.history.length" style="padding-left: 4px">
				<el-timeline-item
					v-for="log in detail.history"
					:key="log.id"
					:timestamp="log.created_at"
					:type="log.to_status === 'cancelled' ? 'danger' : 'primary'"
				>
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
import api from '@/api/order'

const loading = ref(false)
const list = ref([])
const page = ref(1)
const pageSize = ref(20)
const total = ref(0)
const onlyRisk = ref(false)
const dateRange = ref([])
const dateRangeRef = dateRange
const filters = reactive({ keyword: '' })
const detailVisible = ref(false)
const detail = reactive({ order_no: '', customer: '', order_date: '', status: '', total_qty: 0, total_amount: 0, items: [], history: [] })

const formatNumber = (val) => {
	const num = Number(val) || 0
	return num.toLocaleString('zh-CN', { maximumFractionDigits: 2 })
}

const load = async () => {
	loading.value = true
	try {
		const params = {
			page: page.value,
			page_size: pageSize.value,
			keyword: filters.keyword,
			only_risk: onlyRisk.value ? 1 : 0,
		}
		if (dateRangeRef.value && dateRangeRef.value.length === 2) {
			params.start_date = dateRangeRef.value[0]
			params.end_date = dateRangeRef.value[1]
		}
		const res = await api.check.list(params)
		list.value = res.data?.list || []
		total.value = res.data?.total || 0
	} finally {
		loading.value = false
	}
}

const resetFilters = () => {
	filters.keyword = ''
	dateRange.value = []
	onlyRisk.value = false
	page.value = 1
	load()
}

const openDetail = async (row) => {
	detailVisible.value = true
	const res = await api.check.detail(row.order_id)
	Object.assign(detail, res.data || {})
}

onMounted(load)
</script>

<style scoped>
/* 沿用 perf 域视觉规范：表体 14px #606266，行高 48px，表头 #F5F7FA */
.perf-table {
	flex: 1;
	margin: 12px 20px 0;
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
