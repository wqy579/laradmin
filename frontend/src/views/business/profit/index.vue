<template>
	<div class="profit-page">
		<div class="stat-cards">
			<div class="stat-card">
				<div class="stat-label">总收入</div>
				<div class="stat-value text-success">¥{{ Number(stats.total_income || 0).toFixed(2) }}</div>
			</div>
			<div class="stat-card">
				<div class="stat-label">总支出</div>
				<div class="stat-value text-danger">¥{{ Number(stats.total_expense || 0).toFixed(2) }}</div>
			</div>
			<div class="stat-card">
				<div class="stat-label">净利润</div>
				<div class="stat-value" :class="profit >= 0 ? 'text-success' : 'text-danger'">¥{{ profit.toFixed(2) }}</div>
			</div>
		</div>
		<div class="toolbar">
			<el-radio-group v-model="month" size="small" @change="fetchData">
				<el-radio-button value="">本月</el-radio-button>
				<el-radio-button value="last">上月</el-radio-button>
				<el-radio-button value="quarter">本季度</el-radio-button>
			</el-radio-group>
		</div>
		<sTable ref="tableRef" tableName="finance_profit" :data="data" :columns="columns" :loading="loading" height="100%" stripe />
	</div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import businessApi from '@/api/business'
import sTable from '@/components/sTable/index.vue'

const data = ref([])
const loading = ref(false)
const stats = ref({})
const month = ref('')
const profit = computed(() => Number(stats.value.total_income || 0) - Number(stats.value.total_expense || 0))
const columns = [
	{ prop: 'category', title: '类别', width: 150 },
	{ prop: 'amount', title: '金额', width: 120, align: 'right' },
	{ prop: 'count', title: '笔数', width: 80, align: 'center' },
]

async function fetchData() {
	loading.value = true
	try {
		const res = await businessApi.profit.list.get({ month: month.value })
		if (res.code === 200) { data.value = res.data?.list || []; stats.value = res.data?.stats || {} }
	} catch { ElMessage.error('加载失败') }
	finally { loading.value = false }
}
onMounted(() => fetchData())
</script>

<style scoped>
.profit-page { height: 100%; display: flex; flex-direction: column; }
.stat-cards { display: flex; gap: 12px; padding: 12px; }
.stat-card { flex: 1; padding: 16px; border-radius: 8px; background: var(--el-fill-color-light); text-align: center; }
.stat-label { font-size: 13px; color: var(--el-text-color-secondary); }
.stat-value { font-size: 22px; font-weight: 700; margin-top: 6px; }
.toolbar { padding: 0 12px 8px; }
.text-success { color: var(--el-color-success); }
.text-danger { color: var(--el-color-danger); }
</style>
