<template>
	<div class="rp-page">
		<ReportFilterBar
			:model-value="filter"
			:fields="FILTER_FIELDS"
			report="customer"
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
					report="customer"
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
					@row-dblclick="onRowDblclick"
				/>
			</el-tab-pane>
		</el-tabs>

		<!-- 穿透：客户维度双击 → 该客户订单列表抽屉 -->
		<CustomerDrillDrawer v-model="drillOpen" :payload="drillPayload" :fetcher="businessApi.report.customer.drill.get" />
	</div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import businessApi from '@/api/business'
import ReportFilterBar from '../components/ReportFilterBar.vue'
import ReportTable from '../components/ReportTable.vue'
import CustomerDrillDrawer from '../components/CustomerDrillDrawer.vue'
import { saveBlob, printTable } from '@/utils/fileDownload'

/**
 * 6 个 tab 与后端 DIMENSIONS 一一对应，顺序即为设计方案里的 tab 顺序。
 * 切换 tab 只改 dimension，筛选条件共用一套——客户类报表共用一套查询条件。
 */
const TABS = [
	{ value: 'order_detail', label: '客户订单明细' },
	{ value: 'shipped_uncollected', label: '发货未收款' },
	{ value: 'return_rate_mom', label: '日月增长退货率对比' },
	{ value: 'customer_sales', label: '客户销售汇总' },
	{ value: 'customer_order', label: '客户订货汇总' },
	{ value: 'return_summary', label: '退货汇总' },
]

/** 客户类报表支持的查询条件：key 与 ReportFilter 解析的入参同名，顺序即展示顺序 */
const FILTER_FIELDS = [
	'date_type', 'date_range', 'start_time', 'end_time',
	'customer_category', 'customer_level_ids', 'customer_ids', 'customer_code', 'customer_name',
	'salesman_ids', 'sources', 'include_red_flush',
]

const activeTab = ref('order_detail')
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
		customer_category: [], customer_level_ids: [], customer_ids: [],
		customer_code: '', customer_name: '',
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

/** 穿透抽屉：双击客户行打开该客户订单列表；无 customer_id 的行不可穿透 */
const drillOpen = ref(false)
const drillPayload = ref(null)
function onRowDblclick(row) {
	if (row && row.customer_id) {
		drillPayload.value = { customer_id: row.customer_id }
		drillOpen.value = true
	}
}

async function fetchData() {
	loading.value = true
	try {
		const res = await businessApi.report.customer.get(buildParams())
		if (res.code === 200) {
			const d = res.data || {}
			list.value = d.list || []
			columns.value = d.columns || []
			summary.value = d.summary || null
			total.value = d.total || 0
			page.value = d.page || page.value
		}
	} catch {
		ElMessage.error('加载客户报表失败')
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
		const blob = await businessApi.report.customer.export.get(buildParams())
		saveBlob(blob, `客户报表_${TABS.find((t) => t.value === activeTab.value)?.label || ''}.xls`)
	} catch {
		ElMessage.error('导出失败')
	} finally {
		exporting.value = false
	}
}
function onPrint() {
	if (!columns.value.length) return ElMessage.warning('当前没有可打印的数据')
	printTable(columns.value, list.value, `客户报表 - ${TABS.find((t) => t.value === activeTab.value)?.label || ''}`)
}

onMounted(fetchData)
</script>