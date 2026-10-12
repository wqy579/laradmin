<template>
	<div class="perf-list">
		<div class="perf-filter">
			<span class="label">业务员</span>
			<el-input v-model="search.salesman_name" placeholder="业务员" clearable size="small" style="width: 140px" @keyup.enter="refresh" @clear="refresh" />
			<span class="label">客户</span>
			<el-input v-model="search.customer_name" placeholder="客户" clearable size="small" style="width: 160px" @keyup.enter="refresh" @clear="refresh" />
			<span class="label">截止日期</span>
			<el-date-picker v-model="search.as_of_date" type="date" value-format="YYYY-MM-DD" size="small" style="width: 150px" />
			<el-button type="primary" size="small" @click="refresh">查询</el-button>
			<el-button size="small" @click="resetSearch">重置</el-button>
			<el-button size="small" @click="exportData">导出对账单</el-button>
		</div>

		<!-- 统计卡片 -->
		<div class="stat-cards" v-if="result?.cards">
			<div class="stat-card" v-for="c in cards" :key="c.key" :style="{ borderLeftColor: c.color }">
				<div class="stat-num">{{ c.prefix }}{{ fmt(result.cards[c.key]) }}</div>
				<div class="stat-label">{{ c.label }}</div>
			</div>
		</div>

		<div class="perf-content">
			<el-table :data="filteredList" border stripe height="100%" v-loading="loading" :default-expand-all="false">
				<el-table-column type="expand">
					<template #default="{ row }">
						<el-table :data="row.orders" border size="small" style="margin: 8px 0 8px 48px">
							<el-table-column prop="order_no" label="订单号" min-width="160" />
							<el-table-column prop="order_date" label="日期" width="120" />
							<el-table-column prop="total_amount" label="应收" width="120" align="right">
								<template #default="{ row: r }">¥{{ fmt(r.total_amount) }}</template>
							</el-table-column>
							<el-table-column prop="paid_amount" label="已收" width="120" align="right">
								<template #default="{ row: r }">¥{{ fmt(r.paid_amount) }}</template>
							</el-table-column>
							<el-table-column prop="outstanding" label="未收" width="120" align="right">
								<template #default="{ row: r }"><span class="text-red">¥{{ fmt(r.outstanding) }}</span></template>
							</el-table-column>
							<el-table-column prop="age_days" label="账龄(天)" width="100" align="center" />
						</el-table>
					</template>
				</el-table-column>
				<el-table-column prop="customer_code" label="客户编号" width="120" />
				<el-table-column prop="customer_name" label="客户名称" min-width="200" />
				<el-table-column prop="salesman_name" label="业务员" width="110" />
				<el-table-column prop="outstanding" label="应收余额" width="130" align="right">
					<template #default="{ row }">¥{{ fmt(row.outstanding) }}</template>
				</el-table-column>
				<el-table-column label="额度占用%" width="140">
					<template #default="{ row }">
						<el-progress :percentage="Math.min(100, row.credit_usage_rate)" :color="row.credit_usage_rate > 100 ? '#f56c6c' : '#409eff'" :stroke-width="8" />
					</template>
				</el-table-column>
				<el-table-column prop="unmatured" label="未到期" width="120" align="right">
					<template #default="{ row }">¥{{ fmt(row.unmatured) }}</template>
				</el-table-column>
				<el-table-column prop="b1_30" label="1~30天" width="120" align="right">
					<template #default="{ row }"><span :style="{ color: agingColor(30) }">¥{{ fmt(row.b1_30) }}</span></template>
				</el-table-column>
				<el-table-column prop="b31_60" label="31~60天" width="120" align="right">
					<template #default="{ row }"><span :style="{ color: agingColor(60) }">¥{{ fmt(row.b31_60) }}</span></template>
				</el-table-column>
				<el-table-column prop="b61_90" label="61~90天" width="120" align="right">
					<template #default="{ row }"><span :style="{ color: agingColor(90) }">¥{{ fmt(row.b61_90) }}</span></template>
				</el-table-column>
				<el-table-column prop="b91_180" label="91~180天" width="130" align="right">
					<template #default="{ row }"><span :style="{ color: agingColor(180) }">¥{{ fmt(row.b91_180) }}</span></template>
				</el-table-column>
				<el-table-column prop="b180_plus" label="180天以上" width="130" align="right">
					<template #default="{ row }"><span :style="{ color: agingColor(999), fontWeight: 'bold' }">¥{{ fmt(row.b180_plus) }}</span></template>
				</el-table-column>
				<el-table-column prop="max_age" label="最长逾期" width="100" align="center">
					<template #default="{ row }"><span class="text-red">{{ row.max_age }}天</span></template>
				</el-table-column>
				<el-table-column label="状态" width="90" align="center">
					<template #default="{ row }">
						<el-tag :type="riskType(row.status)" size="small">{{ riskLabel(row.status) }}</el-tag>
					</template>
				</el-table-column>
			</el-table>
		</div>
	</div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import perfApi from '@/api/perf'
import { saveBlob } from '@/utils/fileDownload'

const fmt = (v) => Number(v || 0).toFixed(2)
const cards = [
	{ key: 'total', label: '应收总额', color: '#409eff', prefix: '¥' },
	{ key: 'unmatured', label: '未逾期', color: '#67c23a', prefix: '¥' },
	{ key: 'overdue', label: '逾期金额', color: '#e6a23c', prefix: '¥' },
	{ key: 'overdue_90', label: '逾期超90天', color: '#f56c6c', prefix: '¥' },
]
const agingColor = (days) => days <= 30 ? '#67c23a' : days <= 60 ? '#e6a23c' : days <= 90 ? '#f56c6c' : '#c0392b'
const riskLabel = (s) => ({ normal: '正常', warning: '预警', high_risk: '高风险' }[s] || s)
const riskType = (s) => ({ normal: 'success', warning: 'warning', high_risk: 'danger' }[s] || 'info')

const search = reactive({ salesman_name: '', customer_name: '', as_of_date: new Date().toISOString().slice(0, 10) })
const result = ref(null)
const loading = ref(false)

const filteredList = computed(() => {
	if (!result.value?.list) return []
	return result.value.list.filter((r) => {
		if (search.salesman_name && !(r.salesman_name || '').includes(search.salesman_name)) return false
		if (search.customer_name && !(r.customer_name || '').includes(search.customer_name)) return false
		return true
	})
})

const refresh = async () => {
	loading.value = true
	try {
		const res = await perfApi.ar.aging.get(search)
		if (res.code === 200) result.value = res.data
	} finally { loading.value = false }
}

const resetSearch = () => { search.salesman_name = ''; search.customer_name = ''; refresh() }

const exportData = () => {
	if (!filteredList.value.length) return ElMessage.warning('无数据')
	const headers = ['客户编号', '客户名称', '业务员', '应收余额', '未到期', '1~30天', '31~60天', '61~90天', '91~180天', '180天以上', '最长逾期', '状态']
	const rows = filteredList.value.map((r) => [r.customer_code, r.customer_name, r.salesman_name, r.outstanding, r.unmatured, r.b1_30, r.b31_60, r.b61_90, r.b91_180, r.b180_plus, r.max_age, riskLabel(r.status)])
	const csv = '﻿应收账龄分析\n\n' + [headers.join(','), ...rows.map((r) => r.join(','))].join('\n')
	saveBlob(csv, 'ar_aging.csv')
}

onMounted(refresh)
</script>

<style scoped>
.perf-list { height: 100%; display: flex; flex-direction: column; overflow: auto; }
.perf-filter { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; padding: 16px 20px; background: #fff; border-bottom: 1px solid #ebeef5; }
.perf-filter .label { font-size: 14px; color: #606266; }
.stat-cards { display: flex; gap: 16px; padding: 12px 20px; }
.stat-card { flex: 1; background: #fff; height: 96px; border: 1px solid #ebeef5; border-left: 4px solid #409eff; border-radius: 4px; padding: 16px 20px; display: flex; flex-direction: column; justify-content: center; }
.stat-num { font-size: 24px; font-weight: bold; color: #303133; }
.stat-label { font-size: 13px; color: #909399; margin-top: 4px; }
.perf-content { flex: 1; overflow: auto; padding: 0 12px; }
.text-red { color: #f56c6c; font-weight: 600; }
</style>
