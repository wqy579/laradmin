<template>
	<div class="rp-page">
		<ReportFilterBar
			:model-value="filter"
			:fields="FILTER_FIELDS"
			report="price-rank"
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
					report="price-rank"
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

/** 4 个 tab 与后端 DIMENSIONS 一一对应，顺序即设计方案里的 tab 顺序 */
const TABS = [
	{ value: 'sales_rank_total', label: '销售排行总表' },
	{ value: 'product_price_list', label: '商品价格一览' },
	{ value: 'customer_special_price', label: '客户特价价格一览' },
	{ value: 'promotion_price_list', label: '促销价格一览' },
]

/** 排行+价格类报表支持的查询条件：key 与 ReportFilter 解析的入参同名 */
const FILTER_FIELDS = [
	'date_type', 'date_range', 'start_time', 'end_time',
	'customer_level_ids', 'customer_ids', 'customer_name',
	'brand_ids', 'main_category_ids', 'sub_category_ids', 'product_ids', 'product_keyword',
	'salesman_ids', 'sources', 'include_red_flush',
]

const activeTab = ref('sales_rank_total')
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
		date_type: 'declare',
		start_date: '', end_date: '', start_time: '', end_time: '',
		customer_level_ids: [], customer_ids: [],
		customer_name: '',
		brand_ids: [], main_category_ids: [], sub_category_ids: [], product_ids: [],
		product_keyword: '',
		salesman_ids: [], sources: [],
		include_red_flush: false,
	}
}
const filter = reactive(blankFilter())

/** 默认查本月：开页面就是一片空白会让用户以为系统坏了 */
function setDefaultRange() {
	const now = new Date()
	const first = new Date(now.getFullYear(), now.getMonth(), 1)
	const fmt = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
	filter.start_date = fmt(first)
	filter.end_date = fmt(now)
}
setDefaultRange()

/** 空值不上传：后端对空数组的解析是「不筛选」，但空串会命中 LIKE '' 变成全量 */
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
		const res = await businessApi.report.priceRank.get(buildParams())
		if (res.code === 200) {
			const d = res.data || {}
			list.value = d.list || []
			columns.value = d.columns || []
			summary.value = d.summary || null
			total.value = d.total || 0
			page.value = d.page || page.value
		}
	} catch {
		ElMessage.error('加载排行/价格报表失败')
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
		const blob = await businessApi.report.priceRank.export.get(buildParams())
		saveBlob(blob, `价格排行_${TABS.find((t) => t.value === activeTab.value)?.label || ''}.xls`)
	} catch {
		ElMessage.error('导出失败')
	} finally {
		exporting.value = false
	}
}
function onPrint() {
	if (!columns.value.length) return ElMessage.warning('当前没有可打印的数据')
	printTable(columns.value, list.value, `价格排行 - ${TABS.find((t) => t.value === activeTab.value)?.label || ''}`)
}

onMounted(fetchData)
</script>