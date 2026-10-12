<template>
	<div class="rp-page">
		<!-- 筛选区 -->
		<div class="sr-bar">
			<div class="sr-field">
				<span class="sr-label">月份</span>
				<el-date-picker
					v-model="month"
					type="month"
					placeholder="选择月份"
					value-format="YYYY-MM"
					class="sr-month"
					:clearable="false"
					:editable="false"
				/>
			</div>

			<div class="sr-field">
				<span class="sr-label">业务员</span>
				<el-select v-model="salesmanId" clearable filterable placeholder="全部业务员" class="sr-select">
					<el-option v-for="u in salesmen" :key="u.value" :label="u.label" :value="u.value" />
				</el-select>
			</div>

			<div class="sr-field">
				<span class="sr-label">客户等级</span>
				<el-select v-model="levelId" clearable placeholder="全部等级" class="sr-select-level">
					<el-option v-for="l in levels" :key="l.id" :label="l.name" :value="l.id" />
				</el-select>
			</div>

			<div class="sr-buttons">
				<el-button type="primary" class="sr-btn-search" :loading="loading" @click="onSearch">查询</el-button>
				<el-button class="sr-btn-export" :loading="exporting" @click="onExport">导出</el-button>
			</div>
		</div>

		<!-- 汇总卡片 -->
		<div class="sr-cards">
			<div class="sr-card">
				<div class="sr-card-value" style="color: #409eff">¥{{ fmt(summary.amount) }}</div>
				<div class="sr-card-label">月销售额</div>
			</div>
			<div class="sr-card">
				<div class="sr-card-value" style="color: #67c23a">{{ summary.order_count }}</div>
				<div class="sr-card-label">月订单数</div>
			</div>
			<div class="sr-card">
				<div class="sr-card-value" style="color: #e6a23c">{{ summary.customer_count }}</div>
				<div class="sr-card-label">月客户数</div>
			</div>
			<div class="sr-card">
				<div class="sr-card-value" style="color: #722ed1">{{ summary.profit_rate }}%</div>
				<div class="sr-card-label">月毛利率</div>
			</div>
		</div>

		<!-- 趋势折线图：X 轴 1~31 日，Y 轴销售额，线 #409eff 2px -->
		<div class="sr-chart-wrap">
			<div ref="chartEl" class="sr-chart"></div>
		</div>

		<!-- 明细表格 -->
		<ReportTable
			:columns="columns"
			:data="list"
			:loading="loading"
			:total="total"
			:current-page="page"
			:page-size="pageSize"
			:summary="null"
			@page-change="onPageChange"
			@page-size-change="onPageSizeChange"
			@refresh="fetchData"
		>
			<!-- 环比增长：正 ↑ 绿、负 ↓ 红（后端只下发文字，颜色由前端按 growth_rate 判） -->
			<template #cell-growth_label="{ row }">
				<span
					v-if="row.growth_rate !== null && row.growth_rate !== undefined"
					:style="{ color: growthColor(row.growth_rate), fontWeight: 600 }"
				>
					{{ row.growth_label }}
				</span>
				<span v-else class="sr-na">—</span>
			</template>
		</ReportTable>
	</div>
</template>

<script setup>
import { ref, onMounted, onBeforeUnmount, nextTick } from 'vue'
import { ElMessage } from 'element-plus'
import * as echarts from 'echarts'
import businessApi from '@/api/business'
import ReportTable from '../components/ReportTable.vue'
import { saveBlob } from '@/utils/fileDownload'

/**
 * 销售月报：整月逐日趋势 + 环比增长。
 * 趋势图 ECharts 折线，数据由后端一次性下发（最多 31 天，无需分页窗口）。
 */
const month = ref(date('YYYY-MM'))
const salesmanId = ref('')
const levelId = ref('')
const salesmen = ref([])
const levels = ref([])
const list = ref([])
const columns = ref([])
const summary = ref({ amount: 0, order_count: 0, customer_count: 0, profit_rate: 0 })
const total = ref(0)
const page = ref(1)
const pageSize = ref(31)
const loading = ref(false)
const exporting = ref(false)
const chartEl = ref(null)
let chart = null

function date(fmt) {
	const d = new Date()
	const m = String(d.getMonth() + 1).padStart(2, '0')
	const day = String(d.getDate()).padStart(2, '0')
	return fmt === 'YYYY-MM' ? `${d.getFullYear()}-${m}` : `${d.getFullYear()}-${m}-${day}`
}

function fmt(v) {
	return Number(v || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

/** 环比增长着色：正增长 ↑ 绿、负增长 ↓ 红。后端 growth_rate 为 null（上月无数据）时不着色 */
function growthColor(v) {
	if (v === null || v === undefined) return ''
	return Number(v) >= 0 ? '#67c23a' : '#f56c6c'
}

function buildParams() {
	const p = { month: month.value, page: page.value, page_size: pageSize.value }
	if (salesmanId.value !== '' && salesmanId.value !== null) p.salesman_id = salesmanId.value
	if (levelId.value !== '' && levelId.value !== null) p.customer_level_id = levelId.value
	return p
}

async function fetchData() {
	loading.value = true
	try {
		const res = await businessApi.report.monthly.get(buildParams())
		if (res.code === 200) {
			const d = res.data || {}
			list.value = d.list || []
			columns.value = d.columns || []
			summary.value = { ...summary.value, ...(d.summary || {}) }
			total.value = d.total || 0
		} else {
			ElMessage.error(res.message || '加载失败')
		}
		renderChart(list.value)
	} catch {
		ElMessage.error('加载销售月报失败')
	} finally {
		loading.value = false
	}
}

/** 折线图：#409eff 2px，数据点蓝色圆形 6px */
function renderChart(rows) {
	if (!chartEl.value) return
	if (!chart) chart = echarts.init(chartEl.value)

	chart.setOption({
		tooltip: {
			trigger: 'axis',
			valueFormatter: (v) => '¥' + Number(v || 0).toFixed(2),
		},
		grid: { left: 70, right: 24, top: 24, bottom: 32 },
		xAxis: {
			type: 'category',
			data: (rows || []).map((r) => Number(String(r.date).slice(-2)) + '日'),
			axisLine: { lineStyle: { color: '#dcdfe6' } },
			axisLabel: { color: '#909399', fontSize: 12 },
		},
		yAxis: {
			type: 'value',
			name: '销售额（元）',
			nameTextStyle: { color: '#909399', fontSize: 12 },
			axisLabel: { color: '#909399', fontSize: 12 },
			splitLine: { lineStyle: { color: '#ebeef5' } },
		},
		series: [
			{
				type: 'line',
				smooth: true,
				symbol: 'circle',
				symbolSize: 6,
				data: (rows || []).map((r) => Number(r.amount || 0)),
				itemStyle: { color: '#409eff' },
				lineStyle: { color: '#409eff', width: 2 },
			},
		],
	}, true)
}

function onResize() {
	if (chart) chart.resize()
}

function onSearch() {
	page.value = 1
	fetchData()
}
function onPageChange(p) {
	page.value = p
	fetchData()
}
function onPageSizeChange(s) {
	pageSize.value = s
	page.value = 1
	fetchData()
}

async function onExport() {
	exporting.value = true
	try {
		const blob = await businessApi.report.monthly.export.get(buildParams())
		saveBlob(blob, `销售月报_${month.value}.csv`)
	} catch {
		ElMessage.error('导出失败')
	} finally {
		exporting.value = false
	}
}

onMounted(async () => {
	try {
		const res = await businessApi.report.options.get()
		if (res.code === 200) {
			salesmen.value = res.data?.salesmen || []
			levels.value = res.data?.customer_levels || []
		}
	} catch {
		salesmen.value = []
		levels.value = []
	}
	fetchData()
	await nextTick()
	window.addEventListener('resize', onResize)
})

onBeforeUnmount(() => {
	window.removeEventListener('resize', onResize)
	if (chart) {
		chart.dispose()
		chart = null
	}
})
</script>

<style scoped>
.sr-bar {
	flex-shrink: 0;
	background: #ffffff;
	padding: 16px 20px;
	border-bottom: 1px solid #ebeef5;
	display: flex;
	align-items: center;
	gap: 16px;
}

.sr-field {
	display: flex;
	align-items: center;
	gap: 8px;
}

.sr-label {
	font-size: 14px;
	color: #606266;
	flex-shrink: 0;
}

.sr-month {
	width: 140px;
}

.sr-month :deep(.el-input__wrapper) {
	border-radius: 4px;
}

.sr-select {
	width: 140px;
}

.sr-select-level {
	width: 120px;
}

.sr-buttons {
	margin-left: auto;
	display: flex;
	gap: 8px;
}

.sr-btn-search {
	width: 80px;
	height: 32px;
	font-size: 14px;
}

.sr-btn-export {
	width: 90px;
	height: 32px;
	font-size: 14px;
	color: #606266;
}

.sr-cards {
	flex-shrink: 0;
	display: flex;
	gap: 16px;
	margin: 20px;
}

.sr-card {
	width: 200px;
	height: 100px;
	background: #ffffff;
	border-radius: 8px;
	box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
	padding: 16px;
	box-sizing: border-box;
}

.sr-card-value {
	font-size: 24px;
	font-weight: 700;
}

.sr-card-label {
	margin-top: 10px;
	font-size: 14px;
	color: #606266;
}

/* 趋势图：宽 100% 高 300px */
.sr-chart-wrap {
	flex-shrink: 0;
	margin: 0 20px 12px;
	background: #ffffff;
	border-radius: 8px;
	box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
	padding: 8px 4px 4px;
}

.sr-chart {
	width: 100%;
	height: 300px;
}

:deep(.rp-grid) {
	flex: 1;
	min-height: 160px;
}

.sr-na {
	color: #c0c4cc;
}
</style>
