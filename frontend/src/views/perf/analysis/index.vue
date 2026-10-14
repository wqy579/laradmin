<template>
	<div class="perf-list">
		<div class="perf-filter">
			<span class="label">日期范围</span>
			<el-date-picker v-model="dateRange" type="daterange" value-format="YYYY-MM-DD" size="small" style="width: 240px" @change="onDate" />
			<template v-if="activeTab === 'customers'">
				<span class="label">流失天数</span>
				<el-input-number v-model="lostDays" :min="1" :max="999" size="small" style="width: 120px" @change="refresh" />
			</template>
			<el-button type="primary" size="small" @click="refresh">查询</el-button>
			<el-button size="small" @click="exportData">导出</el-button>
		</div>

		<el-tabs v-model="activeTab" @tab-change="refresh">
			<!-- 商品销售分析 -->
			<el-tab-pane label="商品销售分析" name="products">
				<el-table :data="pagedList" border stripe v-loading="loading" height="100%" empty-text="当前筛选条件下暂无数据">
					<el-table-column prop="product_code" label="商品编码" width="120" />
					<el-table-column prop="product_name" label="商品" min-width="180" />
					<el-table-column prop="brand_name" label="品牌" width="100" />
					<el-table-column prop="category_name" label="分类" width="100" />
					<el-table-column prop="qty" label="销量" width="100" align="right" sortable />
					<el-table-column prop="sale_amount" label="销售额" width="130" align="right" sortable>
						<template #default="{ row }"><span :class="moneyCls(row.sale_amount)">{{ fmtMoney(row.sale_amount) }}</span></template>
					</el-table-column>
					<el-table-column label="占比" width="90" align="right">
						<template #default="{ row }">{{ sharePct(row) }}%</template>
					</el-table-column>
					<el-table-column prop="gross_profit" label="毛利" width="130" align="right" sortable>
						<template #default="{ row }"><span :class="moneyCls(row.gross_profit)">{{ fmtMoney(row.gross_profit) }}</span></template>
					</el-table-column>
					<el-table-column prop="profit_rate" label="毛利率" width="100" align="right">
						<template #default="{ row }"><span :class="row.profit_rate >= 0 ? 'text-green' : 'text-red'">{{ pct2(row.profit_rate) }}</span></template>
					</el-table-column>
					<el-table-column v-if="hasCol('yoy')" prop="yoy" label="同比" width="90" align="right">
						<template #default="{ row }"><span :class="rateCls(row.yoy)">{{ rateText(row.yoy) }}</span></template>
					</el-table-column>
					<el-table-column v-if="hasCol('mom')" prop="mom" label="环比" width="90" align="right">
						<template #default="{ row }"><span :class="rateCls(row.mom)">{{ rateText(row.mom) }}</span></template>
					</el-table-column>
					<el-table-column prop="return_qty" label="退货数" width="90" align="right" />
					<el-table-column prop="return_rate" label="退货率" width="90" align="right">
						<template #default="{ row }"><span class="text-red">{{ pct1(row.return_rate) }}</span></template>
					</el-table-column>
					<el-table-column prop="customer_count" label="铺市客户数" width="110" align="right" />
				</el-table>
			</el-tab-pane>

			<!-- 客户销售汇总 -->
			<el-tab-pane label="客户销售汇总" name="customers">
				<el-table :data="pagedList" border stripe v-loading="loading" height="100%" empty-text="当前筛选条件下暂无数据">
					<el-table-column prop="customer_name" label="客户" min-width="160" />
					<el-table-column prop="salesman_name" label="业务员" width="100" />
					<el-table-column prop="level_name" label="等级" width="100" />
					<el-table-column prop="order_count" label="订单数" width="90" align="right" />
					<el-table-column prop="sale_amount" label="销售金额" width="130" align="right">
						<template #default="{ row }"><span :class="moneyCls(row.sale_amount)">{{ fmtMoney(row.sale_amount) }}</span></template>
					</el-table-column>
					<el-table-column prop="gross_profit" label="毛利" width="130" align="right">
						<template #default="{ row }"><span :class="moneyCls(row.gross_profit)">{{ fmtMoney(row.gross_profit) }}</span></template>
					</el-table-column>
					<el-table-column prop="payment_amount" label="回款" width="130" align="right">
						<template #default="{ row }"><span :class="moneyCls(row.payment_amount)">{{ fmtMoney(row.payment_amount) }}</span></template>
					</el-table-column>
					<el-table-column prop="receivable" label="应收余额" width="130" align="right">
						<template #default="{ row }"><span class="text-red">{{ fmtMoney(row.receivable) }}</span></template>
					</el-table-column>
					<el-table-column v-if="hasCol('yoy')" prop="yoy" label="同比" width="90" align="right">
						<template #default="{ row }"><span :class="rateCls(row.yoy)">{{ rateText(row.yoy) }}</span></template>
					</el-table-column>
					<el-table-column v-if="hasCol('mom')" prop="mom" label="环比" width="90" align="right">
						<template #default="{ row }"><span :class="rateCls(row.mom)">{{ rateText(row.mom) }}</span></template>
					</el-table-column>
					<el-table-column prop="last_order_date" label="最近下单" width="120" align="center" />
					<el-table-column label="流失风险" width="100" align="center">
						<template #default="{ row }">
							<el-tag :type="riskType(row.risk_level)" size="small">{{ riskLabel(row.risk_level) }}</el-tag>
						</template>
					</el-table-column>
					<el-table-column prop="avg_price" label="客单价" width="130" align="right">
						<template #default="{ row }"><span :class="moneyCls(row.avg_price)">{{ fmtMoney(row.avg_price) }}</span></template>
					</el-table-column>
					<el-table-column v-if="hasAnyCol(['repurchase_rate', 'rebuy_rate', 'return_buy_rate', 'purchase_rate'])" label="回购率" width="100" align="right">
						<template #default="{ row }">{{ pct1(row.repurchase_rate ?? row.rebuy_rate ?? row.return_buy_rate ?? row.purchase_rate) }}</template>
					</el-table-column>
				</el-table>
			</el-tab-pane>

			<!-- 业务员销售分析 -->
			<el-tab-pane label="业务员销售分析" name="employees">
				<el-table :data="pagedList" border stripe v-loading="loading" height="100%" empty-text="当前筛选条件下暂无数据">
					<el-table-column prop="salesman_name" label="业务员" width="120" />
					<el-table-column prop="department" label="部门" width="120" />
					<el-table-column prop="visit_count" label="拜访客户数" width="110" align="right" />
					<el-table-column prop="deal_count" label="成交客户数" width="110" align="right" />
					<el-table-column prop="deal_rate" label="成交率" width="90" align="right">
						<template #default="{ row }">{{ pct1(row.deal_rate) }}</template>
					</el-table-column>
					<el-table-column prop="order_count" label="订单数" width="90" align="right" />
					<el-table-column prop="sale_amount" label="销售额" width="130" align="right">
						<template #default="{ row }"><span :class="moneyCls(row.sale_amount)">{{ fmtMoney(row.sale_amount) }}</span></template>
					</el-table-column>
					<el-table-column v-if="hasCol('target')" prop="target" label="目标" width="130" align="right">
						<template #default="{ row }">{{ fmtMoney(row.target) }}</template>
					</el-table-column>
					<el-table-column v-if="showAchieve" prop="achieve_rate" label="达成率" width="100" align="right">
						<template #default="{ row }">
							<span :class="row.achieve_rate >= 100 ? 'text-green' : 'text-red'">{{ pct1(row.achieve_rate) }}</span>
						</template>
					</el-table-column>
					<el-table-column prop="payment_amount" label="回款" width="130" align="right">
						<template #default="{ row }"><span :class="moneyCls(row.payment_amount)">{{ fmtMoney(row.payment_amount) }}</span></template>
					</el-table-column>
					<el-table-column prop="gross_profit" label="毛利" width="130" align="right">
						<template #default="{ row }"><span :class="moneyCls(row.gross_profit)">{{ fmtMoney(row.gross_profit) }}</span></template>
					</el-table-column>
					<el-table-column prop="commission" label="提成" width="130" align="right">
						<template #default="{ row }"><span class="text-red">{{ fmtMoney(row.commission) }}</span></template>
					</el-table-column>
					<el-table-column prop="return_rate" label="退货率" width="90" align="right">
						<template #default="{ row }"><span class="text-red">{{ pct1(row.return_rate) }}</span></template>
					</el-table-column>
					<el-table-column v-if="hasCol('yoy')" prop="yoy" label="同比" width="90" align="right">
						<template #default="{ row }"><span :class="rateCls(row.yoy)">{{ rateText(row.yoy) }}</span></template>
					</el-table-column>
					<el-table-column v-if="hasCol('mom')" prop="mom" label="环比" width="90" align="right">
						<template #default="{ row }"><span :class="rateCls(row.mom)">{{ rateText(row.mom) }}</span></template>
					</el-table-column>
				</el-table>
			</el-tab-pane>

			<!-- 增长与退货率 -->
			<el-tab-pane label="增长与退货率" name="growthReturn">
				<el-table :data="pagedList" border stripe v-loading="loading" height="100%" empty-text="当前筛选条件下暂无数据">
					<el-table-column prop="month" label="月份" width="120" align="center" />
					<el-table-column prop="sale" label="销售额" width="150" align="right">
						<template #default="{ row }"><span :class="moneyCls(row.sale)">{{ fmtMoney(row.sale) }}</span></template>
					</el-table-column>
					<el-table-column prop="return" label="退货额" width="150" align="right">
						<template #default="{ row }"><span class="text-red">{{ fmtMoney(row.return) }}</span></template>
					</el-table-column>
					<el-table-column prop="return_rate" label="退货率" width="110" align="right">
						<template #default="{ row }"><span class="text-red">{{ pct1(row.return_rate) }}</span></template>
					</el-table-column>
					<el-table-column v-if="hasCol('yoy')" prop="yoy" label="同比" width="90" align="right">
						<template #default="{ row }"><span :class="rateCls(row.yoy)">{{ rateText(row.yoy) }}</span></template>
					</el-table-column>
					<el-table-column v-if="hasCol('mom')" prop="mom" label="环比" width="90" align="right">
						<template #default="{ row }"><span :class="rateCls(row.mom)">{{ rateText(row.mom) }}</span></template>
					</el-table-column>
				</el-table>
			</el-tab-pane>
		</el-tabs>

		<div class="perf-toolbar">
			<span class="pager-total">共 {{ total }} 条</span>
			<el-pagination small layout="prev, pager, next, jumper" :page-size="30" :total="total" :current-page="page" @current-change="onPage" />
		</div>
	</div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import perfApi from '@/api/perf'
import { saveBlob } from '@/utils/fileDownload'

// 金额千分位（两位小数），负数红色由 moneyCls 控制
const fmtMoney = (v) => {
	const n = Number(v || 0)
	const neg = n < 0
	const s = Math.abs(n).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',')
	return (neg ? '-' : '') + '¥' + s
}
const moneyCls = (v) => (Number(v || 0) < 0 ? 'text-red' : '')
// 百分比一位小数 / 毛利率两位小数
const pct1 = (v) => (v === null || v === undefined || v === '' ? '—' : Number(v).toFixed(1) + '%')
const pct2 = (v) => (v === null || v === undefined || v === '' ? '—' : Number(v).toFixed(2) + '%')
// 涨红跌绿（与看板一致：升红降绿）
const rateText = (v) => (v === null || v === undefined || v === '' ? '—' : `${Number(v) >= 0 ? '↑' : '↓'} ${Math.abs(Number(v)).toFixed(1)}%`)
const rateCls = (v) => (v === null || v === undefined || v === '' ? 'text-nil' : Number(v) >= 0 ? 'text-up' : 'text-down')

const riskLabel = (s) => ({ normal: '正常', medium: '关注', high: '高风险', lost: '流失' }[s] || s)
const riskType = (s) => ({ normal: 'success', medium: 'info', high: 'warning', lost: 'danger' }[s] || 'info')

const activeTab = ref('products')
const list = ref([])
const loading = ref(false)
const dateRange = ref([])
const lostDays = ref(90)
const page = ref(1)
const total = ref(0)
const PAGE_SIZE = 30

const pagedList = computed(() => {
	const start = (page.value - 1) * PAGE_SIZE
	return list.value.slice(start, start + PAGE_SIZE)
})

const totalSale = computed(() => list.value.reduce((s, r) => s + Math.abs(Number(r.sale_amount || 0)), 0))
const sharePct = (row) => {
	const t = totalSale.value
	if (!t) return '0.00'
	return ((Math.abs(Number(row.sale_amount || 0)) / t) * 100).toFixed(2)
}
const hasCol = (key) => list.value.some((r) => r[key] !== undefined && r[key] !== null && r[key] !== '')
const hasAnyCol = (keys) => keys.some(hasCol)
// 达成率：有 target 才显示
const showAchieve = computed(() => list.value.some((r) => Number(r.target || 0) > 0))

const buildParams = () => {
	const p = { start_date: dateRange.value?.[0] || '', end_date: dateRange.value?.[1] || '' }
	if (activeTab.value === 'customers') {
		// 参数名兼容 lost_days / deal_days 两种约定，取值默认 90
		p.lost_days = lostDays.value
		p.deal_days = lostDays.value
	}
	return p
}

const refresh = async () => {
	loading.value = true
	page.value = 1
	try {
		const params = buildParams()
		const api = {
			products: perfApi.analysis.products,
			customers: perfApi.analysis.customers,
			employees: perfApi.analysis.employees,
			growthReturn: perfApi.analysis.growthReturn,
		}[activeTab.value]
		const res = await api.get(params)
		if (res.code === 200) {
			// growthReturn 接口返回看板信封，取 growth_return 数组；其余取 list
			const rows = activeTab.value === 'growthReturn'
				? (res.data?.growth_return || res.data?.list || [])
				: (res.data?.list || [])
			list.value = rows
			total.value = res.data?.total ?? rows.length
		}
	} finally { loading.value = false }
}

const onDate = () => refresh()
const onPage = (p) => { page.value = p }

// 各 Tab 对应后端导出接口（blob）
const exportData = async () => {
	if (!list.value.length) return ElMessage.warning('当前筛选条件下暂无数据')
	const api = {
		products: perfApi.analysis.productsExport,
		customers: perfApi.analysis.customersExport,
		employees: perfApi.analysis.employeesExport,
		growthReturn: perfApi.analysis.growthReturnExport,
	}[activeTab.value]
	try {
		const blob = await api.get(buildParams())
		saveBlob(blob, `analysis_${activeTab.value}.csv`)
	} catch (e) {
		ElMessage.error(e?.response?.data?.message || '导出失败')
	}
}

onMounted(() => {
	const now = new Date()
	const first = new Date(now.getFullYear(), now.getMonth(), 1)
	const f = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
	dateRange.value = [f(first), f(now)]
	refresh()
})
</script>

<style scoped>
.perf-list { height: 100%; display: flex; flex-direction: column; overflow: auto; }
.perf-filter { display: flex; align-items: center; gap: 12px; padding: 16px 20px; background: #fff; border-bottom: 1px solid #ebeef5; }
.perf-filter .label { font-size: 14px; color: #606266; }
.perf-toolbar { display: flex; align-items: center; justify-content: flex-end; gap: 12px; margin: 12px 20px; }
.pager-total { font-size: 14px; color: #606266; }
.text-green { color: #67C23A; }
.text-red { color: #F56C6C; font-weight: 600; }
.text-up { color: #F56C6C; }
.text-down { color: #67C23A; }
.text-nil { color: #909399; }
</style>
