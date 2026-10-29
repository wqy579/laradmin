<template>
	<div class="page fin-page">
		<div class="page-header">
			<span class="title">出纳工作台</span>
			<span class="subtitle">{{ fmtDay(today()) }}</span>
		</div>

		<div class="body">
			<!-- 4 张统计卡 -->
			<div class="stat-grid" v-loading="loading">
				<div v-for="card in cards" :key="card.label" class="stat-grid__item">
					<StatCard :type="card.type" :label="card.label" :value="card.value" :sub-value="card.subValue">
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
						<span class="card-title">近 7 日收支趋势</span>
						<span class="card-sub">按现金流水业务日期汇总</span>
					</div>
				</template>
				<div v-show="hasTrend" ref="trendEl" class="chart-box" v-loading="trendLoading"></div>
				<div v-if="!hasTrend" class="chart-empty">
					<FinEmpty text="近 7 日暂无资金流水" />
				</div>
			</el-card>

			<!-- 待办：待确认上交款 -->
			<el-card shadow="never" class="pending-card">
				<template #header>
					<div class="card-head">
						<span class="card-title">待办</span>
						<el-button link type="primary" @click="goHandover">去处理</el-button>
					</div>
				</template>
				<div v-if="pending.count > 0" class="pending-body" @click="goHandover">
					<span class="pending-text">待确认上交款</span>
					<span class="pending-num tabular">{{ fmtCount(pending.count) }}</span>
					<span class="pending-unit">单</span>
					<span class="pending-amt tabular">{{ fmtMoney(pending.amount) }}</span>
					<span class="pending-unit">元</span>
				</div>
				<FinEmpty v-else text="暂无待确认上交款" />
			</el-card>
		</div>
	</div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, onBeforeUnmount, nextTick } from 'vue'
import { useRouter } from 'vue-router'
import * as echarts from 'echarts'
import StatCard from '@/views/home/widgets/stat-card.vue'
import FinEmpty from '../../finance/_FinEmpty.vue'
import cashApi from '@/api/cash'
import { fmtMoney, fmtCount, fmtDay, today } from '../shared'

const router = useRouter()

const loading = ref(false)
const trendLoading = ref(false)
const summary = ref({})
const trend = ref([])

const trendEl = ref(null)
let trendChart = null

const pending = reactive({ count: 0, amount: 0 })

const cards = computed(() => [
	{
		type: 'danger',
		icon: 'ElIconCoin',
		label: '今日收入',
		value: fmtMoney(summary.value.today_income),
		subValue: `刷卡收款 ${fmtCount(summary.value.today_card_count)} 笔`,
	},
	{
		type: 'success',
		icon: 'ElIconWallet',
		label: '今日支出',
		value: fmtMoney(summary.value.today_expense),
		subValue: `今日净额 ${fmtMoney(summary.value.today_net)}`,
	},
	{
		type: 'primary',
		icon: 'ElIconTrendCharts',
		label: '今日净额',
		value: fmtMoney(summary.value.today_net),
		subValue: `刷卡收款 ${fmtMoney(summary.value.today_card_amount)} 元`,
	},
	{
		type: 'warning',
		icon: 'ElIconMoney',
		label: '账户余额合计',
		value: fmtMoney(summary.value.account_total),
		subValue: `启用账户 ${fmtCount(summary.value.account_count)} 个`,
	},
])

const hasTrend = computed(() => trend.value.some((d) => Number(d.income) !== 0 || Number(d.expense) !== 0))

// 读取设计令牌值（供 ECharts 使用，避免私造色值；与 tokens.css 保持一致）
const cssVar = (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim() || ''

const drawTrend = () => {
	if (trendChart) {
		trendChart.dispose()
		trendChart = null
	}
	if (!hasTrend.value || !trendEl.value) return
	trendChart = echarts.init(trendEl.value)
	trendChart.setOption({
		tooltip: {
			trigger: 'axis',
			valueFormatter: (v) => fmtMoney(v),
		},
		legend: { data: ['收入', '支出'], right: 20, top: 8, textStyle: { fontSize: 12, color: cssVar('--text-secondary') } },
		grid: { left: 70, right: 30, top: 48, bottom: 32, borderColor: cssVar('--border-lighter') },
		xAxis: {
			type: 'category',
			boundaryGap: false,
			data: trend.value.map((d) => (d.date || '').slice(5)),
			axisLine: { lineStyle: { color: cssVar('--border-lighter') } },
			axisLabel: { fontSize: 12, color: cssVar('--text-secondary') },
		},
		yAxis: {
			type: 'value',
			splitLine: { lineStyle: { color: cssVar('--border-lighter') } },
			axisLabel: { fontSize: 12, color: cssVar('--text-secondary'), formatter: (v) => Number(v || 0).toLocaleString('zh-CN') },
		},
		series: [
			{
				name: '收入',
				type: 'line',
				smooth: true,
				symbol: 'circle',
				symbolSize: 6,
				data: trend.value.map((d) => Number(d.income) || 0),
				itemStyle: { color: cssVar('--color-danger') },
				lineStyle: { color: cssVar('--color-danger') },
			},
			{
				name: '支出',
				type: 'line',
				smooth: true,
				symbol: 'circle',
				symbolSize: 6,
				data: trend.value.map((d) => Number(d.expense) || 0),
				itemStyle: { color: cssVar('--color-success') },
				lineStyle: { color: cssVar('--color-success') },
			},
		],
	})
}

const loadSummary = async () => {
	loading.value = true
	try {
		const res = await cashApi.dashboard.summary.get()
		const data = res.data || {}
		summary.value = data
		pending.count = Number(data.pending_handover_count) || 0
		pending.amount = Number(data.pending_handover_amount) || 0
	} finally {
		loading.value = false
	}
}

const loadTrend = async () => {
	trendLoading.value = true
	try {
		const res = await cashApi.dashboard.trend.get({ days: 7 })
		trend.value = res.data?.list || []
		await nextTick()
		drawTrend()
	} finally {
		trendLoading.value = false
	}
}

const goHandover = () => {
	router.push({ path: '/cash/handover-query', query: { status: 'pending' } })
}

const resize = () => trendChart?.resize()

onMounted(() => {
	loadSummary()
	loadTrend()
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
@import '../../finance/_shared.css';
</style>

<style scoped>
.page {
	background: var(--bg-body);
	min-height: 100vh;
	display: flex;
	flex-direction: column;
}

.page-header {
	height: 50px;
	background: var(--bg-card);
	border-bottom: 1px solid var(--border-lighter);
	padding: 0 var(--space-xl);
	display: flex;
	align-items: baseline;
	gap: 12px;
}

.title {
	font-size: 16px;
	font-weight: 600;
	color: var(--text-primary);
}

.subtitle {
	font-size: var(--font-aux);
	color: var(--text-secondary);
}

.body {
	flex: 1;
	padding: var(--space-lg) var(--space-xl);
}

.stat-grid {
	display: grid;
	grid-template-columns: repeat(4, 1fr);
	gap: 16px;
	margin-bottom: 16px;
}

.stat-grid__item {
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
	font-size: var(--font-h2);
	font-weight: 600;
	color: var(--text-primary);
}

.card-sub {
	font-size: var(--font-aux);
	color: var(--text-secondary);
}

.chart-box {
	height: 320px;
}

.chart-empty {
	height: 320px;
	display: flex;
	align-items: center;
	justify-content: center;
	background: var(--bg-hover);
	border: 1px dashed var(--border-base);
	border-radius: 4px;
}

.pending-body {
	display: flex;
	align-items: baseline;
	gap: 6px;
	margin-top: var(--space-md);
	cursor: pointer;
}

.pending-text {
	font-size: var(--font-h3);
	color: var(--text-regular);
}

.pending-num,
.pending-amt {
	font-size: var(--font-stat);
	font-weight: 600;
	color: var(--color-warning);
}

.pending-unit {
	font-size: var(--font-aux);
	color: var(--text-secondary);
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
