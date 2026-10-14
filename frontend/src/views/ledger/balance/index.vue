<template>
	<div class="page">
		<div class="page-header"><span class="title">科目余额表</span></div>
		<el-card shadow="never" class="card">
			<div class="filter">
				<span class="lbl">起止期间：</span>
				<el-date-picker v-model="filters.start_period" type="month" value-format="YYYY-MM" placeholder="起始" style="width:120px" />
				<span>至</span>
				<el-date-picker v-model="filters.end_period" type="month" value-format="YYYY-MM" placeholder="结束" style="width:120px" />
				<el-select v-model="filters.max_level" placeholder="科目级次" style="width:120px">
					<el-option label="1 级" :value="1" />
					<el-option label="2 级" :value="2" />
					<el-option label="3 级" :value="3" />
					<el-option label="末级" :value="99" />
				</el-select>
				<el-checkbox v-model="filters.leaf_only">只显示末级</el-checkbox>
				<el-checkbox v-model="filters.include_zero">包含零余额</el-checkbox>
				<el-button type="primary" @click="load">查询</el-button>
				<el-button @click="handleExport">导出</el-button>
			</div>
			<sTable :columns="columns" :data="rows" :loading="loading" height="100%" stripe show-footer :footer-method="footer">
				<template #opening_debit="{ row }"><span class="amt">{{ fmt(row.opening_debit) }}</span></template>
				<template #opening_credit="{ row }"><span class="amt">{{ fmt(row.opening_credit) }}</span></template>
				<template #occur_debit="{ row }"><span class="amt">{{ fmt(row.occur_debit) }}</span></template>
				<template #occur_credit="{ row }"><span class="amt">{{ fmt(row.occur_credit) }}</span></template>
				<template #end_debit="{ row }"><span class="amt" :class="{ 'non-leaf': !row.is_leaf }">{{ fmt(row.end_debit) }}</span></template>
				<template #end_credit="{ row }"><span class="amt" :class="{ 'non-leaf': !row.is_leaf }">{{ fmt(row.end_credit) }}</span></template>
			</sTable>
			<div class="balance-hint" :class="total.balanced ? 'ok' : 'err'">
				{{ total.balanced ? '试算平衡' : `借贷不平衡，差额 ${fmt(total.end_debit - total.end_credit)}` }}
			</div>
		</el-card>
	</div>
</template>

<script setup>
import { ref, reactive, onMounted, computed } from 'vue'
import { ElMessage } from 'element-plus'
import ledgerApi from '@/api/ledger'
import sTable from '@/components/sTable/index.vue'

const rows = ref([])
const loading = ref(false)
const total = ref({ balanced: true, end_debit: 0, end_credit: 0 })
const filters = reactive({ start_period: '', end_period: '', max_level: 99, leaf_only: false, include_zero: false })

const columns = [
	{ field: 'code', title: '科目编码', width: 140 },
	{ field: 'name', title: '科目名称', minWidth: 220 },
	{ field: 'direction', title: '方向', width: 80, align: 'center' },
	{ field: 'opening_debit', title: '期初借方', width: 130, align: 'right', slots: { default: 'opening_debit' } },
	{ field: 'opening_credit', title: '期初贷方', width: 130, align: 'right', slots: { default: 'opening_credit' } },
	{ field: 'occur_debit', title: '本期发生借方', width: 130, align: 'right', slots: { default: 'occur_debit' } },
	{ field: 'occur_credit', title: '本期发生贷方', width: 130, align: 'right', slots: { default: 'occur_credit' } },
	{ field: 'end_debit', title: '期末借方', width: 130, align: 'right', slots: { default: 'end_debit' } },
	{ field: 'end_credit', title: '期末贷方', width: 130, align: 'right', slots: { default: 'end_credit' } },
]
const fmt = (v) => Number(v || 0).toFixed(2)
const footer = () => ['合计', '', '', fmt(total.value.opening_debit), fmt(total.value.opening_credit), fmt(total.value.occur_debit), fmt(total.value.occur_credit), fmt(total.value.end_debit), fmt(total.value.end_credit)]

const load = async () => {
	loading.value = true
	try {
		const res = await ledgerApi.balance.list.get({ ...filters })
		rows.value = res.data?.rows || []
		total.value = res.data?.total || {}
	} catch { ElMessage.error('加载失败') }
	finally { loading.value = false }
}
const handleExport = async () => {
	try { const blob = await ledgerApi.balance.export({ ...filters }); const url = URL.createObjectURL(blob); const a = document.createElement('a'); a.href = url; a.download = 'ledger_subject_balance.csv'; a.click(); setTimeout(() => URL.revokeObjectURL(url), 1000) }
	catch { ElMessage.error('导出失败') }
}
onMounted(() => { filters.start_period = new Date().getFullYear() + '-01'; filters.end_period = new Date().toISOString().slice(0, 7); load() })
</script>

<style scoped>
.page { background: #f0f2f5; min-height: 100vh; }
.page-header { height: 50px; background: #fff; border-bottom: 1px solid #e8e8e8; padding: 0 20px; display: flex; align-items: center; }
.title { font-size: 18px; font-weight: 700; color: #303133; }
.card { margin: 16px 20px; }
.filter { display: flex; gap: 12px; flex-wrap: wrap; align-items: center; padding-bottom: 12px; }
.lbl { font-size: 14px; color: #606266; }
.amt { text-align: right; display: inline-block; width: 100%; }
.non-leaf { font-weight: 700; }
.balance-hint { margin-top: 12px; font-size: 14px; font-weight: 700; text-align: right; }
.balance-hint.ok { color: #67c23a; }
.balance-hint.err { color: #f56c6c; }
</style>
