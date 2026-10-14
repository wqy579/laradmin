<template>
	<div class="perf-list">
		<div class="perf-filter">
			<span class="label">计算月份</span>
			<el-date-picker v-model="filter.period" type="month" value-format="YYYY-MM" placeholder="选择月份" size="small" style="width: 140px" />
			<span class="label">业务员</span>
			<el-select v-model="filter.employee_ids" multiple placeholder="全部" size="small" style="width: 320px">
				<el-option v-for="e in employees" :key="e.id" :label="e.name" :value="e.id" />
			</el-select>
			<el-button type="primary" class="btn-120" :loading="loading" @click="doPreview">开始计算</el-button>
		</div>

		<div v-if="result && result.groups?.length" class="perf-content">
			<!-- 汇总 -->
			<div class="summary-bar">
				<span>业务员数：<b>{{ result.groups.length }}</b></span>
				<span class="sep">|</span>
				<span>销售总额：<span :class="negCls(result.totals.sales_amount)">¥{{ fmt(result.totals.sales_amount) }}</span></span>
				<span class="sep">|</span>
				<span>回款总额：<span :class="negCls(result.totals.received_amount)">¥{{ fmt(result.totals.received_amount) }}</span></span>
				<span class="sep">|</span>
				<span>实发提成：<b class="text-red">¥{{ fmt(result.totals.actual_amount) }}</b></span>
			</div>

			<el-table :data="result.groups" border stripe height="100%" :default-expand-all="false">
				<el-table-column type="expand">
					<template #default="{ row }">
						<el-table :data="row.detail_lines" border size="small" style="margin: 8px 0 8px 48px">
							<el-table-column prop="source_no" label="来源" min-width="180" />
							<el-table-column prop="rule_name" label="方案" width="140" />
							<el-table-column prop="rate_or_unit" label="比例/单额" width="120" />
							<el-table-column prop="base_amount" label="基数" width="120" align="right">
								<template #default="{ row: r }"><span :class="negCls(r.base_amount)">¥{{ fmt(r.base_amount) }}</span></template>
							</el-table-column>
							<el-table-column prop="amount" label="提成" width="120" align="right">
								<template #default="{ row: r }"><span :class="negCls(r.amount)">¥{{ fmt(r.amount) }}</span></template>
							</el-table-column>
						</el-table>
					</template>
				</el-table-column>
				<el-table-column prop="employee_name" label="业务员" width="120" />
				<el-table-column prop="department_name" label="部门" width="110" />
				<el-table-column prop="sales_amount" label="销售金额" width="120" align="right">
					<template #default="{ row }"><span :class="negCls(row.sales_amount)">¥{{ fmt(row.sales_amount) }}</span></template>
				</el-table-column>
				<el-table-column prop="received_amount" label="回款金额" width="120" align="right">
					<template #default="{ row }"><span :class="negCls(row.received_amount)">¥{{ fmt(row.received_amount) }}</span></template>
				</el-table-column>
				<el-table-column prop="return_amount" label="退货金额" width="110" align="right">
					<template #default="{ row }"><span :class="negCls(row.return_amount)">¥{{ fmt(row.return_amount) }}</span></template>
				</el-table-column>
				<el-table-column prop="profit_amount" label="毛利" width="120" align="right">
					<template #default="{ row }"><span :class="negCls(row.profit_amount)">¥{{ fmt(row.profit_amount) }}</span></template>
				</el-table-column>
				<el-table-column prop="rule_name" label="命中方案" width="150" show-overflow-tooltip />
				<el-table-column label="提成比例" width="100" align="right">
					<template #default="{ row }">{{ rateText(row) }}</template>
				</el-table-column>
				<el-table-column label="单额" width="100" align="right">
					<template #default="{ row }">{{ unitText(row) }}</template>
				</el-table-column>
				<el-table-column prop="base_amount" label="提成基数" width="120" align="right">
					<template #default="{ row }"><span :class="negCls(row.base_amount)">¥{{ fmt(row.base_amount) }}</span></template>
				</el-table-column>
				<el-table-column prop="commission_amount" label="应发提成" width="120" align="right">
					<template #default="{ row }"><span :class="negCls(row.commission_amount)">¥{{ fmt(row.commission_amount) }}</span></template>
				</el-table-column>
				<el-table-column prop="deduction" label="扣减" width="100" align="right">
					<template #default="{ row }"><span :class="negCls(row.deduction)">¥{{ fmt(row.deduction) }}</span></template>
				</el-table-column>
				<el-table-column prop="actual_amount" label="实发提成" width="130" align="right" fixed="right">
					<template #default="{ row }"><b class="text-red">¥{{ fmt(row.actual_amount) }}</b></template>
				</el-table-column>
			</el-table>

			<div class="perf-toolbar">
				<el-button class="btn-120 btn-outline" :loading="loading" @click="doPreview">重新试算</el-button>
				<el-button class="btn-120" :loading="exporting" @click="exportDetail">导出明细</el-button>
				<el-button type="primary" class="btn-120" :loading="generating" @click="doGenerate">确认生成提成单</el-button>
			</div>
		</div>
		<el-empty v-else description="当前筛选条件下暂无数据" />
	</div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import perfApi from '@/api/perf'
import businessApi from '@/api/business'
import { saveBlob } from '@/utils/fileDownload'

const fmt = (v) => Number(v || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const negCls = (v) => (Number(v || 0) < 0 ? 'text-red' : '')

const employees = ref([])
const rules = ref([])
const ruleMap = computed(() => Object.fromEntries(rules.value.map((r) => [r.id, r])))
const filter = reactive({ period: lastMonth(), employee_ids: [] })
const result = ref(null)
const loading = ref(false)
const exporting = ref(false)
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

/** 提成方案（取 rate / unit_amount 展示「提成比例」「单额」列用） */
const loadRules = async () => {
	try {
		const res = await perfApi.rule.list.get({ page: 1, page_size: 200 })
		if (res?.code === 200) rules.value = res.data?.list || []
	} catch { /* ignore */ }
}

const rateText = (row) => {
	const r = row.rate ?? ruleMap.value[row.rule_id]?.rate
	if (r !== undefined && r !== null && r !== '') return `${r}%`
	const line = (row.detail_lines || []).find((l) => l.rate_or_unit && String(l.rate_or_unit).endsWith('%'))
	return line ? line.rate_or_unit : '—'
}

const unitText = (row) => {
	const rule = ruleMap.value[row.rule_id]
	const piece = row.calc_type === 'fixed_per_unit' || rule?.calc_type === 'fixed_per_unit' || rule?.type === 'piece'
	const u = row.unit_amount ?? rule?.unit_amount
	if (piece && u !== undefined && u !== null && u !== '') return `¥${u}`
	return '—'
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

/** 服务端 CSV（与试算同 body），blob 落盘，不在前端拼 CSV */
const exportDetail = async () => {
	if (!result.value?.groups?.length) return ElMessage.warning('当前筛选条件下暂无数据')
	exporting.value = true
	try {
		const blob = await perfApi.commission.export.post({ period: filter.period, employee_ids: filter.employee_ids })
		saveBlob(blob, `commission_preview_${filter.period}.csv`)
	} finally { exporting.value = false }
}

onMounted(() => { loadEmployees(); loadRules() })
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

/* 主按钮统一 120×36 */
.btn-120 { width: 120px; height: 36px; margin-left: 0; }
/* 「重新试算」白底蓝描边 */
.btn-outline { background: #fff; border: 1px solid #409eff; color: #409eff; }
.btn-outline:hover, .btn-outline:focus { background: #ecf5ff; border-color: #409eff; color: #409eff; }

/* 表格：表头 #F5F7FA 14px/500，表体 14px #606266，行高 48px，斑马 #FAFAFA */
:deep(.el-table th.el-table__cell) { background: #f5f7fa; color: #303133; font-size: 14px; font-weight: 500; height: 48px; }
:deep(.el-table td.el-table__cell) { color: #606266; font-size: 14px; height: 48px; }
:deep(.el-table .el-table__row--striped td.el-table__cell) { background: #fafafa; }
</style>
