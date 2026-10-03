<template>
	<div class="profit-page">
		<div class="toolbar">
			<el-date-picker v-model="dateRange" type="daterange" range-separator="至" start-placeholder="开始" end-placeholder="结束" size="small" style="width:240px" value-format="YYYY-MM-DD" @change="fetchData" />
			<el-button size="small" type="primary" @click="fetchData">查询(C)</el-button>
		</div>
		<div class="stat-cards">
			<div class="stat-card"><div class="stat-label">总收入</div><div class="stat-value text-success">¥{{ Number(stats.total_income || 0).toFixed(2) }}</div></div>
			<div class="stat-card"><div class="stat-label">总支出</div><div class="stat-value text-danger">¥{{ Number(stats.total_expense || 0).toFixed(2) }}</div></div>
			<div class="stat-card"><div class="stat-label">净利润</div><div class="stat-value" :class="profit >= 0 ? 'text-success' : 'text-danger'">¥{{ profit.toFixed(2) }}</div></div>
		</div>
		<el-tabs v-model="activeTab" class="profit-tabs">
			<el-tab-pane label="利润表" name="profit">
				<el-table :data="profitList" size="small" border>
					<el-table-column prop="category" label="项目" width="200" />
					<el-table-column prop="amount" label="本期数" align="right">
						<template #default="{ row }"><span :class="row.amount < 0 ? 'text-danger' : ''">¥{{ Number(row.amount).toFixed(2) }}</span></template>
					</el-table-column>
					<el-table-column label="说明">
						<template #default="{ row }">{{ row.note || '' }}</template>
					</el-table-column>
				</el-table>
			</el-tab-pane>
			<el-tab-pane label="资产负债表" name="balance">
				<div class="balance-grid">
					<div class="balance-col">
						<div class="balance-title">资产</div>
						<el-table :data="balanceAssets" size="small" border>
							<el-table-column prop="name" label="科目" />
							<el-table-column prop="amount" label="金额" align="right" width="120">
								<template #default="{ row }">¥{{ Number(row.amount).toFixed(2) }}</template>
							</el-table-column>
						</el-table>
					</div>
					<div class="balance-col">
						<div class="balance-title">负债+所有者权益</div>
						<el-table :data="balanceLiabilities" size="small" border>
							<el-table-column prop="name" label="科目" />
							<el-table-column prop="amount" label="金额" align="right" width="120">
								<template #default="{ row }">¥{{ Number(row.amount).toFixed(2) }}</template>
							</el-table-column>
						</el-table>
					</div>
				</div>
			</el-tab-pane>
		</el-tabs>
	</div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import businessApi from '@/api/business'

const activeTab = ref('profit')
const stats = ref({})
const profitList = ref([])
const balanceAssets = ref([])
const balanceLiabilities = ref([])
const dateRange = ref(null)
const profit = computed(() => Number(stats.value.total_income || 0) - Number(stats.value.total_expense || 0))

async function fetchData() {
	try {
		const params = {}
		if (dateRange.value?.[0]) params.start_date = dateRange.value[0]
		if (dateRange.value?.[1]) params.end_date = dateRange.value[1]
		const res = await businessApi.profit.list.get(params)
		if (res.code === 200) {
			stats.value = res.data?.stats || {}
			const list = res.data?.list || []
			profitList.value = list
			// 使用后端计算的真实资产负债表数据
			balanceAssets.value = res.data?.balance_assets || []
			balanceLiabilities.value = res.data?.balance_liabilities || []
		}
	} catch { ElMessage.error('加载失败') }
}
onMounted(() => fetchData())
</script>

<style scoped>
.profit-page { height: 100%; display: flex; flex-direction: column; }
.toolbar { padding: 8px; display: flex; gap: 8px; }
.stat-cards { display: flex; gap: 12px; padding: 0 12px 12px; }
.stat-card { flex: 1; padding: 16px; border-radius: 8px; background: var(--el-fill-color-light); text-align: center; }
.stat-label { font-size: 13px; color: var(--el-text-color-secondary); }
.stat-value { font-size: 22px; font-weight: 700; margin-top: 6px; }
.profit-tabs { flex: 1; padding: 0 12px; min-height: 0; }
.balance-grid { display: flex; gap: 16px; }
.balance-col { flex: 1; }
.balance-title { font-weight: 600; padding: 8px 0; font-size: 14px; }
.text-success { color: var(--el-color-success); }
.text-danger { color: var(--el-color-danger); }
</style>
