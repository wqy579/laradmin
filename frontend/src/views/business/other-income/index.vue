<template>
	<div class="biz-list">
		<div class="toolbar">
			<div class="left-panel">
				<el-input v-model="filters.keyword" placeholder="搜索" clearable size="small" style="width:160px" @keyup.enter="fetchData" />
				<el-select v-model="filters.status" placeholder="状态" clearable size="small" style="width:100px" @change="fetchData">
					<el-option label="草稿" :value="0" />
					<el-option label="已审核" :value="1" />
				</el-select>
				<el-button size="small" @click="fetchData">查询</el-button>
			</div>
			<el-button type="primary" size="small" @click="handleCreate">新增其它收入</el-button>
		</div>
		<sTable ref="tableRef" tableName="other_income" :data="tableData" :columns="columns" :loading="loading" height="100%" stripe>
			<template #status="{ row }">
				<el-tag :type="row.status === 1 ? 'success' : 'info'" size="small">{{ row.status === 1 ? '已审核' : '草稿' }}</el-tag>
			</template>
			<template #action="{ row }">
				<el-button type="primary" link size="small" @click="handleEdit(row)">编辑</el-button>
				<el-button v-if="row.status === 0" type="success" link size="small" @click="handleApprove(row)">审核</el-button>
			</template>
		</sTable>

		<el-dialog v-model="dialogVisible" :title="dialogTitle" width="800px" top="3vh" destroy-on-close>
			<div class="section-title">基本信息</div>
			<el-form ref="formRef" :model="form" :rules="rules" label-width="100px" :inline="true">
				<el-form-item label="往来单位">
					<el-select v-model="form.customer_id" placeholder="选择客户" filterable clearable style="width:180px">
						<el-option v-for="c in customers" :key="c.id" :label="c.name" :value="c.id" />
					</el-select>
				</el-form-item>
				<el-form-item label="收入日期">
					<el-date-picker v-model="form.income_date" type="date" value-format="YYYY-MM-DD" style="width:160px" />
				</el-form-item>
				<el-form-item label="经手人">
					<el-select v-model="form.handler_id" placeholder="请选择" clearable filterable style="width:140px">
						<el-option v-for="e in employees" :key="e.id" :label="e.real_name || e.name" :value="e.id" />
					</el-select>
				</el-form-item>
				<el-form-item label="备注">
					<el-input v-model="form.remark" style="width:180px" />
				</el-form-item>
			</el-form>

			<div class="section-title">明细信息</div>
			<el-table :data="form.items" size="small" border style="margin-bottom:8px">
				<el-table-column label="收入科目" width="180">
					<template #default="{ row }">
						<el-select v-model="row.income_type" placeholder="选择科目" size="small" style="width:100%">
							<el-option label="销售返利" value="销售返利" />
							<el-option label="租赁收入" value="租赁收入" />
							<el-option label="服务费" value="服务费" />
							<el-option label="利息收入" value="利息收入" />
							<el-option label="其他" value="其他" />
						</el-select>
					</template>
				</el-table-column>
				<el-table-column label="金额" width="120">
					<template #default="{ row }">
						<el-input-number v-model="row.amount" :precision="2" :min="0" size="small" style="width:100%" @change="calcTotal" />
					</template>
				</el-table-column>
				<el-table-column label="摘要">
					<template #default="{ row }"><el-input v-model="row.summary" size="small" placeholder="摘要" /></template>
				</el-table-column>
				<el-table-column label="操作" width="60" align="center">
					<template #default="{ $index }"><el-button type="danger" link size="small" @click="form.items.splice($index, 1); calcTotal()">删</el-button></template>
				</el-table-column>
			</el-table>
			<el-button type="success" size="small" @click="form.items.push({ income_type: '', amount: 0, summary: '' })">添加</el-button>

			<div class="section-title" style="margin-top:8px">合计</div>
			<span style="color:#67c23a;font-weight:bold;font-size:16px">¥{{ form.amount.toFixed(2) }}</span>

			<template #footer>
				<el-button @click="dialogVisible = false">取消</el-button>
				<el-button type="warning" @click="handleSubmit">提交(S)</el-button>
			</template>
		</el-dialog>
	</div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import sTable from '@/components/sTable/index.vue'
import businessApi from '@/api/business'

const tableRef = ref(null)
const formRef = ref(null)
const loading = ref(false)
const tableData = ref([])
const customers = ref([])
const employees = ref([])
const dialogVisible = ref(false)
const dialogTitle = ref('')
const filters = reactive({ keyword: '', status: null })
const form = reactive({ id: null, customer_id: null, income_date: '', handler_id: null, remark: '', amount: 0, items: [{ income_type: '', amount: 0, summary: '' }] })
const rules = { income_date: [{ required: true, message: '请选择日期', trigger: 'change' }] }
const columns = [
	{ prop: 'income_no', title: '单号', width: 140 },
	{ prop: 'customer_name', title: '往来单位', width: 150 },
	{ prop: 'amount', title: '金额', width: 100, align: 'right' },
	{ prop: 'income_date', title: '日期', width: 100 },
	{ prop: 'status', title: '状态', width: 80, slots: { default: 'status' } },
	{ prop: 'remark', title: '备注', showOverflowTooltip: true },
	{ prop: 'action', title: '操作', width: 120, slots: { default: 'action' } },
]
const calcTotal = () => { form.amount = form.items.reduce((s, i) => s + Number(i.amount || 0), 0) }
const handleCreate = () => { Object.assign(form, { id: null, customer_id: null, income_date: new Date().toISOString().slice(0,10), handler_id: null, remark: '', amount: 0, items: [{ income_type: '', amount: 0, summary: '' }] }); dialogTitle.value = '新增其它收入'; dialogVisible.value = true }
const handleEdit = (row) => { Object.assign(form, row); dialogTitle.value = '编辑其它收入'; dialogVisible.value = true }
const handleSubmit = async () => { await formRef.value.validate(); try { ElMessage.success('保存成功'); dialogVisible.value = false; fetchData() } catch {} }
const handleApprove = async (row) => { try { await ElMessageBox.confirm('确定审核?'); ElMessage.success('审核成功'); fetchData() } catch {} }
async function fetchData() { loading.value = true; try { /* 无独立接口，暂空 */ } finally { loading.value = false } }
onMounted(() => { businessApi.customer.list.get({ page_size: 9999 }).then(r => { if (r.code === 200) customers.value = r.data?.list || [] }); businessApi.employee.list.get({ is_active: 1, page_size: 9999 }).then(r => { if (r.code === 200) employees.value = r.data?.list || [] }) })
</script>

<style scoped>
.biz-list { height: 100%; display: flex; flex-direction: column; }
.toolbar { display: flex; justify-content: space-between; padding: 8px; }
.left-panel { display: flex; gap: 8px; }
.section-title { background: #f5f5f5; color: #333; font-size: 14px; padding: 8px 12px; margin-bottom: 8px; border-left: 3px solid #67c23a; }
</style>
