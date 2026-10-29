<template>
	<div class="hr-flow hr-page">
		<!-- 查询区 -->
		<div class="hr-filter">
			<span class="label">期间</span>
			<el-select v-model="search.period" placeholder="全部" clearable filterable size="small" style="width: 130px" @change="onPeriodChange">
				<el-option v-for="p in periods" :key="p" :label="p" :value="p" />
			</el-select>
			<span class="label">业务员</span>
			<el-select v-model="search.employee_id" placeholder="全部" clearable filterable size="small" style="width: 160px" @change="onPeriodChange">
				<el-option v-for="e in employees" :key="e.id" :label="e.name" :value="e.id" />
			</el-select>
			<span class="label">来源单号</span>
			<el-input v-model="search.source_no" placeholder="销售单号" clearable size="small" style="width: 170px" @keyup.enter="refresh" @clear="refresh" />
			<span class="label">关键字</span>
			<el-input v-model="search.keyword" placeholder="流水号 / 提成单号 / 业务员" clearable size="small" style="width: 190px" @keyup.enter="refresh" @clear="refresh" />
			<el-button type="primary" size="small" @click="refresh">查询</el-button>
			<el-button size="small" @click="resetSearch">重置</el-button>
		</div>

		<!-- 汇总条 -->
		<div class="summary-bar">
			<span>流水数：<b>{{ stat.count }}</b></span>
			<span class="sep">|</span>
			<span>销售额：<span class="amt">¥{{ fmtMoney(stat.sales_amount) }}</span></span>
			<span class="sep">|</span>
			<span>计提基数：<span class="amt">¥{{ fmtMoney(stat.base_amount) }}</span></span>
			<span class="sep">|</span>
			<span>提成额：<span class="amt">¥{{ fmtMoney(stat.commission_amount) }}</span></span>
			<span class="sep">|</span>
			<span>扣减：<span class="amt text-red">-¥{{ fmtMoney(stat.deduction) }}</span></span>
			<span class="sep">|</span>
			<span>实发合计：<b class="amt">¥{{ fmtMoney(stat.actual_amount) }}</b></span>
		</div>

		<!-- 与提成模块对账（只读口径核对） -->
		<div class="recon-bar">
			<span class="recon-title">与提成模块对账</span>
			<template v-if="recon">
				<span>提成模块：{{ recon.perf.count }} 张 / 实发 <b class="amt">¥{{ fmtMoney(recon.perf.actual_amount) }}</b></span>
				<span class="sep">|</span>
				<span>已入流水：{{ recon.local.count }} 条 / 实发 <b class="amt">¥{{ fmtMoney(recon.local.actual_amount) }}</b></span>
				<span class="sep">|</span>
				<el-tag :type="recon.matched ? 'success' : 'warning'" size="small" effect="light">
					{{ recon.matched ? '口径一致' : '存在差异' }}
				</el-tag>
				<span class="recon-note">{{ recon.note }}</span>
			</template>
			<span v-else class="recon-note">选择期间后可核对本期间提成单与已快照流水是否一致。</span>
		</div>

		<div class="hr-toolbar">
			<el-button v-permission="'hr:commission-flow:export'" :loading="exporting" @click="doExport">导出</el-button>
		</div>

		<div class="hr-content">
			<el-table v-loading="loading" :data="rows" border stripe height="100%">
				<el-table-column prop="flow_no" label="流水号" width="150" show-overflow-tooltip />
				<el-table-column prop="bill_no" label="提成单号" width="150" show-overflow-tooltip />
				<el-table-column prop="period" label="期间" width="88" align="center" class-name="col-amt" />
				<el-table-column prop="employee_name" label="业务员" min-width="100" show-overflow-tooltip />
				<el-table-column prop="department_name" label="部门" min-width="100" show-overflow-tooltip />
				<el-table-column prop="source_no" label="来源单号" width="150" show-overflow-tooltip>
					<template #default="{ row }">{{ row.source_no || '—' }}</template>
				</el-table-column>
				<el-table-column prop="sales_amount" label="销售额" width="120" align="right" class-name="col-amt">
					<template #default="{ row }">{{ fmtMoney(row.sales_amount) }}</template>
				</el-table-column>
				<el-table-column prop="received_amount" label="回款额" width="120" align="right" class-name="col-amt">
					<template #default="{ row }">{{ fmtMoney(row.received_amount) }}</template>
				</el-table-column>
				<el-table-column prop="commission_amount" label="提成额" width="110" align="right" class-name="col-amt">
					<template #default="{ row }">{{ fmtMoney(row.commission_amount) }}</template>
				</el-table-column>
				<el-table-column prop="deduction" label="扣减" width="100" align="right" class-name="col-amt">
					<template #default="{ row }"><span :class="negCls(row.deduction)">-{{ fmtMoney(row.deduction) }}</span></template>
				</el-table-column>
				<el-table-column prop="actual_amount" label="实发" width="120" align="right" class-name="col-amt">
					<template #default="{ row }"><b>{{ fmtMoney(row.actual_amount) }}</b></template>
				</el-table-column>
				<el-table-column prop="payroll_no" label="关联工资单" width="150" show-overflow-tooltip>
					<template #default="{ row }">{{ row.payroll_no || '—' }}</template>
				</el-table-column>
				<el-table-column prop="paid_date" label="发放日期" width="110" align="center" class-name="col-amt">
					<template #default="{ row }">{{ row.paid_date || '—' }}</template>
				</el-table-column>
				<template #empty>
					<FinEmpty text="当前筛选条件下暂无提成流水（工资发放后自动快照）" />
				</template>
			</el-table>

			<div class="hr-pager">
				<el-pagination
					:current-page="page"
					:page-size="pageSize"
					:page-sizes="[20, 50, 100]"
					:total="total"
					layout="total, sizes, prev, pager, next, jumper"
					@current-change="onPage"
					@size-change="onSize"
				/>
			</div>
		</div>
	</div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import hrApi from '@/api/hr'
import { saveBlob } from '@/utils/fileDownload'
import FinEmpty from '../../finance/_FinEmpty.vue'
import { fmtMoney, negCls } from '../_fmt.js'

const EMPTY_STAT = { count: 0, sales_amount: 0, base_amount: 0, commission_amount: 0, deduction: 0, actual_amount: 0 }

const employees = ref([])
const periods = ref([])
const rows = ref([])
const stat = ref({ ...EMPTY_STAT })
const recon = ref(null)
const total = ref(0)
const page = ref(1)
const pageSize = ref(20)
const loading = ref(false)
const exporting = ref(false)

const search = reactive({ period: '', employee_id: null, source_no: '', keyword: '' })

const params = () => ({
	period: search.period || undefined,
	employee_id: search.employee_id || undefined,
	source_no: search.source_no || undefined,
	keyword: search.keyword || undefined,
})

const loadOptions = async () => {
	try {
		const res = await hrApi.commissionFlow.options.get()
		if (res?.code === 200) {
			employees.value = res.data?.employees || []
			periods.value = res.data?.periods || []
		}
	} catch { /* 下拉失败不阻断列表 */ }
}

const loadList = async () => {
	loading.value = true
	try {
		const res = await hrApi.commissionFlow.list.get({ ...params(), page: page.value, page_size: pageSize.value })
		if (res?.code === 200) {
			rows.value = res.data?.list || []
			total.value = res.data?.total || 0
			stat.value = { ...EMPTY_STAT, ...(res.data?.stat || {}) }
		}
	} finally { loading.value = false }
}

// 对账接口要求期间必填，未选期间时清空对账区
const loadReconcile = async () => {
	if (!search.period) { recon.value = null; return }
	try {
		const res = await hrApi.commissionFlow.reconcile.get({ period: search.period, employee_id: search.employee_id || undefined })
		if (res?.code === 200) recon.value = res.data
	} catch { recon.value = null }
}

const refresh = () => { page.value = 1; loadList() }
const onPeriodChange = () => { refresh(); loadReconcile() }
const onPage = (p) => { page.value = p; loadList() }
const onSize = (s) => { pageSize.value = s; refresh() }
const resetSearch = () => {
	search.period = ''
	search.employee_id = null
	search.source_no = ''
	search.keyword = ''
	recon.value = null
	refresh()
}

const doExport = async () => {
	if (!rows.value.length) return ElMessage.warning('当前筛选条件下暂无数据')
	exporting.value = true
	try {
		const blob = await hrApi.commissionFlow.export(params())
		saveBlob(blob, `commission_flows_${new Date().toISOString().slice(0, 10).replace(/-/g, '')}.csv`)
	} finally { exporting.value = false }
}

onMounted(() => { loadOptions(); loadList() })
</script>

<style>
@import '../_shared.css';
</style>

<style scoped>
.hr-flow { height: 100%; display: flex; flex-direction: column; }
.hr-filter { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.summary-bar { display: flex; gap: 8px; align-items: center; padding: 8px 20px; background: var(--bg-hover); font-size: var(--font-base); color: var(--text-regular); border-bottom: 1px solid var(--border-lighter); }
.summary-bar .sep { color: var(--border-base); }
.recon-bar { display: flex; gap: 8px; align-items: center; padding: 8px 20px; font-size: var(--font-base); color: var(--text-regular); border-bottom: 1px solid var(--border-lighter); }
.recon-bar .sep { color: var(--border-base); }
.recon-title { color: var(--text-secondary); }
.recon-note { color: var(--text-secondary); }
.hr-toolbar { display: flex; gap: 8px; padding: 12px 20px; }
.hr-content { flex: 1; display: flex; flex-direction: column; overflow: auto; padding: 0 20px; }
.amt { font-variant-numeric: tabular-nums; font-family: var(--font-numeric); }
:deep(.el-table) { color: var(--text-primary); }
</style>
