<template>
	<div class="rp-page">
		<ReportFilterBar
			:model-value="filter"
			:fields="FILTER_FIELDS"
			report="receivable"
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
					report="receivable"
					:columns="columns"
					:data="list"
					:loading="loading"
					:total="total"
					:current-page="page"
					:page-size="pageSize"
					:summary="summary"
					:drill-fetcher="drillFetcher"
					:drill-payload="drillPayload"
					:drill-drawer="GenericDrillDrawer"
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
import GenericDrillDrawer from '../components/GenericDrillDrawer.vue'
import { saveBlob, printTable } from '@/utils/fileDownload'

/** 4 个 tab 与后端 DIMENSIONS 一一对应，顺序即设计方案里的 tab 顺序 */
const TABS = [
	{ value: 'receivable', label: '应收款明细' },
	{ value: 'balance', label: '客户余额一览' },
	{ value: 'attendance', label: '业务员考勤' },
	{ value: 'visit', label: '业务访店行程' },
]

/**
 * 应收/访店族查询条件：截止日期对应收账龄生效（默认今天，未结清单按此日算账龄）；
 * 日期区间对考勤/访店生效；客户/业务员跨维度通用，后端各自忽略不相关的键。
 */
const FILTER_FIELDS = [
	'as_of_date', 'date_range', 'customer_ids', 'customer_category', 'customer_level_ids',
	'channel_category', 'customer_name', 'salesman_ids', 'order_no',
]

const activeTab = ref('receivable')
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
		as_of_date: '', start_date: '', end_date: '',
		customer_ids: [], customer_category: [], customer_level_ids: [],
		channel_category: [], customer_name: '', salesman_ids: [], order_no: '',
	}
}
const filter = reactive(blankFilter())

/** 应收默认截止今天；考勤/访店默认本月（流水维度，开页面全量会淹死用户） */
function setDefaultDates() {
	const now = new Date()
	const fmt = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
	filter.as_of_date = fmt(now)
	filter.start_date = fmt(new Date(now.getFullYear(), now.getMonth(), 1))
	filter.end_date = fmt(now)
}
setDefaultDates()

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
		const res = await businessApi.report.receivable.get(buildParams())
		if (res.code === 200) {
			const d = res.data || {}
			list.value = d.list || []
			columns.value = d.columns || []
			summary.value = d.summary || null
			total.value = d.total || 0
			page.value = d.page || page.value
		}
	} catch {
		ElMessage.error('加载应收/访店报表失败')
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

// ================= 穿透（可穿）：双击行 → 源单/源明细 =================
// 应收/余额穿透回该客户未结单据；考勤穿透回该员工当日拜访；访店穿透回该业务员行程明细。
// 载荷按当前 tab 构造，后端 drill 的 match 与 index 的 dimension 同源，key 一一对应。
const drillFetcher = (payload) => {
	return businessApi.report.receivable.drill.get({ ...payload, ...buildParams() })
}

function drillPayload(row) {
	const dim = activeTab.value
	if (dim === 'receivable' || dim === 'balance') return row?.customer_id != null ? { customer_id: row.customer_id } : null
	if (dim === 'attendance') return row?.employee_user_id != null && row?.date != null
		? { employee_user_id: row.employee_user_id, date: row.date } : null
	if (dim === 'visit') return row?.salesman_id != null ? { salesman_id: row.salesman_id } : null
	return null
}

async function onExport() {
	exporting.value = true
	try {
		const blob = await businessApi.report.receivable.export.get(buildParams())
		saveBlob(blob, `应收访店_${currentLabel()}.xls`)
	} catch {
		ElMessage.error('导出失败')
	} finally {
		exporting.value = false
	}
}
function onPrint() {
	if (!columns.value.length) return ElMessage.warning('当前没有可打印的数据')
	printTable(columns.value, list.value, `应收访店 - ${currentLabel()}`)
}

onMounted(fetchData)
</script>
