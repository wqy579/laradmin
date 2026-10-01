<template>
	<div class="biz-list">
		<div class="toolbar">
			<div class="left-panel">
				<el-input v-model="keyword" placeholder="搜索客户" clearable size="small" style="width:200px" @keyup.enter="fetchData" @clear="fetchData" />
				<el-button size="small" @click="fetchData">查询</el-button>
			</div>
		</div>
		<sTable ref="tableRef" tableName="finance_receivable" :data="data" :columns="columns" :loading="loading" height="100%" stripe>
			<template #receivable="{ row }">
				<span :class="row.receivable > 0 ? 'text-danger' : 'text-success'">¥{{ Number(row.receivable).toFixed(2) }}</span>
			</template>
		</sTable>
	</div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import businessApi from '@/api/business'
import sTable from '@/components/sTable/index.vue'

const data = ref([])
const loading = ref(false)
const keyword = ref('')
const columns = [
	{ prop: 'customer_name', title: '客户', width: 200 },
	{ prop: 'order_total', title: '订单总额', width: 120, align: 'right', slots: { default: 'order_total' } },
	{ prop: 'received_total', title: '已收款', width: 120, align: 'right', slots: { default: 'received_total' } },
	{ prop: 'receivable', title: '应收款', width: 120, align: 'right', slots: { default: 'receivable' } },
]

async function fetchData() {
	loading.value = true
	try {
		const res = await businessApi.receive.receivable.get({})
		if (res.code === 200) {
			let list = res.data?.list || []
			if (keyword.value) list = list.filter(r => r.customer_name?.includes(keyword.value))
			data.value = list
		}
	} catch { ElMessage.error('加载失败') }
	finally { loading.value = false }
}
onMounted(() => fetchData())
</script>

<style scoped>
.biz-list { height: 100%; display: flex; flex-direction: column; }
.toolbar { display: flex; justify-content: flex-end; padding: 8px; }
.left-panel { display: flex; gap: 8px; }
.text-danger { color: var(--el-color-danger); font-weight: 600; }
.text-success { color: var(--el-color-success); }
</style>
