<template>
	<div class="biz-list">
		<div class="filter-bar">
			<el-input v-model="keyword" placeholder="搜索客户" clearable size="small" style="width:180px" @keyup.enter="fetchData" @clear="fetchData" />
			<el-radio-group v-model="statusFilter" size="small" @change="fetchData">
				<el-radio-button value="">全部</el-radio-button>
				<el-radio-button value="unpaid">未收款</el-radio-button>
				<el-radio-button value="paid">已收款</el-radio-button>
			</el-radio-group>
			<el-checkbox v-model="includeRedFlush" @change="fetchData">包含红冲</el-checkbox>
			<el-button size="small" type="primary" @click="fetchData">查询(C)</el-button>
		</div>
		<div class="summary-bar">
			<span>应收：<b class="text-blue">¥{{ totalReceivable }}</b></span>
			<span class="sep">|</span>
			<span>已收：<b class="text-green">¥{{ totalReceived }}</b></span>
			<span class="sep">|</span>
			<span>待收：<b class="text-red">¥{{ totalPending }}</b></span>
		</div>
		<sTable ref="tableRef" tableName="finance_receivable" :data="data" :loading="loading" height="100%" stripe>
			<template #receivable="{ row }">
				<span :class="row.receivable > 0 ? 'text-danger' : 'text-success'">¥{{ Number(row.receivable).toFixed(2) }}</span>
			</template>
			<template #order_total="{ row }">
				<span>¥{{ Number(row.order_total).toFixed(2) }}</span>
			</template>
			<template #received_total="{ row }">
				<span>¥{{ Number(row.received_total).toFixed(2) }}</span>
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
const keyword = ref('')
const statusFilter = ref('')
const includeRedFlush = ref(false)

const columns = [
	{ prop: 'customer_name', title: '客户', width: 200 },
	{ prop: 'order_total', title: '应收金额', width: 120, align: 'right', slots: { default: 'order_total' } },
	{ prop: 'received_total', title: '已收金额', width: 120, align: 'right', slots: { default: 'received_total' } },
	{ prop: 'receivable', title: '待收金额', width: 120, align: 'right', slots: { default: 'receivable' } },
]

const totalReceivable = computed(() => data.value.reduce((s, r) => s + Number(r.order_total || 0), 0).toFixed(2))
const totalReceived = computed(() => data.value.reduce((s, r) => s + Number(r.received_total || 0), 0).toFixed(2))
const totalPending = computed(() => data.value.reduce((s, r) => s + Number(r.receivable || 0), 0).toFixed(2))

async function fetchData() {
	loading.value = true
	try {
		const params = {}
		if (statusFilter.value === 'unpaid') params.status = 'unpaid'
		if (statusFilter.value === 'paid') params.status = 'paid'
		if (includeRedFlush.value) params.include_red = 1
		const res = await businessApi.receive.receivable.get(params)
		if (res.code === 200) {
			let list = res.data?.list || []
			if (keyword.value) list = list.filter(r => r.customer_name?.includes(keyword.value))
			if (statusFilter.value === 'unpaid') list = list.filter(r => r.receivable > 0)
			if (statusFilter.value === 'paid') list = list.filter(r => r.receivable <= 0)
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
.text-danger { color: var(--el-color-danger); font-weight: 600; }
.text-success { color: var(--el-color-success); }
</style>
