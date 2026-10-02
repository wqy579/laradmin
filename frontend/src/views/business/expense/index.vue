<template>
  <sPageSplit side-title="费用管理">
    <el-card>
      <el-form :inline="true" :model="filters" class="demo-form-inline">
        <el-form-item label="费用类型">
          <el-select v-model="filters.expense_type" placeholder="请选择" clearable>
            <el-option label="办公用品" value="办公用品" />
            <el-option label="差旅费" value="差旅费" />
            <el-option label="交通费" value="交通费" />
            <el-option label="招待费" value="招待费" />
            <el-option label="水电费" value="水电费" />
            <el-option label="租金" value="租金" />
            <el-option label="工资" value="工资" />
            <el-option label="其他" value="其他" />
          </el-select>
        </el-form-item>
        <el-form-item label="状态">
          <el-select v-model="filters.status" placeholder="请选择" clearable>
            <el-option label="草稿" :value="0" />
            <el-option label="已审核" :value="1" />
          </el-select>
        </el-form-item>
        <el-form-item label="日期">
          <el-date-picker v-model="filters.start_date" type="date" placeholder="开始日期" value-format="YYYY-MM-DD" />
        </el-form-item>
        <el-form-item>
          <el-date-picker v-model="filters.end_date" type="date" placeholder="结束日期" value-format="YYYY-MM-DD" />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="handleSearch">查询</el-button>
          <el-button @click="handleReset">重置</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <el-card>
      <template #header>
        <div class="card-header">
          <span>费用列表</span>
          <el-button type="primary" @click="handleCreate">新增费用</el-button>
        </div>
      </template>

      <sTable
        ref="tableRef"
        :columns="columns"
        :data="tableData"
        :loading="loading"
        :pagination="pagination"
        @page-change="handlePageChange"
        @size-change="handleSizeChange"
      >
        <template #action="{ row }">
          <el-button link type="primary" @click="handleEdit(row)">编辑</el-button>
          <el-button v-if="row.status === 0" link type="success" @click="handleApprove(row)">审核</el-button>
          <el-button v-if="row.status === 0" link type="danger" @click="handleDelete(row)">删除</el-button>
        </template>
      </sTable>
    </el-card>

    <el-dialog v-model="dialogVisible" :title="dialogTitle" width="800px" top="3vh" destroy-on-close>
      <div class="section-title">基本信息</div>
      <el-form ref="formRef" :model="form" :rules="rules" label-width="100px" :inline="true">
        <el-form-item label="费用日期">
          <el-date-picker v-model="form.expense_date" type="date" value-format="YYYY-MM-DD" style="width:160px" />
        </el-form-item>
        <el-form-item label="经手人">
          <el-select v-model="form.handler_id" placeholder="请选择" clearable filterable style="width:160px">
            <el-option v-for="item in employees" :key="item.id" :label="item.real_name || item.name" :value="item.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="备注">
          <el-input v-model="form.remark" style="width:200px" />
        </el-form-item>
      </el-form>

      <div class="section-title">明细信息</div>
      <el-table :data="form.items" size="small" border style="margin-bottom:8px">
        <el-table-column label="费用科目" width="180">
          <template #default="{ row }">
            <el-select v-model="row.expense_type" placeholder="选择科目" size="small" style="width:100%">
              <el-option label="办公用品" value="办公用品" />
              <el-option label="差旅费" value="差旅费" />
              <el-option label="交通费" value="交通费" />
              <el-option label="招待费" value="招待费" />
              <el-option label="水电费" value="水电费" />
              <el-option label="租金" value="租金" />
              <el-option label="工资" value="工资" />
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
          <template #default="{ row }">
            <el-input v-model="row.summary" size="small" placeholder="摘要" />
          </template>
        </el-table-column>
        <el-table-column label="操作" width="60" align="center">
          <template #default="{ $index }">
            <el-button type="danger" link size="small" @click="form.items.splice($index, 1); calcTotal()">删</el-button>
          </template>
        </el-table-column>
      </el-table>
      <el-button type="success" size="small" @click="form.items.push({ expense_type: '', amount: 0, summary: '' })">添加</el-button>

      <div class="section-title" style="margin-top:8px">提交信息</div>
      <el-form :model="form" label-width="100px" :inline="true">
        <el-form-item label="合计金额">
          <span style="color:#f56c6c;font-weight:bold;font-size:16px">¥{{ form.amount.toFixed(2) }}</span>
        </el-form-item>
        <el-checkbox v-model="form.autoApprove">录单时自动审核</el-checkbox>
      </el-form>

      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="warning" @click="handleSubmit">提交(S)</el-button>
      </template>
    </el-dialog>
  </sPageSplit>
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
const employees = ref([])
const dialogVisible = ref(false)
const dialogTitle = ref('')
const isEdit = ref(false)

const filters = reactive({
  expense_type: null,
  status: null,
  start_date: '',
  end_date: ''
})

const pagination = reactive({
  page: 1,
  page_size: 20,
  total: 0
})

const form = reactive({
  id: null,
  expense_type: '',
  amount: 0,
  expense_date: '',
  handler_id: null,
  remark: '',
  items: [{ expense_type: '', amount: 0, summary: '' }],
  autoApprove: false,
})

const rules = {
  expense_date: [{ required: true, message: '请选择费用日期', trigger: 'change' }],
}

const calcTotal = () => {
  form.amount = form.items.reduce((s, i) => s + Number(i.amount || 0), 0)
}

const columns = [
  { field: 'expense_no', title: '费用单号', width: 150 },
  { field: 'expense_type', title: '费用类型', width: 100 },
  { field: 'amount', title: '费用金额', width: 100 },
  { field: 'expense_date', title: '费用日期', width: 120 },
  { field: 'handler.real_name', title: '经手人', width: 100 },
  { field: 'status', title: '状态', width: 80, formatter: (row) => row.status === 1 ? '已审核' : '草稿' },
  { field: 'remark', title: '备注', ellipsis: true },
  { title: '操作', width: 150, slot: 'action' }
]

const fetchData = async () => {
  loading.value = true
  try {
    const res = await businessApi.expense.list.get({
      ...filters,
      page: pagination.page,
      page_size: pagination.page_size
    })
    tableData.value = res.data.list
    pagination.total = res.data.total
  } catch (e) {
    ElMessage.error('加载失败')
  } finally {
    loading.value = false
  }
}

const fetchEmployees = async () => {
  try {
    const res = await businessApi.employee.list.get({ page_size: 1000 })
    employees.value = res.data.list
  } catch (e) {}
}

const handleSearch = () => {
  pagination.page = 1
  fetchData()
}

const handleReset = () => {
  filters.expense_type = null
  filters.status = null
  filters.start_date = ''
  filters.end_date = ''
  handleSearch()
}

const handlePageChange = (page) => {
  pagination.page = page
  fetchData()
}

const handleSizeChange = (size) => {
  pagination.page_size = size
  pagination.page = 1
  fetchData()
}

const handleCreate = () => {
  isEdit.value = false
  dialogTitle.value = '新增费用'
  Object.assign(form, { id: null, expense_type: '', amount: 0, expense_date: new Date().toISOString().slice(0, 10), handler_id: null, remark: '', items: [{ expense_type: '', amount: 0, summary: '' }], autoApprove: false })
  dialogVisible.value = true
}

const handleEdit = (row) => {
  isEdit.value = true
  dialogTitle.value = '编辑费用'
  Object.assign(form, {
    id: row.id,
    expense_type: row.expense_type,
    amount: parseFloat(row.amount),
    expense_date: row.expense_date,
    handler_id: row.handler_id,
    remark: row.remark
  })
  dialogVisible.value = true
}

const handleSubmit = async () => {
  await formRef.value.validate()
  try {
    if (isEdit.value) {
      await businessApi.expense.update.put(form.id, form)
      ElMessage.success('更新成功')
    } else {
      await businessApi.expense.create.post(form)
      ElMessage.success('创建成功')
    }
    dialogVisible.value = false
    fetchData()
  } catch (e) {
    ElMessage.error('操作失败')
  }
}

const handleApprove = async (row) => {
  try {
    await ElMessageBox.confirm('确定审核该费用单?', '提示')
    await businessApi.expense.approve.post(row.id)
    ElMessage.success('审核成功')
    fetchData()
  } catch (e) {
    if (e !== 'cancel') ElMessage.error('操作失败')
  }
}

const handleDelete = async (row) => {
  try {
    await ElMessageBox.confirm('确定删除该费用单?', '提示')
    await businessApi.expense.delete.delete(row.id)
    ElMessage.success('删除成功')
    fetchData()
  } catch (e) {
    if (e !== 'cancel') ElMessage.error('操作失败')
  }
}

onMounted(() => {
  fetchData()
  fetchEmployees()
})
</script>

<style scoped>
.card-header { display: flex; justify-content: space-between; align-items: center; }
.section-title { background: #f5f5f5; color: #333; font-size: 14px; padding: 8px 12px; margin-bottom: 8px; border-left: 3px solid #428bca; }
</style>
</style>
