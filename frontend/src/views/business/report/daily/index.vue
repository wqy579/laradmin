<template>
	<div class="rp-page">
		<!-- 筛选区：白底 padding 16px 20px，底部 1px 边框 -->
		<div class="sr-bar">
			<div class="sr-field">
				<span class="sr-label">日期</span>
				<el-date-picker
					v-model="date"
					type="date"
					placeholder="选择日期"
					value-format="YYYY-MM-DD"
					class="sr-date"
					:clearable="false"
					:editable="false"
				/>
			</div>

			<div class="sr-field">
				<span class="sr-label">仓库</span>
				<el-select v-model="warehouseId" clearable placeholder="全部仓库" class="sr-select">
					<el-option v-for="w in warehouses" :key="w.id" :label="w.name" :value="w.id" />
				</el-select>
			</div>

			<div class="sr-field">
				<span class="sr-label">客户</span>
				<el-select
					v-model="customerId"
					clearable
					filterable
					remote
					reserve-keyword
					placeholder="输入客户名称搜索"
					:remote-method="searchCustomers"
					:loading="customerLoading"
					class="sr-select-customer"
				>
					<el-option v-for="c in customers" :key="c.id" :label="c.name" :value="c.id" />
				</el-select>
			</div>

			<div class="sr-buttons">
				<el-button type="primary" class="sr-btn-search" :loading="loading" @click="onSearch">查询</el-button>
				<el-button class="sr-btn-export" :loading="exporting" @click="onExport">导出</el-button>
			</div>
		</div>

		<!-- 4 张汇总卡片 -->
		<div class="sr-cards">
			<div class="sr-card">
				<div class="sr-card-value" style="color: #409eff">¥{{ fmt(summary.amount) }}</div>
				<div class="sr-card-label">销售额</div>
			</div>
			<div class="sr-card">
				<div class="sr-card-value" style="color: #67c23a">{{ summary.order_count }}</div>
				<div class="sr-card-label">订单数</div>
			</div>
			<div class="sr-card">
				<div class="sr-card-value" style="color: #e6a23c">{{ summary.customer_count }}</div>
				<div class="sr-card-label">客户数</div>
			</div>
			<div class="sr-card">
				<div class="sr-card-value" style="color: #722ed1">¥{{ fmt(summary.avg_price) }}</div>
				<div class="sr-card-label">客单价</div>
			</div>
		</div>

		<!-- 明细表格 -->
		<ReportTable
			:columns="columns"
			:data="list"
			:loading="loading"
			:total="total"
			:current-page="page"
			:page-size="pageSize"
			:summary="null"
			@page-change="onPageChange"
			@page-size-change="onPageSizeChange"
			@refresh="fetchData"
		/>
	</div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import businessApi from '@/api/business'
import ReportTable from '../components/ReportTable.vue'
import { saveBlob } from '@/utils/fileDownload'

/**
 * 销售日报：单日销售快照。
 * 汇总卡 4 张（销售额蓝 / 订单数绿 / 客户数橙 / 客单价紫），明细按单据展开。
 * 默认今天；空筛选条件不上传（后端空串会命中 LIKE '' 变全量）。
 */
const today = () => {
	const d = new Date()
	return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

const date = ref(today())
const warehouseId = ref('')
const customerId = ref('')
const customers = ref([])
const customerLoading = ref(false)
const warehouses = ref([])
const list = ref([])
const columns = ref([])
const summary = ref({ amount: 0, order_count: 0, customer_count: 0, avg_price: 0 })
const total = ref(0)
const page = ref(1)
const pageSize = ref(30)
const loading = ref(false)
const exporting = ref(false)

function fmt(v) {
	return Number(v || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function buildParams() {
	const p = { date: date.value, page: page.value, page_size: pageSize.value }
	if (warehouseId.value !== '' && warehouseId.value !== null) p.warehouse_id = warehouseId.value
	if (customerId.value !== '' && customerId.value !== null) p.customer_id = customerId.value
	return p
}

async function searchCustomers(keyword) {
	if (!keyword) {
		customers.value = []
		return
	}
	customerLoading.value = true
	try {
		const res = await businessApi.report.options.get({ keyword })
		customers.value = (res.code === 200 && res.data?.customers) || []
	} catch {
		customers.value = []
	} finally {
		customerLoading.value = false
	}
}

async function fetchData() {
	loading.value = true
	try {
		const res = await businessApi.report.daily.get(buildParams())
		if (res.code === 200) {
			const d = res.data || {}
			list.value = d.list || []
			columns.value = d.columns || []
			summary.value = { ...summary.value, ...(d.summary || {}) }
			total.value = d.total || 0
			page.value = d.page || page.value
		} else {
			ElMessage.error(res.message || '加载失败')
		}
	} catch {
		ElMessage.error('加载销售日报失败')
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
		const blob = await businessApi.report.daily.export.get(buildParams())
		saveBlob(blob, `销售日报_${date.value}.csv`)
	} catch {
		ElMessage.error('导出失败')
	} finally {
		exporting.value = false
	}
}

onMounted(async () => {
	try {
		const res = await businessApi.report.options.get()
		if (res.code === 200) warehouses.value = res.data?.warehouses || []
	} catch {
		warehouses.value = []
	}
	fetchData()
})
</script>

<style scoped>
/* 筛选区：白底 padding 16px 20px，底部边框 1px #EBEEF5 */
.sr-bar {
	flex-shrink: 0;
	background: #ffffff;
	padding: 16px 20px;
	border-bottom: 1px solid #ebeef5;
	display: flex;
	align-items: center;
	gap: 16px;
}

.sr-field {
	display: flex;
	align-items: center;
	gap: 8px;
}

.sr-label {
	font-size: 14px;
	color: #606266;
	flex-shrink: 0;
}

.sr-date {
	width: 160px;
}

.sr-date :deep(.el-input__wrapper) {
	border-radius: 4px;
}

.sr-select {
	width: 140px;
}

.sr-select-customer {
	width: 160px;
}

.sr-buttons {
	margin-left: auto;
	display: flex;
	gap: 8px;
}

.sr-btn-search {
	width: 80px;
	height: 32px;
	font-size: 14px;
}

.sr-btn-export {
	width: 90px;
	height: 32px;
	font-size: 14px;
	color: #606266;
}

/* 汇总卡片：横向排列，间距 16，margin 20；卡 200×100 */
.sr-cards {
	flex-shrink: 0;
	display: flex;
	gap: 16px;
	margin: 20px;
}

.sr-card {
	width: 200px;
	height: 100px;
	background: #ffffff;
	border-radius: 8px;
	box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
	padding: 16px;
	box-sizing: border-box;
}

.sr-card-value {
	font-size: 24px;
	font-weight: 700;
}

.sr-card-label {
	margin-top: 10px;
	font-size: 14px;
	color: #606266;
}

/* 表格区占满剩余高度 */
:deep(.rp-grid) {
	flex: 1;
}
</style>
