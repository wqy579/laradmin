<template>
	<div class="rp-page">
		<ReportFilterBar
			:model-value="filter"
			:fields="FILTER_FIELDS"
			report="procurement"
			:loading="loading"
			:exporting="exporting"
			@search="onSearch"
			@refresh="fetchData"
			@export="onExport"
			@print="onPrint"
		/>

		<el-tabs v-model="activeTab" class="rp-tabs" @tab-change="onTabChange">
			<el-tab-pane v-for="t in TABS" :key="t.value" :label="t.label" :name="t.value">
				<ReportTable
					report="procurement"
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

/** 8 个 tab 与后端 DIMENSIONS 一一对应，顺序即设计方案里的 tab 顺序 */
const TABS = [
	{ value: 'purchase', label: '采购汇总' },
	{ value: 'stock_in', label: '入库明细' },
	{ value: 'transfer', label: '调拨明细' },
	{ value: 'scrap', label: '报废报损' },
	{ value: 'stock_in_summary', label: '入库汇总' },
	{ value: 'stock_detail_summary', label: '收发存汇总' },
	{ value: 'expense', label: '费用查询' },
	{ value: 'price_plan', label: '价格方案' },
]

/**
 * 采购/入出库/费用族查询条件：日期区间对流水类维度生效，价格方案是当前快照与时间无关；
 * 供应商只对采购/入库相关维度有意义，费用类型只对费用查询有意义，后端各自忽略不相关的键。
 */
const FILTER_FIELDS = [
	'date_range', 'warehouse_ids', 'supplier_ids', 'brand_ids', 'main_category_ids',
	'sub_category_ids', 'product_ids', 'product_keyword', 'expense_types', 'include_zero',
]

const activeTab = ref('purchase')
const list = ref([])
const columns = ref([])
const summary = ref(null)
const total = ref(0)
const page = ref(1)
const pageSize = ref(30)
const loading = ref(false)
const exporting = ref(false)

function blankFilter() {
	return {
		start_date: '', end_date: '',
		warehouse_ids: [], supplier_ids: [], brand_ids: [], main_category_ids: [],
		sub_category_ids: [], product_ids: [], product_keyword: '',
		expense_types: [], include_zero: false,
	}
}
const filter = reactive(blankFilter())

/** 默认查本月：采购/入出库/费用都是流水，开页面就全量会把用户淹死 */
function setDefaultRange() {
	const now = new Date()
	const first = new Date(now.getFullYear(), now.getMonth(), 1)
	const fmt = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
	filter.start_date = fmt(first)
	filter.end_date = fmt(now)
}
setDefaultRange()

/** 空值不上传：后端对空串的关键字是「不筛选」，空数组同理，索性一律不送 */
function buildParams() {
	const p = { dimension: activeTab.value, page: page.value, page_size: pageSize.value }
	Object.keys(filter).forEach((k) => {
		const v = filter[k]
		if (Array.isArray(v)) {
			if (v.length) p[k] = v
		} else if (typeof v === 'boolean') {
			if (v) p[k] = 1
		} else if (v !== '' && v !== null && v !== undefined) {
			p[k] = v
		}
	})
	return p
}

async function fetchData() {
	loading.value = true
	try {
		const res = await businessApi.report.procurement.get(buildParams())
		if (res.code === 200) {
			const d = res.data || {}
			list.value = d.list || []
			columns.value = d.columns || []
			summary.value = d.summary || null
			total.value = d.total || 0
			page.value = d.page || page.value
		}
	} catch {
		ElMessage.error('加载采购/费用报表失败')
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

function currentLabel() {
	return TABS.find((t) => t.value === activeTab.value)?.label || ''
}

async function onExport() {
	exporting.value = true
	try {
		const blob = await businessApi.report.procurement.export.get(buildParams())
		saveBlob(blob, `采购费用_${currentLabel()}.xls`)
	} catch {
		ElMessage.error('导出失败')
	} finally {
		exporting.value = false
	}
}
function onPrint() {
	if (!columns.value.length) return ElMessage.warning('当前没有可打印的数据')
	printTable(columns.value, list.value, `采购费用 - ${currentLabel()}`)
}

onMounted(fetchData)
</script>
