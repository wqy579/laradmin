<template>
	<div class="biz-list">
		<div class="toolbar">
			<div class="left-panel">
				<el-radio-group v-model="quickDate" size="small" @change="fetchData">
					<el-radio-button value="">全部</el-radio-button>
					<el-radio-button value="today">今天</el-radio-button>
					<el-radio-button value="week">近7天</el-radio-button>
					<el-radio-button value="month">本月</el-radio-button>
				</el-radio-group>
			</div>
		</div>
		<sTable ref="tableRef" tableName="finance_cash_flow" :data="data" :columns="columns" :loading="loading" height="100%" stripe>
			<template #amount="{ row }">
				<span :class="row.amount > 0 ? 'text-success' : 'text-danger'">{{ row.amount > 0 ? '+' : '' }}¥{{ Number(row.amount).toFixed(2) }}</span>
			</template>
			<template #type="{ row }">
				<el-tag :type="row.amount > 0 ? 'success' : 'danger'" size="small">{{ row.type }}</el-tag>
			</template>
		</sTable>
	</div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import businessApi from '@/api/business'
import sTable from '@/components/sTable/index.vue'

const data = ref([])
const loading = ref(false)
const quickDate = ref('')
const columns = [
	{ prop: 'date', title: '日期', width: 120 },
	{ prop: 'type', title: '类型', width: 100, align: 'center', slots: { default: 'type' } },
	{ prop: 'description', title: '说明', width: 200 },
	{ prop: 'amount', title: '金额', width: 120, align: 'right', slots: { default: 'amount' } },
]

async function fetchData() {
	loading.value = true
	try {
		const res = await businessApi.cashFlow.list.get({ quick_date: quickDate.value })
		if (res.code === 200) data.value = res.data?.list || []
	} catch { ElMessage.error('加载失败') }
	finally { loading.value = false }
}
onMounted(() => fetchData())
</script>

<style scoped>
.biz-list { height: 100%; display: flex; flex-direction: column; }
.toolbar { display: flex; justify-content: flex-end; padding: 8px; }
.left-panel { display: flex; gap: 8px; }
.text-success { color: var(--el-color-success); font-weight: 600; }
.text-danger { color: var(--el-color-danger); font-weight: 600; }
</style>
