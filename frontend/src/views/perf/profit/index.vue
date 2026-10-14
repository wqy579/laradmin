<template>
	<div class="perf-list">
		<div class="perf-filter">
			<span class="label">维度</span>
			<el-radio-group v-model="filter.dimension" size="small" @change="onDimensionChange">
				<el-radio-button value="orders">订单</el-radio-button>
				<el-radio-button value="customers">客户</el-radio-button>
				<el-radio-button value="employees">业务员</el-radio-button>
				<el-radio-button value="products">商品</el-radio-button>
				<el-radio-button value="brands">品牌</el-radio-button>
			</el-radio-group>
			<span class="label">日期范围</span>
			<el-date-picker v-model="dateRange" type="daterange" value-format="YYYY-MM-DD" size="small" style="width: 240px" @change="onDate" />
			<el-checkbox v-model="filter.apportion" @change="onFilterChange">费用分摊</el-checkbox>
			<el-checkbox v-model="filter.only_profit" @change="onFilterChange">仅看盈利</el-checkbox>
			<el-checkbox v-model="filter.only_loss" @change="onFilterChange">仅看亏损</el-checkbox>
			<el-button type="primary" size="small" @click="refresh">查询</el-button>
			<el-button size="small" @click="resetSearch">重置</el-button>
			<el-button size="small" :loading="exporting" @click="exportData">导出</el-button>
			<el-tooltip content="毛利=销售金额−销售成本−退货金额−分摊费用；净毛利=销售额−成本−退货额（未扣费用）；未扣除固定开支与税" placement="bottom">
				<el-icon style="color: #909399; cursor: help"><QuestionFilled /></el-icon>
			</el-tooltip>
		</div>

		<!-- 趋势卡片 -->
		<div class="trend-card">
			<div ref="trendEl" style="height: 180px"></div>
		</div>

		<div class="perf-content">
			<div class="table-wrap">
				<el-table :data="data" border stripe height="100%" v-loading="loading" show-summary :summary-method="summaryMethod">
					<el-table-column v-for="c in columns" :key="c.prop" :prop="c.prop" :label="c.label" :min-width="c.minWidth" :width="c.width" :align="c.align">
						<template #default="{ row }">
							<template v-if="c.type === 'dim'">
								<span class="link" @click="drill(row)">{{ row.dim_name }}</span>
							</template>
							<template v-else-if="c.type === 'qty'">{{ Number(row.qty || 0).toFixed(3) }}</template>
							<template v-else-if="c.type === 'net'">
								<span :class="negClass(netProfit(row))">¥{{ fmt(netProfit(row)) }}</span>
							</template>
							<template v-else-if="c.type === 'money'">
								<span :class="negClass(row[c.prop])">¥{{ fmt(row[c.prop]) }}</span>
							</template>
							<template v-else-if="c.type === 'rate'">
								<div class="rate-bar">
									<div class="rate-track">
										<div class="rate-fill" :style="{ width: Math.min(100, Math.abs(Number(row.profit_rate) || 0)) + '%', background: rateColor(row.profit_rate) }"></div>
									</div>
									<span :style="{ color: rateColor(row.profit_rate) }">{{ fmtRate(row.profit_rate) }}</span>
								</div>
							</template>
							<template v-else>{{ row[c.prop] }}</template>
						</template>
					</el-table-column>
					<template #empty>
						<span class="empty-text">当前筛选条件下暂无数据</span>
					</template>
				</el-table>
			</div>
			<div class="perf-pager">
				<el-pagination background layout="total, prev, pager, next" :total="total" :page-size="pageSize" :current-page="page" @current-change="onPage" />
			</div>
		</div>
	</div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, nextTick } from 'vue'
import { useRoute } from 'vue-router'
import { ElMessage } from 'element-plus'
import { QuestionFilled } from '@element-plus/icons-vue'
import * as echarts from 'echarts'
import perfApi from '@/api/perf'
import { saveBlob } from '@/utils/fileDownload'

// 金额千分位 + 两位小数；负数由 negClass 上 #F56C6C
const fmt = (v) => Number(v || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const fmtRate = (v) => Number(v || 0).toFixed(2) + '%' // 毛利率固定两位小数
const negClass = (v) => (Number(v) < 0 ? 'text-red' : '')

const dimMap = { orders: '订单', customers: '客户', employees: '业务员', products: '商品', brands: '品牌/分类' }
const route = useRoute()

const dateRange = ref([])
const filter = reactive({ dimension: 'customers', start_date: '', end_date: '', apportion: true, only_profit: false, only_loss: false })
const data = ref([])
const summary = ref(null)
const total = ref(0)
const page = ref(1)
const pageSize = 30
const loading = ref(false)
const exporting = ref(false)
const trendEl = ref(null)
let trendChart = null

// 全体平均毛利率：后端全量聚合 summary.profit_rate（非当前页均值）
const avgRate = computed(() => (summary.value && summary.value.profit_rate !== undefined && summary.value.profit_rate !== null ? Number(summary.value.profit_rate) : null))
// 利润口径绿好红差：高于/等于平均绿 #67C23A，低于平均红 #F56C6C；无均值时退回按正负
const rateColor = (v) => {
	const r = Number(v) || 0
	if (avgRate.value === null) return r >= 0 ? '#67C23A' : '#F56C6C'
	return r >= avgRate.value ? '#67C23A' : '#F56C6C'
}
// 净毛利 = 销售额 − 成本 − 退货额（后端有 net_profit 则直接用）
const netProfit = (row) => {
	if (row.net_profit !== undefined && row.net_profit !== null) return Number(row.net_profit)
	return Number(row.sale_amount || 0) - Number(row.cost_amount || 0) - Number(row.return_amount || 0)
}

// 列头按当前维度动态生成（维度名列随 dimension 变化，度量列与后端返回字段对齐）
const columns = computed(() => {
	const first = dimMap[filter.dimension] || filter.dimension
	return [
		{ prop: 'dim_name', label: first, type: 'dim', minWidth: 200, align: 'left' },
		{ prop: 'qty', label: '销售数量', type: 'qty', width: 110, align: 'right' },
		{ prop: 'sale_amount', label: '销售金额', type: 'money', width: 130, align: 'right' },
		{ prop: 'cost_amount', label: '销售成本', type: 'money', width: 130, align: 'right' },
		{ prop: 'return_amount', label: '退货金额', type: 'money', width: 120, align: 'right' },
		{ prop: 'net_profit', label: '净毛利', type: 'net', width: 130, align: 'right' },
		{ prop: 'expense_amount', label: '费用分摊', type: 'money', width: 120, align: 'right' },
		{ prop: 'gross_profit', label: '毛利额', type: 'money', width: 130, align: 'right' },
		{ prop: 'profit_rate', label: '毛利率', type: 'rate', width: 170, align: 'right' },
		{ prop: 'payment_amount', label: '回款金额', type: 'money', width: 120, align: 'right' },
		{ prop: 'uncollected_amount', label: '未回款', type: 'money', width: 120, align: 'right' },
	]
})

const buildParams = () => ({
	dimension: filter.dimension, // 兼容 query/导出参数，接口已按路径版分发
	start_date: filter.start_date,
	end_date: filter.end_date,
	apportion: filter.apportion,
	only_profit: filter.only_profit,
	only_loss: filter.only_loss,
	page: page.value,
	page_size: pageSize,
})

const onDate = () => {
	filter.start_date = dateRange.value?.[0] || ''
	filter.end_date = dateRange.value?.[1] || ''
	page.value = 1
	refresh()
}
const onFilterChange = () => {
	page.value = 1
	refresh()
}
// 维度切换：联动重置筛选与分页
const onDimensionChange = () => {
	filter.apportion = true
	filter.only_profit = false
	filter.only_loss = false
	page.value = 1
	refresh()
}
const resetSearch = () => {
	filter.apportion = true
	filter.only_profit = false
	filter.only_loss = false
	page.value = 1
	refresh()
}
const onPage = (p) => {
	page.value = p
	refresh()
}

const refresh = async () => {
	loading.value = true
	try {
		// 路径版 API：按当前维度取 perfApi.profit[dimension]，缺省回退 orders
		const api = perfApi.profit[filter.dimension] || perfApi.profit.orders
		const res = await api.get(buildParams())
		if (res.code === 200) {
			data.value = res.data?.list || []
			summary.value = res.data?.summary || null
			total.value = Number(res.data?.total || 0)
			if (res.data?.page) page.value = Number(res.data.page)
		}
	} finally {
		loading.value = false
	}
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
			{ name: '销售额', type: 'line', smooth: true, data: sales, itemStyle: { color: '#409EFF' } },
			{ name: '毛利', type: 'line', smooth: true, data: profits, itemStyle: { color: '#67C23A' }, areaStyle: { color: 'rgba(103,194,58,0.1)' } },
		],
	})
}

// 合计行：服务端全量聚合，禁止前端对当前页求和；按列 prop 对齐
const summaryMethod = ({ columns: cols }) => {
	return cols.map((c, i) => {
		if (i === 0) return '合计'
		const s = summary.value
		if (!s) return ''
		if (c.prop === 'net_profit') {
			const v = Number(s.sale_amount || 0) - Number(s.cost_amount || 0) - Number(s.return_amount || 0)
			return '¥' + fmt(v)
		}
		if (c.prop === 'profit_rate') return fmtRate(s.profit_rate)
		if (c.prop === 'qty' || c.prop === 'dim_name') return ''
		if (c.prop === 'uncollected_amount') return ''
		const v = s[c.prop]
		return v !== undefined && v !== null ? '¥' + fmt(v) : ''
	})
}

const drill = (row) => {
	ElMessage.info(`下钻：${row.dim_name}`)
}

const exportData = async () => {
	if (!data.value.length) return ElMessage.warning('无数据')
	exporting.value = true
	try {
		const blob = await perfApi.profit.export(filter.dimension, buildParams())
		saveBlob(blob, `利润查询_${dimMap[filter.dimension] || filter.dimension}.csv`)
		ElMessage.success('导出成功')
	} catch (e) {
		ElMessage.error(e?.message || '导出失败')
	} finally {
		exporting.value = false
	}
}

onMounted(() => {
	// 兼容 ?dimension= 查询参数
	if (route.query.dimension && dimMap[route.query.dimension]) filter.dimension = route.query.dimension
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
.trend-card { background: #fff; margin: 12px 0; border: 1px solid #DCDFE6; border-radius: 4px; }
.perf-content { flex: 1; display: flex; flex-direction: column; overflow: hidden; min-height: 0; }
.table-wrap { flex: 1; min-height: 0; overflow: hidden; }
.perf-pager { display: flex; justify-content: flex-end; padding: 10px 0; background: #fff; }
.link { color: #409EFF; cursor: pointer; }
.text-green { color: #67C23A; }
.text-red { color: #F56C6C; }
.empty-text { color: #909399; font-size: 14px; }
.rate-bar { display: flex; align-items: center; gap: 8px; }
.rate-track { flex: 1; height: 6px; background: #F5F7FA; border-radius: 3px; overflow: hidden; }
.rate-fill { height: 100%; border-radius: 3px; }
:deep(.el-table) { font-size: 14px; color: #606266; }
:deep(.el-table th.el-table__cell) { background: #F5F7FA; }
:deep(.el-table .el-table__row) { height: 48px; }
:deep(.el-table--striped .el-table__body tr.el-table__row--striped td.el-table__cell) { background: #FAFAFA; }
</style>
