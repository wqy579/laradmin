<template>
	<div class="cash-page">
		<!-- 筛选 -->
		<div class="cash-filter">
			<span class="cash-filter__label">日期起止</span>
			<el-date-picker v-model="dateRange" type="daterange" value-format="YYYY-MM-DD" range-separator="-" start-placeholder="开始日期" end-placeholder="结束日期" size="small" style="width: 240px" @change="refresh" />
			<span class="cash-filter__label">往来单位</span>
			<el-select v-model="search.counterpart" filterable clearable placeholder="全部" size="small" style="width: 200px" @change="refresh">
				<el-option v-for="c in customers" :key="c.id" :label="c.name" :value="c.id" />
			</el-select>
			<span class="cash-filter__label">资金账户</span>
			<el-select v-model="search.account" clearable placeholder="全部" size="small" style="width: 180px" @change="refresh">
				<el-option v-for="a in accounts" :key="a.id" :label="a.name" :value="a.id" />
			</el-select>
			<span class="cash-filter__label">状态</span>
			<el-select v-model="search.status" clearable placeholder="全部" size="small" style="width: 120px" @change="refresh">
				<el-option v-for="(v, k) in statusOpts" :key="k" :label="v" :value="k" />
			</el-select>
			<el-button type="primary" size="small" @click="refresh">查询</el-button>
			<el-button size="small" @click="resetSearch">重置</el-button>
			<div style="flex: 1" />
			<el-button size="small" @click="exportData">导出</el-button>
			<el-button size="small" @click="printData">打印</el-button>
			<el-button type="primary" size="small" @click="openDialog">新增存取款</el-button>
		</div>

		<!-- 列表 -->
		<div class="cash-table">
			<el-table :data="pagedList" border v-loading="loading">
				<el-table-column type="index" label="序号" width="50" align="center" />
				<el-table-column prop="no" label="单据号" width="150" align="center">
					<template #default="{ row }">
						<span class="tabular">{{ row.no }}</span>
					</template>
				</el-table-column>
				<el-table-column prop="date" label="日期" width="110" align="center">
					<template #default="{ row }">
						<span class="tabular">{{ row.date }}</span>
					</template>
				</el-table-column>
				<el-table-column label="类型" width="110" align="center">
					<template #default="{ row }">
						<el-tag :type="typeTag(row.depositType)" size="small" :disable-transitions="true">{{ typeLabel(row.depositType) }}</el-tag>
					</template>
				</el-table-column>
				<el-table-column label="往来单位" min-width="180" show-overflow-tooltip>
					<template #default="{ row }">{{ counterpartName(row.counterpart) }}</template>
				</el-table-column>
				<el-table-column label="资金账户" width="140" show-overflow-tooltip>
					<template #default="{ row }">{{ row.account_name }}</template>
				</el-table-column>
				<el-table-column label="收入" width="120" align="right">
					<template #default="{ row }">
						<span v-if="isIncome(row)" class="cash-income">{{ fmtMoney(row.amount) }}</span>
						<span v-else class="tabular">—</span>
					</template>
				</el-table-column>
				<el-table-column label="支出" width="120" align="right">
					<template #default="{ row }">
						<span v-if="isExpense(row)" class="cash-expense">{{ fmtMoney(row.amount) }}</span>
						<span v-else class="tabular">—</span>
					</template>
				</el-table-column>
				<el-table-column label="余额" width="130" align="right">
					<template #default="{ row }">
						<span :class="['cash-balance', row.balance_after < 0 ? 'cash-balance--neg' : '']">{{ fmtMoney(row.balance_after) }}</span>
					</template>
				</el-table-column>
				<el-table-column label="经手人" width="100" align="center">
					<template #default="{ row }">{{ empName(row.handler) }}</template>
				</el-table-column>
				<el-table-column label="状态" width="90" align="center">
					<template #default="{ row }">
						<el-tag :type="statusTag(row.status)" size="small" :disable-transitions="true">{{ statusLabel(row.status) }}</el-tag>
					</template>
				</el-table-column>
				<el-table-column label="操作" width="130" align="center">
					<template #default="{ row }">
						<el-button link type="primary" size="small" @click="viewDetail(row)">查看</el-button>
						<el-button v-if="isToday(row.date) && row.status !== 'flush'" link type="danger" size="small" @click="removeDoc(row)">删除</el-button>
					</template>
				</el-table-column>
			</el-table>
			<div class="cash-hint">提示：存取款保存即生成现金流水；取款/互转时账户余额不足会提示「账户余额不足」。</div>
		</div>

		<!-- 分页 -->
		<div class="cash-pager">
			<el-pagination background layout="total, prev, pager, next, sizes" :total="total" :page-size="pageSize" :current-page="page" :page-sizes="[10, 20, 50]" @current-change="onPageChange" @size-change="onSizeChange" />
		</div>

		<!-- 存取款弹窗 720px -->
		<el-dialog v-model="dialogVisible" :title="'新增存取款单（' + curNo + '）'" width="720px" :close-on-click-modal="false" destroy-on-close>
			<el-form ref="formRef" class="cash-form" :model="form" :rules="rules" label-width="96px" label-position="right">
				<el-row :gutter="16">
					<el-col :span="12">
						<el-form-item label="存取类型" prop="depositType">
							<el-radio-group v-model="form.depositType" @change="onTypeChange">
								<el-radio-button value="deposit">存款</el-radio-button>
								<el-radio-button value="withdraw">取款</el-radio-button>
								<el-radio-button value="transfer">互转</el-radio-button>
							</el-radio-group>
						</el-form-item>
					</el-col>
					<el-col :span="12">
						<el-form-item label="单据日期" prop="date">
							<el-date-picker v-model="form.date" type="date" value-format="YYYY-MM-DD" placeholder="选择日期" size="small" style="width: 100%" />
						</el-form-item>
					</el-col>
					<el-col :span="12">
						<el-form-item label="往来单位" prop="counterpart">
							<el-select v-model="form.counterpart" filterable placeholder="客户/供应商/业务员" size="small" style="width: 100%">
								<el-option-group v-for="g in counterpartGroups" :key="g.label" :label="g.label">
									<el-option v-for="c in g.list" :key="c.id" :label="c.name" :value="c.id" />
								</el-option-group>
							</el-select>
						</el-form-item>
					</el-col>
					<el-col :span="12">
						<el-form-item label="资金账户" prop="account">
							<el-select v-model="form.account" placeholder="选择账户" size="small" style="width: 100%">
								<el-option v-for="a in accounts" :key="a.id" :label="`${a.name}（余 ${fmtMoney(accountBalance(a.id))}）`" :value="a.id" />
							</el-select>
						</el-form-item>
					</el-col>
					<el-col v-if="form.depositType === 'transfer'" :span="12">
						<el-form-item label="转入账户" prop="to_account">
							<el-select v-model="form.to_account" placeholder="选择转入账户" size="small" style="width: 100%">
								<el-option v-for="a in toAccounts" :key="a.id" :label="a.name" :value="a.id" />
							</el-select>
						</el-form-item>
					</el-col>
					<el-col :span="12">
						<el-form-item :label="amountLabel" prop="amount">
							<el-input-number v-model="form.amount" :min="0.01" :precision="2" :step="100" size="small" style="width: 100%" />
						</el-form-item>
					</el-col>
					<el-col :span="12">
						<el-form-item label="手续费" prop="fee">
							<el-input-number v-model="form.fee" :min="0" :precision="2" :step="5" size="small" style="width: 100%" />
						</el-form-item>
					</el-col>
					<el-col :span="12">
						<el-form-item label="经手人" prop="handler">
							<el-select v-model="form.handler" placeholder="选择员工" size="small" style="width: 100%">
								<el-option v-for="e in employees" :key="e.id" :label="e.name" :value="e.id" />
							</el-select>
						</el-form-item>
					</el-col>
					<el-col :span="12">
						<el-form-item label="部门" prop="department">
							<el-select v-model="form.department" clearable placeholder="选择部门" size="small" style="width: 100%">
								<el-option v-for="d in departments" :key="d.id" :label="d.name" :value="d.id" />
							</el-select>
						</el-form-item>
					</el-col>
					<el-col :span="12">
						<el-form-item label="摘要" prop="remark">
							<el-input v-model="form.remark" placeholder="选填，≤120 字" maxlength="120" style="width: 100%" />
						</el-form-item>
					</el-col>
					<el-col :span="12">
						<el-form-item label="备注" prop="memo">
							<el-input v-model="form.memo" type="textarea" :rows="2" maxlength="200" show-word-limit placeholder="选填" style="width: 100%" />
						</el-form-item>
					</el-col>
				</el-row>
			</el-form>
			<template #footer>
				<el-button size="small" @click="dialogVisible = false">取消</el-button>
				<el-button type="primary" size="small" :loading="saving" @click="save">确定</el-button>
			</template>
		</el-dialog>
	</div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
	ACCOUNTS, CUSTOMERS, SUPPLIERS, SALESMEN, DEPARTMENTS,
	ACC_LABEL, CUS_LABEL, SUP_LABEL, EMP_LABEL, DEPT_LABEL,
	DOC_STATUS, STATUS_LABEL, fmtMoney, fmtDay,
} from '../shared'
import { listAccounts, accountBalance, listDeposits, addDeposit, genDocNo } from '../../../mock/cashier'
import { saveBlob, printTable } from '../../../utils/fileDownload'

const accounts = listAccounts()
const customers = CUSTOMERS
const suppliers = SUPPLIERS
const employees = SALESMEN
const departments = DEPARTMENTS

const statusOpts = STATUS_LABEL
const statusTag = (s) => DOC_STATUS[s]?.tag || 'info'
const statusLabel = (s) => STATUS_LABEL[s] || s

const search = reactive({ counterpart: null, account: null, status: null })
const dateRange = ref([])
const total = ref(0)
const page = ref(1)
const pageSize = ref(20)
const loading = ref(false)
const saving = ref(false)
const dialogVisible = ref(false)
const formRef = ref(null)
const curNo = ref('')

const counterpartGroups = computed(() => [
	{ label: '客户', list: customers },
	{ label: '供应商', list: suppliers },
	{ label: '业务员', list: employees },
])
const toAccounts = computed(() => accounts.filter((a) => a.id !== form.account))
const amountLabel = computed(() => (form.depositType === 'withdraw' ? '取款金额' : form.depositType === 'transfer' ? '互转金额' : '存款金额'))

const form = reactive({
	depositType: 'deposit',
	date: new Date().toISOString().slice(0, 10),
	counterpart: null,
	account: null,
	to_account: null,
	amount: null,
	fee: 0,
	handler: null,
	department: null,
	remark: '',
	memo: '',
})

const rules = {
	date: [{ required: true, message: '请选择单据日期', trigger: 'change' }],
	counterpart: [{ required: true, message: '请选择往来单位', trigger: 'change' }],
	account: [{ required: true, message: '请选择资金账户', trigger: 'change' }],
	to_account: [{ required: true, message: '请选择转入账户', trigger: 'change' }],
	amount: [{ required: true, type: 'number', message: '请输入金额', trigger: 'blur' }],
	handler: [{ required: true, message: '请选择经手人', trigger: 'change' }],
}

/* 列表数据（按筛选过滤 + 按余额列展示当前账户余额） */
const filtered = computed(() => {
	let list = [...listDeposits()]
	if (search.counterpart) list = list.filter((r) => r.counterpart === search.counterpart)
	if (search.account) list = list.filter((r) => r.account === search.account)
	if (search.status) list = list.filter((r) => r.status === search.status)
	if (dateRange.value && dateRange.value.length === 2) {
		const [s, e] = dateRange.value
		list = list.filter((r) => r.date >= s && r.date <= e)
	}
	return list.map((r) => ({ ...r, balance_after: accountBalance(r.account) })).sort((a, b) => (a.no < b.no ? 1 : -1))
})

const pagedList = computed(() => {
	const start = (page.value - 1) * pageSize.value
	return filtered.value.slice(start, start + pageSize.value)
})

function isIncome(row) {
	return row.depositType === 'deposit'
}
function isExpense(row) {
	return row.depositType === 'withdraw' || row.depositType === 'transfer'
}
function typeLabel(t) {
	return { deposit: '存款', withdraw: '取款', transfer: '互转' }[t] || t
}
function typeTag(t) {
	return { deposit: 'success', withdraw: 'warning', transfer: 'info' }[t] || 'info'
}
function counterpartName(id) {
	return CUS_LABEL[id] || SUP_LABEL[id] || EMP_LABEL[id] || String(id)
}
function empName(id) {
	return EMP_LABEL[id] || String(id)
}

function refresh() {
	loading.value = true
	// TODO(api): 替换为 /api/cashier/deposit 列表接口
	setTimeout(() => {
		loading.value = false
		total.value = filtered.value.length
	}, 200)
}

function resetSearch() {
	search.counterpart = null
	search.account = null
	search.status = null
	dateRange.value = []
	page.value = 1
	refresh()
}

function onPageChange(p) {
	page.value = p
}
function onSizeChange(s) {
	pageSize.value = s
	page.value = 1
}

function openDialog() {
	// TODO(api): 单据号后端生成，前缀 CQ
	curNo.value = genDocNo('CQ')
	Object.assign(form, {
		depositType: 'deposit',
		date: new Date().toISOString().slice(0, 10),
		counterpart: null,
		account: null,
		to_account: null,
		amount: null,
		fee: 0,
		handler: null,
		department: null,
		remark: '',
		memo: '',
	})
	dialogVisible.value = true
}

function onTypeChange() {
	if (form.depositType !== 'transfer') form.to_account = null
}

function save() {
	formRef.value.validate((valid) => {
		if (!valid) return
		if (form.depositType === 'transfer' && !form.to_account) {
			ElMessage.warning('请选择转入账户')
			return
		}
		const payload = {
			no: curNo.value,
			date: form.date,
			depositType: form.depositType,
			counterpart: form.counterpart,
			account: form.account,
			to_account: form.to_account,
			to_account_name: form.to_account ? ACC_LABEL[form.to_account] : '',
			amount: form.amount,
			fee: form.fee,
			handler: form.handler,
			department: form.department,
			remark: form.remark,
			memo: form.memo,
		}
		saving.value = true
		// TODO(api): 替换为 POST /api/cashier/deposit 保存接口
		setTimeout(() => {
			const res = addDeposit(payload)
			saving.value = false
			if (!res.ok) {
				ElMessage.error(res.error)
				return
			}
			dialogVisible.value = false
			ElMessage.success(`存取款单 ${curNo.value} 已保存`)
			refresh()
		}, 300)
	})
}

function viewDetail(row) {
	ElMessage.info(`单据 ${row.no} · 类型 ${typeLabel(row.depositType)} · 金额 ${fmtMoney(row.amount)}`)
}

function isToday(date) {
	return date === new Date().toISOString().slice(0, 10)
}

function removeDoc(row) {
	ElMessageBox.confirm(`确定删除单据 ${row.no}？仅限当日未审核单可删。`, '删除确认', { type: 'warning' })
		.then(() => {
			// TODO(api): 替换为 DELETE /api/cashier/deposit/:no 接口
			ElMessage.success(`已删除 ${row.no}`)
			refresh()
		})
		.catch(() => {})
}

function exportData() {
	const header = ['序号', '单据号', '日期', '类型', '往来单位', '资金账户', '收入', '支出', '余额', '经手人', '状态']
	const lines = filtered.value.map((r, i) => [i + 1, r.no, r.date, typeLabel(r.depositType), counterpartName(r.counterpart), r.account_name, isIncome(r) ? r.amount : '', isExpense(r) ? r.amount : '', r.balance_after, empName(r.handler), statusLabel(r.status)])
	const csv = [header, ...lines].map((r) => r.map((c) => `"${c}"`).join(',')).join('\r\n')
	saveBlob(new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8' }), '存取款单.csv')
}

function printData() {
	printTable(
		[
			{ prop: 'no', label: '单据号' },
			{ prop: 'date', label: '日期' },
			{ prop: 'type', label: '类型' },
			{ prop: 'counterpart', label: '往来单位' },
			{ prop: 'account', label: '资金账户' },
			{ prop: 'income', label: '收入', type: 'money' },
			{ prop: 'expense', label: '支出', type: 'money' },
			{ prop: 'balance', label: '余额', type: 'money' },
			{ prop: 'handler', label: '经手人' },
			{ prop: 'status', label: '状态' },
		],
		filtered.value.map((r) => ({
			no: r.no,
			date: r.date,
			type: typeLabel(r.depositType),
			counterpart: counterpartName(r.counterpart),
			account: r.account_name,
			income: isIncome(r) ? r.amount : 0,
			expense: isExpense(r) ? r.amount : 0,
			balance: r.balance_after,
			handler: empName(r.handler),
			status: statusLabel(r.status),
		})),
		'存取款单'
	)
}

onMounted(refresh)
</script>

<style scoped>
/* _shared.css 共享页面样式：后端离线场景下非必供，由 ../_shared.css 引用 */
</style>