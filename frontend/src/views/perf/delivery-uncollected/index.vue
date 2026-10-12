<template>
	<div class="perf-list">
		<div class="perf-filter">
			<span class="label">业务员</span>
			<el-input v-model="search.salesman_name" placeholder="业务员" clearable size="small" style="width: 140px" @keyup.enter="refresh" @clear="refresh" />
			<span class="label">客户</span>
			<el-input v-model="search.customer_name" placeholder="客户" clearable size="small" style="width: 160px" @keyup.enter="refresh" @clear="refresh" />
			<span class="label">天数区间</span>
			<el-input-number v-model="search.day_from" :min="0" :controls="false" size="small" style="width: 80px" placeholder="起" />
			<span style="color: #dcdfe6">~</span>
			<el-input-number v-model="search.day_to" :min="0" :controls="false" size="small" style="width: 80px" placeholder="止" />
			<el-button type="primary" size="small" @click="refresh">查询</el-button>
			<el-button size="small" @click="resetSearch">重置</el-button>
			<el-button size="small" @click="exportData">导出</el-button>
		</div>

		<div class="stat-cards" v-if="result?.cards">
			<div class="stat-card" style="border-left-color: #f56c6c">
				<div class="stat-num">¥{{ fmt(result.cards.total_uncollected) }}</div>
				<div class="stat-label">总未收金额</div>
			</div>
			<div class="stat-card" style="border-left-color: #67c23a">
				<div class="stat-num">¥{{ fmt(result.cards.b1_30) }}</div>
				<div class="stat-label">1~30天</div>
			</div>
			<div class="stat-card" style="border-left-color: #e6a23c">
				<div class="stat-num">¥{{ fmt(result.cards.b31_60) }}</div>
				<div class="stat-label">31~60天</div>
			</div>
			<div class="stat-card" style="border-left-color: #f56c6c">
				<div class="stat-num">¥{{ fmt(result.cards.b61_90) }}</div>
				<div class="stat-label">61~90天</div>
			</div>
			<div class="stat-card" style="border-left-color: #c0392b">
				<div class="stat-num">¥{{ fmt(result.cards.b90_plus) }}</div>
				<div class="stat-label">90天以上</div>
			</div>
		</div>

		<div class="perf-content">
			<el-table :data="filteredList" border stripe height="100%" v-loading="loading">
				<el-table-column prop="delivery_date" label="发货日期" width="120" align="center" />
				<el-table-column prop="order_no" label="单据号" min-width="160">
					<template #default="{ row }"><span class="link" @click="goReceive(row)">{{ row.order_no }}</span></template>
				</el-table-column>
				<el-table-column prop="customer_name" label="客户" min-width="160" />
				<el-table-column prop="salesman_name" label="业务员" width="110" />
				<el-table-column prop="total_amount" label="发货金额" width="120" align="right">
					<template #default="{ row }">¥{{ fmt(row.total_amount) }}</template>
				</el-table-column>
				<el-table-column prop="paid_amount" label="已收金额" width="120" align="right">
					<template #default="{ row }">¥{{ fmt(row.paid_amount) }}</template>
				</el-table-column>
				<el-table-column prop="uncollected" label="未收金额" width="120" align="right">
					<template #default="{ row }"><span class="text-red">¥{{ fmt(row.uncollected) }}</span></template>
				</el-table-column>
				<el-table-column prop="age_days" label="账龄(天)" width="100" align="center" />
				<el-table-column prop="days_from_today" label="距今天数" width="100" align="center" />
				<el-table-column label="状态" width="90" align="center">
					<template #default="{ row }">
						<el-tag :type="statusType(row.status)" size="small">{{ statusLabel(row.status) }}</el-tag>
					</template>
				</el-table-column>
			</el-table>
		</div>
	</div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import { useRouter } from 'vue-router'
import perfApi from '@/api/perf'
import { saveBlob } from '@/utils/fileDownload'

const fmt = (v) => Number(v || 0).toFixed(2)
const statusLabel = (s) => ({ normal: '正常', soon: '临期', overdue: '逾期' }[s] || s)
const statusType = (s) => ({ normal: 'success', soon: 'warning', overdue: 'danger' }[s] || 'info')
const router = useRouter()

const search = reactive({ salesman_name: '', customer_name: '', day_from: null, day_to: null })
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
		const res = await perfApi.delivery.uncollected.get(search)
		if (res.code === 200) result.value = res.data
	} finally { loading.value = false }
}

const resetSearch = () => { search.salesman_name = ''; search.customer_name = ''; search.day_from = null; search.day_to = null; refresh() }

const goReceive = (row) => { router.push(`/business/payment?order_no=${row.order_no}`) }

const exportData = () => {
	if (!filteredList.value.length) return ElMessage.warning('无数据')
	const headers = ['发货日期', '单据号', '客户', '业务员', '发货金额', '已收', '未收', '账龄', '距今', '状态']
	const rows = filteredList.value.map((r) => [r.delivery_date, r.order_no, r.customer_name, r.salesman_name, r.total_amount, r.paid_amount, r.uncollected, r.age_days, r.days_from_today, statusLabel(r.status)])
	const csv = '﻿发货未收款\n\n' + [headers.join(','), ...rows.map((r) => r.join(','))].join('\n')
	saveBlob(csv, 'delivery_uncollected.csv')
}

onMounted(refresh)
</script>

<style scoped>
.perf-list { height: 100%; display: flex; flex-direction: column; overflow: auto; }
.perf-filter { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; padding: 16px 20px; background: #fff; border-bottom: 1px solid #ebeef5; }
.perf-filter .label { font-size: 14px; color: #606266; }
.stat-cards { display: flex; gap: 16px; padding: 12px 20px; }
.stat-card { flex: 1; background: #fff; height: 96px; border: 1px solid #ebeef5; border-left: 4px solid #f56c6c; border-radius: 4px; padding: 16px 20px; display: flex; flex-direction: column; justify-content: center; }
.stat-num { font-size: 24px; font-weight: bold; color: #303133; }
.stat-label { font-size: 13px; color: #909399; margin-top: 4px; }
.perf-content { flex: 1; overflow: auto; padding: 0 12px; }
.link { color: #409eff; cursor: pointer; }
.text-red { color: #f56c6c; font-weight: 600; }
</style>
