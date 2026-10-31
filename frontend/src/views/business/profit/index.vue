<template>
	<div class="profit-page">
		<div class="toolbar">
			<el-date-picker v-model="dateRange" type="daterange" range-separator="至" start-placeholder="开始" end-placeholder="结束" size="small" style="width:240px" value-format="YYYY-MM-DD" @change="fetchData" />
			<el-button size="small" type="primary" @click="fetchData">查询(C)</el-button>
			<el-button size="small" @click="handleExport">导出</el-button>
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

function buildParams() {
	const params = {}
	if (dateRange.value?.[0]) params.start_date = dateRange.value[0]
	if (dateRange.value?.[1]) params.end_date = dateRange.value[1]
	return params
}

// 与 history 页同款：后端只回 CSV 文本，文件名前端拼（区间跟随当前查询条件）
async function handleExport() {
	try {
		const params = new URLSearchParams(buildParams())
		const token = localStorage.getItem('laradmin_token') || localStorage.getItem('token')
		const res = await fetch(`/admin/business/profit/export?${params}`, {
			headers: { Authorization: `Bearer ${token}` },
		})
		if (!res.ok) throw new Error(res.status)
		const blob = await res.blob()
		const url = URL.createObjectURL(blob)
		const a = document.createElement('a')
		a.href = url
		// 未选区间时后端默认当前自然月，文件名同口径
		const now = new Date()
		const pad = (n) => String(n).padStart(2, '0')
		const ym = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}`
		const monthStart = params.get('start_date') || `${ym(now)}-01`
		const monthEnd = params.get('end_date') || `${ym(new Date(now.getFullYear(), now.getMonth() + 1, 0))}`
		a.download = `利润查询_${monthStart}_${monthEnd}.csv`
		a.click()
		URL.revokeObjectURL(url)
	} catch { ElMessage.error('导出失败') }
}

async function fetchData() {
	try {
		const params = buildParams()
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
