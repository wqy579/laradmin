<template>
	<div class="rp-page">
		<ReportFilterBar
			:model-value="filter"
			:fields="FILTER_FIELDS"
			report="sales"
			:loading="loading"
			:exporting="exporting"
			@search="onSearch"
			@refresh="fetchData"
			@export="onExport"
			@print="onPrint"
		/>

		<el-tabs v-model="activeTab" class="rp-tabs" @tab-change="onTabChange">
			<el-tab-pane v-for="t in TABS" :key="t.value" :label="t.label" :name="t.value">
				<!-- 帮助视频 -->
				<div v-if="t.value === 'help'" class="rp-help">
					<h3>销售报表使用说明</h3>
					<ol>
						<li v-for="(s, i) in helpSteps" :key="i">{{ s }}</li>
					</ol>
					<el-empty v-if="!helpSteps.length" description="暂无帮助内容" />
				</div>

				<ReportTable
					v-else
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

/**
 * 13 个 tab 与后端 DIMENSIONS 一一对应，顺序即为设计方案里的 tab 顺序。
 * 切换 tab 只改 dimension，筛选条件共用一套——这是方案里「30 项查询条件 + 13 个 tab」的要求。
 */
const TABS = [
	{ value: 'product_detail', label: '商品明细' },
	{ value: 'customer', label: '客户' },
	{ value: 'customer_product', label: '客户商品' },
	{ value: 'customer_category_product', label: '客户大类商品' },
	{ value: 'product', label: '商品' },
	{ value: 'warehouse_product', label: '仓库商品' },
	{ value: 'brand', label: '品牌' },
	{ value: 'customer_category_sale_type', label: '客户大类销售类型' },
	{ value: 'customer_subcategory_sale_type', label: '客户小类销售类型' },
	{ value: 'customer_product_sale_type', label: '客户商品销售类型' },
	{ value: 'product_sale_type', label: '商品销售类型' },
	{ value: 'doc', label: '单据' },
	{ value: 'help', label: '帮助视频' },
]

/** 30 项查询条件：key 与 ReportFilter 解析的入参同名，顺序即展示顺序 */
const FILTER_FIELDS = [
	'date_type', 'date_range', 'start_time', 'end_time',
	'customer_category', 'customer_level_ids', 'channel_category', 'channel_sub_category',
	'customer_ids', 'customer_code', 'customer_name',
	'brand_ids', 'main_category_ids', 'sub_category_ids', 'product_ids', 'warehouse_ids',
	'product_code', 'product_name', 'is_new', 'is_key',
	'sale_types', 'sources', 'payment_methods', 'salesman_ids', 'delivery_person_ids',
	'operator_ids', 'approver_ids', 'order_no', 'delivery_no', 'remark',
	'include_red_flush', 'with_price', 'zero_sale',
]

const activeTab = ref('product_detail')
const list = ref([])
const columns = ref([])
const summary = ref(null)
const helpSteps = ref([])
const total = ref(0)
const page = ref(1)
const pageSize = ref(30)
const loading = ref(false)
const exporting = ref(false)

function blankFilter() {
	return {
		date_type: 'declare',
		start_date: '', end_date: '', start_time: '', end_time: '',
		customer_category: [], customer_level_ids: [], channel_category: [], channel_sub_category: [],
		customer_ids: [], customer_code: '', customer_name: '',
		brand_ids: [], main_category_ids: [], sub_category_ids: [], product_ids: [], warehouse_ids: [],
		product_code: '', product_name: '', is_new: false, is_key: false,
		sale_types: [], sources: [], payment_methods: [], salesman_ids: [], delivery_person_ids: [],
		operator_ids: [], approver_ids: [], order_no: '', delivery_no: '', remark: '',
		include_red_flush: false, with_price: true, zero_sale: '',
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
	if (activeTab.value === 'help') {
		loading.value = true
		try {
			const res = await businessApi.report.sales.get({ dimension: 'help' })
			if (res.code === 200) helpSteps.value = res.data?.help?.steps || []
		} catch {
			ElMessage.error('加载失败')
		} finally {
			loading.value = false
		}
		return
	}

	loading.value = true
	try {
		const res = await businessApi.report.sales.get(buildParams())
		if (res.code === 200) {
			const d = res.data || {}
			list.value = d.list || []
			columns.value = d.columns || []
			summary.value = d.summary || null
			total.value = d.total || 0
			page.value = d.page || page.value
		}
	} catch {
		ElMessage.error('加载销售报表失败')
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
		const blob = await businessApi.report.sales.export.get(buildParams())
		saveBlob(blob, `销售报表_${TABS.find((t) => t.value === activeTab.value)?.label || ''}.csv`)
	} catch {
		ElMessage.error('导出失败')
	} finally {
		exporting.value = false
	}
}
function onPrint() {
	if (!columns.value.length) return ElMessage.warning('当前没有可打印的数据')
	printTable(columns.value, list.value, `销售报表 - ${TABS.find((t) => t.value === activeTab.value)?.label || ''}`)
}

onMounted(fetchData)
</script>
