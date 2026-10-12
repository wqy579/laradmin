<template>
	<div class="perf-list">
		<div class="perf-filter">
			<span class="label">计算月份</span>
			<el-date-picker v-model="filter.period" type="month" value-format="YYYY-MM" placeholder="选择月份" size="small" style="width: 140px" />
			<span class="label">业务员</span>
			<el-select v-model="filter.employee_ids" multiple placeholder="全部" size="small" style="width: 320px">
				<el-option v-for="e in employees" :key="e.id" :label="e.name" :value="e.id" />
			</el-select>
			<el-button type="primary" size="default" style="margin-left: 12px" :loading="loading" @click="doPreview">开始计算</el-button>
		</div>

		<div v-if="result" class="perf-content">
			<!-- 汇总 -->
			<div class="summary-bar">
				<span>业务员数：<b>{{ result.groups.length }}</b></span>
				<span class="sep">|</span>
				<span>销售总额：¥{{ fmt(result.totals.sale_amount) }}</span>
				<span class="sep">|</span>
				<span>回款总额：¥{{ fmt(result.totals.payment_amount) }}</span>
				<span class="sep">|</span>
				<span>实发提成：<b class="text-red">¥{{ fmt(result.totals.commission_amount) }}</b></span>
			</div>

			<el-table :data="result.groups" border stripe height="100%" :default-expand-all="false">
				<el-table-column type="expand">
					<template #default="{ row }">
						<el-table :data="row.detail_lines" border size="small" style="margin: 8px 0 8px 48px">
							<el-table-column prop="source_no" label="来源" min-width="180" />
							<el-table-column prop="rule_name" label="方案" width="140" />
							<el-table-column prop="rate_or_unit" label="比例/单额" width="120" />
							<el-table-column prop="base_amount" label="基数" width="120" align="right">
								<template #default="{ row: r }">¥{{ fmt(r.base_amount) }}</template>
							</el-table-column>
							<el-table-column prop="amount" label="提成" width="120" align="right">
								<template #default="{ row: r }"><span class="text-red">¥{{ fmt(r.amount) }}</span></template>
							</el-table-column>
						</el-table>
					</template>
				</el-table-column>
				<el-table-column prop="employee_name" label="业务员" width="120" />
				<el-table-column prop="department_name" label="部门" width="120" />
				<el-table-column prop="sale_amount" label="销售金额" width="120" align="right">
					<template #default="{ row }">¥{{ fmt(row.sale_amount) }}</template>
				</el-table-column>
				<el-table-column prop="payment_amount" label="回款金额" width="120" align="right">
					<template #default="{ row }">¥{{ fmt(row.payment_amount) }}</template>
				</el-table-column>
				<el-table-column prop="return_amount" label="退货金额" width="110" align="right">
					<template #default="{ row }">¥{{ fmt(row.return_amount) }}</template>
				</el-table-column>
				<el-table-column prop="gross_profit" label="毛利" width="120" align="right">
					<template #default="{ row }">¥{{ fmt(row.gross_profit) }}</template>
				</el-table-column>
				<el-table-column prop="rule_name" label="命中方案" width="160" />
				<el-table-column prop="commission_base" label="提成基数" width="120" align="right">
					<template #default="{ row }">¥{{ fmt(row.commission_base) }}</template>
				</el-table-column>
				<el-table-column prop="commission_gross" label="应发提成" width="120" align="right">
					<template #default="{ row }">¥{{ fmt(row.commission_gross) }}</template>
				</el-table-column>
				<el-table-column prop="commission_deduct" label="扣减" width="100" align="right">
					<template #default="{ row }">¥{{ fmt(row.commission_deduct) }}</template>
				</el-table-column>
				<el-table-column prop="commission_amount" label="实发提成" width="130" align="right" fixed="right">
					<template #default="{ row }"><b class="text-red">¥{{ fmt(row.commission_amount) }}</b></template>
				</el-table-column>
			</el-table>

			<div class="perf-toolbar">
				<el-button @click="doPreview" :loading="loading">重算</el-button>
				<el-button @click="exportDetail">导出明细</el-button>
				<el-button type="primary" :loading="generating" @click="doGenerate">确认生成提成单</el-button>
			</div>
		</div>
		<el-empty v-else description="选择月份后点击「开始计算」进行试算" />
	</div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import perfApi from '@/api/perf'
import businessApi from '@/api/business'
import { saveBlob } from '@/utils/fileDownload'

const fmt = (v) => Number(v || 0).toFixed(2)
const employees = ref([])
const filter = reactive({ period: lastMonth(), employee_ids: [] })
const result = ref(null)
const loading = ref(false)
const generating = ref(false)

function lastMonth() {
	const d = new Date()
	d.setMonth(d.getMonth() - 1)
	return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`
}

const loadEmployees = async () => {
	try {
		const res = await businessApi.employee.list.get({ page: 1, page_size: 200 })
		if (res?.code === 200) employees.value = (res.data?.list || []).filter((e) => e.user_id).map((e) => ({ id: e.user_id, name: e.name }))
	} catch { /* ignore */ }
}

const doPreview = async () => {
	if (!filter.period) return ElMessage.warning('请选择月份')
	loading.value = true
	try {
		const res = await perfApi.commission.preview.post({ period: filter.period, employee_ids: filter.employee_ids })
		if (res.code === 200) {
			result.value = res.data
			if (!res.data.groups?.length) ElMessage.info('该月无可计算提成的数据')
		}
	} finally { loading.value = false }
}

const doGenerate = async () => {
	try {
		await ElMessageBox.confirm('确认后将为每位业务员生成提成单（待审核），是否继续？', '操作确认', { type: 'warning' })
	} catch { return }
	generating.value = true
	try {
		const res = await perfApi.commission.generate.post({ period: filter.period, employee_ids: filter.employee_ids })
		if (res.code === 200) { ElMessage.success(res.message || '生成成功'); result.value = null }
	} finally { generating.value = false }
}

const exportDetail = () => {
	if (!result.value?.groups?.length) return ElMessage.warning('无数据可导出')
	const headers = ['业务员', '部门', '销售额', '回款额', '退货额', '毛利', '方案', '应发', '扣减', '实发']
	const rows = result.value.groups.map((g) => [g.employee_name, g.department_name, g.sale_amount, g.payment_amount, g.return_amount, g.gross_profit, g.rule_name, g.commission_gross, g.commission_deduct, g.commission_amount])
	const csv = '﻿提成试算明细\n\n' + [headers.join(','), ...rows.map((r) => r.join(','))].join('\n')
	saveBlob(csv, `commission_preview_${filter.period}.csv`)
}

onMounted(loadEmployees)
</script>

<style scoped>
.perf-list { height: 100%; display: flex; flex-direction: column; }
.perf-filter { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; padding: 16px 20px; background: #fff; border-bottom: 1px solid #ebeef5; }
.perf-filter .label { font-size: 14px; color: #606266; }
.perf-content { flex: 1; display: flex; flex-direction: column; overflow: auto; padding: 12px; }
.summary-bar { display: flex; gap: 8px; align-items: center; padding: 8px 12px; background: #f5f7fa; font-size: 13px; color: #606266; margin-bottom: 12px; }
.summary-bar .sep { color: #dcdfe6; }
.text-red { color: #f56c6c; font-weight: 600; }
.perf-toolbar { display: flex; gap: 8px; justify-content: flex-end; margin-top: 12px; }
</style>
