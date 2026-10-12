<template>
	<div class="perf-list">
		<div class="perf-filter">
			<span class="label">维度</span>
			<el-radio-group v-model="filter.dimension" size="small" @change="refresh">
				<el-radio-button value="orders">订单</el-radio-button>
				<el-radio-button value="customers">客户</el-radio-button>
				<el-radio-button value="employees">业务员</el-radio-button>
				<el-radio-button value="products">商品</el-radio-button>
				<el-radio-button value="brands">品牌</el-radio-button>
			</el-radio-group>
			<span class="label">日期范围</span>
			<el-date-picker v-model="dateRange" type="daterange" value-format="YYYY-MM-DD" size="small" style="width: 240px" @change="onDate" />
			<el-checkbox v-model="filter.apportion">费用分摊</el-checkbox>
			<el-checkbox v-model="filter.only_profit" @change="refresh">仅看盈利</el-checkbox>
			<el-checkbox v-model="filter.only_loss" @change="refresh">仅看亏损</el-checkbox>
			<el-button type="primary" size="small" @click="refresh">查询</el-button>
			<el-tooltip content="毛利=销售金额−销售成本−退货金额−分摊费用；未扣除固定开支与税" placement="bottom">
				<el-icon style="color: #909399; margin-left: 8px; cursor: help"><QuestionFilled /></el-icon>
			</el-tooltip>
		</div>

		<!-- 趋势卡片 -->
		<div class="trend-card">
			<div ref="trendEl" style="height: 180px"></div>
		</div>

		<div class="perf-content">
			<el-table :data="data" border stripe height="100%" v-loading="loading" show-summary :summary-method="summaryMethod">
				<el-table-column prop="dim_name" :label="dimLabel" min-width="200">
					<template #default="{ row }"><span class="link" @click="drill(row)">{{ row.dim_name }}</span></template>
				</el-table-column>
				<el-table-column prop="qty" label="销售数量" width="110" align="right">
					<template #default="{ row }">{{ Number(row.qty || 0).toFixed(3) }}</template>
				</el-table-column>
				<el-table-column prop="sale_amount" label="销售金额" width="130" align="right">
					<template #default="{ row }">¥{{ fmt(row.sale_amount) }}</template>
				</el-table-column>
				<el-table-column prop="cost_amount" label="销售成本" width="130" align="right">
					<template #default="{ row }">¥{{ fmt(row.cost_amount) }}</template>
				</el-table-column>
				<el-table-column prop="return_amount" label="退货金额" width="120" align="right">
					<template #default="{ row }">¥{{ fmt(row.return_amount) }}</template>
				</el-table-column>
				<el-table-column prop="expense_amount" label="费用分摊" width="120" align="right">
					<template #default="{ row }">¥{{ fmt(row.expense_amount) }}</template>
				</el-table-column>
				<el-table-column prop="gross_profit" label="毛利额" width="130" align="right">
					<template #default="{ row }"><span :class="row.gross_profit >= 0 ? 'text-green' : 'text-red'">¥{{ fmt(row.gross_profit) }}</span></template>
				</el-table-column>
				<el-table-column label="毛利率" width="160" align="right">
					<template #default="{ row }">
						<div class="rate-bar">
							<div class="rate-track">
								<div class="rate-fill" :style="{ width: Math.min(100, Math.abs(row.profit_rate)) + '%', background: row.profit_rate >= 0 ? '#67c23a' : '#f56c6c' }"></div>
							</div>
							<span :class="row.profit_rate >= 0 ? 'text-green' : 'text-red'">{{ row.profit_rate }}%</span>
						</div>
					</template>
				</el-table-column>
				<el-table-column prop="payment_amount" label="回款金额" width="120" align="right">
					<template #default="{ row }">¥{{ fmt(row.payment_amount) }}</template>
				</el-table-column>
				<el-table-column prop="uncollected_amount" label="未回款" width="120" align="right">
					<template #default="{ row }"><span class="text-red">¥{{ fmt(row.uncollected_amount) }}</span></template>
				</el-table-column>
			</el-table>
			<div class="perf-toolbar">
				<el-button @click="exportData">导出</el-button>
			</div>
		</div>
	</div>
</template>

<script setup>
import { ref, reactive, onMounted, nextTick, watch } from 'vue'
import { ElMessage } from 'element-plus'
import { QuestionFilled } from '@element-plus/icons-vue'
import * as echarts from 'echarts'
import perfApi from '@/api/perf'
import { saveBlob } from '@/utils/fileDownload'

const fmt = (v) => Number(v || 0).toFixed(2)
const dimMap = { orders: '订单', customers: '客户', employees: '业务员', products: '商品', brands: '品牌/分类' }
const dimLabel = ref('客户')

const dateRange = ref([])
const filter = reactive({ dimension: 'customers', start_date: '', end_date: '', apportion: true, only_profit: false, only_loss: false })
const data = ref([])
const summary = ref(null)
const loading = ref(false)
const trendEl = ref(null)
let trendChart = null

const onDate = () => {
	filter.start_date = dateRange.value?.[0] || ''
	filter.end_date = dateRange.value?.[1] || ''
	refresh()
}

const refresh = async () => {
	loading.value = true
	try {
		const res = await perfApi.profit.index.get(filter)
		if (res.code === 200) {
			data.value = res.data?.list || []
			summary.value = res.data?.summary || null
			dimLabel.value = dimMap[filter.dimension] || filter.dimension
		}
	} finally { loading.value = false }
}

const loadTrend = async () => {
	const res = await perfApi.profit.trend.get()
	if (res.code !== 200) return
	await nextTick()
	if (!trendEl.value) return
	if (!trendChart) trendChart = echarts.init(trendEl.value)
	const months = res.data.map((r) => r.month)
	const sales = res.data.map((r) => r.sale)
	const profits = res.data.map((r) => r.profit)
	trendChart.setOption({
		title: { text: '近 12 个月毛利趋势', left: 20, top: 8, textStyle: { fontSize: 14, color: '#303133' } },
		tooltip: { trigger: 'axis' },
		legend: { data: ['销售额', '毛利'], right: 20, top: 8 },
		grid: { left: 50, right: 30, top: 50, bottom: 30 },
		xAxis: { type: 'category', data: months },
		yAxis: { type: 'value' },
		series: [
			{ name: '销售额', type: 'line', smooth: true, data: sales, itemStyle: { color: '#409eff' } },
			{ name: '毛利', type: 'line', smooth: true, data: profits, itemStyle: { color: '#67c23a' }, areaStyle: { color: 'rgba(103,194,58,0.1)' } },
		],
	})
}

const summaryMethod = ({ columns }) => {
	return columns.map((c, i) => {
		if (i === 0) return '合计'
		if (!summary.value) return ''
		const map = ['', '', '', 'sale_amount', 'cost_amount', 'return_amount', 'expense_amount', 'gross_profit', '', 'payment_amount', 'uncollected_amount']
		const key = map[i]
		if (!key) return ''
		if (key === 'gross_profit') {
			const v = summary.value[key] || 0
			return (v >= 0 ? '¥' : '¥') + fmt(v)
		}
		const v = summary.value[key]
		return v !== undefined ? '¥' + fmt(v) : ''
	})
}

const drill = (row) => { ElMessage.info(`下钻：${row.dim_name}`) }

const exportData = () => {
	if (!data.value.length) return ElMessage.warning('无数据')
	const headers = ['维度', '数量', '销售额', '成本', '退货', '费用', '毛利', '毛利率%', '回款', '未回款']
	const rows = data.value.map((r) => [r.dim_name, r.qty, r.sale_amount, r.cost_amount, r.return_amount, r.expense_amount, r.gross_profit, r.profit_rate, r.payment_amount, r.uncollected_amount])
	const csv = '﻿利润查询\n\n' + [headers.join(','), ...rows.map((r) => r.join(','))].join('\n')
	saveBlob(csv, `profit_${filter.dimension}.csv`)
}

watch(() => filter.apportion, refresh)

onMounted(() => {
	const now = new Date()
	const first = new Date(now.getFullYear(), now.getMonth(), 1)
	const f = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
	dateRange.value = [f(first), f(now)]
	filter.start_date = f(first)
	filter.end_date = f(now)
	refresh()
	loadTrend()
})
</script>

<style scoped>
.perf-list { height: 100%; display: flex; flex-direction: column; overflow: auto; }
.perf-filter { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; padding: 16px 20px; background: #fff; border-bottom: 1px solid #ebeef5; }
.perf-filter .label { font-size: 14px; color: #606266; }
.trend-card { background: #fff; margin: 12px 0; border: 1px solid #ebeef5; border-radius: 4px; }
.perf-content { flex: 1; display: flex; flex-direction: column; overflow: auto; }
.perf-toolbar { display: flex; gap: 8px; justify-content: flex-end; margin: 12px 0; }
.link { color: #409eff; cursor: pointer; }
.text-green { color: #67c23a; }
.text-red { color: #f56c6c; }
.rate-bar { display: flex; align-items: center; gap: 8px; }
.rate-track { flex: 1; height: 6px; background: #f5f7fa; border-radius: 3px; overflow: hidden; }
.rate-fill { height: 100%; border-radius: 3px; }
</style>
