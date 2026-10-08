<template>
	<div class="rp-page">
		<ReportFilterBar
			:model-value="filter"
			:fields="FILTER_FIELDS"
			report="stock"
			:loading="loading"
			:exporting="exporting"
			@search="onSearch"
			@refresh="fetchData"
			@export="onExport"
			@print="onPrint"
		/>

		<el-tabs v-model="activeTab" class="rp-tabs" @tab-change="onTabChange">
			<el-tab-pane v-for="t in TABS" :key="t.value" :label="t.label" :name="t.value">
				<div v-if="t.value === 'help'" class="rp-help">
					<h3>库存报表使用说明</h3>
					<ol>
						<li v-for="(s, i) in helpSteps" :key="i">{{ s }}</li>
					</ol>
					<el-empty v-if="!helpSteps.length" description="暂无帮助内容" />
				</div>

				<!-- 库存负数标红由列定义的 negative_red 驱动，ReportTable 统一处理 -->
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

const TABS = [
	{ value: 'product_detail', label: '商品明细' },
	{ value: 'product', label: '商品汇总' },
	{ value: 'warehouse_product', label: '仓库商品汇总' },
	{ value: 'brand', label: '品牌汇总' },
	{ value: 'help', label: '帮助视频' },
]

/** 库存报表 6 项查询条件：商品、仓库、品牌、大类、小类，外加「统计为 0 商品」开关 */
const FILTER_FIELDS = [
	'warehouse_ids', 'brand_ids', 'main_category_ids', 'sub_category_ids',
	'product_ids', 'product_code', 'product_name', 'include_zero',
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

const filter = reactive({
	warehouse_ids: [], brand_ids: [], main_category_ids: [], sub_category_ids: [],
	product_ids: [], product_code: '', product_name: '',
	include_zero: false,
})

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
		const res = await businessApi.report.stock.get(buildParams())
		if (res.code === 200) {
			const d = res.data || {}
			list.value = d.list || []
			columns.value = d.columns || []
			summary.value = d.summary || null
			total.value = d.total || 0
			helpSteps.value = d.help?.steps || helpSteps.value
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
async function onExport() {
	exporting.value = true
	try {
		const blob = await businessApi.report.stock.export.get(buildParams())
		saveBlob(blob, `库存报表_${TABS.find((t) => t.value === activeTab.value)?.label || ''}.csv`)
	} catch {
		ElMessage.error('导出失败')
	} finally {
		exporting.value = false
	}
}
function onPrint() {
	if (!columns.value.length) return ElMessage.warning('当前没有可打印的数据')
	printTable(columns.value, list.value, `库存报表 - ${TABS.find((t) => t.value === activeTab.value)?.label || ''}`)
}

onMounted(fetchData)
</script>
