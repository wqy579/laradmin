<template>
	<div class="hr-payroll hr-page">
		<!-- 查询区 -->
		<div class="hr-filter">
			<span class="label">期间</span>
			<el-date-picker v-model="search.period" type="month" value-format="YYYY-MM" placeholder="全部" clearable size="small" style="width: 130px" @change="refresh" />
			<span class="label">业务员</span>
			<el-select v-model="search.employee_id" placeholder="全部" clearable filterable size="small" style="width: 160px" @change="refresh">
				<el-option v-for="e in employees" :key="e.id" :label="e.name" :value="e.id" />
			</el-select>
			<span class="label">状态</span>
			<el-select v-model="search.status" placeholder="全部" clearable size="small" style="width: 110px" @change="refresh">
				<el-option label="草稿" value="draft" />
				<el-option label="已发放" value="paid" />
			</el-select>
			<span class="label">关键字</span>
			<el-input v-model="search.keyword" placeholder="工资单号 / 业务员" clearable size="small" style="width: 180px" @keyup.enter="refresh" @clear="refresh" />
			<el-button type="primary" size="small" @click="refresh">查询</el-button>
			<el-button size="small" @click="resetSearch">重置</el-button>
		</div>

		<!-- 汇总条 -->
		<div class="summary-bar">
			<span>工资单数：<b>{{ stat.count }}</b></span>
			<span class="sep">|</span>
			<span>基本：<span class="amt">¥{{ fmtMoney(stat.base_amount) }}</span></span>
			<span class="sep">|</span>
			<span>提成：<span class="amt">¥{{ fmtMoney(stat.commission_amount) }}</span></span>
			<span class="sep">|</span>
			<span>绩效：<span class="amt">¥{{ fmtMoney(stat.performance_amount) }}</span></span>
			<span class="sep">|</span>
			<span>扣项：<span class="amt text-red">-¥{{ fmtMoney(stat.deduction_amount) }}</span></span>
			<span class="sep">|</span>
			<span>应发合计：<b class="amt">¥{{ fmtMoney(stat.net_amount) }}</b></span>
		</div>

		<div class="hr-toolbar">
			<el-button v-permission="'hr:payroll:create'" type="primary" @click="openGenerate">按期间生成</el-button>
			<el-button v-permission="'hr:payroll:export'" :loading="exporting" @click="doExport">导出</el-button>
		</div>

		<div class="hr-content">
			<el-table ref="tableRef" v-loading="loading" :data="rows" border stripe height="100%">
				<el-table-column type="expand">
					<template #default="{ row }">
						<div class="formula-panel">
							<div class="formula-title">应发构成明细 —— 基本工资 + 提成 + 绩效 − 扣项</div>
							<el-table :data="formulaRows(row)" border size="small" class="formula-table">
								<el-table-column prop="label" label="构成项" width="150" />
								<el-table-column prop="sign" label="运算" width="70" align="center" />
								<el-table-column label="金额" width="150" align="right" class-name="col-amt">
									<template #default="{ row: r }">¥{{ fmtMoney(r.amount) }}</template>
								</el-table-column>
								<el-table-column prop="source" label="取数口径" min-width="240" />
							</el-table>
							<div class="formula-sum">
								应发净额 = {{ fmtMoney(row.base_amount) }} + {{ fmtMoney(row.commission_amount) }} + {{ fmtMoney(row.performance_amount) }} − {{ fmtMoney(row.deduction_amount) }} =
								<b class="amt">¥{{ fmtMoney(row.net_amount) }}</b>
							</div>
						</div>
					</template>
				</el-table-column>
				<el-table-column prop="payroll_no" label="工资单号" width="140" show-overflow-tooltip />
				<el-table-column prop="period" label="期间" width="90" align="center" class-name="col-amt" />
				<el-table-column prop="employee_name" label="业务员" min-width="110" show-overflow-tooltip />
				<el-table-column prop="department_name" label="部门" min-width="110" show-overflow-tooltip />
				<el-table-column prop="base_amount" label="基本工资" width="120" align="right" class-name="col-amt">
					<template #default="{ row }">{{ fmtMoney(row.base_amount) }}</template>
				</el-table-column>
				<el-table-column prop="commission_amount" label="提成" width="120" align="right" class-name="col-amt">
					<template #default="{ row }">{{ fmtMoney(row.commission_amount) }}</template>
				</el-table-column>
				<el-table-column prop="performance_amount" label="绩效" width="110" align="right" class-name="col-amt">
					<template #default="{ row }">{{ fmtMoney(row.performance_amount) }}</template>
				</el-table-column>
				<el-table-column prop="deduction_amount" label="扣项" width="110" align="right" class-name="col-amt">
					<template #default="{ row }"><span :class="negCls(row.deduction_amount)">-{{ fmtMoney(row.deduction_amount) }}</span></template>
				</el-table-column>
				<!-- 应发：灰显公式列，点击展开核对各构成明细 -->
				<el-table-column prop="net_amount" label="应发（公式）" width="150" align="right">
					<template #default="{ row }">
						<span class="formula-cell" title="点击展开应发构成明细" @click="toggleExpand(row)">¥{{ fmtMoney(row.net_amount) }}</span>
					</template>
				</el-table-column>
				<el-table-column label="状态" width="88" align="center">
					<template #default="{ row }">
						<el-tag :type="row.status === 'paid' ? 'success' : 'info'" size="small" effect="light">{{ row.status_label }}</el-tag>
					</template>
				</el-table-column>
				<el-table-column prop="pay_no" label="付款单号" width="140" show-overflow-tooltip>
					<template #default="{ row }">{{ row.pay_no || '—' }}</template>
				</el-table-column>
				<el-table-column label="操作" width="200" align="center" fixed="right">
					<template #default="{ row }">
						<el-button type="primary" link size="small" @click="openSlip(row)">工资条</el-button>
						<el-button v-permission="'hr:payroll:edit'" type="primary" link size="small" :disabled="row.status === 'paid'" @click="openEdit(row)">编辑</el-button>
						<el-button v-permission="'hr:payroll:pay'" type="success" link size="small" :disabled="row.status === 'paid'" @click="openPay(row)">发放</el-button>
						<el-button v-permission="'hr:payroll:edit'" type="danger" link size="small" :disabled="row.status === 'paid'" @click="doDelete(row)">删除</el-button>
					</template>
				</el-table-column>
				<template #empty>
					<FinEmpty text="当前筛选条件下暂无工资单" />
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

		<!-- 按期间生成 -->
		<el-dialog v-model="genVisible" class="hr-dialog" title="按期间生成工资单" width="460px" :close-on-click-modal="false">
			<el-form ref="genFormRef" :model="genForm" :rules="genRules" label-width="84px">
				<el-form-item label="核算期间" prop="period">
					<el-date-picker v-model="genForm.period" type="month" value-format="YYYY-MM" placeholder="如 2026-03" style="width: 100%" />
				</el-form-item>
				<el-form-item label="业务员">
					<el-select v-model="genForm.employee_ids" multiple filterable clearable placeholder="留空 = 该期间全部有提成的业务员" style="width: 100%">
						<el-option v-for="e in employees" :key="e.id" :label="e.name" :value="e.id" />
					</el-select>
				</el-form-item>
			</el-form>
			<div class="gen-tip">提成取该期间已审核 / 已发放提成单的实发额，重复生成自动跳过已有单据。</div>
			<template #footer>
				<el-button @click="genVisible = false">取消</el-button>
				<el-button type="primary" :loading="saving" @click="onGenerate">生成</el-button>
			</template>
		</el-dialog>

		<!-- 编辑草稿（提成为 perf 快照，不可手改） -->
		<el-dialog v-model="formVisible" class="hr-dialog" title="编辑工资单" width="480px" :close-on-click-modal="false">
			<el-form ref="formRef" :model="form" :rules="formRules" label-width="92px">
				<el-form-item label="工资单号">
					<span class="amt readonly-val">{{ form.payroll_no }} · {{ form.employee_name }}（{{ form.period }}）</span>
				</el-form-item>
				<el-form-item label="基本工资" prop="base_amount">
					<el-input-number v-model="form.base_amount" :min="0" :precision="2" :step="500" controls-position="right" style="width: 100%" />
				</el-form-item>
				<el-form-item label="提成">
					<span class="amt readonly-val">¥{{ fmtMoney(form.commission_amount) }}（取自提成模块，不可修改）</span>
				</el-form-item>
				<el-form-item label="绩效" prop="performance_amount">
					<el-input-number v-model="form.performance_amount" :min="0" :precision="2" :step="100" controls-position="right" style="width: 100%" />
				</el-form-item>
				<el-form-item label="扣项" prop="deduction_amount">
					<el-input-number v-model="form.deduction_amount" :min="0" :precision="2" :step="100" controls-position="right" style="width: 100%" />
				</el-form-item>
				<el-form-item label="应发净额">
					<span class="amt net-preview">¥{{ fmtMoney(previewNet) }}</span>
				</el-form-item>
				<el-form-item label="备注">
					<el-input v-model="form.remark" type="textarea" :rows="2" maxlength="500" show-word-limit placeholder="选填" />
				</el-form-item>
			</el-form>
			<template #footer>
				<el-button @click="formVisible = false">取消</el-button>
				<el-button type="primary" :loading="saving" @click="onSave">保存</el-button>
			</template>
		</el-dialog>

		<!-- 发放 -->
		<el-dialog v-model="payVisible" class="hr-dialog" title="工资发放" width="440px" :close-on-click-modal="false">
			<el-descriptions v-if="payTarget" :column="1" border size="small">
				<el-descriptions-item label="工资单">{{ payTarget.payroll_no }} · {{ payTarget.employee_name }}</el-descriptions-item>
				<el-descriptions-item label="应发净额"><span class="amt">¥{{ fmtMoney(payTarget.net_amount) }}</span></el-descriptions-item>
			</el-descriptions>
			<el-form label-width="84px" style="margin-top: 16px">
				<el-form-item label="发放日期">
					<el-date-picker v-model="payForm.paid_date" type="date" value-format="YYYY-MM-DD" style="width: 100%" />
				</el-form-item>
				<el-form-item label="付款方式">
					<el-select v-model="payForm.payment_method" style="width: 100%">
						<el-option label="银行转账" value="银行转账" />
						<el-option label="现金" value="现金" />
						<el-option label="第三方" value="第三方" />
					</el-select>
				</el-form-item>
			</el-form>
			<div class="gen-tip">发放后自动生成付款单与资金流水，工资单锁定不可再修改或删除。</div>
			<template #footer>
				<el-button @click="payVisible = false">取消</el-button>
				<el-button type="primary" :loading="saving" @click="onPay">确认发放</el-button>
			</template>
		</el-dialog>

		<!-- 工资条：360px 右侧抽屉 -->
		<el-drawer v-model="slipVisible" size="360px" :title="slip ? `工资条 ${slip.payroll.payroll_no}` : '工资条'" destroy-on-close>
			<div v-if="slip" class="slip">
				<div class="slip-sec">
					<div class="v-label">业务员</div>
					<div class="v-value">{{ slip.payroll.employee_name }}<span v-if="slip.payroll.department_name"> · {{ slip.payroll.department_name }}</span></div>
				</div>
				<div class="slip-sec">
					<div class="v-label">核算期间</div>
					<div class="v-value tabular">{{ slip.payroll.period }}</div>
				</div>
				<div class="slip-sec is-formula">
					<div class="v-label">应发构成</div>
					<div class="slip-lines">
						<div v-for="l in slipLines" :key="l.label" class="slip-line">
							<span class="v-label">{{ l.sign }} {{ l.label }}</span>
							<span class="v-value tabular">¥{{ fmtMoney(l.amount) }}</span>
						</div>
						<div class="slip-line is-total">
							<span class="v-label">应发净额</span>
							<span class="v-value tabular">¥{{ fmtMoney(slip.formula.net_amount) }}</span>
						</div>
					</div>
				</div>
				<div class="slip-sec">
					<div class="v-label">当期提成单（{{ slip.commission_orders.length }} 张）</div>
					<el-table v-if="slip.commission_orders.length" :data="slip.commission_orders" border size="small" class="slip-table">
						<el-table-column prop="bill_no" label="提成单号" min-width="120" show-overflow-tooltip />
						<el-table-column prop="status" label="状态" width="72" align="center" />
						<el-table-column label="实发" width="92" align="right" class-name="col-amt">
							<template #default="{ row }">{{ fmtMoney(row.actual_amount) }}</template>
						</el-table-column>
					</el-table>
					<div v-else class="hr-empty">该期间暂无提成单</div>
				</div>
				<div v-if="slip.pay" class="slip-sec">
					<div class="v-label">付款信息</div>
					<div class="slip-lines">
						<div class="slip-line"><span class="v-label">付款单号</span><span class="v-value tabular">{{ slip.pay.pay_no }}</span></div>
						<div class="slip-line"><span class="v-label">付款金额</span><span class="v-value tabular">¥{{ fmtMoney(slip.pay.amount) }}</span></div>
						<div class="slip-line"><span class="v-label">付款日期</span><span class="v-value tabular">{{ slip.pay.pay_date }}</span></div>
						<div class="slip-line"><span class="v-label">付款方式</span><span class="v-value">{{ slip.pay.payment_method || '—' }}</span></div>
					</div>
				</div>
				<div v-if="slip.payroll.remark" class="slip-sec">
					<div class="v-label">备注</div>
					<div class="v-value">{{ slip.payroll.remark }}</div>
				</div>
			</div>
		</el-drawer>
	</div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import hrApi from '@/api/hr'
import { saveBlob } from '@/utils/fileDownload'
import FinEmpty from '../../finance/_FinEmpty.vue'
import { fmtMoney, negCls, toNum, lastPeriod, today } from '../_fmt.js'

const EMPTY_STAT = { count: 0, base_amount: 0, commission_amount: 0, performance_amount: 0, deduction_amount: 0, net_amount: 0 }

const tableRef = ref()
const employees = ref([])
const rows = ref([])
const stat = ref({ ...EMPTY_STAT })
const total = ref(0)
const page = ref(1)
const pageSize = ref(20)
const loading = ref(false)
const exporting = ref(false)
const saving = ref(false)

const search = reactive({ period: lastPeriod(), employee_id: null, status: '', keyword: '' })

const params = () => ({
	period: search.period || undefined,
	employee_id: search.employee_id || undefined,
	status: search.status || undefined,
	keyword: search.keyword || undefined,
})

const loadOptions = async () => {
	try {
		const res = await hrApi.payroll.options.get()
		if (res?.code === 200) employees.value = res.data?.employees || []
	} catch { /* 下拉失败不阻断列表 */ }
}

const loadList = async () => {
	loading.value = true
	try {
		const res = await hrApi.payroll.list.get({ ...params(), page: page.value, page_size: pageSize.value })
		if (res?.code === 200) {
			rows.value = res.data?.list || []
			total.value = res.data?.total || 0
			stat.value = { ...EMPTY_STAT, ...(res.data?.stat || {}) }
		}
	} finally { loading.value = false }
}

const refresh = () => { page.value = 1; loadList() }
const onPage = (p) => { page.value = p; loadList() }
const onSize = (s) => { pageSize.value = s; refresh() }
const resetSearch = () => {
	search.period = ''
	search.employee_id = null
	search.status = ''
	search.keyword = ''
	refresh()
}

// 展开明细与应发公式同一口径：基本 + 提成 + 绩效 − 扣项
const formulaRows = (row) => [
	{ label: '基本工资', sign: '+', amount: row.base_amount, source: '员工档案基本工资，可在编辑时调整' },
	{ label: '提成', sign: '+', amount: row.commission_amount, source: '提成模块 audited / paid 提成单实发额求和' },
	{ label: '绩效', sign: '+', amount: row.performance_amount, source: '手工录入考核结果' },
	{ label: '扣项', sign: '−', amount: row.deduction_amount, source: '手工录入请假、代扣等扣减' },
]

const toggleExpand = (row) => tableRef.value?.toggleRowExpansion(row)

// ── 按期间生成 ───────────────────────────────────────────────
const genVisible = ref(false)
const genFormRef = ref()
const genForm = reactive({ period: lastPeriod(), employee_ids: [] })
const genRules = {
	period: [{ required: true, message: '请选择核算期间', trigger: 'change' }],
}

const openGenerate = () => {
	genForm.period = search.period || lastPeriod()
	genForm.employee_ids = search.employee_id ? [search.employee_id] : []
	genVisible.value = true
	genFormRef.value?.clearValidate()
}

const onGenerate = async () => {
	try {
		await genFormRef.value.validate()
	} catch { return }
	saving.value = true
	try {
		const res = await hrApi.payroll.generate({ period: genForm.period, employee_ids: genForm.employee_ids })
		if (res?.code === 200) {
			ElMessage.success(res.message || '已生成')
			genVisible.value = false
			search.period = genForm.period
			refresh()
		}
	} catch { /* 拦截器已提示 */ } finally { saving.value = false }
}

// ── 编辑草稿 ─────────────────────────────────────────────────
const formVisible = ref(false)
const formRef = ref()
const form = reactive({
	id: null, payroll_no: '', employee_name: '', period: '',
	base_amount: 0, commission_amount: 0, performance_amount: 0, deduction_amount: 0, remark: '',
})
const formRules = {
	base_amount: [{ required: true, type: 'number', min: 0, message: '请填写基本工资', trigger: 'change' }],
	performance_amount: [{ required: true, type: 'number', min: 0, message: '请填写绩效', trigger: 'change' }],
	deduction_amount: [{ required: true, type: 'number', min: 0, message: '请填写扣项', trigger: 'change' }],
}

const previewNet = computed(
	() => toNum(form.base_amount) + toNum(form.commission_amount) + toNum(form.performance_amount) - toNum(form.deduction_amount),
)

const openEdit = async (row) => {
	Object.assign(form, {
		id: row.id, payroll_no: row.payroll_no, employee_name: row.employee_name, period: row.period,
		base_amount: toNum(row.base_amount), commission_amount: toNum(row.commission_amount),
		performance_amount: toNum(row.performance_amount), deduction_amount: toNum(row.deduction_amount), remark: '',
	})
	try {
		const res = await hrApi.payroll.detail.get(row.id)
		if (res?.code === 200 && res.data) form.remark = res.data.payroll?.remark || ''
	} catch { /* 详情失败时用列表行数据兜底 */ }
	formVisible.value = true
	formRef.value?.clearValidate()
}

const onSave = async () => {
	try {
		await formRef.value.validate()
	} catch { return }
	saving.value = true
	try {
		const res = await hrApi.payroll.edit.put(form.id, {
			base_amount: toNum(form.base_amount),
			performance_amount: toNum(form.performance_amount),
			deduction_amount: toNum(form.deduction_amount),
			remark: form.remark || null,
		})
		if (res?.code === 200) {
			ElMessage.success(res.message || '已保存')
			formVisible.value = false
			loadList()
		}
	} catch { /* 422 文案（如已发放不可修改）由拦截器弹出 */ } finally { saving.value = false }
}

// ── 发放 ─────────────────────────────────────────────────────
const payVisible = ref(false)
const payTarget = ref(null)
const payForm = reactive({ paid_date: today(), payment_method: '银行转账' })

const openPay = (row) => {
	if (toNum(row.net_amount) < 0) return ElMessage.warning('应发净额为负，不能发放，请先核对扣项')
	payTarget.value = row
	payForm.paid_date = today()
	payForm.payment_method = '银行转账'
	payVisible.value = true
}

const onPay = async () => {
	saving.value = true
	try {
		const res = await hrApi.payroll.pay.post(payTarget.value.id, { ...payForm })
		if (res?.code === 200) {
			ElMessage.success(res.message || '发放成功')
			payVisible.value = false
			loadList()
		}
	} catch { /* 拦截器已提示 */ } finally { saving.value = false }
}

const doDelete = async (row) => {
	try {
		await ElMessageBox.confirm(`确认删除工资单「${row.payroll_no}」？`, '删除确认', { type: 'warning' })
	} catch { return }
	try {
		const res = await hrApi.payroll.delete.delete(row.id)
		if (res?.code === 200) { ElMessage.success(res.message || '已删除'); loadList() }
	} catch { /* 拦截器已提示 */ }
}

// ── 工资条抽屉 ───────────────────────────────────────────────
const slipVisible = ref(false)
const slip = ref(null)

const slipLines = computed(() => {
	const f = slip.value?.formula || {}
	return [
		{ label: '基本工资', sign: '+', amount: f.base_amount },
		{ label: '提成', sign: '+', amount: f.commission_amount },
		{ label: '绩效', sign: '+', amount: f.performance_amount },
		{ label: '扣项', sign: '−', amount: f.deduction_amount },
	]
})

const openSlip = async (row) => {
	slip.value = null
	slipVisible.value = true
	try {
		const res = await hrApi.payroll.detail.get(row.id)
		if (res?.code === 200) slip.value = res.data
	} catch { slipVisible.value = false }
}

const doExport = async () => {
	if (!rows.value.length) return ElMessage.warning('当前筛选条件下暂无数据')
	exporting.value = true
	try {
		const blob = await hrApi.payroll.export(params())
		saveBlob(blob, `payrolls_${new Date().toISOString().slice(0, 10).replace(/-/g, '')}.csv`)
	} finally { exporting.value = false }
}

onMounted(() => { loadOptions(); loadList() })
</script>

<style>
@import '../_shared.css';
</style>

<style scoped>
.hr-payroll { height: 100%; display: flex; flex-direction: column; }
.hr-filter { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.summary-bar { display: flex; gap: 8px; align-items: center; padding: 8px 20px; background: var(--bg-hover); font-size: var(--font-base); color: var(--text-regular); border-bottom: 1px solid var(--border-lighter); }
.summary-bar .sep { color: var(--border-base); }
.hr-toolbar { display: flex; gap: 8px; padding: 12px 20px; }
.hr-content { flex: 1; display: flex; flex-direction: column; overflow: auto; padding: 0 20px; }
.amt { font-variant-numeric: tabular-nums; font-family: var(--font-numeric); }
.readonly-val { color: var(--text-regular); }
.net-preview { color: var(--color-primary); font-weight: 600; }
.gen-tip { font-size: var(--font-aux); color: var(--text-secondary); line-height: 1.6; }

/* 应发公式列：灰显 + 手型，点击展开构成明细 */
.formula-cell { color: var(--text-secondary); cursor: pointer; font-variant-numeric: tabular-nums; font-family: var(--font-numeric); }
.formula-cell:hover { color: var(--color-primary); }
.formula-panel { padding: 8px 20px 12px 48px; }
.formula-title { font-size: var(--font-base); color: var(--text-secondary); margin-bottom: 8px; }
.formula-sum { margin-top: 8px; font-size: var(--font-base); color: var(--text-regular); }
:deep(.el-table) { color: var(--text-primary); }

/* 工资条抽屉：标签 13px #909399，值 13px #303133 */
.slip-sec { padding: 12px 0; border-bottom: 1px solid var(--border-lighter); }
.slip-sec:last-child { border-bottom: none; }
.v-label { font-size: var(--font-base); color: var(--text-secondary); line-height: 20px; }
.v-value { font-size: var(--font-base); color: var(--text-primary); line-height: 20px; }
.tabular { font-variant-numeric: tabular-nums; font-family: var(--font-numeric); }
.slip-lines { margin-top: 4px; }
.slip-line { display: flex; justify-content: space-between; align-items: baseline; gap: 12px; padding: 3px 0; }
.slip-line.is-total { margin-top: 4px; padding-top: 8px; border-top: 1px dashed var(--border-lighter); }
.slip-line.is-total .v-label { color: var(--text-regular); }
.slip-line.is-total .v-value { font-weight: 600; }
.slip-table { margin-top: 6px; }
</style>
