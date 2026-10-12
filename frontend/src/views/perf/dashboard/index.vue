<template>
	<div class="perf-list">
		<div class="perf-filter">
			<span class="label">业务员</span>
			<el-select v-model="salesmanIds" multiple placeholder="全部" size="small" style="width: 280px" @change="refresh">
				<el-option v-for="e in employees" :key="e.id" :label="e.name" :value="e.id" />
			</el-select>
			<el-button type="primary" size="small" @click="refresh">刷新</el-button>
		</div>

		<!-- 六指标卡 -->
		<div class="metric-cards" v-if="data?.cards">
			<div class="metric-card" v-for="c in data.cards" :key="c.key">
				<div class="metric-label">{{ c.label }}</div>
				<div class="metric-value">{{ c.unit === '%' ? c.value + '%' : c.unit + fmt(c.value) }}</div>
				<div class="metric-mom" :class="c.mom >= 0 ? 'up' : 'down'">
					{{ c.mom >= 0 ? '↑' : '↓' }} {{ Math.abs(c.mom) }}% 环比
				</div>
			</div>
		</div>

		<div class="chart-row">
			<div class="chart-box">
				<div ref="trendEl" style="height: 300px"></div>
			</div>
			<div class="chart-box">
				<div ref="composeEl" style="height: 300px"></div>
			</div>
		</div>

		<div class="chart-row">
			<div class="chart-box">
				<div class="chart-title">销售排行</div>
				<el-radio-group v-model="rankType" size="small" style="margin-bottom: 8px">
					<el-radio-button value="product">商品销量Top20</el-radio-button>
					<el-radio-button value="customer">客户Top10</el-radio-button>
					<el-radio-button value="employee">业务员Top10</el-radio-button>
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
import { ref, onMounted, nextTick, watch } from 'vue'
import * as echarts from 'echarts'
import perfApi from '@/api/perf'
import businessApi from '@/api/business'

const fmt = (v) => Number(v || 0).toFixed(2)
const data = ref(null)
const salesmanIds = ref([])
const employees = ref([])
const rankType = ref('product')
const trendEl = ref(null), composeEl = ref(null), rankEl = ref(null), growthEl = ref(null)
let trendChart, composeChart, rankChart, growthChart

const loadEmployees = async () => {
	try {
		const res = await businessApi.employee.list.get({ page: 1, page_size: 200 })
		if (res?.code === 200) employees.value = (res.data?.list || []).filter((e) => e.user_id).map((e) => ({ id: e.user_id, name: e.name }))
	} catch { /* ignore */ }
}

const refresh = async () => {
	const res = await perfApi.dashboard.overview.get({ salesman_ids: salesmanIds.value })
	if (res.code !== 200) return
	data.value = res.data
	await nextTick()
	drawTrend()
	drawCompose()
	drawRank()
	drawGrowth()
}

const drawTrend = () => {
	if (!trendEl.value) return
	if (!trendChart) trendChart = echarts.init(trendEl.value)
	const months = data.value.trend_12m.map((r) => r.month)
	trendChart.setOption({
		title: { text: '近12个月销售与回款', left: 20, top: 8, textStyle: { fontSize: 14, color: '#303133' } },
		tooltip: { trigger: 'axis' },
		legend: { data: ['销售', '回款'], right: 20, top: 8 },
		grid: { left: 50, right: 30, top: 50, bottom: 30 },
		xAxis: { type: 'category', data: months },
		yAxis: { type: 'value' },
		series: [
			{ name: '销售', type: 'line', smooth: true, data: data.value.trend_12m.map((r) => r.sale), itemStyle: { color: '#409eff' } },
			{ name: '回款', type: 'line', smooth: true, data: data.value.trend_12m.map((r) => r.payment), itemStyle: { color: '#67c23a' } },
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
	const list = data.value.rank[rankType.value] || []
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
	const months = data.value.growth_return.map((r) => r.month)
	growthChart.setOption({
		tooltip: { trigger: 'axis' },
		legend: { data: ['销售额', '退货率'], top: 0 },
		grid: { left: 50, right: 50, top: 40, bottom: 30 },
		xAxis: { type: 'category', data: months },
		yAxis: [
			{ type: 'value', name: '销售额' },
			{ type: 'value', name: '退货率%', axisLabel: { formatter: '{value}%' } },
		],
		series: [
			{ name: '销售额', type: 'bar', data: data.value.growth_return.map((r) => r.sale), itemStyle: { color: '#409eff' } },
			{ name: '退货率', type: 'line', yAxisIndex: 1, data: data.value.growth_return.map((r) => r.return_rate), itemStyle: { color: '#f56c6c' }, smooth: true },
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
.perf-filter { display: flex; align-items: center; gap: 12px; padding: 16px 20px; background: #fff; border-bottom: 1px solid #ebeef5; }
.perf-filter .label { font-size: 14px; color: #606266; }
.metric-cards { display: flex; gap: 12px; padding: 12px 20px; }
.metric-card { flex: 1; background: #fff; border: 1px solid #ebeef5; border-radius: 4px; padding: 16px; text-align: center; }
.metric-label { font-size: 13px; color: #909399; }
.metric-value { font-size: 24px; font-weight: bold; color: #303133; margin: 4px 0; }
.metric-mom { font-size: 12px; }
.metric-mom.up { color: #67c23a; }
.metric-mom.down { color: #f56c6c; }
.chart-row { display: flex; gap: 12px; padding: 0 20px 12px; }
.chart-box { flex: 1; background: #fff; border: 1px solid #ebeef5; border-radius: 4px; padding: 12px; }
.chart-title { font-size: 14px; font-weight: 600; color: #303133; margin-bottom: 8px; }
</style>
