<template>
	<div class="rp-page">
		<ReportFilterBar
			:model-value="filter"
			:fields="FILTER_FIELDS"
			report="recent-price"
			:loading="loading"
			:exporting="exporting"
			@search="onSearch"
			@refresh="fetchData"
			@export="onExport"
			@print="onPrint"
		/>

		<ReportTable
			:columns="COLUMNS"
			:data="list"
			:loading="loading"
			:total="total"
			:current-page="page"
			:page-size="pageSize"
			@page-change="onPageChange"
			@page-size-change="onPageSizeChange"
			@refresh="fetchData"
		/>
	</div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import businessApi from '@/api/business'
import ReportFilterBar from '../report/components/ReportFilterBar.vue'
import ReportTable from '../report/components/ReportTable.vue'
import { saveBlob, printTable } from '@/utils/fileDownload'

/**
 * 最近价格：单表无 tab（方案明确「单表无 tab」）。
 * 销售单价按方案要求用 #f56c6c 加粗，是整个页面最该被看到的一列。
 */
const COLUMNS = [
	{ prop: 'product_code', label: '商品编码', width: 110, type: 'text' },
	{ prop: 'product_name', label: '商品名称', width: 200, type: 'text' },
	{ prop: 'spec', label: '规格', width: 100, type: 'text' },
	{ prop: 'unit', label: '单位', width: 60, align: 'center', type: 'text' },
	{ prop: 'last_sale_date', label: '最近销售日期', width: 120, align: 'center', type: 'text' },
	{ prop: 'order_no', label: '销售单号', width: 160, type: 'text' },
	{ prop: 'customer_code', label: '客户编码', width: 110, type: 'text' },
	{ prop: 'customer_name', label: '客户名称', width: 180, type: 'text' },
	{ prop: 'quantity', label: '销售数量', width: 100, align: 'right', type: 'number' },
	{ prop: 'price', label: '销售单价', width: 110, align: 'right', type: 'money', cell_class: 'rp-price' },
	{ prop: 'amount', label: '销售金额', width: 120, align: 'right', type: 'money', bold: true },
	{ prop: 'warehouse_name', label: '仓库', width: 100, align: 'center', type: 'text' },
]

/** 10 项查询条件 */
const FILTER_FIELDS = [
	'date_type', 'date_range',
	'customer_ids', 'product_ids', 'product_code', 'product_name',
	'warehouse_ids', 'brand_ids', 'main_category_ids', 'sub_category_ids',
	'salesman_ids',
]

const list = ref([])
const total = ref(0)
const page = ref(1)
const pageSize = ref(30)
const loading = ref(false)
const exporting = ref(false)

const filter = reactive({
	date_type: 'declare', start_date: '', end_date: '',
	customer_ids: [], product_ids: [], product_code: '', product_name: '',
	warehouse_ids: [], brand_ids: [], main_category_ids: [], sub_category_ids: [],
	salesman_ids: [],
})

function buildParams() {
	const p = { page: page.value, page_size: pageSize.value }
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
		const res = await businessApi.recentPrice.list.get(buildParams())
		if (res.code === 200) {
			list.value = res.data?.list || []
			total.value = res.data?.total || 0
			page.value = res.data?.page || page.value
		}
	} catch {
		ElMessage.error('加载最近价格失败')
	} finally {
		loading.value = false
	}
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
		const blob = await businessApi.recentPrice.export.get(buildParams())
		saveBlob(blob, '最近价格.csv')
	} catch {
		ElMessage.error('导出失败')
	} finally {
		exporting.value = false
	}
}
function onPrint() {
	if (!list.value.length) return ElMessage.warning('当前没有可打印的数据')
	printTable(COLUMNS, list.value, '最近价格')
}

onMounted(fetchData)
</script>
