<template>
	<div class="perf-list">
		<div class="perf-filter">
			<span class="label">日期范围</span>
			<el-date-picker v-model="dateRange" type="daterange" value-format="YYYY-MM-DD" size="small" style="width: 240px" @change="onDate" />
			<el-button type="primary" size="small" @click="refresh">查询</el-button>
			<el-button size="small" @click="exportData">导出</el-button>
		</div>

		<el-tabs v-model="activeTab" @tab-change="refresh">
			<!-- 商品销售分析 -->
			<el-tab-pane label="商品销售分析" name="products">
				<el-table :data="list" border stripe v-loading="loading" height="100%">
					<el-table-column prop="product_code" label="商品编码" width="120" />
					<el-table-column prop="product_name" label="商品" min-width="180" />
					<el-table-column prop="brand_name" label="品牌" width="100" />
					<el-table-column prop="category_name" label="分类" width="100" />
					<el-table-column prop="qty" label="销量" width="100" align="right" sortable />
					<el-table-column prop="sale_amount" label="销售额" width="120" align="right" sortable>
						<template #default="{ row }">¥{{ fmt(row.sale_amount) }}</template>
					</el-table-column>
					<el-table-column prop="gross_profit" label="毛利" width="120" align="right" sortable>
						<template #default="{ row }"><span :class="row.gross_profit >= 0 ? 'text-green' : 'text-red'">¥{{ fmt(row.gross_profit) }}</span></template>
					</el-table-column>
					<el-table-column prop="profit_rate" label="毛利率" width="100" align="right">
						<template #default="{ row }"><span :class="row.profit_rate >= 0 ? 'text-green' : 'text-red'">{{ row.profit_rate }}%</span></template>
					</el-table-column>
					<el-table-column prop="return_qty" label="退货数" width="90" align="right" />
					<el-table-column prop="return_rate" label="退货率" width="90" align="right">
						<template #default="{ row }">{{ row.return_rate }}%</template>
					</el-table-column>
					<el-table-column prop="customer_count" label="铺市客户数" width="110" align="right" />
				</el-table>
			</el-tab-pane>

			<!-- 客户销售汇总 -->
			<el-tab-pane label="客户销售汇总" name="customers">
				<el-table :data="list" border stripe v-loading="loading" height="100%">
					<el-table-column prop="customer_name" label="客户" min-width="160" />
					<el-table-column prop="salesman_name" label="业务员" width="100" />
					<el-table-column prop="level_name" label="等级" width="100" />
					<el-table-column prop="order_count" label="订单数" width="90" align="right" />
					<el-table-column prop="sale_amount" label="销售金额" width="120" align="right">
						<template #default="{ row }">¥{{ fmt(row.sale_amount) }}</template>
					</el-table-column>
					<el-table-column prop="gross_profit" label="毛利" width="110" align="right">
						<template #default="{ row }">¥{{ fmt(row.gross_profit) }}</template>
					</el-table-column>
					<el-table-column prop="payment_amount" label="回款" width="110" align="right">
						<template #default="{ row }">¥{{ fmt(row.payment_amount) }}</template>
					</el-table-column>
					<el-table-column prop="receivable" label="应收余额" width="110" align="right">
						<template #default="{ row }"><span class="text-red">¥{{ fmt(row.receivable) }}</span></template>
					</el-table-column>
					<el-table-column prop="last_order_date" label="最近下单" width="120" align="center" />
					<el-table-column label="流失风险" width="100" align="center">
						<template #default="{ row }">
							<el-tag :type="riskType(row.risk_level)" size="small">{{ riskLabel(row.risk_level) }}</el-tag>
						</template>
					</el-table-column>
					<el-table-column prop="avg_price" label="客单价" width="110" align="right">
						<template #default="{ row }">¥{{ fmt(row.avg_price) }}</template>
					</el-table-column>
				</el-table>
			</el-tab-pane>

			<!-- 业务员销售分析 -->
			<el-tab-pane label="业务员销售分析" name="employees">
				<el-table :data="list" border stripe v-loading="loading" height="100%">
					<el-table-column prop="salesman_name" label="业务员" width="120" />
					<el-table-column prop="department" label="部门" width="120" />
					<el-table-column prop="visit_count" label="拜访客户数" width="110" align="right" />
					<el-table-column prop="deal_count" label="成交客户数" width="110" align="right" />
					<el-table-column prop="deal_rate" label="成交率" width="90" align="right">
						<template #default="{ row }">{{ row.deal_rate }}%</template>
					</el-table-column>
					<el-table-column prop="order_count" label="订单数" width="90" align="right" />
					<el-table-column prop="sale_amount" label="销售额" width="120" align="right">
						<template #default="{ row }">¥{{ fmt(row.sale_amount) }}</template>
					</el-table-column>
					<el-table-column prop="payment_amount" label="回款" width="110" align="right">
						<template #default="{ row }">¥{{ fmt(row.payment_amount) }}</template>
					</el-table-column>
					<el-table-column prop="gross_profit" label="毛利" width="110" align="right">
						<template #default="{ row }">¥{{ fmt(row.gross_profit) }}</template>
					</el-table-column>
					<el-table-column prop="commission" label="提成" width="110" align="right">
						<template #default="{ row }"><span class="text-red">¥{{ fmt(row.commission) }}</span></template>
					</el-table-column>
					<el-table-column prop="return_rate" label="退货率" width="90" align="right">
						<template #default="{ row }">{{ row.return_rate }}%</template>
					</el-table-column>
					<el-table-column prop="achieve_rate" label="达成率" width="100" align="right">
						<template #default="{ row }">
							<span :class="row.achieve_rate >= 100 ? 'text-green' : 'text-red'">{{ row.achieve_rate }}%</span>
						</template>
					</el-table-column>
				</el-table>
			</el-tab-pane>
		</el-tabs>
	</div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import perfApi from '@/api/perf'
import { saveBlob } from '@/utils/fileDownload'

const fmt = (v) => Number(v || 0).toFixed(2)
const riskLabel = (s) => ({ normal: '正常', medium: '关注', high: '高风险', lost: '流失' }[s] || s)
const riskType = (s) => ({ normal: 'success', medium: 'info', high: 'warning', lost: 'danger' }[s] || 'info')

const activeTab = ref('products')
const list = ref([])
const loading = ref(false)
const dateRange = ref([])

const onDate = () => refresh()

const refresh = async () => {
	loading.value = true
	try {
		const params = { start_date: dateRange.value?.[0] || '', end_date: dateRange.value?.[1] || '' }
		const api = { products: perfApi.analysis.products, customers: perfApi.analysis.customers, employees: perfApi.analysis.employees }[activeTab.value]
		const res = await api.get(params)
		if (res.code === 200) list.value = res.data?.list || []
	} finally { loading.value = false }
}

const exportData = () => {
	if (!list.value.length) return ElMessage.warning('无数据')
	const headers = Object.keys(list.value[0])
	const rows = list.value.map((r) => Object.values(r).join(','))
	const csv = '﻿' + activeTab.value + '分析\n\n' + [headers.join(','), ...rows].join('\n')
	saveBlob(csv, `analysis_${activeTab.value}.csv`)
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
.text-green { color: #67c23a; }
.text-red { color: #f56c6c; font-weight: 600; }
</style>
