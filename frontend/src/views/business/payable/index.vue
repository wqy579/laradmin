<template>
	<div class="biz-list">
		<div class="filter-bar">
			<el-input v-model="keyword" placeholder="搜索供应商" clearable size="small" style="width:180px" @keyup.enter="fetchData" @clear="fetchData" />
			<el-radio-group v-model="statusFilter" size="small" @change="fetchData">
				<el-radio-button value="">全部</el-radio-button>
				<el-radio-button value="unpaid">未付款</el-radio-button>
				<el-radio-button value="paid">已付款</el-radio-button>
			</el-radio-group>
			<el-checkbox v-model="includeRedFlush" @change="fetchData">包含红冲</el-checkbox>
			<el-button size="small" type="primary" @click="fetchData">查询(C)</el-button>
		</div>
		<div class="summary-bar">
			<span>已付：<b class="text-green">¥{{ totalPaid }}</b></span>
		</div>
		<sTable ref="tableRef" tableName="finance_payable" :data="data" :loading="loading" height="100%" stripe>
			<template #paid_total="{ row }">
				<span>¥{{ Number(row.paid_total).toFixed(2) }}</span>
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
	{ prop: 'supplier_name', title: '供应商', width: 200 },
	{ prop: 'paid_total', title: '已付金额', width: 120, align: 'right', slots: { default: 'paid_total' } },
]

const totalPaid = computed(() => data.value.reduce((s, r) => s + Number(r.paid_total || 0), 0).toFixed(2))

async function fetchData() {
	loading.value = true
	try {
		const res = await businessApi.pay.payable.get({})
		if (res.code === 200) {
			let list = res.data?.list || []
			if (keyword.value) list = list.filter(r => r.supplier_name?.includes(keyword.value))
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
.text-green { color: #67c23a; font-weight: 600; }
</style>
