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
			title="核对规则：差异率 =（实际数量 − 订货量）÷ 订货量 × 100%；差异率超出 ±30% 红色、±10~30% 橙色、其余绿色。"
		/>

		<!-- 批量操作条 -->
		<div v-if="selection.length" class="batch-bar">
			<span class="batch-count">已选 <b>{{ selection.length }}</b> 行</span>
			<el-button type="primary" size="small" @click="openBatchCompare">批量对比</el-button>
		</div>

		<!-- 列表 -->
		<el-table
			v-loading="loading"
			:data="list"
			class="perf-table"
			height="100%"
			row-key="rowKey"
			:header-cell-style="{ background: '#F5F7FA', color: '#606266', fontWeight: 600 }"
			@selection-change="onSelectionChange"
		>
			<el-table-column type="selection" width="46" reserve-selection />
			<el-table-column prop="order_no" label="订单号" min-width="150" />
			<el-table-column prop="order_date" label="下单日期" width="110" />
			<el-table-column prop="customer" label="客户" min-width="120" show-overflow-tooltip />
			<el-table-column prop="product" label="商品" min-width="120" show-overflow-tooltip />
			<el-table-column prop="ordered_qty" label="订货量" width="90" align="right" />
			<el-table-column prop="actual_qty" label="实际数量" width="90" align="right" />
			<el-table-column label="差异" width="110" align="right">
				<template #default="{ row }">
					<span class="diff-cell">
						<span v-if="diffArrow(row.diff)" class="diff-arrow" :class="diffArrow(row.diff) === '↑' ? 'arrow-up' : 'arrow-down'">
							{{ diffArrow(row.diff) }}
						</span>
						{{ formatNumber(row.diff) }}
					</span>
				</template>
			</el-table-column>
			<el-table-column label="差异率(%)" width="120" align="right">
				<template #default="{ row }">
					<span :class="rateClass(row.diff_rate)">{{ formatNumber(row.diff_rate) }}%</span>
				</template>
			</el-table-column>
			<el-table-column label="风险" width="80" align="center">
				<template #default="{ row }">
					<el-tag v-if="row.risk" type="danger" size="small">超阈值</el-tag>
					<span v-else style="color: #909399">—</span>
				</template>
			</el-table-column>
			<el-table-column label="操作" width="150" align="center" fixed="right">
				<template #default="{ row }">
					<el-button link type="primary" size="small" @click="openCompare(row)">对比</el-button>
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

		<!-- 左右对比弹窗（F2）：左订货 / 右实际，差异单元格黄底 + ↑↓ 箭头，汇总条差异率三色 -->
		<el-dialog v-model="compareVisible" :title="compareTitle" width="860px" top="6vh">
			<el-descriptions v-if="compare.single" :column="3" border size="small" style="margin-bottom: 12px">
				<el-descriptions-item label="订单号">{{ compare.order_no }}</el-descriptions-item>
				<el-descriptions-item label="客户">{{ compare.customer }}</el-descriptions-item>
				<el-descriptions-item label="下单日期">{{ compare.order_date }}</el-descriptions-item>
			</el-descriptions>

			<el-table :data="compare.rows" size="small" border max-height="340" :row-key="(row) => row.key">
				<el-table-column label="商品" min-width="150" show-overflow-tooltip>
					<template #default="{ row }">{{ row.product }}</template>
				</el-table-column>
				<!-- 左：订货量 -->
				<el-table-column label="订货量" width="120" align="right">
					<template #header><span class="side-head side-left">订货量</span></template>
					<template #default="{ row }"><span class="side-left">{{ formatNumber(row.ordered_qty) }}</span></template>
				</el-table-column>
				<!-- 差异单元格：黄底高亮 + ↑↓ 箭头 -->
				<el-table-column label="差异" width="120" align="right">
					<template #default="{ row }">
						<span class="diff-cell">
							<span v-if="diffArrow(row.diff)" class="diff-arrow" :class="diffArrow(row.diff) === '↑' ? 'arrow-up' : 'arrow-down'">
								{{ diffArrow(row.diff) }}
							</span>
							{{ formatNumber(row.diff) }}
						</span>
					</template>
				</el-table-column>
				<!-- 右：实际数量 -->
				<el-table-column label="实际数量" width="120" align="right">
					<template #header><span class="side-head side-right">实际数量</span></template>
					<template #default="{ row }"><span class="side-right">{{ formatNumber(row.actual_qty) }}</span></template>
				</el-table-column>
				<el-table-column label="差异率(%)" width="120" align="right">
					<template #default="{ row }">
						<span :class="rateClass(row.diff_rate)">{{ formatNumber(row.diff_rate) }}%</span>
					</template>
				</el-table-column>
			</el-table>

			<!-- 汇总条：总体差异率三色 -->
			<div class="compare-summary">
				<span class="summary-item">合计订货：<b>{{ formatNumber(compare.totalOrdered) }}</b></span>
				<span class="summary-item">合计实际：<b>{{ formatNumber(compare.totalActual) }}</b></span>
				<span class="summary-item">
					总体差异率：
					<b :class="rateClass(compare.overallRate)">{{ formatNumber(compare.overallRate) }}%</b>
				</span>
				<span class="summary-count">共 {{ compare.rows.length }} 行</span>
			</div>
		</el-dialog>

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
				<el-table-column label="差异" width="110" align="right">
					<template #default="{ row }">
						<span class="diff-cell">
							<span v-if="diffArrow(row.diff)" class="diff-arrow" :class="diffArrow(row.diff) === '↑' ? 'arrow-up' : 'arrow-down'">
								{{ diffArrow(row.diff) }}
							</span>
							{{ formatNumber(row.diff) }}
						</span>
					</template>
				</el-table-column>
				<el-table-column label="差异率(%)" width="100" align="right">
					<template #default="{ row }">
						<span :class="rateClass(row.diff_rate)">{{ formatNumber(row.diff_rate) }}%</span>
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

// ---------------- 差异率三色 / 方向箭头（F2） ----------------
// 红 >±30%，橙 ±10~30%，绿 其余；与后端 RISK_THRESHOLD=30 对齐
const rateClass = (rate) => {
	const r = Number(rate) || 0
	if (Math.abs(r) > 30) return 'rate-red'
	if (Math.abs(r) >= 10) return 'rate-orange'
	return 'rate-green'
}

const diffArrow = (diff) => {
	const d = Number(diff) || 0
	if (d > 0) return '↑'
	if (d < 0) return '↓'
	return ''
}

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
		list.value = (res.data?.list || []).map((r) => ({ ...r, rowKey: `${r.order_id}-${r.product_id}` }))
		total.value = res.data?.total || 0
		selection.value = []
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

// ---------------- 批量选择（F2） ----------------
const selection = ref([])
const onSelectionChange = (rows) => {
	selection.value = rows
}

// ---------------- 左右对比弹窗（F2） ----------------
const compareVisible = ref(false)
const compareTitle = ref('')
const compare = reactive({ single: false, order_no: '', customer: '', order_date: '', rows: [], totalOrdered: 0, totalActual: 0, overallRate: 0 })

const buildCompare = (rows, header = {}) => {
	const clean = (rows || []).map((r, i) => ({
		key: `${header.single ? 'order' : 'batch'}-${r.order_id || header.order_id || ''}-${r.product_id || i}`,
		product: r.product || `商品 #${r.product_id}`,
		ordered_qty: Number(r.ordered_qty) || 0,
		actual_qty: Number(r.actual_qty) || 0,
	}))
	clean.forEach((r) => {
		r.diff = Math.round((Number(r.actual_qty) - Number(r.ordered_qty)) * 100) / 100
		r.diff_rate = r.ordered_qty > 0 ? Math.round((r.diff / r.ordered_qty) * 10000) / 100 : (r.actual_qty > 0 ? 100 : 0)
	})
	const totalOrdered = clean.reduce((s, r) => s + r.ordered_qty, 0)
	const totalActual = clean.reduce((s, r) => s + r.actual_qty, 0)
	const totalDiff = totalActual - totalOrdered
	const overallRate = totalOrdered > 0 ? Math.round((totalDiff / totalOrdered) * 10000) / 100 : (totalActual > 0 ? 100 : 0)
	Object.assign(compare, { single: !!header.single, order_no: header.order_no || '', customer: header.customer || '', order_date: header.order_date || '', rows: clean, totalOrdered, totalActual, overallRate })
}

/** 单行/单订单：调详情接口拿整单全部明细做左右对比。 */
const openCompare = async (row) => {
	const res = await api.check.detail(row.order_id)
	const d = res.data || {}
	buildCompare(d.items, { single: true, order_no: d.order_no, customer: d.customer, order_date: d.order_date })
	compareTitle.value = `左右对比 · ${d.order_no || ''}`
	compareVisible.value = true
}

/** 批量：用已选行做汇总左右对比。 */
const openBatchCompare = () => {
	if (!selection.value.length) return
	buildCompare(selection.value, { single: false })
	compareTitle.value = `批量对比 · 已选 ${selection.value.length} 行`
	compareVisible.value = true
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
.perf-list {
	height: 100%;
	display: flex;
	flex-direction: column;
	overflow: auto;
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
.batch-bar {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 8px 20px 0;
}
.batch-count {
	font-size: 13px;
	color: #606266;
}
.batch-count b {
	color: #409eff;
	font-weight: 600;
}

/* 差异率三色（F2） */
.rate-red {
	color: #f56c6c;
	font-weight: 700;
}
.rate-orange {
	color: #e6a23c;
	font-weight: 700;
}
.rate-green {
	color: #67c23a;
	font-weight: 600;
}

/* 差异单元格：黄底高亮 + ↑↓ 箭头（F2） */
.diff-cell {
	display: inline-flex;
	align-items: center;
	justify-content: flex-end;
	gap: 2px;
	padding: 1px 6px;
	background: #fff3cd;
	border-radius: 3px;
	border: 1px solid #ffe08a;
	font-weight: 600;
	color: #303133;
	font-variant-numeric: tabular-nums;
}
.diff-arrow {
	font-weight: 700;
	font-size: 13px;
}
.arrow-up {
	color: #f56c6c;
}
.arrow-down {
	color: #409eff;
}

/* 左右对比弹窗：左右侧配色（F2） */
.side-left {
	color: #e6a23c;
	font-weight: 600;
	font-variant-numeric: tabular-nums;
}
.side-right {
	color: #409eff;
	font-weight: 600;
	font-variant-numeric: tabular-nums;
}
.side-head {
	font-size: 13px;
}

/* 汇总条 */
.compare-summary {
	display: flex;
	align-items: center;
	gap: 24px;
	margin-top: 12px;
	padding: 12px 16px;
	background: #f5f7fa;
	border-radius: 4px;
	font-size: 14px;
	color: #606266;
}
.summary-item b {
	color: #303133;
	font-variant-numeric: tabular-nums;
	font-weight: 700;
}
.summary-count {
	margin-left: auto;
	color: #909399;
	font-size: 13px;
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
