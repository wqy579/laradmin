<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import StatCard from '@/views/home/widgets/stat-card.vue'
import FinEmpty from '@/views/finance/_FinEmpty.vue'
import ledgerApi from '@/api/ledger'

const router = useRouter()

const stats = ref({
	voucher_count: 0,
	pending_count: 0,
	debit_credit_diff: 0,
	period: { period: '', status: '未结账', closed: false, voucher_count: 0 },
})
const recentVouchers = ref([])
const loading = ref(false)

// 金额千分位格式化
const fmt = (n, prefix = '', fixed = 2) => {
	if (n === null || n === undefined || isNaN(n)) return prefix + '0'
	return prefix + Number(n).toLocaleString('zh-CN', { minimumFractionDigits: fixed, maximumFractionDigits: fixed })
}

const isClosed = computed(() => stats.value.period.closed)

onMounted(async () => {
	loading.value = true
	// 单点失败不拖垮另一张卡：allSettled
	const [overviewRes, recentRes] = await Promise.allSettled([
		ledgerApi.dashboard.overview.get(),
		ledgerApi.dashboard.recentVouchers.get({ limit: 20 }),
	])
	if (overviewRes.status === 'fulfilled' && overviewRes.value?.data?.stats) {
		stats.value = { ...stats.value, ...overviewRes.value.data.stats }
	}
	if (recentRes.status === 'fulfilled' && recentRes.value?.data?.list) {
		recentVouchers.value = recentRes.value.data.list
	}
	loading.value = false
})

// 统计卡配置：type 对应 stat-card 四种主题色
const cards = computed(() => [
	{
		type: 'primary',
		icon: 'ElIconNotebook',
		label: '凭证总数',
		value: fmt(stats.value.voucher_count, '', 0),
		subValue: '累计记账凭证',
		to: '/ledger/voucher',
	},
	{
		type: 'warning',
		icon: 'ElIconClock',
		label: '待审核凭证',
		value: fmt(stats.value.pending_count, '', 0),
		subValue: '待审核',
		to: '/ledger/voucher-audit',
	},
	{
		type: isClosed.value ? 'success' : 'danger',
		icon: 'ElIconScaleToOriginal',
		label: '借贷差额',
		value: fmt(stats.value.debit_credit_diff, '', 2),
		subValue: '借-贷',
		to: '/ledger/voucher-query',
	},
	{
		type: 'success',
		icon: 'ElIconCalendar',
		label: '月结状态',
		value: stats.value.period.status,
		subValue: `${stats.value.period.period} · ${stats.value.period.voucher_count} 张`,
		to: '/ledger/period',
	},
])

function go(routePath) {
	router.push(routePath)
}

// 凭证状态中文标签
const statusLabels = { draft: '草稿', submitted: '待审核', audited: '已审核', reversed: '已作废' }
const statusTagType = { draft: 'info', submitted: 'warning', audited: 'success', reversed: 'danger' }
</script>

<template>
	<div class="page">
		<div class="page-header">
			<div class="title">总账驾驶舱</div>
			<div class="subtitle">总账管理首页 · 凭证与月结总览</div>
		</div>

		<!-- 4 张统计卡 -->
		<div class="stat-grid" v-loading="loading">
			<div v-for="card in cards" :key="card.label" class="stat-grid__item" @click="go(card.to)">
				<StatCard :type="card.type" :label="card.label" :value="card.value" :sub-value="card.subValue">
					<template #icon>
						<el-icon :size="20"><component :is="card.icon" /></el-icon>
					</template>
				</StatCard>
			</div>
		</div>

		<!-- 最近凭证列表 -->
		<el-card shadow="never" class="recent-card">
			<template #header>
				<div class="recent-card__header">
					<span class="recent-card__title">最近凭证</span>
					<el-button link type="primary" @click="go('/ledger/voucher-query')">全部凭证</el-button>
				</div>
			</template>
			<el-table :data="recentVouchers" v-loading="loading" empty-text="暂无凭证" row-class-name="recent-card__row" @row-click="(row) => go('/ledger/voucher-query')">
				<template #empty>
					<FinEmpty text="暂无凭证" />
				</template>
				<el-table-column prop="voucher_no" label="凭证字号" width="150" />
				<el-table-column prop="voucher_date" label="日期" width="120" />
				<el-table-column prop="summary" label="摘要" min-width="200" show-overflow-tooltip />
				<el-table-column label="借方" width="140" align="right">
					<template #default="{ row }"><span class="tabular">{{ fmt(row.total_debit) }}</span></template>
				</el-table-column>
				<el-table-column label="贷方" width="140" align="right">
					<template #default="{ row }"><span class="tabular">{{ fmt(row.total_credit) }}</span></template>
				</el-table-column>
				<el-table-column label="状态" width="100" align="center">
					<template #default="{ row }">
						<el-tag :type="statusTagType[row.status] || 'info'" size="small">{{ statusLabels[row.status] || row.status }}</el-tag>
					</template>
				</el-table-column>
				<el-table-column prop="maker_name" label="制单人" width="100" />
			</el-table>
		</el-card>
	</div>
</template>

<style scoped>
.page {
	padding: 16px;
	background: var(--bg-body);
	min-height: 100%;
}

.page-header {
	display: flex;
	align-items: baseline;
	gap: 12px;
	margin-bottom: 16px;
}

.page-header .title {
	font-size: 16px;
	font-weight: 700;
	color: #303133;
}

.page-header .subtitle {
	font-size: 14px;
	color: #909399;
}

.stat-grid {
	display: grid;
	grid-template-columns: repeat(4, 1fr);
	gap: 16px;
	margin-bottom: 16px;
}

.stat-grid__item {
	cursor: pointer;
	border-radius: 12px;
	transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.stat-grid__item:hover {
	transform: translateY(-2px);
	box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
}

.recent-card {
	border-radius: 12px;
}

.recent-card__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.recent-card__title {
	font-size: 16px;
	font-weight: 600;
	color: #303133;
}

.recent-card__row {
	cursor: pointer;
}

:deep(.el-table th.el-table__cell) {
	background: #f5f7fa;
	color: #909399;
	font-weight: 600;
	height: 44px;
}

:deep(.el-table th.el-table__cell .cell) {
	font-size: var(--font-base);
}

:deep(.el-table .el-table__body .el-table__row) {
	height: 41px;
}

:deep(.el-table .el-table__body td.el-table__cell .cell) {
	font-size: var(--font-base);
	color: #606266;
}

.tabular {
	font-variant-numeric: tabular-nums;
	font-family: var(--font-numeric);
}

@media (max-width: 900px) {
	.stat-grid {
		grid-template-columns: repeat(2, 1fr);
	}
}

@media (max-width: 560px) {
	.stat-grid {
		grid-template-columns: 1fr;
	}
}
</style>