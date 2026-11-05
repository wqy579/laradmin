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
			<span>工龄：<span class="amt">¥{{ fmtMoney(stat.seniority_amount) }}</span></span>
			<span class="sep">|</span>
			<span>提成：<span class="amt">¥{{ fmtMoney(stat.commission_amount) }}</span></span>
			<span class="sep">|</span>
			<span>绩效：<span class="amt">¥{{ fmtMoney(stat.performance_amount) }}</span></span>
			<span class="sep">|</span>
			<span>加班：<span class="amt">¥{{ fmtMoney(stat.overtime_amount) }}</span></span>
			<span class="sep">|</span>
			<span>调整：<span class="amt">¥{{ fmtMoney(stat.adjustment_amount) }}</span></span>
			<span class="sep">|</span>
			<span>报销：<span class="amt">¥{{ fmtMoney(stat.reimbursement_amount) }}</span></span>
			<span class="sep">|</span>
			<span>扣项：<span class="amt text-red">-¥{{ fmtMoney(stat.deduction_amount) }}</span></span>
			<span class="sep">|</span>
			<span>应发合计：<b class="amt">¥{{ fmtMoney(stat.net_amount) }}</b></span>
		</div>

		<el-tabs v-model="activeTab" class="hr-tabs" @tab-change="onTabChange">
			<el-tab-pane label="工资明细" name="list">
				<div class="hr-toolbar">
					<el-button v-permission="'hr:payroll:create'" type="primary" @click="openGenerate">按期间生成</el-button>
					<el-button v-permission="'hr:payroll:import'" @click="openImport">导入</el-button>
					<el-button v-permission="'hr:payroll:export'" :loading="exporting" @click="doExport">导出</el-button>
					<el-button link type="primary" @click="guideVisible = !guideVisible">
						{{ guideVisible ? '收起工资项目说明' : '工资项目使用说明' }}
					</el-button>
				</div>

				<div v-show="guideVisible" class="item-guide">
					<div v-for="g in ITEM_GUIDE" :key="g.name" class="item-guide-row">
						<span class="item-guide-name">{{ g.name }}</span>
						<span class="item-guide-note">{{ g.note }}</span>
					</div>
				</div>

				<div class="hr-content">
					<el-table ref="tableRef" v-loading="loading" :data="rows" border stripe height="100%">
						<el-table-column type="expand">
							<template #default="{ row }">
								<div class="formula-panel">
									<div class="formula-title">应发构成明细 —— 基本 + 工龄 + 提成 + 绩效 + 加班 + 调整 + 报销 − 扣项</div>
									<el-table :data="formulaRows(row)" border size="small" class="formula-table">
										<el-table-column prop="label" label="构成项" width="150" />
										<el-table-column prop="sign" label="运算" width="70" align="center" />
										<el-table-column label="金额" width="150" align="right" class-name="col-amt">
											<template #default="{ row: r }">¥{{ fmtMoney(r.amount) }}</template>
										</el-table-column>
										<el-table-column prop="source" label="取数口径" min-width="240" />
									</el-table>
									<div class="formula-sum">
										应发净额 = <b class="amt">¥{{ fmtMoney(row.net_amount) }}</b>
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
						<el-table-column prop="seniority_amount" label="工龄工资" width="110" align="right" class-name="col-amt">
							<template #default="{ row }">{{ fmtMoney(row.seniority_amount) }}</template>
						</el-table-column>
						<el-table-column prop="commission_amount" label="销售提成" width="120" align="right" class-name="col-amt">
							<template #default="{ row }">{{ fmtMoney(row.commission_amount) }}</template>
						</el-table-column>
						<el-table-column prop="performance_amount" label="绩效工资" width="110" align="right" class-name="col-amt">
							<template #default="{ row }">{{ fmtMoney(row.performance_amount) }}</template>
						</el-table-column>
						<el-table-column prop="overtime_amount" label="加班工资" width="110" align="right" class-name="col-amt">
							<template #default="{ row }">{{ fmtMoney(row.overtime_amount) }}</template>
						</el-table-column>
						<el-table-column prop="adjustment_amount" label="调整工资" width="110" align="right" class-name="col-amt">
							<template #default="{ row }">{{ fmtMoney(row.adjustment_amount) }}</template>
						</el-table-column>
						<el-table-column prop="reimbursement_amount" label="报销金额" width="110" align="right" class-name="col-amt">
							<template #default="{ row }">{{ fmtMoney(row.reimbursement_amount) }}</template>
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
			</el-tab-pane>

			<!-- 工资报表：部门 × 期间汇总，页内 Tab（不另开路由） -->
			<el-tab-pane label="工资报表" name="report">
				<div class="hr-toolbar">
					<el-button v-permission="'hr:payroll:report'" :loading="reporting" @click="loadReport">刷新</el-button>
					<el-button v-permission="'hr:payroll:report'" :loading="reportExporting" @click="doReportExport">导出报表</el-button>
					<el-button @click="printReport">打印</el-button>
				</div>
				<div id="payroll-report-area" class="hr-content">
					<div class="report-head">
						<span class="report-title">工资汇总表</span>
						<span class="report-range">{{ reportRangeText }}</span>
					</div>
					<el-table v-loading="reportLoading" :data="report.list" border show-summary :summary-method="reportSummary" size="small">
						<el-table-column type="index" label="序号" width="60" align="center" />
						<el-table-column prop="period" label="期间" width="90" align="center" class-name="col-amt" />
						<el-table-column prop="department_name" label="部门" min-width="140" show-overflow-tooltip />
						<el-table-column prop="count" label="人数" width="80" align="right" class-name="col-amt" />
						<el-table-column v-for="col in REPORT_COLUMNS" :key="col.field" :prop="col.field" :label="col.label" min-width="118" align="right" class-name="col-amt">
							<template #default="{ row }">{{ fmtMoney(row[col.field]) }}</template>
						</el-table-column>
						<template #empty>
							<FinEmpty text="当前筛选条件下暂无工资数据" />
						</template>
					</el-table>
				</div>
			</el-tab-pane>
		</el-tabs>

		<sImport
			ref="importRef"
			title="导入工资项"
			:api-method="hrApi.payroll.importFile"
			:form-data="importFormData"
			:template-api="hrApi.payroll.template"
			accept=".xlsx,.xls"
			@success="onImportSuccess"
		/>

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
				<el-form-item label="工龄工资">
					<el-input-number v-model="form.seniority_amount" :min="0" :precision="2" :step="100" controls-position="right" style="width: 100%" />
				</el-form-item>
				<el-form-item label="提成">
					<span class="amt readonly-val">¥{{ fmtMoney(form.commission_amount) }}（取自提成模块，不可修改）</span>
				</el-form-item>
				<el-form-item label="绩效">
					<el-input-number v-model="form.performance_amount" :min="0" :precision="2" :step="100" controls-position="right" style="width: 100%" />
				</el-form-item>
				<el-form-item label="加班工资">
					<el-input-number v-model="form.overtime_amount" :min="0" :precision="2" :step="100" controls-position="right" style="width: 100%" />
				</el-form-item>
				<!-- 调整工资是唯一可负的项目（一次性扣减），所以不设 min -->
				<el-form-item label="调整工资">
					<el-input-number v-model="form.adjustment_amount" :precision="2" :step="100" controls-position="right" style="width: 100%" />
				</el-form-item>
				<el-form-item label="报销金额">
					<el-input-number v-model="form.reimbursement_amount" :min="0" :precision="2" :step="100" controls-position="right" style="width: 100%" />
				</el-form-item>
				<el-form-item label="扣项">
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
import sImport from '@/components/sImport/index.vue'
import { fmtMoney, negCls, toNum, lastPeriod, today } from '../_fmt.js'

const EMPTY_STAT = {
	count: 0, base_amount: 0, seniority_amount: 0, commission_amount: 0, performance_amount: 0,
	overtime_amount: 0, adjustment_amount: 0, reimbursement_amount: 0, deduction_amount: 0, net_amount: 0,
}

// 与后端 Payroll::AMOUNT_FIELDS 同序，改一边必须改另一边
const REPORT_COLUMNS = [
	{ field: 'base_amount', label: '基本工资' },
	{ field: 'seniority_amount', label: '工龄工资' },
	{ field: 'commission_amount', label: '销售提成' },
	{ field: 'performance_amount', label: '绩效工资' },
	{ field: 'overtime_amount', label: '加班工资' },
	{ field: 'adjustment_amount', label: '调整工资' },
	{ field: 'reimbursement_amount', label: '报销金额' },
	{ field: 'deduction_amount', label: '扣项' },
	{ field: 'net_amount', label: '应发合计' },
]

const ITEM_GUIDE = [
	{ name: '基本工资', note: '员工档案的标准月薪，生成工资单时自动带入，可按月调整。' },
	{ name: '工龄工资', note: '按入职年限发放的补贴，手工录入或用「导入」批量填写。' },
	{ name: '销售提成', note: '取自提成模块已审核 / 已发放提成单的实发金额，只读快照，不可手改、不在导入模板内。' },
	{ name: '绩效工资', note: '月度考核结果对应的浮动部分，手工录入或导入。' },
	{ name: '加班工资', note: '加班时数折算的工资，手工录入或导入。' },
	{ name: '调整工资', note: '一次性补发或扣减，是唯一可以填负数的项目。' },
	{ name: '报销金额', note: '随工资一并发放的报销款，单列出来便于与报销台账核对。' },
	{ name: '扣项', note: '社保个人部分、缺勤扣款等，只能为正数，计算时做减法。' },
	{ name: '应发合计', note: '基本 + 工龄 + 提成 + 绩效 + 加班 + 调整 + 报销 − 扣项，由系统自动重算。' },
]

const tableRef = ref()
const activeTab = ref('list')
const guideVisible = ref(false)
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

// 展开明细与应发公式同一口径：基本 + 工龄 + 提成 + 绩效 + 加班 + 调整 + 报销 − 扣项
const formulaRows = (row) => [
	{ label: '基本工资', sign: '+', amount: row.base_amount, source: '员工档案基本工资，可在编辑时调整' },
	{ label: '工龄工资', sign: '+', amount: row.seniority_amount, source: '手工录入或导入' },
	{ label: '销售提成', sign: '+', amount: row.commission_amount, source: '提成模块 audited / paid 提成单实发额求和' },
	{ label: '绩效工资', sign: '+', amount: row.performance_amount, source: '手工录入考核结果' },
	{ label: '加班工资', sign: '+', amount: row.overtime_amount, source: '手工录入或导入' },
	{ label: '调整工资', sign: '+', amount: row.adjustment_amount, source: '一次性补发/扣减，可为负数' },
	{ label: '报销金额', sign: '+', amount: row.reimbursement_amount, source: '随工资发放的报销款' },
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
	base_amount: 0, seniority_amount: 0, commission_amount: 0, performance_amount: 0,
	overtime_amount: 0, adjustment_amount: 0, reimbursement_amount: 0, deduction_amount: 0, remark: '',
})
const formRules = {
	base_amount: [{ required: true, type: 'number', min: 0, message: '请填写基本工资', trigger: 'change' }],
}

const previewNet = computed(
	() => toNum(form.base_amount) + toNum(form.seniority_amount) + toNum(form.commission_amount)
		+ toNum(form.performance_amount) + toNum(form.overtime_amount) + toNum(form.adjustment_amount)
		+ toNum(form.reimbursement_amount) - toNum(form.deduction_amount),
)

const openEdit = async (row) => {
	Object.assign(form, {
		id: row.id, payroll_no: row.payroll_no, employee_name: row.employee_name, period: row.period,
		base_amount: toNum(row.base_amount), seniority_amount: toNum(row.seniority_amount),
		commission_amount: toNum(row.commission_amount), performance_amount: toNum(row.performance_amount),
		overtime_amount: toNum(row.overtime_amount), adjustment_amount: toNum(row.adjustment_amount),
		reimbursement_amount: toNum(row.reimbursement_amount), deduction_amount: toNum(row.deduction_amount), remark: '',
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
			seniority_amount: toNum(form.seniority_amount),
			performance_amount: toNum(form.performance_amount),
			overtime_amount: toNum(form.overtime_amount),
			adjustment_amount: toNum(form.adjustment_amount),
			reimbursement_amount: toNum(form.reimbursement_amount),
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
		{ label: '工龄工资', sign: '+', amount: f.seniority_amount },
		{ label: '销售提成', sign: '+', amount: f.commission_amount },
		{ label: '绩效工资', sign: '+', amount: f.performance_amount },
		{ label: '加班工资', sign: '+', amount: f.overtime_amount },
		{ label: '调整工资', sign: '+', amount: f.adjustment_amount },
		{ label: '报销金额', sign: '+', amount: f.reimbursement_amount },
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

// ── 导入（复用 sImport 组件，期间取查询区当前值）──────────────
const importRef = ref()
const importFormData = computed(() => ({ period: search.period || '' }))

const openImport = () => {
	if (!search.period) return ElMessage.warning('请先在查询区选择导入期间')
	importRef.value?.open()
}

const onImportSuccess = (data) => {
	if (data?.unmatched?.length) {
		ElMessage.warning(`以下工号在 ${data.period} 没有工资单：${data.unmatched.slice(0, 5).join('、')}${data.unmatched.length > 5 ? ' 等' : ''}`)
	}
	loadList()
}

// ── 工资报表（部门 × 期间 汇总，页内 Tab）────────────────────
const report = reactive({ list: [], total: { ...EMPTY_STAT } })
const reportLoading = ref(false)
const reporting = ref(false)
const reportExporting = ref(false)

const reportRangeText = computed(() => (search.period ? `期间：${search.period}` : '期间：全部'))

const loadReport = async () => {
	reporting.value = true
	reportLoading.value = true
	try {
		const res = await hrApi.payroll.report(params())
		if (res?.code === 200) {
			report.list = res.data?.list || []
			report.total = { ...EMPTY_STAT, ...(res.data?.total || {}) }
		}
	} finally { reporting.value = false; reportLoading.value = false }
}

const onTabChange = (name) => { if (name === 'report') loadReport() }

const reportSummary = ({ columns }) => {
	return columns.map((col, index) => {
		if (index === 0) return '合计'
		if (col.property === 'count') return report.total.count
		const field = col.property
		if (!field || !(field in report.total)) return ''
		return fmtMoney(report.total[field])
	})
}

const doReportExport = async () => {
	if (!report.list.length) return ElMessage.warning('当前筛选条件下暂无数据')
	reportExporting.value = true
	try {
		const blob = await hrApi.payroll.reportExport(params())
		saveBlob(blob, `payroll_report_${new Date().toISOString().slice(0, 10).replace(/-/g, '')}.csv`)
	} finally { reportExporting.value = false }
}

// 打印：隐藏 iframe 里重排一张纯表格再 print()。
// 不用 window.print() 是因为那会把侧边栏、页签等应用外壳一起打进纸；
// 也不用 window.open()，弹窗拦截会让「打印」点了没反应。
const printReport = () => {
	if (!report.list.length) return ElMessage.warning('当前筛选条件下暂无数据')

	const iframe = document.createElement('iframe')
	iframe.setAttribute('aria-hidden', 'true')
	iframe.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0'
	document.body.appendChild(iframe)

	const doc = iframe.contentDocument
	const el = (tag, text, cls) => {
		const node = doc.createElement(tag)
		// 文本一律走 textContent，部门名里带尖括号也不会打断打印页结构
		if (text !== undefined) node.textContent = text
		if (cls) node.className = cls
		return node
	}

	const style = doc.createElement('style')
	style.textContent = [
		'body{margin:16px;font:13px/1.5 -apple-system,"Microsoft YaHei",sans-serif;color:#303133}',
		'h2{font-size:16px;margin:0 0 4px}',
		'.sub{font-size:12px;color:#909399;margin-bottom:10px}',
		'table{border-collapse:collapse;width:100%}',
		'th,td{border:1px solid #DCDFE6;padding:4px 8px;font-size:12px}',
		'th{background:#F5F7FA;font-weight:600;text-align:center}',
		'td.amt,th.amt{text-align:right;font-variant-numeric:tabular-nums}',
		'tr.total td{font-weight:600;background:#FAFAFA}',
	].join('')
	doc.head.appendChild(style)

	doc.body.appendChild(el('h2', '工资汇总表'))
	doc.body.appendChild(el('div', `${reportRangeText.value} · 共 ${report.total.count} 张工资单`, 'sub'))

	const table = doc.createElement('table')
	const headRow = doc.createElement('tr')
	;['序号', '期间', '部门', '人数', ...REPORT_COLUMNS.map((c) => c.label)].forEach((label, i) => {
		headRow.appendChild(el('th', label, i >= 3 ? 'amt' : ''))
	})
	const thead = doc.createElement('thead')
	thead.appendChild(headRow)
	table.appendChild(thead)

	const tbody = doc.createElement('tbody')
	report.list.forEach((row, index) => {
		const tr = doc.createElement('tr')
		tr.appendChild(el('td', String(index + 1)))
		tr.appendChild(el('td', row.period))
		tr.appendChild(el('td', row.department_name))
		tr.appendChild(el('td', String(row.count), 'amt'))
		REPORT_COLUMNS.forEach((c) => tr.appendChild(el('td', fmtMoney(row[c.field]), 'amt')))
		tbody.appendChild(tr)
	})
	table.appendChild(tbody)
	doc.body.appendChild(table)

	const cleanup = () => iframe.remove()
	iframe.contentWindow.addEventListener('afterprint', cleanup)
	iframe.contentWindow.focus()
	iframe.contentWindow.print()
	// 个别浏览器不触发 afterprint，留个兜底避免 iframe 越积越多
	setTimeout(cleanup, 60000)
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

.hr-tabs { flex: 1; display: flex; flex-direction: column; min-height: 0; padding: 0 20px; }
.hr-tabs :deep(.el-tabs__content) { flex: 1; min-height: 0; }
.hr-tabs :deep(.el-tab-pane) { height: 100%; display: flex; flex-direction: column; }
.hr-tabs .hr-toolbar { padding: 12px 0; }
.hr-tabs .hr-content { padding: 0; }

/* 工资项目使用说明：折叠区，浅底 + 辅助字号 */
.item-guide { margin: 0 0 8px; padding: 10px 14px; background: var(--color-primary-light); border-radius: var(--radius-md); }
.item-guide-row { display: flex; gap: 10px; padding: 2px 0; font-size: var(--font-aux); line-height: 1.6; }
.item-guide-name { flex: none; width: 76px; color: var(--text-regular); }
.item-guide-note { color: var(--text-secondary); }

.report-head { display: flex; align-items: baseline; gap: 12px; padding: 4px 0 10px; }
.report-title { font-size: var(--font-h2); font-weight: 600; color: var(--text-primary); }
.report-range { font-size: var(--font-aux); color: var(--text-secondary); }

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
