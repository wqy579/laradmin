<template>
	<div class="perf-list perf-page">
		<div class="perf-head">
			<div class="perf-title">业务员订单汇总</div>
			<div class="perf-filter">
				<span class="label">申报日期</span>
				<el-date-picker
					v-model="dateRange"
					type="daterange"
					value-format="YYYY-MM-DD"
					start-placeholder="开始"
					end-placeholder="结束"
					style="width: 260px"
					@change="refreshAll"
				/>
				<span class="label">业务员</span>
				<el-select v-model="salesmanId" placeholder="全部业务员" clearable filterable style="width: 150px" @change="refreshAll">
					<el-option v-for="s in salesmen" :key="s.id" :label="s.name" :value="s.id" />
				</el-select>
				<span class="label">客户</span>
				<el-select v-model="customerId" placeholder="全部客户" clearable filterable style="width: 180px" @change="refreshAll">
					<el-option v-for="c in customers" :key="c.id" :label="c.name" :value="c.id" />
				</el-select>
				<el-button type="primary" @click="refreshAll">查询</el-button>
				<el-button @click="onReset">重置</el-button>
				<el-button :loading="exporting" @click="exportData">导出Excel</el-button>
			</div>
		</div>

		<el-tabs v-model="activeTab" class="perf-tabs" @tab-change="onTabChange">
			<!-- 页签一：商品明细 -->
			<el-tab-pane label="商品明细" name="items">
				<div class="perf-table">
					<el-table
						:data="items.list"
						border
						stripe
						height="100%"
						v-loading="items.loading"
						show-summary
						:summary-method="summaryItems"
					>
						<el-table-column type="index" label="序号" width="60" align="center" :index="indexOf" />
						<el-table-column prop="apply_date" label="申报日期" width="110" align="center" />
						<el-table-column prop="order_no" label="单号" width="150" show-overflow-tooltip />
						<el-table-column prop="salesman" label="业务员" width="100" />
						<el-table-column prop="customer" label="客户" min-width="160" show-overflow-tooltip />
						<el-table-column prop="product_code" label="商品编码" width="120" />
						<el-table-column prop="product_name" label="商品名称" min-width="180" show-overflow-tooltip />
						<el-table-column prop="brand" label="品牌" width="110" show-overflow-tooltip />
						<el-table-column prop="qty" label="数量" width="90" align="right">
							<template #default="{ row }">{{ fmtQty(row.qty) }}</template>
						</el-table-column>
						<el-table-column prop="unit_price" label="单价" width="100" align="right">
							<template #default="{ row }">{{ fmtMoney(row.unit_price) }}</template>
						</el-table-column>
						<el-table-column prop="amount" label="金额" width="130" align="right">
							<template #default="{ row }">{{ fmtMoney(row.amount) }}</template>
						</el-table-column>
						<el-table-column prop="status" label="订单状态" width="100" align="center">
							<template #default="{ row }">{{ statusText(row.status) }}</template>
						</el-table-column>
						<template #empty>
							<FinEmpty text="当前筛选条件下暂无数据" />
						</template>
					</el-table>
				</div>
			</el-tab-pane>

			<!-- 页签二：客户商品汇总 -->
			<el-tab-pane label="客户商品汇总" name="customerItem">
				<div class="perf-table">
					<el-table
						:data="customerItem.list"
						border
						stripe
						height="100%"
						v-loading="customerItem.loading"
						show-summary
						:summary-method="summaryCustomerItem"
					>
						<el-table-column type="index" label="序号" width="60" align="center" :index="indexOf" />
						<el-table-column prop="salesman" label="业务员" width="120" />
						<el-table-column prop="customer" label="客户" min-width="180" show-overflow-tooltip />
						<el-table-column prop="product_name" label="商品名称" min-width="200" show-overflow-tooltip />
						<el-table-column prop="qty" label="数量汇总" width="120" align="right">
							<template #default="{ row }">{{ fmtQty(row.qty) }}</template>
						</el-table-column>
						<el-table-column prop="amount" label="金额汇总" width="140" align="right">
							<template #default="{ row }">{{ fmtMoney(row.amount) }}</template>
						</el-table-column>
						<el-table-column prop="order_count" label="订单数" width="100" align="right">
							<template #default="{ row }">{{ fmtQty(row.order_count) }}</template>
						</el-table-column>
						<template #empty>
							<FinEmpty text="当前筛选条件下暂无数据" />
						</template>
					</el-table>
				</div>
			</el-tab-pane>

			<!-- 页签三：业务员订单汇总 -->
			<el-tab-pane label="业务员订单汇总" name="salesman">
				<div class="perf-table">
					<el-table
						v-show="viewMode === '列表'"
						:data="salesman.list"
						border
						stripe
						height="100%"
						v-loading="salesman.loading"
						show-summary
						:summary-method="summarySalesman"
						@row-dblclick="openDetail"
					>
						<el-table-column type="index" label="序号" width="60" align="center" />
						<el-table-column prop="sales_name" label="业务员" min-width="140" show-overflow-tooltip />
						<el-table-column prop="order_count" label="订单数" width="110" align="right">
							<template #default="{ row }">{{ fmtQty(row.order_count) }}</template>
						</el-table-column>
						<el-table-column prop="goods_item_count" label="商品件数" width="120" align="right">
							<template #default="{ row }">{{ fmtQty(row.goods_item_count) }}</template>
						</el-table-column>
						<el-table-column prop="order_amount" label="订单金额" width="150" align="right">
							<template #default="{ row }">{{ fmtMoney(row.order_amount) }}</template>
						</el-table-column>
						<el-table-column prop="revenue" label="占比" width="180">
							<template #default="{ row }">
								<el-progress :percentage="toNum(row.revenue)" :stroke-width="10" color="#409EFF" />
							</template>
						</el-table-column>
						<el-table-column prop="rank" label="排名" width="90" align="center" />
						<template #empty>
							<FinEmpty text="当前筛选条件下暂无数据" />
						</template>
					</el-table>
					<div v-show="viewMode === '图表'" class="chart-box" v-loading="salesman.loading">
						<div ref="chartRef" class="chart"></div>
					</div>
				</div>
			</el-tab-pane>
		</el-tabs>

		<div class="perf-toolbar perf-pager">
			<template v-if="activeTab === 'salesman'">
				<el-radio-group v-model="viewMode" size="small" @change="onViewModeChange">
					<el-radio-button value="列表">列表</el-radio-button>
					<el-radio-button value="图表">图表</el-radio-button>
				</el-radio-group>
				<span class="pager-total">共 {{ salesman.total }} 人 · 双击行看订单明细</span>
			</template>
			<template v-else>
				<span class="pager-total">共 {{ current.total }} 条</span>
				<el-pagination
					layout="sizes, prev, pager, next, jumper"
					:page-sizes="[10, 20, 30, 50, 100]"
					:page-size="current.pageSize"
					:total="current.total"
					:current-page="current.page"
					@current-change="onPage"
					@size-change="onSizeChange"
				/>
			</template>
		</div>

		<!-- 双击业务员 → 该业务员的订单明细 -->
		<el-dialog v-model="detailVisible" class="perf-dialog" :title="`${detailName} · 订单明细`" width="80%">
			<el-table :data="detail.list" border stripe max-height="420" v-loading="detail.loading" empty-text="暂无数据">
				<el-table-column type="index" label="序号" width="60" align="center" />
				<el-table-column prop="apply_date" label="申报日期" width="110" align="center" />
				<el-table-column prop="order_no" label="单号" width="150" show-overflow-tooltip />
				<el-table-column prop="customer" label="客户" min-width="160" show-overflow-tooltip />
				<el-table-column prop="product_code" label="商品编码" width="120" />
				<el-table-column prop="product_name" label="商品名称" min-width="180" show-overflow-tooltip />
				<el-table-column prop="qty" label="数量" width="90" align="right">
					<template #default="{ row }">{{ fmtQty(row.qty) }}</template>
				</el-table-column>
				<el-table-column prop="unit_price" label="单价" width="100" align="right">
					<template #default="{ row }">{{ fmtMoney(row.unit_price) }}</template>
				</el-table-column>
				<el-table-column prop="amount" label="金额" width="130" align="right">
					<template #default="{ row }">{{ fmtMoney(row.amount) }}</template>
				</el-table-column>
				<el-table-column prop="status" label="订单状态" width="100" align="center">
					<template #default="{ row }">{{ statusText(row.status) }}</template>
				</el-table-column>
			</el-table>
		</el-dialog>
	</div>
</template>

<script setup>
import { ref, reactive, computed, nextTick, onMounted, onBeforeUnmount } from 'vue'
import { ElMessage } from 'element-plus'
import * as echarts from 'echarts'
import perfApi from '@/api/perf'
import businessApi from '@/api/business'
import { saveBlob } from '@/utils/fileDownload'
import FinEmpty from '../../finance/_FinEmpty.vue'
import { toNum, fmtMoney, fmtRate } from '../../perf/_fmt.js'

const STATUS_TEXT = { approved: '已审核', completed: '已完成' }
const statusText = (v) => STATUS_TEXT[v] || v || '—'
// 金额/数量一律服务端 round 过，这里只做千分位展示，不再做二次运算
const fmtQty = (v) => String(Math.round(toNum(v))).replace(/\B(?=(\d{3})+(?!\d))/g, ',')

const activeTab = ref('items')
const dateRange = ref([])
const salesmanId = ref(null)
const customerId = ref(null)
const salesmen = ref([])
const customers = ref([])
const exporting = ref(false)
const viewMode = ref('列表')

/** 分页列表的统一状态：一个 tab 一份，切换 tab 只刷当前页签 */
const newTabState = () => reactive({ list: [], total: 0, totals: {}, page: 1, pageSize: 30, loading: false })
const items = newTabState()
const customerItem = newTabState()
const salesman = reactive({ list: [], total: 0, totals: {}, loading: false })

const TAB_FETCHERS = { items: fetchItems, customerItem: fetchCustomerItem, salesman: fetchSalesman }
const current = computed(() => (activeTab.value === 'items' ? items : customerItem))

const indexOf = (i) => (current.value.page - 1) * current.value.pageSize + i + 1

function buildParams(extra = {}) {
	const p = { ...extra }
	if (dateRange.value?.[0]) p.apply_date_start = dateRange.value[0]
	if (dateRange.value?.[1]) p.apply_date_end = dateRange.value[1]
	if (salesmanId.value !== null && salesmanId.value !== '') p.salesman_id = salesmanId.value
	if (customerId.value !== null && customerId.value !== '') p.customer_id = customerId.value
	return p
}

async function fetchItems() {
	items.loading = true
	try {
		const res = await perfApi.salesmanOrder.items.get(buildParams({ page: items.page, page_size: items.pageSize }))
		items.list = res?.data?.list || []
		items.total = res?.data?.total || 0
		items.totals = res?.data?.totals || {}
	} catch (e) {
		items.list = []
		items.total = 0
		items.totals = {}
		ElMessage.error(e?.message || '商品明细加载失败')
	} finally {
		items.loading = false
	}
}

async function fetchCustomerItem() {
	customerItem.loading = true
	try {
		const res = await perfApi.salesmanOrder.customerItem.get(buildParams({ page: customerItem.page, page_size: customerItem.pageSize }))
		customerItem.list = res?.data?.list || []
		customerItem.total = res?.data?.total || 0
		customerItem.totals = res?.data?.totals || {}
	} catch (e) {
		customerItem.list = []
		customerItem.total = 0
		customerItem.totals = {}
		ElMessage.error(e?.message || '客户商品汇总加载失败')
	} finally {
		customerItem.loading = false
	}
}

async function fetchSalesman() {
	salesman.loading = true
	try {
		const res = await perfApi.salesmanOrder.salesman.get(buildParams())
		salesman.list = res?.data?.list || []
		salesman.total = salesman.list.length
		salesman.totals = res?.data?.totals || {}
		if (viewMode.value === '图表') await renderChart()
	} catch (e) {
		salesman.list = []
		salesman.total = 0
		salesman.totals = {}
		ElMessage.error(e?.message || '业务员汇总加载失败')
	} finally {
		salesman.loading = false
	}
}

/** 查询：三个页签共用一套筛选，只刷当前页签，切页签时再按需加载 */
const dirty = reactive({ items: false, customerItem: false, salesman: false })
const refreshAll = async () => {
	Object.keys(dirty).forEach((k) => { dirty[k] = k !== activeTab.value })
	await TAB_FETCHERS[activeTab.value]()
}

const onTabChange = async (name) => {
	if (dirty[name]) {
		dirty[name] = false
		await TAB_FETCHERS[name]()
	}
	if (name === 'salesman' && viewMode.value === '图表') await renderChart()
}

const onReset = () => {
	dateRange.value = []
	salesmanId.value = null
	customerId.value = null
	items.page = 1
	customerItem.page = 1
	refreshAll()
}

const onPage = async (p) => {
	current.value.page = p
	await TAB_FETCHERS[activeTab.value]()
}
const onSizeChange = async (s) => {
	current.value.pageSize = s
	current.value.page = 1
	await TAB_FETCHERS[activeTab.value]()
}

// 合计行取服务端 totals（= 本页返回行之和），保证所见即所合计
const summaryItems = ({ columns: cols }) =>
	cols.map((c, i) => {
		if (i === 0) return '合计'
		if (c.property === 'qty') return fmtQty(items.totals.qty)
		if (c.property === 'amount') return fmtMoney(items.totals.amount)
		return ''
	})

const summaryCustomerItem = ({ columns: cols }) =>
	cols.map((c, i) => {
		if (i === 0) return '合计'
		if (c.property === 'qty') return fmtQty(customerItem.totals.qty)
		if (c.property === 'amount') return fmtMoney(customerItem.totals.amount)
		if (c.property === 'order_count') return fmtQty(customerItem.list.reduce((s, r) => s + toNum(r.order_count), 0))
		return ''
	})

const summarySalesman = ({ columns: cols }) =>
	cols.map((c, i) => {
		if (i === 0) return '合计'
		if (c.property === 'order_count') return fmtQty(salesman.totals.order_count)
		if (c.property === 'goods_item_count') return fmtQty(salesman.totals.goods_item_count)
		if (c.property === 'order_amount') return fmtMoney(salesman.totals.order_amount)
		if (c.property === 'revenue') return `${fmtRate(salesman.totals.revenue, 2)}%`
		return ''
	})

const exportData = async () => {
	if (!current.value.total && !salesman.total) return ElMessage.warning('当前筛选无数据')
	exporting.value = true
	try {
		const blob = await perfApi.salesmanOrder.export(buildParams())
		saveBlob(blob, `业务员订单汇总_${dateRange.value?.[0] || ''}-${dateRange.value?.[1] || ''}.csv`)
		ElMessage.success('导出成功')
	} catch (e) {
		ElMessage.error(e?.message || '导出失败')
	} finally {
		exporting.value = false
	}
}

// ── 双击业务员行：按同筛选拉该业务员的订单明细 ──
const detailVisible = ref(false)
const detailName = ref('')
const detail = reactive({ list: [], loading: false })
const openDetail = async (row) => {
	detailName.value = row.sales_name || ''
	detailVisible.value = true
	detail.loading = true
	try {
		const res = await perfApi.salesmanOrder.items.get(buildParams({ page: 1, page_size: 200 }))
		detail.list = (res?.data?.list || []).filter((r) => (r.salesman || '-') === detailName.value)
	} catch (e) {
		detail.list = []
		ElMessage.error(e?.message || '订单明细加载失败')
	} finally {
		detail.loading = false
	}
}

// ── 业务员订单金额横向柱状图 ──
const chartRef = ref(null)
let chart = null
const onViewModeChange = async (v) => {
	if (v === '图表') await renderChart()
}

async function renderChart() {
	await nextTick()
	if (!chartRef.value) return
	if (!chart) chart = echarts.init(chartRef.value)
	chart.setOption({
		title: { text: '业务员订单金额', left: 16, top: 10, textStyle: { fontSize: 13, color: '#303133' } },
		tooltip: { trigger: 'axis', axisPointer: { type: 'shadow' } },
		grid: { left: 90, right: 40, top: 48, bottom: 30 },
		xAxis: { type: 'value', axisLabel: { fontSize: 12, color: '#909399' } },
		yAxis: {
			type: 'category',
			data: salesman.list.map((r) => r.sales_name),
			axisLabel: { fontSize: 12, color: '#606266' },
		},
		series: [
			{
				name: '订单金额',
				type: 'bar',
				data: salesman.list.map((r) => Number(r.order_amount || 0)),
				itemStyle: { color: '#409EFF' },
				barMaxWidth: 18,
			},
		],
	})
	chart.resize()
}

const onResize = () => chart?.resize()

const loadOptions = async () => {
	try {
		const res = await businessApi.employee.list.get({ page: 1, page_size: 200 })
		salesmen.value = (res?.data?.list || []).filter((e) => e.user_id).map((e) => ({ id: e.user_id, name: e.name }))
	} catch { /* 下拉降级为空，不影响查询 */ }
	try {
		const res = await businessApi.customer.list.get({ page: 1, page_size: 200 })
		customers.value = res?.data?.list || []
	} catch { /* 同上 */ }
}

onMounted(async () => {
	window.addEventListener('resize', onResize)
	await loadOptions()
	await fetchItems()
	dirty.customerItem = true
	dirty.salesman = true
})

onBeforeUnmount(() => {
	window.removeEventListener('resize', onResize)
	chart?.dispose()
	chart = null
})
</script>

<style>
@import '../../perf/_shared.css';
</style>

<style scoped>
.perf-list { height: 100%; display: flex; flex-direction: column; overflow: auto; }
/* 白底/内边距/下边框/标签字号由 _shared.css .perf-filter 提供，这里只留排版 */
.perf-head { background: var(--bg-card); }
.perf-title { padding: 16px 20px 0; font-size: 18px; font-weight: 700; color: var(--text-primary); }
.perf-filter { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.perf-filter .el-button { height: 36px; padding: 0 20px; font-size: var(--font-h3); border-radius: var(--radius-md); }
.perf-filter .el-button--primary { background: var(--color-primary); border-color: var(--color-primary); color: #fff; }
.perf-filter .el-button:not(.el-button--primary) { background: var(--bg-card); border: 1px solid var(--border-base); color: var(--text-regular); }

.perf-tabs { flex: 1; min-height: 0; display: flex; flex-direction: column; margin: 12px 20px 0; padding: 0 16px; background: var(--bg-card); }
.perf-tabs :deep(.el-tabs__content) { flex: 1; min-height: 0; }
.perf-tabs :deep(.el-tab-pane) { height: 100%; }
.perf-table { height: 100%; min-height: 320px; }
.chart-box { height: 100%; min-height: 320px; }
.chart { width: 100%; height: 100%; min-height: 320px; }

/* 表头底色/字号、行高、斑马纹、hover、空态文案均由 _shared.css 提供 */
/* 合计行加粗 */
:deep(.el-table__footer td.el-table__cell .cell) { font-weight: 700; }

.perf-toolbar { gap: 12px; margin: 0 20px; }
.pager-total { font-size: var(--font-h3); color: var(--text-regular); }
:deep(.perf-toolbar .el-pagination) { font-size: var(--font-h3); color: var(--text-regular); --el-pagination-text-color: var(--text-regular); --el-pagination-button-color: var(--text-regular); }
</style>
