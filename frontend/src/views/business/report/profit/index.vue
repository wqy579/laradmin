<template>
	<div class="rp-page">
		<!-- 筛选区 -->
		<div class="sr-bar">
			<div class="sr-field">
				<span class="sr-label">日期范围</span>
				<el-date-picker
					v-model="dateRange"
					type="daterange"
					range-separator="至"
					start-placeholder="开始日期"
					end-placeholder="结束日期"
					value-format="YYYY-MM-DD"
					class="sr-daterange"
					:clearable="false"
				/>
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

			<div class="sr-field">
				<span class="sr-label">业务员</span>
				<el-select v-model="salesmanId" clearable filterable placeholder="全部业务员" class="sr-select">
					<el-option v-for="u in salesmen" :key="u.value" :label="u.label" :value="u.value" />
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
				<div class="sr-card-value" style="color: #409eff">¥{{ fmt(summary.income) }}</div>
				<div class="sr-card-label">总收入</div>
			</div>
			<div class="sr-card">
				<div class="sr-card-value" style="color: #909399">¥{{ fmt(summary.cost) }}</div>
				<div class="sr-card-label">总成本</div>
			</div>
			<div class="sr-card">
				<div class="sr-card-value" :style="{ color: sign(summary.profit) }">¥{{ fmt(summary.profit) }}</div>
				<div class="sr-card-label">总毛利</div>
			</div>
			<div class="sr-card">
				<div class="sr-card-value" :style="{ color: rate(summary.profit_rate) }">{{ summary.profit_rate }}%</div>
				<div class="sr-card-label">毛利率</div>
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
			:summary="summaryRow"
			:summary-props="SUMMARY_PROPS"
			@page-change="onPageChange"
			@page-size-change="onPageSizeChange"
			@refresh="fetchData"
		/>
	</div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import businessApi from '@/api/business'
import ReportTable from '../components/ReportTable.vue'
import { saveBlob } from '@/utils/fileDownload'

/**
 * 销售利润报表：客户 × 商品维度，净毛利 = 销售额 − 成本 − 退货金额。
 * 汇总 4 卡对应规格：总收入 / 总成本 / 总毛利 / 毛利率；
 * 明细 9 列里退货金额与净毛利是本次新增口径（退货来自 sales_returns，
 * 与采购退货 returns 不是同一张表）。
 */
const fmtDate = (d) =>
	`${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`

const monthFirst = new Date()
monthFirst.setDate(1)

const dateRange = ref([fmtDate(monthFirst), fmtDate(new Date())])
const customerId = ref('')
const customers = ref([])
const customerLoading = ref(false)
const salesmanId = ref('')
const salesmen = ref([])
const list = ref([])
const columns = ref([])
const summary = ref({ income: 0, cost: 0, profit: 0, profit_rate: 0, return_amount: 0, net_profit: 0 })
const total = ref(0)
const page = ref(1)
const pageSize = ref(30)
const loading = ref(false)
const exporting = ref(false)

// 表格汇总行只展示这 5 项，避免把退货金额之类重复一遍
const SUMMARY_PROPS = ['amount', 'cost_amount', 'profit', 'return_amount', 'net_profit']

const summaryRow = computed(() => ({
	amount: summary.value.income,
	cost_amount: summary.value.cost,
	profit: summary.value.profit,
	return_amount: summary.value.return_amount,
	net_profit: summary.value.net_profit,
}))

function fmt(v) {
	return Number(v || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
/** 毛利/净毛利：负数红，正数绿 */
function sign(v) {
	return Number(v || 0) < 0 ? '#f56c6c' : '#67c23a'
}
/** 毛利率：<0 红、<20% 橙、≥20% 绿 */
function rate(v) {
	const n = Number(v || 0)
	if (n < 0) return '#f56c6c'
	if (n < 20) return '#e6a23c'
	return '#67c23a'
}

function buildParams() {
	const p = { page: page.value, page_size: pageSize.value }
	if (dateRange.value && dateRange.value.length === 2) {
		p.start_date = dateRange.value[0]
		p.end_date = dateRange.value[1]
	}
	if (customerId.value !== '' && customerId.value !== null) p.customer_id = customerId.value
	if (salesmanId.value !== '' && salesmanId.value !== null) p.salesman_id = salesmanId.value
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
		const res = await businessApi.report.profit.get(buildParams())
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
		ElMessage.error('加载利润报表失败')
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
		const blob = await businessApi.report.profit.export.get(buildParams())
		const r = dateRange.value || []
		saveBlob(blob, `利润报表_${r[0] || ''}_${r[1] || ''}.csv`)
	} catch {
		ElMessage.error('导出失败')
	} finally {
		exporting.value = false
	}
}

onMounted(async () => {
	try {
		const res = await businessApi.report.options.get()
		if (res.code === 200) salesmen.value = res.data?.salesmen || []
	} catch {
		salesmen.value = []
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

.sr-daterange {
	width: 260px;
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
