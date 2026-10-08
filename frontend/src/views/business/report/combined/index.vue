<template>
	<div class="rp-page">
		<ReportFilterBar
			:model-value="filter"
			:fields="FILTER_FIELDS"
			report="combined"
			:loading="loading"
			:exporting="exporting"
			@search="onSearch"
			@refresh="fetchData"
			@export="onExport"
			@print="onPrint"
		/>

		<!-- 4 张概览卡片 -->
		<div class="rp-cards">
			<div v-for="c in cards" :key="c.key" class="rp-card">
				<div class="rp-card-label">{{ c.title }}</div>
				<div class="rp-card-value">{{ formatCard(c) }}</div>
				<div class="rp-card-compare" :class="c.trend === 'up' ? 'rp-success' : 'rp-danger'">
					{{ c.compare }}
				</div>
			</div>
			<div v-if="!cards.length" class="rp-card">
				<div class="rp-card-label">加载中…</div>
				<div class="rp-card-value">—</div>
			</div>
		</div>

		<el-tabs v-model="activeTab" class="rp-tabs" @tab-change="onTabChange">
			<el-tab-pane v-for="t in TABS" :key="t.value" :label="t.label" :name="t.value">
				<ReportTable
					:columns="columns"
					:data="list"
					:loading="loading"
					:total="total"
					:current-page="page"
					:page-size="pageSize"
					:summary="summary"
					@page-change="onPageChange"
					@page-size-change="onPageSizeChange"
					@refresh="fetchData"
				/>
			</el-tab-pane>
		</el-tabs>
	</div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import businessApi from '@/api/business'
import ReportFilterBar from '../components/ReportFilterBar.vue'
import ReportTable from '../components/ReportTable.vue'
import { saveBlob, printTable } from '@/utils/fileDownload'

const TABS = [
	{ value: 'sales', label: '销售汇总' },
	{ value: 'stock', label: '库存汇总' },
	{ value: 'purchase', label: '采购汇总' },
	{ value: 'finance', label: '财务汇总' },
	{ value: 'customer', label: '客户分析' },
	{ value: 'product', label: '商品分析' },
]

const FILTER_FIELDS = ['date_type', 'date_range', 'warehouse_ids', 'salesman_ids']

const activeTab = ref('sales')
const cards = ref([])
const list = ref([])
const columns = ref([])
const summary = ref(null)
const total = ref(0)
const page = ref(1)
const pageSize = ref(30)
const loading = ref(false)
const exporting = ref(false)

const filter = reactive({
	date_type: 'declare', start_date: '', end_date: '',
	warehouse_ids: [], salesman_ids: [],
})

function setDefaultRange() {
	const now = new Date()
	const first = new Date(now.getFullYear(), now.getMonth(), 1)
	const fmt = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
	filter.start_date = fmt(first)
	filter.end_date = fmt(now)
}
setDefaultRange()

function formatCard(c) {
	if (c.format === 'money') return '¥' + Number(c.value || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
	return String(Math.round(Number(c.value || 0)))
}

function buildParams() {
	const p = { dimension: activeTab.value, page: page.value, page_size: pageSize.value }
	Object.keys(filter).forEach((k) => {
		const v = filter[k]
		if (Array.isArray(v)) {
			if (v.length) p[k] = v
		} else if (v !== '' && v !== null && v !== undefined) {
			p[k] = v
		}
	})
	return p
}

async function fetchData() {
	loading.value = true
	try {
		const res = await businessApi.report.combined.get(buildParams())
		if (res.code === 200) {
			const d = res.data || {}
			list.value = d.list || []
			columns.value = d.columns || []
			summary.value = d.summary || null
			total.value = d.total || 0
			if (Array.isArray(d.overview)) cards.value = d.overview
		}
	} catch {
		ElMessage.error('加载综合报表失败')
	} finally {
		loading.value = false
	}
}

function onTabChange() {
	page.value = 1
	list.value = []
	columns.value = []
	summary.value = null
	fetchData()
}
function onSearch() {
	page.value = 1
	fetchData()
}
function onPageChange(p) {
	page.value = p
	fetchData()
}
function onPageSizeChange(s) {
	pageSize.value = s
	page.value = 1
	fetchData()
}
async function onExport() {
	exporting.value = true
	try {
		const blob = await businessApi.report.combined.export.get(buildParams())
		saveBlob(blob, `综合报表_${TABS.find((t) => t.value === activeTab.value)?.label || ''}.csv`)
	} catch {
		ElMessage.error('导出失败')
	} finally {
		exporting.value = false
	}
}
function onPrint() {
	if (!columns.value.length) return ElMessage.warning('当前没有可打印的数据')
	printTable(columns.value, list.value, `综合报表 - ${TABS.find((t) => t.value === activeTab.value)?.label || ''}`)
}

onMounted(fetchData)
</script>
