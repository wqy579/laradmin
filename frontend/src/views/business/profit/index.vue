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
		<el-tabs v-model="activeTab" class="profit-tabs" @tab-change="onTabChange">
			<el-tab-pane label="趋势图表" name="chart">
				<div class="chart-toolbar">
					<span class="chart-label">年度</span>
					<el-select v-model="chartYear" size="small" style="width: 110px" @change="loadCharts">
						<el-option v-for="y in yearOptions" :key="y" :label="`${y} 年`" :value="y" />
					</el-select>
					<el-radio-group v-model="chartView" size="small" @change="onChartViewChange">
						<el-radio-button value="chart">图表</el-radio-button>
						<el-radio-button value="table">数据表</el-radio-button>
					</el-radio-group>
					<span class="chart-tip">图表按年度取数，与上方查询区间无关</span>
				</div>

				<div v-loading="chartLoading" class="chart-body">
					<template v-if="chartView === 'chart'">
						<template v-if="hasChartData">
							<div class="chart-card">
								<div class="chart-title">{{ chartYear }} 年收入 / 成本 / 利润月度趋势</div>
								<div ref="trendEl" class="chart-canvas"></div>
							</div>
							<div class="chart-card">
								<div class="chart-title">利润同比 —— {{ chartYear }} 年 vs {{ chartYear - 1 }} 年</div>
								<div ref="yoyEl" class="chart-canvas"></div>
							</div>
						</template>
						<div v-else-if="!chartLoading" class="chart-empty">该年度暂无收支数据</div>
					</template>
					<el-table v-else :data="chartRows" size="small" border show-summary :summary-method="chartSummary">
						<el-table-column prop="month" label="月份" width="110" />
						<el-table-column label="收入" align="right">
							<template #default="{ row }">¥{{ fmtAmt(row.income) }}</template>
						</el-table-column>
						<el-table-column label="成本" align="right">
							<template #default="{ row }">¥{{ fmtAmt(row.expense) }}</template>
						</el-table-column>
						<el-table-column label="利润" align="right">
							<template #default="{ row }">
								<span :class="row.profit < 0 ? 'text-danger' : 'text-success'">¥{{ fmtAmt(row.profit) }}</span>
							</template>
						</el-table-column>
						<el-table-column :label="`${chartYear - 1} 年同期利润`" align="right">
							<template #default="{ row }">¥{{ fmtAmt(row.prev_profit) }}</template>
						</el-table-column>
					</el-table>
				</div>
			</el-tab-pane>

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
import { ref, computed, nextTick, onMounted, onBeforeUnmount } from 'vue'
import { ElMessage } from 'element-plus'
import * as echarts from 'echarts'
import businessApi from '@/api/business'

// 取色经 CVD 全对校验（deutan/protan/tritan ΔE 全部 >= 8）：
// 绿(#67C23A)与 Element Plus 正红(#F56C6C)在红角色盲下 ΔE 仅 4.9，故成本取
// 同族 danger-dark(#C45656) 拉开亮度；同比柱是同一指标的两年，属序数对比，
// 用单色深浅两级（tokens 的 primary-dark + 一档提亮）。
const C = {
	income: '#409EFF',
	profit: '#67C23A',
	expense: '#C45656',
	cur: '#337ECC',
	prev: '#5CAEFF',
	ink: '#303133',
	inkSoft: '#606266',
	grid: '#EBEEF5',
	surface: '#FFFFFF',
}
const AXIS_LABEL = { color: C.inkSoft, fontSize: 12 }
const AMT_TICKER = (v) => (Math.abs(v) >= 10000 ? `${(v / 10000).toFixed(1)}万` : `${v}`)

const activeTab = ref('profit')
const stats = ref({})
const profitList = ref([])
const balanceAssets = ref([])
const balanceLiabilities = ref([])
const dateRange = ref(null)
const profit = computed(() => Number(stats.value.total_income || 0) - Number(stats.value.total_expense || 0))

const fmtAmt = (v) => Number(v || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

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

// ── 趋势图表 ────────────────────────────────────────────────
// /business/profit 只回区间聚合（无按月分组，后端在本页可改范围之外），
// 所以按月各取一次、前端组序列；口径与上方统计卡完全一致。
const yearOptions = (() => {
	const y = new Date().getFullYear()

	return [y, y - 1, y - 2, y - 3]
})()

const chartYear = ref(new Date().getFullYear())
const chartView = ref('chart')
const chartLoading = ref(false)
const chartRows = ref([])
const trendEl = ref(null)
const yoyEl = ref(null)
let trendChart = null
let yoyChart = null
const chartCache = new Map()
// 切年度时旧请求可能后到，用序号丢弃过期结果
let chartSeq = 0

const monthRange = (year, month) => {
	const pad = (n) => String(n).padStart(2, '0')
	const last = new Date(year, month, 0).getDate()

	return {
		start_date: `${year}-${pad(month)}-01`,
		end_date: `${year}-${pad(month)}-${pad(last)}`,
	}
}

const hasChartData = computed(() => chartRows.value.some((r) => r.income || r.expense))

// 4 并发池：24 个月请求串行要等完，全并发又会压后端
const fetchMonths = async (year) => {
	const months = Array.from({ length: 12 }, (_, i) => i + 1)
	const out = new Array(12).fill(null)
	let cursor = 0

	const worker = async () => {
		while (cursor < months.length) {
			const idx = cursor++
			try {
				const res = await businessApi.profit.list.get(monthRange(year, months[idx]))
				const s = res?.data?.stats || {}
				out[idx] = {
					income: Number(s.total_income || 0),
					expense: Number(s.total_expense || 0),
				}
			} catch {
				out[idx] = { income: 0, expense: 0 }
			}
		}
	}

	await Promise.all(Array.from({ length: 4 }, worker))

	return out
}

const loadCharts = async () => {
	const year = chartYear.value
	const seq = ++chartSeq
	const prevYear = year - 1

	chartLoading.value = true
	chartRows.value = []
	const key = `${year}|${prevYear}`

	let cur, prev
	if (chartCache.has(key)) {
		;[cur, prev] = chartCache.get(key)
	} else {
		;[cur, prev] = await Promise.all([fetchMonths(year), fetchMonths(prevYear)])
		chartCache.set(key, [cur, prev])
	}
	if (seq !== chartSeq) return // 已切到别的年度，结果作废

	chartRows.value = cur.map((m, i) => ({
		month: `${i + 1}月`,
		income: m.income,
		expense: m.expense,
		profit: Number((m.income - m.expense).toFixed(2)),
		prev_profit: prev[i].income - prev[i].expense,
	}))
	chartLoading.value = false

	if (activeTab.value === 'chart' && chartView.value === 'chart') {
		await nextTick()
		if (seq !== chartSeq) return
		renderTrend()
		renderYoy()
	}
}

const baseAxis = (data) => ({
	type: 'category',
	data,
	axisLine: { lineStyle: { color: C.grid } },
	axisTick: { show: false },
	axisLabel: AXIS_LABEL,
})
const valueAxis = {
	type: 'value',
	axisLine: { show: false },
	axisTick: { show: false },
	splitLine: { lineStyle: { color: C.grid, type: 'solid', width: 1 } },
	axisLabel: { ...AXIS_LABEL, formatter: AMT_TICKER },
}
const tooltipBase = {
	trigger: 'axis',
	axisPointer: { type: 'line', lineStyle: { color: C.inkSoft, width: 1 } },
	valueFormatter: (v) => `¥${fmtAmt(v)}`,
}

// 图表容器在「图表 / 数据表」切换时会被 v-if 移除，实例绑的是旧 DOM，
// 复用会画到已脱离文档的节点上，所以 DOM 变了就必须重建。
const ensureChart = (instance, el) => {
	if (instance && instance.getDom() === el) return instance
	instance?.dispose()

	return el ? echarts.init(el) : null
}

const renderTrend = () => {
	if (!trendEl.value) return
	trendChart = ensureChart(trendChart, trendEl.value)
	if (!trendChart) return
	const months = chartRows.value.map((r) => r.month)
	const series = (name, key, color) => ({
		name,
		type: 'line',
		smooth: false,
		showSymbol: true,
		symbolSize: 8,
		data: chartRows.value.map((r) => r[key]),
		lineStyle: { width: 2, color },
		itemStyle: { color, borderColor: C.surface, borderWidth: 2 },
		emphasis: { focus: 'series' },
	})

	trendChart.setOption(
		{
			color: [C.income, C.profit, C.expense],
			tooltip: tooltipBase,
			legend: { data: ['收入', '利润', '成本'], top: 4, right: 8, textStyle: { color: C.inkSoft, fontSize: 12 } },
			grid: { left: 64, right: 24, top: 44, bottom: 32 },
			xAxis: baseAxis(months),
			yAxis: valueAxis,
			series: [series('收入', 'income', C.income), series('利润', 'profit', C.profit), series('成本', 'expense', C.expense)],
		},
		true,
	)
}

const renderYoy = () => {
	if (!yoyEl.value) return
	yoyChart = ensureChart(yoyChart, yoyEl.value)
	if (!yoyChart) return
	const bar = (name, key, color) => ({
		name,
		type: 'bar',
		barMaxWidth: 18,
		data: chartRows.value.map((r) => r[key]),
		itemStyle: { color, borderRadius: [4, 4, 0, 0] },
		emphasis: { focus: 'series' },
	})

	yoyChart.setOption(
		{
			tooltip: { ...tooltipBase, axisPointer: { type: 'shadow' } },
			legend: { data: [`${chartYear.value} 年`, `${chartYear.value - 1} 年`], top: 4, right: 8, textStyle: { color: C.inkSoft, fontSize: 12 } },
			grid: { left: 64, right: 24, top: 44, bottom: 32 },
			xAxis: { ...baseAxis(chartRows.value.map((r) => r.month)), barCategoryGap: '28%' },
			yAxis: valueAxis,
			series: [bar(`${chartYear.value} 年`, 'profit', C.cur), bar(`${chartYear.value - 1} 年`, 'prev_profit', C.prev)],
		},
		true,
	)
}

const chartSummary = ({ columns, data }) => {
	const sum = (field) => data.reduce((acc, r) => acc + Number(r[field] || 0), 0)

	return columns.map((col, index) => {
		if (index === 0) return '合计'
		const field = ['income', 'expense', 'profit', 'prev_profit'][index - 1]

		return field ? `¥${fmtAmt(sum(field))}` : ''
	})
}

// 图表首次进入才取数；切回已缓存的年度不重复打接口
const ensureCharts = async () => {
	if (chartRows.value.length) return
	await loadCharts()
}

const onTabChange = (name) => {
	if (name !== 'chart') return
	ensureCharts().then(async () => {
		await nextTick()
		// 数据可能是在「数据表」视图下取到的，那时没画过图，这里补一次
		if (chartView.value === 'chart' && hasChartData.value) {
			renderTrend()
			renderYoy()
		}
		trendChart?.resize()
		yoyChart?.resize()
	})
}

const onChartViewChange = async () => {
	if (chartView.value !== 'chart') return
	await nextTick()
	renderTrend()
	renderYoy()
}

const onResize = () => {
	trendChart?.resize()
	yoyChart?.resize()
}

onMounted(() => {
	fetchData()
	window.addEventListener('resize', onResize)
})
onBeforeUnmount(() => {
	window.removeEventListener('resize', onResize)
	trendChart?.dispose()
	yoyChart?.dispose()
	trendChart = null
	yoyChart = null
})
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

.profit-tabs :deep(.el-tabs__content) { overflow: auto; }
.chart-toolbar { display: flex; align-items: center; gap: 8px; padding: 0 4px 8px; }
.chart-label { font-size: 13px; color: var(--el-text-color-secondary); }
.chart-tip { font-size: 12px; color: var(--el-text-color-placeholder); }
.chart-body { display: flex; flex-direction: column; gap: 12px; padding: 4px; }
.chart-card { border: 1px solid var(--el-border-color-lighter); border-radius: 6px; padding: 10px 12px 6px; background: #fff; }
.chart-title { font-size: 14px; font-weight: 600; color: var(--el-text-color-primary); margin-bottom: 4px; }
/* echarts 需要容器有显式高度，0 高会静默画不出东西 */
.chart-canvas { height: 280px; width: 100%; }
.chart-empty { padding: 40px 0; text-align: center; font-size: 13px; color: var(--el-text-color-placeholder); }
</style>
