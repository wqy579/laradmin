<template>
	<div class="rp-page">
		<ReportFilterBar
			:model-value="filter"
			:fields="FILTER_FIELDS"
			report="salesman"
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
	{ value: 'salesman', label: '按业务员' },
	{ value: 'salesman_customer', label: '按业务员客户' },
	{ value: 'salesman_product', label: '按业务员商品' },
	{ value: 'salesman_customer_product', label: '按业务员客户商品' },
	{ value: 'salesman_brand_product', label: '按业务员品牌商品' },
]

/** 16 项查询条件 */
const FILTER_FIELDS = [
	'date_type', 'date_range',
	'salesman_ids', 'customer_ids', 'customer_category', 'customer_level_ids',
	'channel_category', 'channel_sub_category',
	'brand_ids', 'main_category_ids', 'sub_category_ids', 'product_ids', 'warehouse_ids',
	'sale_types', 'sources', 'order_no', 'include_red_flush', 'with_price',
]

const activeTab = ref('salesman')
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
	salesman_ids: [], customer_ids: [], customer_category: [], customer_level_ids: [],
	channel_category: [], channel_sub_category: [],
	brand_ids: [], main_category_ids: [], sub_category_ids: [], product_ids: [], warehouse_ids: [],
	sale_types: [], sources: [], order_no: '', include_red_flush: false, with_price: true,
})

function setDefaultRange() {
	const now = new Date()
	const first = new Date(now.getFullYear(), now.getMonth(), 1)
	const fmt = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
	filter.start_date = fmt(first)
	filter.end_date = fmt(now)
}
setDefaultRange()

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
		const res = await businessApi.report.salesman.get(buildParams())
		if (res.code === 200) {
			const d = res.data || {}
			list.value = d.list || []
			columns.value = d.columns || []
			summary.value = d.summary || null
			total.value = d.total || 0
		}
	} catch {
		ElMessage.error('加载业务员报表失败')
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
		const blob = await businessApi.report.salesman.export.get(buildParams())
		saveBlob(blob, `业务员报表_${TABS.find((t) => t.value === activeTab.value)?.label || ''}.csv`)
	} catch {
		ElMessage.error('导出失败')
	} finally {
		exporting.value = false
	}
}
function onPrint() {
	if (!columns.value.length) return ElMessage.warning('当前没有可打印的数据')
	printTable(columns.value, list.value, `业务员报表 - ${TABS.find((t) => t.value === activeTab.value)?.label || ''}`)
}

onMounted(fetchData)
</script>
