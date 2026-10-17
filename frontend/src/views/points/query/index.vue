<template>
	<div class="pts-page">
		<!-- 筛选 -->
		<div class="pts-filter">
			<span class="pts-filter__label">客户</span>
			<el-select v-model="search.customerId" filterable clearable placeholder="全部客户" size="small" style="width: 200px" @change="onSearch">
				<el-option v-for="c in customers" :key="c.id" :label="`${c.name}（${c.code}）`" :value="c.id" />
			</el-select>
			<span class="pts-filter__label">统计期间</span>
			<el-date-picker v-model="dateRange" type="daterange" value-format="YYYY-MM-DD" range-separator="-" start-placeholder="开始日期" end-placeholder="结束日期" size="small" style="width: 260px" @change="onSearch" />
			<el-button type="primary" size="small" @click="refresh">查询</el-button>
			<el-button size="small" @click="resetSearch">重置</el-button>
			<div style="flex: 1" />
			<el-button size="small" :loading="exporting" @click="exportData">导出</el-button>
		</div>

		<!-- 汇总列表：客户 / 期初 / 发放 / 核销 / 期末 / 操作 -->
		<div class="pts-table">
			<el-table :data="pagedList" border v-loading="loading" show-summary :summary-method="summaryMethod" row-key="customerId" @row-dblclick="openDetail">
				<el-table-column type="index" label="序号" width="50" align="center" />
				<el-table-column prop="customerName" label="客户" min-width="200" show-overflow-tooltip />
				<el-table-column label="期初积分" width="110" align="right">
					<template #default="{ row }">
						<span class="tabular">{{ fmt(row.opening) }}</span>
					</template>
				</el-table-column>
				<el-table-column label="本期发放" width="110" align="right">
					<template #default="{ row }">
						<span class="tabular pts-num--up">{{ row.grant ? '+' + fmt(row.grant) : '0' }}</span>
					</template>
				</el-table-column>
				<el-table-column label="本期核销" width="110" align="right">
					<template #default="{ row }">
						<span class="tabular pts-num--down">{{ row.use ? '-' + fmt(row.use) : '0' }}</span>
					</template>
				</el-table-column>
				<el-table-column label="期末积分" width="110" align="right">
					<template #default="{ row }">
						<span class="tabular pts-num--total">{{ fmt(row.closing) }}</span>
					</template>
				</el-table-column>
				<el-table-column label="操作" width="70" align="center">
					<template #default="{ row }">
						<el-button link type="primary" size="small" @click.stop="openDetail(row)">流水</el-button>
					</template>
				</el-table-column>
			</el-table>
			<div class="pts-hint">提示：双击表格行可展开该客户的积分流水明细</div>
		</div>

		<!-- 分页 -->
		<div class="pts-pager">
			<el-pagination background layout="total, prev, pager, next, sizes" :total="total" :page-size="pageSize" :current-page="page" :page-sizes="[10, 20, 50]" @current-change="onPageChange" @size-change="onSizeChange" />
		</div>

		<!-- 积分流水明细 Drawer -->
		<el-drawer v-model="drawerVisible" :title="drawerTitle" size="640px" destroy-on-close>
			<div class="drawer-summary" v-if="currentRow">
				<span>客户：<b>{{ currentRow.customerName }}</b></span>
				<span class="tabular">期初 {{ fmt(currentRow.opening) }}</span>
				<span class="tabular">发放 +{{ fmt(currentRow.grant) }}</span>
				<span class="tabular">核销 -{{ fmt(currentRow.use) }}</span>
				<span class="tabular">期末 <b>{{ fmt(currentRow.closing) }}</b></span>
			</div>
			<el-table :data="detailList" border v-loading="detailLoading" size="small" :header-row-style="{ background: 'var(--bg-hover)' }">
				<el-table-column prop="no" label="流水号" width="140">
					<template #default="{ row }">
						<span class="tabular">{{ row.no }}</span>
					</template>
				</el-table-column>
				<el-table-column prop="date" label="日期" width="95" align="center">
					<template #default="{ row }">
						<span class="tabular">{{ row.date }}</span>
					</template>
				</el-table-column>
				<el-table-column label="类型" width="70" align="center">
					<template #default="{ row }">
						<el-tag :type="row.type === 'grant' ? 'primary' : 'info'" size="small" :disable-transitions="true">{{ row.type === 'grant' ? '发放' : '核销' }}</el-tag>
					</template>
				</el-table-column>
				<el-table-column label="积分变动" width="95" align="right">
					<template #default="{ row }">
						<span class="tabular" :class="row.type === 'grant' ? 'pts-num--up' : 'pts-num--down'">{{ row.type === 'grant' ? '+' + row.points : row.points }}</span>
					</template>
				</el-table-column>
				<el-table-column label="变动后余额" width="95" align="right">
					<template #default="{ row }">
						<span class="tabular">{{ fmt(row.balanceAfter) }}</span>
					</template>
				</el-table-column>
				<el-table-column prop="sourceOrder" label="来源单号" min-width="130" align="center">
					<template #default="{ row }">
						<span class="tabular">{{ row.sourceOrder }}</span>
					</template>
				</el-table-column>
				<el-table-column prop="remark" label="备注" min-width="140" show-overflow-tooltip />
			</el-table>
		</el-drawer>
	</div>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { MOCK_CUSTOMERS, MOCK_TXN, customerName } from '../../../mock/points'
import { saveBlob } from '../../../utils/fileDownload'

const route = useRoute()
const customers = MOCK_CUSTOMERS
const search = reactive({ customerId: route.query.customer ? +route.query.customer : null })
const dateRange = ref([])
const total = ref(0)
const page = ref(1)
const pageSize = ref(20)
const loading = ref(false)
const exporting = ref(false)

// Drawer 状态
const drawerVisible = ref(false)
const detailLoading = ref(false)
const currentRow = ref(null)
const drawerTitle = ref('积分流水明细')
const detailList = ref([])

// 按期间筛选后的流水
const scopedTxn = computed(() => {
	let list = [...MOCK_TXN]
	if (search.customerId) {
		list = list.filter((t) => t.customerId === search.customerId)
	}
	if (dateRange.value && dateRange.value.length === 2) {
		const [s, e] = dateRange.value
		list = list.filter((t) => t.date >= s && t.date <= e)
	}
	return list
})

// 汇总每个客户的 期初 / 发放 / 核销 / 期末
const summaryRows = computed(() => {
	const txn = scopedTxn.value
	const ids = [...new Set(txn.map((t) => t.customerId))]
	const rows = []
	for (const id of ids) {
		const list = txn.filter((t) => t.customerId === id).sort((a, b) => a.date.localeCompare(b.date) || a.no.localeCompare(b.no))
		const opening = list.length ? list[0].balanceBefore : 0
		const grant = list.filter((t) => t.type === 'grant').reduce((s, t) => s + t.points, 0)
		const use = list.filter((t) => t.type === 'use').reduce((s, t) => s + Math.abs(t.points), 0)
		rows.push({
			customerId: id,
			customerName: customerName(id),
			opening,
			grant,
			use,
			closing: opening + grant - use,
		})
	}
	return rows.sort((a, b) => b.closing - a.closing)
})

const pagedList = computed(() => {
	const start = (page.value - 1) * pageSize.value
	return summaryRows.value.slice(start, start + pageSize.value)
})

function fmt(v) {
	return Number(v || 0).toLocaleString('en-US')
}

function summaryMethod({ columns }) {
	return columns.map((col, i) => {
		if (i === 0) return '合计'
		if (col.label === '期初积分') return fmt(summaryRows.value.reduce((s, r) => s + r.opening, 0))
		if (col.label === '本期发放') return '+' + fmt(summaryRows.value.reduce((s, r) => s + r.grant, 0))
		if (col.label === '本期核销') return '-' + fmt(summaryRows.value.reduce((s, r) => s + r.use, 0))
		if (col.label === '期末积分') return fmt(summaryRows.value.reduce((s, r) => s + r.closing, 0))
		return ''
	})
}

function refresh() {
	loading.value = true
	// TODO(api): 替换为 integral_txn.summary 接口
	setTimeout(() => {
		loading.value = false
		total.value = summaryRows.value.length
	}, 200)
}

function onSearch() {
	page.value = 1
	refresh()
}

function resetSearch() {
	search.customerId = null
	dateRange.value = []
	page.value = 1
	refresh()
}

function onPageChange(p) {
	page.value = p
}

function onSizeChange(s) {
	pageSize.value = s
	page.value = 1
}

function openDetail(row) {
	if (!row) return
	currentRow.value = row
	drawerTitle.value = `${row.customerName} · 积分流水明细`
	drawerVisible.value = true
	detailLoading.value = true
	// 按日期正序展示该客户的流水
	const list = MOCK_TXN.filter((t) => t.customerId === row.customerId).sort((a, b) => a.date.localeCompare(b.date) || a.no.localeCompare(b.no))
	// TODO(api): 替换为 integral_txn.detail 接口
	setTimeout(() => {
		detailList.value = list
		detailLoading.value = false
	}, 250)
}

async function exportData() {
	exporting.value = true
	try {
		const rows = summaryRows.value
		const header = ['序号', '客户', '期初积分', '本期发放', '本期核销', '期末积分']
		const lines = rows.map((r, i) => [i + 1, r.customerName, r.opening, r.grant, r.use, r.closing])
		lines.push(['', '合计', rows.reduce((s, r) => s + r.opening, 0), rows.reduce((s, r) => s + r.grant, 0), rows.reduce((s, r) => s + r.use, 0), rows.reduce((s, r) => s + r.closing, 0)])
		const csv = [header, ...lines].map((r) => r.map((c) => `"${c}"`).join(',')).join('\r\n')
		saveBlob(new Blob(['' + csv], { type: 'text/csv;charset=utf-8' }), '积分查询.csv')
	} finally {
		exporting.value = false
	}
}

watch(
	() => route.query.customer,
	(val) => {
		search.customerId = val ? +val : null
		page.value = 1
		refresh()
	},
	{ immediate: false }
)

onMounted(refresh)
</script>

<style scoped>
.pts-page {
	display: flex;
	flex-direction: column;
	height: 100%;
}
.pts-filter {
	display: flex;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
	padding: 12px 16px;
	background: var(--bg-card);
	border-bottom: 1px solid var(--border-lighter);
}
.pts-filter__label {
	font-size: var(--font-base);
	color: var(--text-regular);
	white-space: nowrap;
}
.pts-table {
	flex: 1;
	padding: 0 16px;
	display: flex;
	flex-direction: column;
	min-height: 0;
}
.pts-table :deep(.el-table) {
	flex: 1;
}
.pts-table :deep(.el-table th.el-table__cell) {
	background: var(--bg-hover);
	color: var(--text-regular);
	font-weight: 600;
	font-size: var(--font-base);
}
.pts-table :deep(.el-table td.el-table__cell) {
	color: var(--text-primary);
	font-size: var(--font-base);
}
.pts-table :deep(.el-table__row) {
	height: 40px;
	cursor: pointer;
}
.pts-table :deep(.el-table__footer) {
	background: var(--bg-hover);
}
.pts-table :deep(.el-table__footer td.el-table__cell) {
	color: var(--text-primary);
	font-weight: 600;
	font-size: var(--font-base);
}
.pts-hint {
	margin-top: 8px;
	font-size: var(--font-aux);
	color: var(--text-secondary);
}
.pts-num--up {
	color: var(--color-danger);
	font-weight: 600;
}
.pts-num--down {
	color: var(--color-success);
	font-weight: 600;
}
.pts-num--total {
	color: var(--text-primary);
	font-weight: 600;
}
.pts-pager {
	display: flex;
	justify-content: flex-end;
	padding: 12px 16px 0;
}
.drawer-summary {
	display: flex;
	gap: 20px;
	flex-wrap: wrap;
	padding: 0 4px 12px;
	margin-bottom: 12px;
	border-bottom: 1px solid var(--border-lighter);
	font-size: var(--font-base);
	color: var(--text-regular);
}
.drawer-summary b {
	color: var(--text-primary);
	font-family: var(--font-numeric);
	font-variant-numeric: tabular-nums;
}
</style>
