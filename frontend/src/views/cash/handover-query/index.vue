<template>
	<div class="fin-page page">
		<div class="page-header">
			<span class="title">上交款查询</span>
			<el-button type="primary" plain size="small" @click="goForm">
				<el-icon><Plus /></el-icon><span>录入上交款单</span>
			</el-button>
		</div>

		<el-card shadow="never" class="card">
			<el-tabs v-model="activeTab" class="query-tabs" @tab-change="onTabChange">
				<el-tab-pane label="未确认" name="pending" />
				<el-tab-pane label="已确认" name="confirmed" />
				<el-tab-pane label="全部" name="all" />
			</el-tabs>

			<div class="filter">
				<span class="label">日期</span>
				<el-date-picker
					v-model="dateRange"
					type="daterange"
					value-format="YYYY-MM-DD"
					range-separator="至"
					start-placeholder="开始"
					end-placeholder="结束"
					style="width:250px"
					@change="onDateChange"
				/>
				<span class="label">上交人</span>
				<el-select v-model="filters.salesman_id" placeholder="上交人" clearable filterable style="width:120px">
					<el-option v-for="s in salesmen" :key="s.id" :label="s.name" :value="s.id" />
				</el-select>
				<span class="label">收款账户</span>
				<el-select v-model="filters.account_id" placeholder="收款账户" clearable style="width:130px">
					<el-option v-for="a in accounts" :key="a.id" :label="a.name" :value="a.id" />
				</el-select>
				<span class="label">上交单号</span>
				<el-input v-model="filters.no" placeholder="上交单号" clearable style="width:160px" @keyup.enter="onSearch" />
				<el-button type="primary" :loading="loading" @click="onSearch">查询</el-button>
				<el-button @click="onReset">重置</el-button>
				<el-button :disabled="!total" @click="onExport">
					<el-icon><Download /></el-icon><span>导出 Excel</span>
				</el-button>
			</div>

			<!-- 统计条：服务端全筛选口径，不受分页影响 -->
			<div class="stat-strip">
				<div class="stat">
					<div class="stat-label">单据数</div>
					<div class="stat-value tabular">{{ fmtCount(stat.count) }}</div>
				</div>
				<div class="stat">
					<div class="stat-label">上交金额合计</div>
					<div class="stat-value tabular">¥{{ fmtMoney(stat.amount_sum) }}</div>
				</div>
				<div class="stat">
					<div class="stat-label">待确认</div>
					<div class="stat-value tabular warn">{{ fmtCount(stat.pending_count) }}</div>
				</div>
				<div class="stat">
					<div class="stat-label">已确认</div>
					<div class="stat-value tabular ok">{{ fmtCount(stat.confirmed_count) }}</div>
				</div>
			</div>

			<div v-if="checked.length" class="batch-bar">
				<span class="batch-text">已选 {{ checked.length }} 单</span>
				<el-button
					v-permission="'cash:handover:confirm'"
					type="success"
					plain
					size="small"
					:loading="batchLoading"
					@click="onBatchConfirm"
				>批量确认</el-button>
			</div>

			<el-table
				v-loading="loading"
				:data="rows"
				border
				stripe
				row-key="id"
				class="fin-table--dense"
				show-summary
				:summary-method="summaryMethod"
				height="calc(100% - 210px)"
				@selection-change="onSelectionChange"
			>
				<template #empty><FinEmpty text="暂无上交款单" /></template>
				<el-table-column type="selection" width="42" :selectable="(row) => row.status === 'pending'" />
				<el-table-column prop="no" label="上交单号" width="140">
					<template #default="{ row }"><span class="tabular">{{ row.no }}</span></template>
				</el-table-column>
				<el-table-column prop="date" label="上交日期" width="110" align="center">
					<template #default="{ row }"><span class="tabular">{{ fmtDay(row.date) }}</span></template>
				</el-table-column>
				<el-table-column label="上交人" width="100" align="center">
					<template #default="{ row }">{{ row.salesman_name || '—' }}</template>
				</el-table-column>
				<el-table-column label="收款账户" min-width="140" show-overflow-tooltip>
					<template #default="{ row }">{{ row.account_name || '—' }}</template>
				</el-table-column>
				<el-table-column prop="order_count" label="订单数" width="80" align="right">
					<template #default="{ row }"><span class="tabular">{{ row.order_count }}</span></template>
				</el-table-column>
				<el-table-column prop="amount" label="金额" width="130" align="right" header-align="right">
					<template #default="{ row }"><span class="tabular amt">{{ fmtMoney(row.amount) }}</span></template>
				</el-table-column>
				<el-table-column label="确认时间" width="160" align="center">
					<template #default="{ row }"><span class="tabular">{{ row.confirmed_at || '—' }}</span></template>
				</el-table-column>
				<el-table-column label="确认状态" width="90" align="center">
					<template #default="{ row }">
						<el-tag :type="HANDOVER_STATUS[row.status]?.tag || 'info'" size="small" effect="light">
							{{ row.status_label || HANDOVER_STATUS[row.status]?.label || row.status }}
						</el-tag>
					</template>
				</el-table-column>
				<el-table-column label="操作" width="120" align="center" fixed="right">
					<template #default="{ row }">
						<el-button link type="primary" size="small" @click="onView(row)">查看</el-button>
						<el-button
							v-if="row.status === 'pending'"
							v-permission="'cash:handover:confirm'"
							type="success"
							plain
							size="small"
							@click="onConfirm(row)"
						>确认</el-button>
					</template>
				</el-table-column>
			</el-table>

			<div class="pager fin-pager">
				<el-pagination
					v-model:current-page="page"
					v-model:page-size="pageSize"
					:page-sizes="[20, 50, 100]"
					:total="total"
					layout="total, sizes, prev, pager, next, jumper"
					@size-change="load"
					@current-change="load"
				/>
			</div>
		</el-card>

		<!-- 单据详情 -->
		<el-drawer v-model="detailVisible" :title="`上交款单 · ${detail?.no || ''}`" size="640px" append-to-body>
			<template v-if="detail">
				<el-descriptions :column="2" border size="small">
					<el-descriptions-item label="单号" :span="2"><span class="tabular">{{ detail.no }}</span></el-descriptions-item>
					<el-descriptions-item label="上交日期"><span class="tabular">{{ fmtDay(detail.date) }}</span></el-descriptions-item>
					<el-descriptions-item label="确认状态">
						<el-tag :type="HANDOVER_STATUS[detail.status]?.tag || 'info'" size="small" effect="light">
							{{ detail.status_label }}
						</el-tag>
					</el-descriptions-item>
					<el-descriptions-item label="上交人">{{ detail.salesman_name || '—' }}</el-descriptions-item>
					<el-descriptions-item label="收款账户">{{ detail.account_name || '—' }}</el-descriptions-item>
					<el-descriptions-item label="金额"><span class="tabular">{{ fmtMoney(detail.amount) }}</span></el-descriptions-item>
					<el-descriptions-item label="关联订单数">{{ detail.order_count }}</el-descriptions-item>
					<el-descriptions-item label="确认人">{{ detail.confirmed_name || '—' }}</el-descriptions-item>
					<el-descriptions-item label="确认时间">{{ detail.confirmed_at || '—' }}</el-descriptions-item>
					<el-descriptions-item label="备注" :span="2">{{ detail.remark || '—' }}</el-descriptions-item>
				</el-descriptions>

				<div class="det-sub">关联订单明细</div>
				<el-table v-if="detail.items?.length" :data="detail.items" border size="small" show-summary :summary-method="itemSummary">
					<el-table-column prop="order_no" label="订单号" width="150">
						<template #default="{ row }"><span class="tabular">{{ row.order_no }}</span></template>
					</el-table-column>
					<el-table-column prop="customer_name" label="客户" />
					<el-table-column label="订单金额" width="108" align="right">
						<template #default="{ row }"><span class="tabular">{{ fmtMoney(row.amount) }}</span></template>
					</el-table-column>
					<el-table-column label="本次上交" width="108" align="right">
						<template #default="{ row }"><span class="tabular">{{ fmtMoney(row.handover_amount) }}</span></template>
					</el-table-column>
				</el-table>
				<div v-else class="empty-note">暂无明细</div>
			</template>
		</el-drawer>
	</div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus, Download } from '@element-plus/icons-vue'
import { useRoute, useRouter } from 'vue-router'
import cashApi from '@/api/cash'
import { saveBlob } from '@/utils/fileDownload'
import { HANDOVER_STATUS, fmtMoney, fmtCount, fmtDay } from '../shared'
import FinEmpty from '../../finance/_FinEmpty.vue'

const router = useRouter()
const route = useRoute()

// 出纳工作台的「去处理」带 ?status=pending 穿透进来，非法值回落默认页签
const TAB_NAMES = ['pending', 'confirmed', 'all']
const queryTab = TAB_NAMES.includes(route.query.status) ? route.query.status : 'pending'
const activeTab = ref(queryTab)
const filters = reactive({ salesman_id: '', account_id: '', no: '', start_date: '', end_date: '' })
const dateRange = ref([])
const rows = ref([])
const total = ref(0)
const page = ref(1)
const pageSize = ref(20)
const loading = ref(false)
const batchLoading = ref(false)
const checked = ref([])
const stat = reactive({ count: 0, amount_sum: 0, pending_count: 0, confirmed_count: 0 })
const accounts = ref([])
const salesmen = ref([])

const queryPayload = () => ({
	...filters,
	status: activeTab.value,
	page: page.value,
	page_size: pageSize.value,
})

async function load() {
	loading.value = true
	try {
		const res = await cashApi.handover.list.get(queryPayload())
		const data = res.data || {}
		rows.value = data.list || []
		total.value = data.total || 0
		Object.assign(stat, { count: 0, amount_sum: 0, pending_count: 0, confirmed_count: 0 }, data.stat || {})
		checked.value = []
	} catch (e) {
		ElMessage.error(e.message || '查询失败')
	} finally {
		loading.value = false
	}
}

const onTabChange = () => {
	page.value = 1
	load()
}

const onDateChange = (val) => {
	filters.start_date = val?.[0] || ''
	filters.end_date = val?.[1] || ''
}

const onSearch = () => {
	page.value = 1
	load()
}

const onReset = () => {
	Object.assign(filters, { salesman_id: '', account_id: '', no: '', start_date: '', end_date: '' })
	dateRange.value = []
	page.value = 1
	load()
}

// 合计行取服务端全筛选口径，不受当前页影响
const summaryMethod = ({ columns }) =>
	columns.map((col, i) => {
		if (i === 0) return `合计（${fmtCount(stat.count)} 单）`
		if (col.property === 'amount') return '¥' + fmtMoney(stat.amount_sum)
		return ''
	})

const itemSummary = ({ columns, data }) =>
	columns.map((col, i) => {
		if (i === 0) return '合计'
		if (col.property === 'amount') return fmtMoney(data.reduce((s, r) => s + Number(r.amount || 0), 0))
		if (col.label === '本次上交') return fmtMoney(data.reduce((s, r) => s + Number(r.handover_amount || 0), 0))
		return ''
	})

const onExport = async () => {
	try {
		const blob = await cashApi.handover.export({ ...filters, status: activeTab.value })
		saveBlob(blob, `上交款查询_${Date.now()}.csv`)
		ElMessage.success('导出成功')
	} catch (e) {
		ElMessage.error(e.message || '导出失败')
	}
}

/* ---------- 操作 ---------- */
const detailVisible = ref(false)
const detail = ref(null)
const onView = async (row) => {
	try {
		const res = await cashApi.handover.detail.get(row.id)
		detail.value = res.data
		detailVisible.value = true
	} catch (e) {
		ElMessage.error(e.message || '详情加载失败')
	}
}

const onSelectionChange = (sel) => { checked.value = sel }

const onConfirm = (row) => {
	ElMessageBox.confirm(
		`确认上交款单 ${row.no}？金额 ¥${fmtMoney(row.amount)}，确认后记入收款账户现金流水并回写订单收款状态，不可重复确认。`,
		'确认上交款',
		{ type: 'warning', confirmButtonText: '确认并入库', cancelButtonText: '取消' }
	).then(async () => {
		try {
			const res = await cashApi.handover.confirm.post(row.id)
			ElMessage.success(res.message || `单号 ${row.no} 已确认`)
			detail.value = null
			load()
		} catch (e) {
			ElMessage.error(e.message || '确认失败')
		}
	}).catch(() => {})
}

const onBatchConfirm = () => {
	const ids = checked.value.map((r) => r.id)
	if (!ids.length) return ElMessage.warning('请先勾选待确认单据')
	ElMessageBox.confirm(
		`批量确认 ${ids.length} 张上交款单？确认后按各自金额记入收款账户现金流水。`,
		'批量确认上交款',
		{ type: 'warning', confirmButtonText: '确认并入库', cancelButtonText: '取消' }
	).then(async () => {
		batchLoading.value = true
		try {
			const res = await cashApi.handover.batchConfirm(ids)
			ElMessage.success(res.message || `已确认 ${res.data?.confirmed ?? ids.length} 单`)
			load()
		} catch (e) {
			ElMessage.error(e.message || '批量确认失败')
		} finally {
			batchLoading.value = false
		}
	}).catch(() => {})
}

const goForm = () => router.push('/cash/handover')

onMounted(async () => {
	load()
	try {
		const res = await cashApi.handover.options.get()
		accounts.value = res.data?.accounts || []
		salesmen.value = res.data?.salesmen || []
	} catch (e) {
		ElMessage.error(e.message || '选项加载失败')
	}
})
</script>

<style>
@import '../../finance/_shared.css';
</style>

<style scoped>
.page { background: var(--bg-body); min-height: 100vh; display: flex; flex-direction: column; }
.page-header { height: 50px; background: var(--bg-card); border-bottom: 1px solid var(--border-lighter); padding: 0 var(--space-xl); display: flex; align-items: center; justify-content: space-between; }
.title { font-size: var(--font-h1); font-weight: 600; color: var(--text-primary); }
.card { flex: 1; margin: var(--space-lg) var(--space-xl); display: flex; flex-direction: column; }

.query-tabs { margin-top: calc(-1 * var(--space-md)); }
.query-tabs :deep(.el-tabs__item) { font-size: 14px; color: #606266; }
.query-tabs :deep(.is-active) { color: #409eff; }

.filter { display: flex; gap: var(--space-sm); flex-wrap: wrap; padding-bottom: var(--space-md); }
/* DS：筛选区标签 12px #606266 */
.filter .label {
	font-size: var(--font-aux);
	color: var(--text-regular);
	display: inline-flex;
	align-items: center;
	white-space: nowrap;
}

.stat-strip { display: flex; gap: var(--space-lg); margin-bottom: var(--space-md); }
.stat { flex: 1; padding: var(--space-sm) var(--space-md); background: var(--bg-hover); border-radius: var(--radius-md); }
.stat-label { font-size: var(--font-aux); color: var(--text-secondary); line-height: 18px; }
.stat-value { font-size: var(--font-stat); font-weight: 600; color: var(--text-primary); line-height: 32px; }
.stat-value.warn { color: var(--color-warning); }
.stat-value.ok { color: var(--color-success); }

.batch-bar { display: flex; align-items: center; gap: var(--space-sm); padding-bottom: var(--space-sm); }
.batch-text { font-size: var(--font-aux); color: var(--text-secondary); }

.amt { color: var(--text-primary); font-weight: 500; }
.pager { display: flex; justify-content: flex-end; padding-top: var(--space-md); }
/* DS：分页条 52px（覆盖 _shared.css 的 fin-pager，因卡片内已有 padding-top） */
.card .fin-pager { height: 52px; padding-top: 0; }

.det-sub { margin: var(--space-lg) 0 var(--space-sm); font-size: var(--font-h3); font-weight: 600; color: var(--text-primary); }
.empty-note { padding: var(--space-xl) 0; text-align: center; color: var(--text-secondary); font-size: var(--font-base); }
/* D3 简报口径：表头 44px 13px/600，明细行 41px（覆盖 _shared.css 的 14px/500 + 48px） */
.card :deep(.el-table.fin-table--dense th.el-table__cell .cell) {
	font-size: var(--font-base);
	font-weight: 600;
}
.card :deep(.el-table.fin-table--dense .el-table__body .el-table__row) {
	height: 41px;
}
.card :deep(.el-table .el-table__body td.el-table__cell .cell) {
	font-size: var(--font-base);
	color: var(--text-primary);
}
</style>
