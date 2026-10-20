<template>
	<div class="bal-page">
		<el-tabs v-model="activeTab" class="bal-tabs">
			<!-- Tab1：客户余额查询 -->
			<el-tab-pane label="客户余额查询" name="customer">
				<!-- 筛选 -->
				<div class="bal-filter">
					<span class="bal-filter__label">客户</span>
					<el-input v-model="search.keyword" placeholder="客户名称" clearable size="small" style="width: 200px" @keyup.enter="refresh" @clear="refresh" />
					<span class="bal-filter__label">科目</span>
					<el-select v-model="search.subject" clearable placeholder="全部科目" size="small" style="width: 180px" @change="refresh">
						<el-option v-for="s in subjects" :key="s" :label="s" :value="s" />
					</el-select>
					<span class="bal-filter__label">往来单位类型</span>
					<el-select v-model="search.unitType" clearable placeholder="全部类型" size="small" style="width: 160px" @change="refresh">
						<el-option v-for="t in unitTypes" :key="t" :label="t" :value="t" />
					</el-select>
					<span class="bal-filter__label">余额日期</span>
					<el-date-picker v-model="search.balanceDate" type="date" value-format="YYYY-MM-DD" size="small" style="width: 160px" @change="refresh" />
					<el-button type="primary" size="small" @click="refresh">查询</el-button>
					<el-button size="small" @click="resetSearch">重置</el-button>
					<div style="flex: 1" />
					<el-button size="small" :loading="exporting" @click="exportData">导出</el-button>
				</div>

				<!-- 表格 -->
				<div class="bal-table">
					<el-table :data="pagedList" border v-loading="loading" show-summary :summary-method="summaryMethod">
						<el-table-column type="index" label="序号" width="50" align="center" />
						<el-table-column prop="category" label="类别" width="100" align="left" show-overflow-tooltip />
						<el-table-column prop="unit" label="往来单位" min-width="180" show-overflow-tooltip />
						<el-table-column prop="subject" label="科目" width="120" align="left" show-overflow-tooltip />
						<el-table-column label="预收款" width="120" align="right">
							<template #default="{ row }">
								<span class="tabular">{{ fmt(row.prepaid) }}</span>
							</template>
						</el-table-column>
						<el-table-column label="应收款" width="120" align="right">
							<template #default="{ row }">
								<span class="tabular">{{ fmt(row.receivable) }}</span>
							</template>
						</el-table-column>
						<el-table-column label="余额" width="130" align="right">
							<template #default="{ row }">
								<span class="tabular" :class="row.balance < 0 ? 'bal-num--neg' : 'bal-num--pos'">{{ fmt(row.balance) }}</span>
							</template>
						</el-table-column>
						<el-table-column prop="date" label="日期" width="110" align="center">
							<template #default="{ row }">
								<span class="tabular">{{ row.date }}</span>
							</template>
						</el-table-column>
					</el-table>
				</div>

				<!-- 分页 -->
				<div class="bal-pager">
					<el-pagination background layout="total, prev, pager, next, sizes" :total="total" :page-size="pageSize" :current-page="page" :page-sizes="[10, 20, 50]" @current-change="onPageChange" @size-change="onSizeChange" />
				</div>
			</el-tab-pane>

			<!-- Tab2：辅助单位余额查询（暂未开放） -->
			<el-tab-pane label="辅助单位余额查询" name="aux">
				<div class="bal-placeholder">
					<el-empty description="暂未开放" />
				</div>
			</el-tab-pane>
		</el-tabs>
	</div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { MOCK_BALANCE_UNIT, MOCK_SUBJECTS } from '../../../mock/balance'
import { saveBlob } from '../../../utils/fileDownload'

const activeTab = ref('customer')
const subjects = MOCK_SUBJECTS
const unitTypes = ['客户', '业务员', '供应商']
const search = reactive({
	keyword: '',
	subject: '',
	unitType: '',
	balanceDate: '2026-10-17',
})
const total = ref(0)
const page = ref(1)
const pageSize = ref(20)
const loading = ref(false)
const exporting = ref(false)

const filtered = computed(() => {
	let list = [...MOCK_BALANCE_UNIT]
	const kw = search.keyword.trim().toLowerCase()
	if (kw) {
		list = list.filter((r) => r.unit.toLowerCase().includes(kw))
	}
	if (search.subject) {
		list = list.filter((r) => r.subject === search.subject)
	}
	if (search.unitType) {
		list = list.filter((r) => r.unitType === search.unitType)
	}
	if (search.balanceDate) {
		list = list.filter((r) => r.date === search.balanceDate)
	}
	return list
})

const pagedList = computed(() => {
	const start = (page.value - 1) * pageSize.value
	return filtered.value.slice(start, start + pageSize.value)
})

function fmt(v) {
	return Number(v || 0).toLocaleString('en-US')
}

function summaryMethod({ columns }) {
	return columns.map((col, i) => {
		if (i === 0) return '合计'
		if (col.label === '预收款') return fmt(filtered.value.reduce((s, r) => s + r.prepaid, 0))
		if (col.label === '应收款') return fmt(filtered.value.reduce((s, r) => s + r.receivable, 0))
		if (col.label === '余额') return fmt(filtered.value.reduce((s, r) => s + r.balance, 0))
		return ''
	})
}

function refresh() {
	loading.value = true
	// TODO(api): 替换为 fin_balance_snapshot.list 接口
	setTimeout(() => {
		loading.value = false
		total.value = filtered.value.length
	}, 200)
}

function resetSearch() {
	search.keyword = ''
	search.subject = ''
	search.unitType = ''
	search.balanceDate = '2026-10-17'
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

async function exportData() {
	exporting.value = true
	try {
		const rows = filtered.value
		const header = ['序号', '类别', '往来单位', '科目', '预收款', '应收款', '余额', '日期']
		const lines = rows.map((r, i) => [i + 1, r.category, r.unit, r.subject, r.prepaid, r.receivable, r.balance, r.date])
		lines.push(['', '合计', '', '', rows.reduce((s, r) => s + r.prepaid, 0), rows.reduce((s, r) => s + r.receivable, 0), rows.reduce((s, r) => s + r.balance, 0), ''])
		const csv = [header, ...lines].map((r) => r.map((c) => `"${c}"`).join(',')).join('\r\n')
		saveBlob(new Blob(['' + csv], { type: 'text/csv;charset=utf-8' }), '客户余额查询.csv')
	} finally {
		exporting.value = false
	}
}

onMounted(refresh)
</script>

<style scoped>
.bal-page {
	height: 100%;
	display: flex;
	flex-direction: column;
}
.bal-tabs {
	flex: 1;
	display: flex;
	flex-direction: column;
}
.bal-tabs :deep(.el-tabs__header) {
	margin: 0 0 12px;
	padding: 0 16px;
}
.bal-tabs :deep(.el-tabs__item) {
	font-size: var(--font-base);
	font-weight: 600;
}
.bal-tabs :deep(.el-tab-pane) {
	display: flex;
	flex-direction: column;
	flex: 1;
	min-height: 0;
}
.bal-filter {
	display: flex;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
	padding: 12px 16px;
	background: var(--bg-card);
	border-bottom: 1px solid var(--border-lighter);
}
.bal-filter__label {
	font-size: var(--font-base);
	color: var(--text-regular);
	white-space: nowrap;
}
.bal-table {
	flex: 1;
	padding: 0 16px;
	display: flex;
	flex-direction: column;
	min-height: 0;
}
.bal-table :deep(.el-table) {
	flex: 1;
}
.bal-table :deep(.el-table th.el-table__cell) {
	background: var(--bg-hover);
	color: var(--text-regular);
	font-weight: 600;
	font-size: var(--font-base);
}
.bal-table :deep(.el-table td.el-table__cell) {
	color: var(--text-primary);
	font-size: var(--font-base);
}
.bal-table :deep(.el-table__row) {
	height: 40px;
}
.bal-table :deep(.el-table__footer) {
	background: var(--bg-hover);
}
.bal-table :deep(.el-table__footer td.el-table__cell) {
	color: var(--text-primary);
	font-weight: 600;
	font-size: var(--font-base);
}
.bal-num--neg {
	color: var(--color-danger);
	font-weight: 600;
}
.bal-num--pos {
	color: var(--text-primary);
	font-weight: 600;
}
.bal-pager {
	display: flex;
	justify-content: flex-end;
	padding: 12px 16px 0;
}
.bal-placeholder {
	flex: 1;
	display: flex;
	align-items: center;
	justify-content: center;
}
</style>
