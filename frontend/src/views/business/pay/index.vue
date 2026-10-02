<template>
  <sPageSplit side-title="付款管理">
    <el-card>
      <el-form :inline="true" :model="filters" class="demo-form-inline">
        <el-form-item label="供应商">
          <el-select v-model="filters.supplier_id" placeholder="请选择" clearable>
            <el-option v-for="item in suppliers" :key="item.id" :label="item.name" :value="item.id" />
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
          <span>付款列表</span>
          <el-button type="primary" @click="handleCreate">新增付款</el-button>
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

    <el-dialog v-model="dialogVisible" :title="dialogTitle" width="900px" top="3vh" destroy-on-close>
      <div class="section-title">基本信息</div>
      <el-form ref="formRef" :model="form" :rules="rules" label-width="100px" :inline="true">
        <el-form-item label="供应商" prop="supplier_id">
          <el-select v-model="form.supplier_id" placeholder="请选择供应商" filterable clearable style="width:200px" @change="onSupplierChange">
            <el-option v-for="item in suppliers" :key="item.id" :label="item.name" :value="item.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="付款日期">
          <el-date-picker v-model="form.pay_date" type="date" value-format="YYYY-MM-DD" style="width:160px" />
        </el-form-item>
        <el-form-item label="支付方式">
          <el-select v-model="form.payment_method" style="width:120px">
            <el-option label="现金" value="现金" />
            <el-option label="银行转账" value="银行转账" />
            <el-option label="微信" value="微信" />
            <el-option label="支付宝" value="支付宝" />
          </el-select>
        </el-form-item>
        <el-form-item label="摘要">
          <el-input v-model="form.remark" style="width:200px" />
        </el-form-item>
      </el-form>

      <div class="section-title">付款信息</div>
      <el-form :model="form" label-width="100px" :inline="true">
        <el-form-item label="付款金额" prop="amount">
          <el-input-number v-model="form.amount" :precision="2" :min="0" style="width:160px" />
          <span style="margin-left:4px">元</span>
        </el-form-item>
        <el-form-item label="优惠">
          <el-input-number v-model="form.discount" :precision="2" :min="0" style="width:100px" />
        </el-form-item>
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
const suppliers = ref([])
const dialogVisible = ref(false)
const dialogTitle = ref('')
const isEdit = ref(false)

const filters = reactive({
  supplier_id: null,
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
  amount: 0,
  pay_date: '',
  supplier_id: null,
  payment_method: '现金',
  remark: '',
  discount: 0,
})
const onSupplierChange = () => { /* 供应商变更：可加载未付款单据（暂略） */ }

const rules = {
  amount: [{ required: true, message: '请输入付款金额', trigger: 'blur' }]
}

const columns = [
  { field: 'pay_no', title: '付款单号', width: 150 },
  { field: 'supplier.name', title: '供应商', width: 120 },
  { field: 'amount', title: '付款金额', width: 100 },
  { field: 'pay_date', title: '付款日期', width: 120 },
  { field: 'payment_method', title: '支付方式', width: 100 },
  { field: 'status', title: '状态', width: 80, formatter: (row) => row.status === 1 ? '已审核' : '草稿' },
  { field: 'remark', title: '备注', ellipsis: true },
  { title: '操作', width: 150, slot: 'action' }
]

const fetchData = async () => {
  loading.value = true
  try {
    const res = await businessApi.pay.list.get({
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

const fetchSuppliers = async () => {
  try {
    const res = await businessApi.supplier.list.get({ page_size: 1000 })
    suppliers.value = res.data.list
  } catch (e) {}
}

const handleSearch = () => {
  pagination.page = 1
  fetchData()
}

const handleReset = () => {
  filters.supplier_id = null
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
  dialogTitle.value = '新增付款'
  Object.assign(form, { id: null, amount: 0, pay_date: '', supplier_id: null, payment_method: '现金', remark: '', discount: 0 })
  dialogVisible.value = true
}

const handleEdit = (row) => {
  isEdit.value = true
  dialogTitle.value = '编辑付款'
  Object.assign(form, {
    id: row.id,
    amount: parseFloat(row.amount),
    pay_date: row.pay_date,
    supplier_id: row.supplier_id,
    payment_method: row.payment_method,
    remark: row.remark
  })
  dialogVisible.value = true
}

const handleSubmit = async () => {
  await formRef.value.validate()
  try {
    if (isEdit.value) {
      await businessApi.pay.update.put(form.id, form)
      ElMessage.success('更新成功')
    } else {
      await businessApi.pay.create.post(form)
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
    await ElMessageBox.confirm('确定审核该付款单?', '提示')
    await businessApi.pay.approve.post(row.id)
    ElMessage.success('审核成功')
    fetchData()
  } catch (e) {
    if (e !== 'cancel') ElMessage.error('操作失败')
  }
}

const handleDelete = async (row) => {
  try {
    await ElMessageBox.confirm('确定删除该付款单?', '提示')
    await businessApi.pay.delete.delete(row.id)
    ElMessage.success('删除成功')
    fetchData()
  } catch (e) {
    if (e !== 'cancel') ElMessage.error('操作失败')
  }
}

onMounted(() => {
  fetchData()
  fetchSuppliers()
})
</script>

<style scoped>
.card-header { display: flex; justify-content: space-between; align-items: center; }
.section-title { background: #f5f5f5; color: #333; font-size: 14px; padding: 8px 12px; margin-bottom: 8px; border-left: 3px solid #428bca; }
</style>
