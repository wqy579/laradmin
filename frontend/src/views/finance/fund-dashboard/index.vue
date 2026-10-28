<template>
	<div class="page fin-page">
		<div class="page-header">
			<div class="title">资金看板</div>
			<div class="subtitle">现金 / 银行实时余额 · 与资金流水同源</div>
		</div>

		<!-- 4 张统计卡 -->
		<div class="stat-grid" v-loading="loading">
			<div v-for="card in cards" :key="card.label" class="stat-grid__item" @click="go(card.to)">
				<StatCard
					:type="card.type"
					:label="card.label"
					:value="card.value"
					:sub-value="card.subValue"
					:trend="card.trend"
				>
					<template #icon>
						<el-icon :size="20"><component :is="card.icon" /></el-icon>
					</template>
				</StatCard>
			</div>
		</div>

		<!-- 近 7 日收支折线 -->
		<el-card shadow="never" class="chart-card">
			<template #header>
				<div class="card-head">
					<span class="card-title">近 7 日收支</span>
					<span class="card-sub">收入 / 支出（元）</span>
				</div>
			</template>
			<div v-show="hasTrend" ref="trendEl" class="chart-box"></div>
			<div v-if="!hasTrend" class="chart-empty">
				<FinEmpty text="近 7 日暂无资金流水" />
			</div>
		</el-card>

		<!-- 待确认上交款 -->
		<el-card shadow="never" class="pending-card">
			<template #header>
				<div class="card-head">
					<span class="card-title">待确认上交款</span>
					<el-button link type="primary" @click="goHandover">
						全部（{{ pendingTotal }}）
					</el-button>
				</div>
			</template>
			<el-table
				:data="pendingList"
				v-loading="pendingLoading"
				stripe
				class="fin-table--dense"
				:empty-text="'暂无待确认上交款'"
				row-class-name="pending-row"
				@row-click="goHandover"
			>
				<el-table-column prop="remit_no" label="上交单号" width="170" />
				<el-table-column prop="remit_date" label="上交日期" width="120" />
				<el-table-column prop="delivery_person_name" label="配送员" width="120" />
				<el-table-column label="现金" align="right" width="140">
					<template #default="{ row }"><span class="num">{{ fmt(row.cash_amount) }}</span></template>
				</el-table-column>
				<el-table-column label="银行" align="right" width="140">
					<template #default="{ row }"><span class="num">{{ fmt(row.bank_amount) }}</span></template>
				</el-table-column>
				<el-table-column label="合计" align="right" min-width="140">
					<template #default="{ row }"><span class="num">{{ fmt(row.total_amount) }}</span></template>
				</el-table-column>
				<template #empty>
					<FinEmpty text="暂无待确认上交款" />
				</template>
			</el-table>
		</el-card>
	</div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount, nextTick } from 'vue'
import { useRouter } from 'vue-router'
import * as echarts from 'echarts'
import StatCard from '@/views/home/widgets/stat-card.vue'
import FinEmpty from '../_FinEmpty.vue'
import businessApi from '@/api/business'

const router = useRouter()

const loading = ref(false)
const pendingLoading = ref(false)
const stats = ref({
	cash_balance: 0,
	bank_balance: 0,
	today_income: 0,
	today_expense: 0,
	income_ratio: 0,
	expense_ratio: 0,
	trend: [],
})
const pendingList = ref([])
const pendingTotal = ref(0)

const trendEl = ref(null)
let trendChart = null

// 金额千分位右对齐两位小数（禁 NaN）
const fmt = (v) =>
	Number(v || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

const hasTrend = computed(() =>
	(stats.value.trend || []).some((d) => Number(d.income) !== 0 || Number(d.expense) !== 0)
)

const cards = computed(() => [
	{
		type: 'primary',
		icon: 'ElIconWallet',
		label: '现金余额',
		value: fmt(stats.value.cash_balance),
		subValue: '现金 / 微信 / 支付宝',
		to: '/cash/account-flow',
	},
	{
		type: 'success',
		icon: 'ElIconCreditCard',
		label: '银行余额',
		value: fmt(stats.value.bank_balance),
		subValue: '银行卡收款净额',
		to: '/cash/account-flow',
	},
	{
		type: 'danger',
		icon: 'ElIconTop',
		label: '今日收入',
		value: fmt(stats.value.today_income),
		trend: Number(stats.value.income_ratio) || 0,
		to: '/cash/account-flow',
	},
	{
		type: 'warning',
		icon: 'ElIconBottom',
		label: '今日支出',
		value: fmt(stats.value.today_expense),
		trend: Number(stats.value.expense_ratio) || 0,
		to: '/cash/account-flow',
	},
])

const drawTrend = () => {
	if (trendChart) {
		trendChart.dispose()
		trendChart = null
	}
	if (!hasTrend.value || !trendEl.value) return
	trendChart = echarts.init(trendEl.value)
	const rows = stats.value.trend || []
	trendChart.setOption({
		tooltip: {
			trigger: 'axis',
			valueFormatter: (v) => fmt(v),
		},
		legend: { data: ['收入', '支出'], right: 20, top: 8, textStyle: { fontSize: 12, color: '#909399' } },
		grid: { left: 70, right: 30, top: 48, bottom: 32, borderColor: '#EBEEF5' },
		xAxis: {
			type: 'category',
			boundaryGap: false,
			data: rows.map((d) => (d.date || '').slice(5)),
			axisLine: { lineStyle: { color: '#EBEEF5' } },
			axisLabel: { fontSize: 12, color: '#909399' },
		},
		yAxis: {
			type: 'value',
			splitLine: { lineStyle: { color: '#EBEEF5' } },
			axisLabel: { fontSize: 12, color: '#909399', formatter: (v) => Number(v || 0).toLocaleString('zh-CN') },
		},
		series: [
			{
				name: '收入',
				type: 'line',
				smooth: true,
				symbol: 'circle',
				symbolSize: 6,
				data: rows.map((d) => Number(d.income) || 0),
				itemStyle: { color: '#F56C6C' },
				lineStyle: { color: '#F56C6C' },
			},
			{
				name: '支出',
				type: 'line',
				smooth: true,
				symbol: 'circle',
				symbolSize: 6,
				data: rows.map((d) => Number(d.expense) || 0),
				itemStyle: { color: '#67C23A' },
				lineStyle: { color: '#67C23A' },
			},
		],
	})
}

const resize = () => trendChart?.resize()

const loadStats = async () => {
	loading.value = true
	try {
		const res = await businessApi.finance.fundDashboard.get()
		if (res?.code === 200 && res.data) {
			stats.value = { ...stats.value, ...res.data, trend: res.data.trend || [] }
			await nextTick()
			drawTrend()
		}
	} finally {
		loading.value = false
	}
}

const loadPending = async () => {
	pendingLoading.value = true
	try {
		const res = await businessApi.finance.fundDashboard.pendingHandover.get({ limit: 20 })
		if (res?.code === 200 && res.data) {
			pendingList.value = res.data.list || []
			pendingTotal.value = res.data.total || 0
		}
	} finally {
		pendingLoading.value = false
	}
}

const go = (path) => router.push(path)
const goHandover = () => router.push('/business/delivery-remit')

onMounted(() => {
	loadStats()
	loadPending()
	window.addEventListener('resize', resize)
})

onBeforeUnmount(() => {
	window.removeEventListener('resize', resize)
	if (trendChart) {
		trendChart.dispose()
		trendChart = null
	}
})
</script>

<style>
@import '../_shared.css';
</style>

<style scoped>
.page {
	padding: 16px;
	background: #f0f2f5;
	min-height: 100%;
}

.page-header {
	display: flex;
	align-items: baseline;
	gap: 12px;
	margin-bottom: 16px;
}

.page-header .title {
	font-size: 18px;
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
}

.chart-card,
.pending-card {
	border-radius: 4px;
	margin-bottom: 16px;
}

.card-head {
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.card-title {
	font-size: 16px;
	font-weight: 600;
	color: #303133;
}

.card-sub {
	font-size: 12px;
	color: #909399;
}

.chart-box {
	height: 320px;
}

.chart-empty {
	height: 320px;
	display: flex;
	align-items: center;
	justify-content: center;
	background: #fafafa;
	border: 1px dashed #dcdfe6;
	border-radius: 4px;
}

.num {
	font-variant-numeric: tabular-nums;
	font-family: var(--font-numeric);
}

.pending-row {
	cursor: pointer;
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
