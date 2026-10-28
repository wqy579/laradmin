<template>
	<div class="rp-page">
		<ReportFilterBar
			:model-value="filter"
			:fields="FILTER_FIELDS"
			report="stock-analysis"
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
					report="stock-analysis"
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
	{ value: 'expiry', label: '商品超期预警' },
	{ value: 'safety', label: '安全库存预警' },
	{ value: 'sellable', label: '可销天数' },
	{ value: 'change', label: '库存变动' },
	{ value: 'detail', label: '库存明细' },
	{ value: 'gain_loss', label: '盘盈盘亏汇总' },
	{ value: 'assembly', label: '商品拆装' },
	{ value: 'analysis', label: '库存分析' },
]

/**
 * 库存族查询条件：日期区间只对变动/明细/盘盈盘亏/拆装生效（商品快照类维度本来就与时间无关），
 * 阈值三项（临期/严重超期/日均窗口）与「只看预警」只对对应维度生效，后端各自取默认值。
 */
const FILTER_FIELDS = [
	'date_range', 'warehouse_ids', 'brand_ids', 'main_category_ids', 'sub_category_ids',
	'product_ids', 'product_keyword', 'near_days', 'severe_days', 'window',
	'only_warning', 'include_zero',
]

const activeTab = ref('expiry')
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
		warehouse_ids: [], brand_ids: [], main_category_ids: [], sub_category_ids: [],
		product_ids: [], product_keyword: '',
		near_days: 30, severe_days: 30, window: 30,
		only_warning: false, include_zero: false,
	}
}
const filter = reactive(blankFilter())

/** 默认查本月：库存变动/盘盈盘亏/拆装是流水，开页面就全量会把用户淹死 */
function setDefaultRange() {
	const now = new Date()
	const first = new Date(now.getFullYear(), now.getMonth(), 1)
	const fmt = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
	filter.start_date = fmt(first)
	filter.end_date = fmt(now)
}
setDefaultRange()

/** 空值不上传：后端对空串的关键字是「不筛选」，但数值阈值传空串会被当成 0（等于关掉预警） */
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
		const res = await businessApi.report.stockAnalysis.get(buildParams())
		if (res.code === 200) {
			const d = res.data || {}
			list.value = d.list || []
			columns.value = d.columns || []
			summary.value = d.summary || null
			total.value = d.total || 0
			page.value = d.page || page.value
		}
	} catch {
		ElMessage.error('加载库存报表失败')
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
		const blob = await businessApi.report.stockAnalysis.export.get(buildParams())
		saveBlob(blob, `库存分析_${currentLabel()}.xls`)
	} catch {
		ElMessage.error('导出失败')
	} finally {
		exporting.value = false
	}
}
function onPrint() {
	if (!columns.value.length) return ElMessage.warning('当前没有可打印的数据')
	printTable(columns.value, list.value, `库存分析 - ${currentLabel()}`)
}

onMounted(fetchData)
</script>
