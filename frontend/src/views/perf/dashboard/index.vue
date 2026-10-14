<template>
	<div class="perf-list">
		<!-- 六指标卡：同比 yoy + 环比 mom，涨红跌绿 -->
		<div class="metric-cards" v-if="data?.cards">
			<div class="metric-card" v-for="c in data.cards" :key="c.key">
				<div class="metric-label">{{ c.label }}</div>
				<div class="metric-value">{{ cardValue(c) }}</div>
				<div class="metric-stat">
					<span class="ms-label">同比</span>
					<span class="ms-val" :class="rateCls(c.yoy)">{{ rateText(c.yoy) }}</span>
				</div>
				<div class="metric-stat">
					<span class="ms-label">环比</span>
					<span class="ms-val" :class="rateCls(c.mom)">{{ rateText(c.mom) }}</span>
				</div>
			</div>
		</div>

		<div class="chart-row">
			<div class="chart-box">
				<div class="chart-head">
					<span class="chart-title">{{ range === '30d' ? '近30天销售与回款' : '近12个月销售与回款' }}</span>
					<el-radio-group v-model="range" size="small" @change="refresh">
						<el-radio-button value="12m">12个月</el-radio-button>
						<el-radio-button value="30d">近30天</el-radio-button>
					</el-radio-group>
				</div>
				<div v-if="!hasTrend" class="chart-empty">当前筛选条件下暂无数据</div>
				<div v-else ref="trendEl" style="height: 300px"></div>
			</div>
			<div class="chart-box">
				<div class="chart-title">销售/毛利构成</div>
				<div ref="composeEl" style="height: 300px"></div>
			</div>
		</div>

		<div class="chart-row">
			<div class="chart-box">
				<div class="chart-title">{{ rankTitle }}</div>
				<el-radio-group v-model="rankType" size="small" style="margin-bottom: 8px">
					<el-radio-button value="product">商品销售</el-radio-button>
					<el-radio-button value="customer">客户</el-radio-button>
					<el-radio-button value="employee">业务员</el-radio-button>
				</el-radio-group>
				<div ref="rankEl" style="height: 280px"></div>
			</div>
			<div class="chart-box">
				<div class="chart-title">增长与退货率</div>
				<div ref="growthEl" style="height: 320px"></div>
			</div>
		</div>
	</div>
</template>

<script setup>
import { ref, computed, onMounted, nextTick, watch } from 'vue'
import * as echarts from 'echarts'
import perfApi from '@/api/perf'
import businessApi from '@/api/business'

const fmt = (v) => Number(v || 0).toFixed(2)
const data = ref(null)
const range = ref('12m')
const salesmanIds = ref([])
const employees = ref([])
const rankType = ref('product')
const trendEl = ref(null), composeEl = ref(null), rankEl = ref(null), growthEl = ref(null)
let trendChart, composeChart, rankChart, growthChart

// 比率一位小数；null/undefined 显示 —
const fmtRate = (v) => (v === null || v === undefined || v === '' ? '—' : Number(v).toFixed(1))
const rateText = (v) => (v === null || v === undefined || v === '' ? '—' : `${Number(v) >= 0 ? '↑' : '↓'} ${Math.abs(Number(v)).toFixed(1)}%`)
// 涨红跌绿（看板专用）：升 → .up 红，降 → .down 绿
const rateCls = (v) => (v === null || v === undefined || v === '' ? 'nil' : Number(v) >= 0 ? 'up' : 'down')
// 卡值：有 rate 用 rate（退货率/利率类），百分比一位小数
const cardValue = (c) => {
	const raw = c.rate !== null && c.rate !== undefined ? c.rate : c.value
	if (c.unit === '%') return `${fmtRate(raw)}%`
	if (c.unit === '¥') return `¥${fmt(raw)}`
	return fmt(raw)
}

const rankTitle = computed(() => ({
	product: '商品销售 TOP10',
	customer: '客户 TOP10',
	employee: '业务员 TOP10',
}[rankType.value]))

const trendData = computed(() => {
	const d = data.value || {}
	if (range.value === '30d') return d.trend_30d || d.trend_30 || []
	return d.trend_12m || d.trend || []
})
const hasTrend = computed(() => trendData.value.length > 0)

const loadEmployees = async () => {
	try {
		const res = await businessApi.employee.list.get({ page: 1, page_size: 200 })
		if (res?.code === 200) employees.value = (res.data?.list || []).filter((e) => e.user_id).map((e) => ({ id: e.user_id, name: e.name }))
	} catch { /* ignore */ }
}

const refresh = async () => {
	const res = await perfApi.dashboard.overview.get({ salesman_ids: salesmanIds.value, range: range.value })
	if (res.code !== 200) return
	data.value = res.data
	await nextTick()
	drawTrend()
	drawCompose()
	drawRank()
	drawGrowth()
}

const drawTrend = () => {
	if (trendChart) { trendChart.dispose(); trendChart = null }
	if (!hasTrend.value || !trendEl.value) return
	trendChart = echarts.init(trendEl.value)
	const rows = trendData.value
	// 30 天数据键可能是 date/day，12 个月是 month
	const xs = rows.map((r) => r.month || r.date || r.day || r.ymd || '')
	trendChart.setOption({
		title: { text: range.value === '30d' ? '近30天销售与回款' : '近12个月销售与回款', left: 20, top: 8, textStyle: { fontSize: 14, color: '#303133' } },
		tooltip: { trigger: 'axis' },
		legend: { data: ['销售', '回款'], right: 20, top: 8 },
		grid: { left: 50, right: 30, top: 50, bottom: 30 },
		xAxis: { type: 'category', data: xs },
		yAxis: { type: 'value' },
		series: [
			{ name: '销售', type: 'line', smooth: true, data: rows.map((r) => r.sale), itemStyle: { color: '#409eff' } },
			{ name: '回款', type: 'line', smooth: true, data: rows.map((r) => r.payment), itemStyle: { color: '#67c23a' } },
		],
	})
}

const drawCompose = () => {
	if (!composeEl.value) return
	if (!composeChart) composeChart = echarts.init(composeEl.value)
	composeChart.setOption({
		title: { text: '销售/毛利构成', left: 20, top: 8, textStyle: { fontSize: 14, color: '#303133' } },
		tooltip: { trigger: 'item' },
		legend: { bottom: 0 },
		series: [{
			type: 'pie', radius: ['40%', '65%'], center: ['50%', '50%'],
			data: data.value.composition,
			itemStyle: { borderRadius: 4, borderColor: '#fff', borderWidth: 2 },
			label: { formatter: '{b}: {d}%' },
		}],
	})
}

const drawRank = () => {
	if (!rankEl.value) return
	if (!rankChart) rankChart = echarts.init(rankEl.value)
	// 榜单统一取 TOP10
	const list = ((data.value.rank || {})[rankType.value] || []).slice(0, 10)
	rankChart.setOption({
		grid: { left: 100, right: 30, top: 10, bottom: 20 },
		xAxis: { type: 'value' },
		yAxis: { type: 'category', data: list.map((r) => r.name).reverse(), axisLabel: { width: 90, overflow: 'truncate' } },
		series: [{
			type: 'bar', data: list.map((r) => r.amount).reverse(),
			itemStyle: { color: '#409eff', borderRadius: [0, 4, 4, 0] },
			label: { show: true, position: 'right' },
		}],
	})
}

const drawGrowth = () => {
	if (!growthEl.value) return
	if (!growthChart) growthChart = echarts.init(growthEl.value)
	const rows = data.value.growth_return || []
	if (!rows.length) return
	growthChart.setOption({
		tooltip: { trigger: 'axis' },
		legend: { data: ['销售额', '退货率'], top: 0 },
		grid: { left: 50, right: 50, top: 40, bottom: 30 },
		xAxis: { type: 'category', data: rows.map((r) => r.month) },
		yAxis: [
			{ type: 'value', name: '销售额' },
			{ type: 'value', name: '退货率%', axisLabel: { formatter: '{value}%' } },
		],
		series: [
			{ name: '销售额', type: 'bar', data: rows.map((r) => r.sale), itemStyle: { color: '#409eff' } },
			{ name: '退货率', type: 'line', yAxisIndex: 1, data: rows.map((r) => r.return_rate), itemStyle: { color: '#f56c6c' }, lineStyle: { color: '#f56c6c' }, smooth: true },
		],
	})
}

watch(rankType, drawRank)

onMounted(async () => {
	await loadEmployees()
	await refresh()
	window.addEventListener('resize', () => { trendChart?.resize(); composeChart?.resize(); rankChart?.resize(); growthChart?.resize() })
})
</script>

<style scoped>
.perf-list { height: 100%; display: flex; flex-direction: column; overflow: auto; }
.metric-cards { display: flex; gap: 12px; padding: 16px 20px 12px; }
.metric-card { flex: 1; background: #fff; border: 1px solid #DCDFE6; border-radius: 4px; padding: 16px; text-align: center; }
.metric-label { font-size: 13px; color: #909399; }
.metric-value { font-size: 24px; font-weight: bold; color: #303133; margin: 4px 0; }
.metric-stat { display: flex; justify-content: center; gap: 6px; font-size: 12px; line-height: 20px; }
.ms-label { color: #909399; }
.ms-val { font-variant-numeric: tabular-nums; }
/* 看板专用：涨红跌绿 */
.ms-val.up { color: #F56C6C; }
.ms-val.down { color: #67C23A; }
.ms-val.nil { color: #909399; }
.chart-row { display: flex; gap: 12px; padding: 0 20px 12px; }
.chart-box { flex: 1; background: #fff; border: 1px solid #DCDFE6; border-radius: 4px; padding: 12px; }
.chart-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; }
.chart-title { font-size: 14px; font-weight: 600; color: #303133; margin-bottom: 8px; }
.chart-head .chart-title { margin-bottom: 0; }
.chart-empty { height: 300px; display: flex; align-items: center; justify-content: center; font-size: 14px; color: #909399; background: #FAFAFA; border: 1px dashed #DCDFE6; border-radius: 4px; }
</style>
