<template>
	<div class="biz-list">
		<div class="filter-bar">
			<el-date-picker v-model="dateRange" type="daterange" range-separator="至" start-placeholder="开始" end-placeholder="结束" size="small" style="width:240px" value-format="YYYY-MM-DD" @change="fetchData" />
			<el-select v-model="docType" placeholder="单据类型" clearable size="small" style="width:140px" @change="fetchData">
				<el-option label="全部" value="" />
				<el-option label="销售订单" value="销售订单" />
				<el-option label="收款单" value="收款" />
				<el-option label="付款单" value="付款" />
				<el-option label="费用单" value="费用" />
				<el-option label="红冲单" value="红冲" />
			</el-select>
			<el-checkbox v-model="includeRedFlush" @change="fetchData">包含红冲</el-checkbox>
			<el-button size="small" type="primary" @click="fetchData">查询(C)</el-button>
		</div>
		<div class="summary-bar">
			<span>本页合计：<b class="text-blue">¥{{ pageTotal }}</b></span>
			<span class="sep">|</span>
			<span>总合计：<b class="text-blue">¥{{ grandTotal }}</b></span>
		</div>
		<sTable ref="tableRef" tableName="finance_history" :data="data" :loading="loading" height="100%" stripe>
			<template #amount="{ row }">
				<span :class="row.amount > 0 ? 'text-green' : 'text-red'">{{ row.amount > 0 ? '+' : '' }}¥{{ Number(row.amount).toFixed(2) }}</span>
			</template>
			<template #type="{ row }">
				<el-tag :type="typeColor(row.type)" size="small">{{ row.type }}</el-tag>
			</template>
		</sTable>
	</div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import businessApi from '@/api/business'
import sTable from '@/components/sTable/index.vue'

const data = ref([])
const loading = ref(false)
const dateRange = ref(null)
const docType = ref('')
const includeRedFlush = ref(false)

const columns = [
	{ prop: 'date', title: '日期', width: 140 },
	{ prop: 'order_no', title: '单据编号', width: 140 },
	{ prop: 'type', title: '单据类型', width: 100, align: 'center', slots: { default: 'type' } },
	{ prop: 'customer_name', title: '往来单位', width: 150 },
	{ prop: 'amount', title: '单据金额', width: 110, align: 'right', slots: { default: 'amount' } },
	{ prop: 'remark', title: '摘要', width: 200, showOverflowTooltip: true },
	{ prop: 'operator_name', title: '操作人', width: 90 },
]

const pageTotal = computed(() => data.value.reduce((s, r) => s + Number(r.amount || 0), 0).toFixed(2))
const grandTotal = computed(() => pageTotal.value) // 简化：无分页时等于本页

const typeColor = (t) => ({
	'收款': 'success', '付款': 'danger', '费用': 'danger', '红冲': 'info', '销售订单': 'primary',
}[t] || 'info')

async function fetchData() {
	loading.value = true
	try {
		// 从 cash-flow 接口取数据（已 union 收款/付款/费用），加红冲单
		const params = {}
		if (dateRange.value?.[0]) params.start_date = dateRange.value[0]
		if (dateRange.value?.[1]) params.end_date = dateRange.value[1]
		const res = await businessApi.cashFlow.list.get(params)
		if (res.code === 200) {
			let list = res.data?.list || []
			// 类型筛选
			if (docType.value) {
				if (docType.value === '红冲') list = list.filter(r => r.type?.includes('红冲'))
				else list = list.filter(r => r.type === docType.value)
			}
			if (!includeRedFlush.value) list = list.filter(r => !r.type?.includes('红冲'))
			data.value = list
		}
	} catch { ElMessage.error('加载失败') }
	finally { loading.value = false }
}
onMounted(() => fetchData())
</script>

<style scoped>
.biz-list { height: 100%; display: flex; flex-direction: column; }
.filter-bar { display: flex; align-items: center; gap: 8px; padding: 8px; flex-wrap: wrap; }
.summary-bar { display: flex; align-items: center; gap: 8px; padding: 6px 12px; background: var(--el-fill-color-light); font-size: 13px; }
.summary-bar .sep { color: var(--el-text-color-placeholder); }
.text-blue { color: #428bca; font-weight: 600; }
.text-green { color: #67c23a; font-weight: 600; }
.text-red { color: #f56c6c; font-weight: 600; }
</style>
