<template>
	<div class="ge-page fin-page">
		<!-- 筛选区 -->
		<div class="ge-filter fin-filter">
			<span class="label">费用科目</span>
			<el-select v-model="search.expense_type" clearable placeholder="全部科目" size="small" style="width: 150px" @change="onSearch">
				<el-option v-for="t in EXPENSE_TYPES" :key="t" :label="t" :value="t" />
			</el-select>
			<span class="label">状态</span>
			<el-select v-model="search.status" clearable placeholder="全部状态" size="small" style="width: 120px" @change="onSearch">
				<el-option label="草稿" :value="0" />
				<el-option label="已审核" :value="1" />
			</el-select>
			<span class="label">费用日期</span>
			<el-date-picker
				v-model="search.daterange"
				type="daterange"
				size="small"
				style="width: 240px"
				start-placeholder="开始日期"
				end-placeholder="结束日期"
				value-format="YYYY-MM-DD"
				@change="onSearch"
			/>
			<div class="filter-actions fin-toolbar">
				<el-button type="primary" @click="onSearch">查询</el-button>
				<el-button @click="resetSearch">重置</el-button>
			</div>
		</div>

		<!-- 工具条 -->
		<div class="ge-toolbar fin-toolbar">
			<span class="toolbar-title">一般费用单</span>
			<el-button type="primary" v-permission="'fin:general-expense:edit'" @click="openCreate">+ 新增一般费用</el-button>
		</div>

		<!-- 表格区 -->
		<div class="ge-content">
			<el-table :data="rows" border stripe height="100%" v-loading="loading" :show-summary="true" :summary-method="summaryMethod">
				<el-table-column prop="expense_no" label="单号" width="160" align="left">
					<template #default="{ row }">
						<span class="tabular">{{ row.expense_no || '-' }}</span>
					</template>
				</el-table-column>
				<el-table-column prop="expense_type" label="费用科目" width="130" align="left">
					<template #default="{ row }">{{ row.expense_type || '-' }}</template>
				</el-table-column>
				<el-table-column prop="amount" label="金额" width="140" align="right">
					<template #default="{ row }">
						<span class="tabular">{{ fmtMoney(row.amount) }}</span>
					</template>
				</el-table-column>
				<el-table-column prop="expense_date" label="费用日期" width="120" align="center">
					<template #default="{ row }">
						<span class="tabular">{{ fmtDate(row.expense_date) }}</span>
					</template>
				</el-table-column>
				<el-table-column label="经手人" width="110" align="left">
					<template #default="{ row }">{{ row.handler?.name || '-' }}</template>
				</el-table-column>
				<el-table-column label="部门" width="130" align="left">
					<template #default="{ row }">{{ row.department?.name || '-' }}</template>
				</el-table-column>
				<el-table-column prop="status" label="状态" width="90" align="center">
					<template #default="{ row }">
						<el-tag size="small" :type="row.status === 1 ? 'success' : 'info'">{{ row.status === 1 ? '已审核' : '草稿' }}</el-tag>
					</template>
				</el-table-column>
				<el-table-column prop="remark" label="备注" min-width="160" align="left" show-overflow-tooltip>
					<template #default="{ row }">{{ row.remark || '-' }}</template>
				</el-table-column>
				<el-table-column label="操作" width="150" align="center" fixed="right">
					<template #default="{ row }">
						<el-button v-if="row.status === 0" link type="primary" size="small" v-permission="'fin:general-expense:edit'" @click="openEdit(row)">编辑</el-button>
						<el-button v-if="row.status === 0" link type="success" size="small" v-permission="'fin:general-expense:approve'" @click="handleApprove(row)">审核</el-button>
						<el-button v-if="row.status === 0" link type="danger" size="small" v-permission="'fin:general-expense:edit'" @click="handleDelete(row)">删除</el-button>
						<span v-if="row.status === 1">-</span>
					</template>
				</el-table-column>
				<template #empty>
					<FinEmpty />
				</template>
			</el-table>
		</div>

		<!-- 分页 -->
		<div class="ge-pager fin-pager">
			<el-pagination
				background
				layout="total, prev, pager, next"
				:total="total"
				:page-size="pageSize"
				:current-page="page"
				@current-change="onPageChange"
			/>
		</div>

		<!-- 新增/编辑 Dialog -->
		<el-dialog v-model="dialogVisible" :title="dialogTitle" width="560px" class="fin-dialog" :close-on-click-modal="false" destroy-on-close>
			<el-form ref="formRef" :model="form" :rules="rules" label-width="110px">
				<el-form-item label="费用科目" prop="expense_type">
					<el-select v-model="form.expense_type" placeholder="选择科目" style="width: 100%">
						<el-option v-for="t in EXPENSE_TYPES" :key="t" :label="t" :value="t" />
					</el-select>
				</el-form-item>
				<el-form-item label="金额" prop="amount">
					<el-input-number v-model="form.amount" :min="0" :step="100" :precision="2" :controls-position="'right'" style="width: 100%" />
				</el-form-item>
				<el-form-item label="费用日期" prop="expense_date">
					<el-date-picker v-model="form.expense_date" type="date" value-format="YYYY-MM-DD" style="width: 100%" />
				</el-form-item>
				<el-form-item label="经手人">
					<el-select v-model="form.handler_id" placeholder="选择经手人" filterable clearable style="width: 100%">
						<el-option v-for="e in employees" :key="e.id" :label="e.real_name || e.name" :value="e.id" />
					</el-select>
				</el-form-item>
				<el-form-item label="部门">
					<el-select v-model="form.department_id" placeholder="选择部门" filterable clearable style="width: 100%">
						<el-option v-for="d in departments" :key="d.id" :label="d.name" :value="d.id" />
					</el-select>
				</el-form-item>
				<el-form-item label="备注">
					<el-input v-model="form.remark" type="textarea" :rows="2" placeholder="请输入备注" />
				</el-form-item>
			</el-form>
			<template #footer>
				<el-button size="small" @click="dialogVisible = false">取消</el-button>
				<el-button type="primary" size="small" :loading="saving" @click="handleSubmit">保存</el-button>
			</template>
		</el-dialog>
	</div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import businessApi from '@/api/business'
import authApi from '@/api/auth'
import FinEmpty from '../../finance/_FinEmpty.vue'

const EXPENSE_TYPES = ['办公用品', '差旅费', '交通费', '招待费', '水电费', '租金', '工资', '其他']

const fmtMoney = (v) =>
	Number(v || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const fmtDate = (v) => (v ? String(v).slice(0, 10) : '-')
const today = () => new Date().toISOString().slice(0, 10)

const loading = ref(false)
const saving = ref(false)
const page = ref(1)
const pageSize = 20
const rows = ref([])
const total = ref(0)
const employees = ref([])
const departments = ref([])

const search = reactive({ expense_type: '', status: null, daterange: [] })

const refresh = async () => {
	loading.value = true
	try {
		const params = { page: page.value, page_size: pageSize }
		if (search.expense_type) params.expense_type = search.expense_type
		if (search.status !== null && search.status !== '') params.status = search.status
		if (search.daterange?.length === 2) {
			params.start_date = search.daterange[0]
			params.end_date = search.daterange[1]
		}
		const res = await businessApi.finance.generalExpense.list.get(params)
		const data = res?.data ?? {}
		rows.value = data.list || []
		total.value = data.total || 0
	} catch {
		rows.value = []
		total.value = 0
	} finally {
		loading.value = false
	}
}

const onSearch = () => {
	page.value = 1
	refresh()
}
const onPageChange = (p) => {
	page.value = p
	refresh()
}
const resetSearch = () => {
	search.expense_type = ''
	search.status = null
	search.daterange = []
	onSearch()
}

const summaryMethod = ({ columns }) => {
	const sum = rows.value.reduce((s, r) => s + Number(r.amount || 0), 0)
	return columns.map((col, i) => {
		if (i === 0) return '合计'
		if (col.property === 'amount') return fmtMoney(sum)
		return ''
	})
}

/* ---------- 新增 / 编辑 ---------- */
const formRef = ref(null)
const dialogVisible = ref(false)
const dialogTitle = ref('')
const emptyForm = () => ({ id: null, expense_type: '', amount: 0, expense_date: today(), handler_id: null, department_id: null, remark: '' })
const form = reactive(emptyForm())
const rules = {
	expense_type: [{ required: true, message: '请选择费用科目', trigger: 'change' }],
	amount: [{ required: true, message: '请输入金额', trigger: 'blur' }],
	expense_date: [{ required: true, message: '请选择费用日期', trigger: 'change' }],
}

const openCreate = () => {
	Object.assign(form, emptyForm())
	dialogTitle.value = '新增一般费用'
	dialogVisible.value = true
}

const openEdit = (row) => {
	Object.assign(form, {
		id: row.id,
		expense_type: row.expense_type || '',
		amount: Number(row.amount || 0),
		expense_date: fmtDate(row.expense_date),
		handler_id: row.handler_id || null,
		department_id: row.department_id || null,
		remark: row.remark || '',
	})
	dialogTitle.value = '编辑一般费用'
	dialogVisible.value = true
}

const handleSubmit = async () => {
	await formRef.value.validate()
	if (!(Number(form.amount) > 0)) return ElMessage.error('金额须大于 0')
	saving.value = true
	try {
		const payload = {
			expense_type: form.expense_type,
			amount: Number(form.amount),
			expense_date: form.expense_date,
			handler_id: form.handler_id || undefined,
			department_id: form.department_id || undefined,
			remark: form.remark || undefined,
		}
		if (form.id) {
			await businessApi.finance.generalExpense.update.put(form.id, payload)
			ElMessage.success('保存成功')
		} else {
			const res = await businessApi.finance.generalExpense.add.post(payload)
			ElMessage.success(`保存成功，单号：${res?.data?.expense_no || ''}`)
		}
		dialogVisible.value = false
		refresh()
	} catch (e) {
		if (e?.message) ElMessage.error(e.message)
	} finally {
		saving.value = false
	}
}

const handleApprove = async (row) => {
	try {
		await ElMessageBox.confirm(`确定审核单据 ${row.expense_no}？审核后不可修改。`, '审核确认', { type: 'warning' })
	} catch {
		return
	}
	try {
		await businessApi.finance.generalExpense.approve.post(row.id)
		ElMessage.success('审核成功')
		refresh()
	} catch (e) {
		if (e?.message) ElMessage.error(e.message)
	}
}

const handleDelete = async (row) => {
	try {
		await ElMessageBox.confirm(`确定删除单据 ${row.expense_no}？`, '删除确认', { type: 'warning' })
	} catch {
		return
	}
	try {
		await businessApi.finance.generalExpense.delete.delete(row.id)
		ElMessage.success('已删除')
		refresh()
	} catch (e) {
		if (e?.message) ElMessage.error(e.message)
	}
}

onMounted(() => {
	refresh()
	businessApi.employee.list.get({ is_active: 1, page_size: 9999 }).then((r) => {
		if (r.code === 200) employees.value = r.data?.list || []
	})
	authApi.department.all.get().then((r) => {
		if (r.code === 200) departments.value = r.data?.list || []
	})
})
</script>

<style>
@import '../../finance/_shared.css';
</style>

<style scoped>
.ge-page {
	height: 100%;
	display: flex;
	flex-direction: column;
	overflow: hidden;
}
.ge-filter {
	display: flex;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
}
.filter-actions {
	margin-left: auto;
	display: flex;
	gap: 12px;
}
.ge-toolbar {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 12px 20px;
	background: var(--bg-card);
}
.toolbar-title {
	font-size: var(--font-h3);
	font-weight: 600;
	color: var(--text-primary);
}
.ge-content {
	flex: 1;
	min-height: 0;
	padding: 0 12px;
}
.ge-pager {
	padding: 0 12px;
}
.tabular {
	font-variant-numeric: tabular-nums;
}
:deep(.el-table) {
	font-size: var(--font-base);
	color: var(--text-primary);
}
:deep(.el-table__footer td) {
	background: var(--bg-hover);
	color: var(--text-primary);
	font-weight: 600;
}
</style>
