<template>
	<div class="bal-page fin-page">
		<el-tabs v-model="activeTab" class="bal-tabs">
			<!-- Tab1：客户余额查询 -->
			<el-tab-pane label="客户余额查询" name="customer">
				<!-- 筛选 -->
				<div class="bal-filter fin-filter fin-toolbar">
					<span class="label">客户</span>
					<el-input v-model="search.keyword" placeholder="客户名称" clearable size="small" style="width: 200px" @keyup.enter="refresh" @clear="refresh" />
					<span class="label">科目</span>
					<el-select v-model="search.subject" clearable placeholder="全部科目" size="small" style="width: 180px" @change="refresh">
						<el-option v-for="s in subjects" :key="s" :label="s" :value="s" />
					</el-select>
					<span class="label">往来单位类型</span>
					<el-select v-model="search.unitType" clearable placeholder="全部类型" size="small" style="width: 160px" @change="refresh">
						<el-option v-for="t in unitTypes" :key="t" :label="t" :value="t" />
					</el-select>
					<span class="label">余额日期</span>
					<el-date-picker v-model="search.balanceDate" type="date" value-format="YYYY-MM-DD" size="small" style="width: 160px" @change="refresh" />
					<el-button type="primary" @click="refresh">查询</el-button>
					<el-button @click="resetSearch">重置</el-button>
					<div style="flex: 1" />
					<el-button :loading="exporting" @click="exportData">导出</el-button>
				</div>

				<!-- 表格 -->
				<div class="bal-table">
					<el-table :data="pagedList" border stripe v-loading="loading" show-summary :summary-method="summaryMethod">
						<template #empty><FinEmpty /></template>
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
				<div class="bal-pager fin-pager">
					<el-pagination background layout="total, prev, pager, next, sizes" :total="total" :page-size="pageSize" :current-page="page" :page-sizes="[10, 20, 50]" @current-change="onPageChange" @size-change="onSizeChange" />
				</div>
			</el-tab-pane>

			<!-- Tab2：品牌余额查询（D-08 综合双视图：按品牌维度 + 合计行） -->
			<el-tab-pane label="品牌余额查询" name="brand">
				<!-- 筛选 -->
				<div class="bal-filter fin-filter fin-toolbar">
					<span class="label">品牌</span>
					<el-select v-model="brandSearch.brand" clearable placeholder="全部品牌" size="small" style="width: 160px" @change="refreshBrand">
						<el-option v-for="b in brandOptions" :key="b" :label="b" :value="b" />
					</el-select>
					<span class="label">关键字</span>
					<el-input v-model="brandSearch.keyword" placeholder="往来单位/科目" clearable size="small" style="width: 200px" @keyup.enter="refreshBrand" @clear="refreshBrand" />
					<el-button type="primary" @click="refreshBrand">查询</el-button>
					<el-button @click="resetBrandSearch">重置</el-button>
					<div style="flex: 1" />
					<el-button :loading="brandExporting" @click="exportBrandData">导出</el-button>
				</div>

				<!-- 表格 -->
				<div class="bal-table">
					<el-table :data="brandPagedList" border stripe v-loading="brandLoading" show-summary :summary-method="brandSummaryMethod">
						<template #empty><FinEmpty /></template>
						<el-table-column type="index" label="序号" width="50" align="center" />
						<el-table-column prop="brand" label="品牌" width="110" align="left" show-overflow-tooltip />
						<el-table-column prop="category" label="类别" width="90" align="left" show-overflow-tooltip />
						<el-table-column prop="unit" label="往来单位" min-width="170" show-overflow-tooltip />
						<el-table-column prop="subject" label="科目" width="110" align="left" show-overflow-tooltip />
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
						<el-table-column label="合计" width="130" align="right">
							<template #default="{ row }">
								<span class="tabular" :class="row.total < 0 ? 'bal-num--neg' : 'bal-num--pos'">{{ fmt(row.total) }}</span>
							</template>
						</el-table-column>
					</el-table>
				</div>

				<!-- 分页 -->
				<div class="bal-pager fin-pager">
					<el-pagination background layout="total, prev, pager, next, sizes" :total="brandTotal" :page-size="brandPageSize" :current-page="brandPage" :page-sizes="[10, 20, 50]" @current-change="onBrandPageChange" @size-change="onBrandSizeChange" />
				</div>
			</el-tab-pane>

							<!-- Tab3：辅助单位余额查询 -->
				<el-tab-pane label="辅助单位余额查询" name="aux">
					<!-- 筛选 -->
					<div class="bal-filter fin-filter fin-toolbar">
						<span class="label">辅助单位类型</span>
						<el-select v-model="auxSearch.unitType" clearable placeholder="全部类型" size="small" style="width: 160px" @change="refreshAux">
							<el-option v-for="t in auxUnitTypes" :key="t" :label="t" :value="t" />
						</el-select>
						<span class="label">科目</span>
						<el-select v-model="auxSearch.subject" clearable placeholder="全部科目" size="small" style="width: 180px" @change="refreshAux">
							<el-option v-for="s in auxSubjects" :key="s" :label="s" :value="s" />
						</el-select>
						<span class="label">关键字</span>
						<el-input v-model="auxSearch.keyword" placeholder="辅助单位名称" clearable size="small" style="width: 200px" @keyup.enter="refreshAux" @clear="refreshAux" />
						<el-button type="primary" @click="refreshAux">查询</el-button>
						<el-button @click="resetAuxSearch">重置</el-button>
						<div style="flex: 1" />
						<el-button :loading="auxExporting" @click="exportAuxData">导出</el-button>
					</div>

					<!-- 表格 -->
					<div class="bal-table">
						<el-table :data="auxPagedList" border stripe v-loading="auxLoading" show-summary :summary-method="auxSummaryMethod">
							<template #empty><FinEmpty /></template>
							<el-table-column type="index" label="序号" width="50" align="center" />
							<el-table-column prop="category" label="类别" width="100" align="left" show-overflow-tooltip />
							<el-table-column prop="unit" label="辅助单位" min-width="180" show-overflow-tooltip />
							<el-table-column prop="subject" label="科目" width="120" align="left" show-overflow-tooltip />
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
					<div class="bal-pager fin-pager">
						<el-pagination background layout="total, prev, pager, next, sizes" :total="auxTotal" :page-size="auxPageSize" :current-page="auxPage" :page-sizes="[10, 20, 50]" @current-change="onAuxPageChange" @size-change="onAuxSizeChange" />
					</div>
				</el-tab-pane>
		</el-tabs>
	</div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import businessApi from '@/api/business'
import { saveBlob } from '@/utils/fileDownload'
import FinEmpty from '../_FinEmpty.vue'

const activeTab = ref('customer')
const unitTypes = ['客户', '业务员', '供应商']
const search = reactive({
	keyword: '',
	subject: '',
	unitType: '',
	balanceDate: '2026-10-17',
})
// 后端返回的真实余额数据（B3，读 ledger 分录按维度聚合）
const rows = ref([])
const total = ref(0)
const page = ref(1)
const pageSize = ref(20)
const loading = ref(false)
const exporting = ref(false)

// 科目下拉（由当前维度数据去重得出）
const subjects = computed(() => [...new Set(rows.value.map((r) => r.subject).filter(Boolean))])

const filtered = computed(() => {
	// 后端已按 keyword/subject/unitType 过滤，这里仅叠加客户端「余额日期」
	let list = rows.value
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
	return Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
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

const refresh = async () => {
	loading.value = true
	try {
		const res = await businessApi.finance.balance.get({
			dimension: 'customer',
			keyword: search.keyword || undefined,
			subject: search.subject || undefined,
			unitType: search.unitType || undefined,
		})
		const data = res?.data?.data ?? res?.data ?? {}
		rows.value = data.list || []
		total.value = rows.value.length
	} catch {
		rows.value = []
		total.value = 0
	} finally {
		loading.value = false
	}
}

function resetSearch() {
	search.keyword = ''
	search.subject = ''
	search.unitType = ''
	search.balanceDate = ''
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
		const list = filtered.value
		const header = ['序号', '类别', '往来单位', '科目', '预收款', '应收款', '余额', '日期']
		const lines = list.map((r, i) => [i + 1, r.category, r.unit, r.subject, r.prepaid, r.receivable, r.balance, r.date])
		lines.push(['', '合计', '', '', list.reduce((s, r) => s + r.prepaid, 0), list.reduce((s, r) => s + r.receivable, 0), list.reduce((s, r) => s + r.balance, 0), ''])
		const csv = [header, ...lines].map((r) => r.map((c) => `"${c}"`).join(',')).join('\r\n')
		saveBlob(new Blob(['' + csv], { type: 'text/csv;charset=utf-8' }), '客户余额查询.csv')
	} finally {
		exporting.value = false
	}
}

onMounted(refresh)

/* ---------- Tab2 品牌余额查询（独立状态，不与 Tab1 共用） ---------- */
const brandSearch = reactive({
	brand: '',
	keyword: '',
})
const brandRows = ref([])
const brandLoading = ref(false)
const brandExporting = ref(false)
const brandPage = ref(1)
const brandPageSize = ref(20)

const brandOptions = computed(() => [...new Set(brandRows.value.map((r) => r.brand).filter(Boolean))])

const brandFiltered = computed(() => brandRows.value)

const brandTotal = computed(() => brandFiltered.value.length)
const brandPagedList = computed(() => {
	const start = (brandPage.value - 1) * brandPageSize.value
	return brandFiltered.value.slice(start, start + brandPageSize.value)
})

function brandSummaryMethod({ columns }) {
	return columns.map((col, i) => {
		if (i === 0) return '合计'
		if (col.label === '预收款') return fmt(brandFiltered.value.reduce((s, r) => s + r.prepaid, 0))
		if (col.label === '应收款') return fmt(brandFiltered.value.reduce((s, r) => s + r.receivable, 0))
		if (col.label === '合计') return fmt(brandFiltered.value.reduce((s, r) => s + r.total, 0))
		return ''
	})
}

const refreshBrand = async () => {
	brandLoading.value = true
	try {
		const res = await businessApi.finance.balance.get({
			dimension: 'brand',
			brand: brandSearch.brand || undefined,
			keyword: brandSearch.keyword || undefined,
		})
		const data = res?.data?.data ?? res?.data ?? {}
		brandRows.value = data.list || []
	} catch {
		brandRows.value = []
	} finally {
		brandLoading.value = false
	}
}

function resetBrandSearch() {
	brandSearch.brand = ''
	brandSearch.keyword = ''
	brandPage.value = 1
	refreshBrand()
}

function onBrandPageChange(p) {
	brandPage.value = p
}

function onBrandSizeChange(s) {
	brandPageSize.value = s
	brandPage.value = 1
}

async function exportBrandData() {
	brandExporting.value = true
	try {
		const list = brandFiltered.value
		const header = ['序号', '品牌', '类别', '往来单位', '科目', '预收款', '应收款', '合计']
		const lines = list.map((r, i) => [i + 1, r.brand, r.category, r.unit, r.subject, r.prepaid, r.receivable, r.total])
		lines.push(['', '合计', '', '', '', list.reduce((s, r) => s + r.prepaid, 0), list.reduce((s, r) => s + r.receivable, 0), list.reduce((s, r) => s + r.total, 0)])
		const csv = [header, ...lines].map((r) => r.map((c) => `"${c}"`).join(',')).join('\r\n')
		saveBlob(new Blob(['' + csv], { type: 'text/csv;charset=utf-8' }), '品牌余额查询.csv')
	} finally {
		brandExporting.value = false
	}
}

/* ---------- Tab3 辅助单位余额查询（独立状态，与 Tab1/Tab2 互不影响） ---------- */
const auxUnitTypes = ['门店', '部门', '其他']
const auxSearch = reactive({
	unitType: '',
	subject: '',
	keyword: '',
})
const auxRows = ref([])
const auxLoading = ref(false)
const auxExporting = ref(false)
const auxPage = ref(1)
const auxPageSize = ref(20)

const auxSubjects = computed(() => [...new Set(auxRows.value.map((r) => r.subject).filter(Boolean))])

const auxFiltered = computed(() => auxRows.value)

const auxTotal = computed(() => auxFiltered.value.length)
const auxPagedList = computed(() => {
	const start = (auxPage.value - 1) * auxPageSize.value
	return auxFiltered.value.slice(start, start + auxPageSize.value)
})

function auxSummaryMethod({ columns }) {
	return columns.map((col, i) => {
		if (i === 0) return '合计'
		if (col.label === '余额') return fmt(auxFiltered.value.reduce((s, r) => s + r.balance, 0))
		return ''
	})
}

const refreshAux = async () => {
	auxLoading.value = true
	try {
		const res = await businessApi.finance.balance.get({
			dimension: 'aux',
			unitType: auxSearch.unitType || undefined,
			subject: auxSearch.subject || undefined,
			keyword: auxSearch.keyword || undefined,
		})
		const data = res?.data?.data ?? res?.data ?? {}
		auxRows.value = data.list || []
	} catch {
		auxRows.value = []
	} finally {
		auxLoading.value = false
	}
}

function resetAuxSearch() {
	auxSearch.unitType = ''
	auxSearch.subject = ''
	auxSearch.keyword = ''
	auxPage.value = 1
	refreshAux()
}

function onAuxPageChange(p) {
	auxPage.value = p
}

function onAuxSizeChange(s) {
	auxPageSize.value = s
	auxPage.value = 1
}

async function exportAuxData() {
	auxExporting.value = true
	try {
		const list = auxFiltered.value
		const header = ['序号', '类别', '辅助单位', '科目', '余额', '日期']
		const lines = list.map((r, i) => [i + 1, r.category, r.unit, r.subject, r.balance, r.date])
		lines.push(['', '合计', '', '', list.reduce((s, r) => s + r.balance, 0), ''])
		const csv = [header, ...lines].map((r) => r.map((c) => `"${c}"`).join(',')).join('\r\n')
		saveBlob(new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8' }), '辅助单位余额查询.csv')
	} finally {
		auxExporting.value = false
	}
}
</script>

<style>
@import '../_shared.css';
</style>

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
.bal-table :deep(.el-table td.el-table__cell) {
	color: var(--text-primary);
	font-size: var(--font-base);
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
	padding: 0 16px;
}
.bal-placeholder {
	flex: 1;
	display: flex;
	align-items: center;
	justify-content: center;
}
</style>
