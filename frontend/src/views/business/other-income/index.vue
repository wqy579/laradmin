<template>
	<div class="oi-page fin-page">
		<!-- 筛选区 -->
		<div class="oi-filter fin-filter">
			<span class="label">单号</span>
			<el-input
				v-model="search.keyword"
				clearable
				placeholder="收入单号"
				size="small"
				style="width: 180px"
				@keyup.enter="onSearch"
				@clear="onSearch"
			/>
			<span class="label">状态</span>
			<el-select v-model="search.status" clearable placeholder="全部状态" size="small" style="width: 120px" @change="onSearch">
				<el-option label="草稿" :value="0" />
				<el-option label="已审核" :value="1" />
			</el-select>
			<span class="label">收入日期</span>
			<el-date-picker
				v-model="search.daterange"
				type="daterange"
				size="small"
				style="width: 240px"
				start-placeholder="开始日期"
				end-placeholder="结束日期"
				value-format="YYYY-MM-DD"
				@change="onSearch"
			/>
			<div class="filter-actions fin-toolbar">
				<el-button type="primary" @click="onSearch">查询</el-button>
				<el-button @click="resetSearch">重置</el-button>
			</div>
		</div>

		<!-- 工具条：数据来自收款单（其它收款），本域只读 -->
		<div class="oi-toolbar fin-toolbar">
			<span class="toolbar-title">其它收入单</span>
			<span class="toolbar-tip">数据来自收款模块（其它收款），财务域只读</span>
		</div>

		<!-- 表格区 -->
		<div class="oi-content">
			<el-table :data="rows" border stripe height="100%" v-loading="loading" :show-summary="true" :summary-method="summaryMethod">
				<el-table-column prop="receive_no" label="单号" width="160" align="left">
					<template #default="{ row }">
						<span class="tabular">{{ row.receive_no || '-' }}</span>
					</template>
				</el-table-column>
				<el-table-column label="往来单位" width="180" align="left">
					<template #default="{ row }">{{ row.customer?.name || '-' }}</template>
				</el-table-column>
				<el-table-column prop="amount" label="金额" width="140" align="right">
					<template #default="{ row }">
						<span class="tabular">{{ fmtMoney(row.amount) }}</span>
					</template>
				</el-table-column>
				<el-table-column prop="receive_date" label="收入日期" width="120" align="center">
					<template #default="{ row }">
						<span class="tabular">{{ fmtDate(row.receive_date) }}</span>
					</template>
				</el-table-column>
				<el-table-column prop="payment_method" label="付款方式" width="110" align="center">
					<template #default="{ row }">{{ row.payment_method || '-' }}</template>
				</el-table-column>
				<el-table-column label="经手人" width="110" align="left">
					<template #default="{ row }">{{ row.handler?.name || '-' }}</template>
				</el-table-column>
				<el-table-column prop="status" label="状态" width="90" align="center">
					<template #default="{ row }">
						<el-tag size="small" :type="row.status === 1 ? 'success' : 'info'">{{ row.status === 1 ? '已审核' : '草稿' }}</el-tag>
					</template>
				</el-table-column>
				<el-table-column prop="remark" label="备注" min-width="160" align="left" show-overflow-tooltip>
					<template #default="{ row }">{{ row.remark || '-' }}</template>
				</el-table-column>
				<template #empty>
					<FinEmpty />
				</template>
			</el-table>
		</div>

		<!-- 分页 -->
		<div class="oi-pager fin-pager">
			<el-pagination
				background
				layout="total, prev, pager, next"
				:total="total"
				:page-size="pageSize"
				:current-page="page"
				@current-change="onPageChange"
			/>
		</div>
	</div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import businessApi from '@/api/business'
import FinEmpty from '../../finance/_FinEmpty.vue'

const fmtMoney = (v) =>
	Number(v || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const fmtDate = (v) => (v ? String(v).slice(0, 10) : '-')

const loading = ref(false)
const page = ref(1)
const pageSize = 20
const rows = ref([])
const total = ref(0)

const search = reactive({ keyword: '', status: null, daterange: [] })

const refresh = async () => {
	loading.value = true
	try {
		const params = { page: page.value, page_size: pageSize }
		if (search.keyword) params.keyword = search.keyword
		if (search.status !== null && search.status !== '') params.status = search.status
		if (search.daterange?.length === 2) {
			params.start_date = search.daterange[0]
			params.end_date = search.daterange[1]
		}
		const res = await businessApi.finance.otherIncome.list.get(params)
		const data = res?.data ?? {}
		rows.value = data.list || []
		total.value = data.total || 0
	} catch {
		rows.value = []
		total.value = 0
	} finally {
		loading.value = false
	}
}

const onSearch = () => {
	page.value = 1
	refresh()
}
const onPageChange = (p) => {
	page.value = p
	refresh()
}
const resetSearch = () => {
	search.keyword = ''
	search.status = null
	search.daterange = []
	onSearch()
}

const summaryMethod = ({ columns }) => {
	const sum = rows.value.reduce((s, r) => s + Number(r.amount || 0), 0)
	return columns.map((col, i) => {
		if (i === 0) return '合计'
		if (col.property === 'amount') return fmtMoney(sum)
		return ''
	})
}

onMounted(refresh)
</script>

<style>
@import '../../finance/_shared.css';
</style>

<style scoped>
.oi-page {
	height: 100%;
	display: flex;
	flex-direction: column;
	overflow: hidden;
}
.oi-filter {
	display: flex;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
}
.filter-actions {
	margin-left: auto;
	display: flex;
	gap: 12px;
}
.oi-toolbar {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 12px 20px;
	background: var(--bg-card);
}
.toolbar-title {
	font-size: var(--font-h3);
	font-weight: 600;
	color: var(--text-primary);
}
.toolbar-tip {
	font-size: var(--font-aux);
	color: var(--text-secondary);
}
.oi-content {
	flex: 1;
	min-height: 0;
	padding: 0 12px;
}
.oi-pager {
	padding: 0 12px;
}
.tabular {
	font-variant-numeric: tabular-nums;
}
:deep(.el-table) {
	font-size: var(--font-base);
	color: var(--text-primary);
}
:deep(.el-table__footer td) {
	background: var(--bg-hover);
	color: var(--text-primary);
	font-weight: 600;
}
</style>
