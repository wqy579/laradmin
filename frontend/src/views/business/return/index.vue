<script setup>
import { ref, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import sTable from '@/components/sTable/index.vue'
import api from '@/api/business.js'

const tableRef = ref(null)
const loading = ref(false)
const searchForm = ref({ keyword: '', status: '', supplier_id: '' })
const suppliers = ref([])
const warehouses = ref([])

const data = ref([])
const total = ref(0)
const currentPage = ref(1)
const pageSize = ref(20)

const columns = [
  { field: 'order_no', title: '退货单号', width: 180 },
  { field: 'supplier.name', title: '供应商', width: 120 },
  { field: 'warehouse.name', title: '仓库', width: 120 },
  { field: 'return_date', title: '退货日期', width: 120 },
  { field: 'total_qty', title: '总数量', width: 100 },
  { field: 'total_amount', title: '总金额', width: 120 },
  { field: 'status', title: '状态', width: 100 },
  { field: 'op', title: '操作', width: 200, fixed: 'right' }
]

const statusMap = {
  draft: { label: '草稿', type: 'info' },
  approved: { label: '已审批', type: 'success' },
  completed: { label: '已完成', type: 'primary' }
}

const loadData = async () => {
  loading.value = true
  try {
    const res = await api.returnOrder.list.get({
      ...searchForm.value,
      page: currentPage.value,
      per_page: pageSize.value,
    })
    // 后端 ReturnController::index 返回裸 Laravel paginator：{ data: [items], total, current_page, ... }
    // （无 code/message 信封，顶层即 paginator；customers/warehouses 也在顶层）
    const pager = res.data || {}
    data.value = pager.data || pager.list || []
    total.value = pager.total || 0
    if (!suppliers.value.length) suppliers.value = res.customers || []
    if (!warehouses.value.length) warehouses.value = res.warehouses || []
  } catch (e) {
    ElMessage.error('加载失败')
  } finally {
    loading.value = false
  }
}

const handleSearch = () => {
  currentPage.value = 1
  loadData()
}

const handleReset = () => {
  searchForm.value = { keyword: '', status: '', supplier_id: '' }
  currentPage.value = 1
  loadData()
}

const handleProcess = async (row) => {
  try {
    await api.returnOrder.process.post(row.id)
    ElMessage.success('已处理')
    loadData()
  } catch (e) {
    ElMessage.error('操作失败')
  }
}

const onPageChange = (p) => { currentPage.value = p; loadData() }
const onPageSizeChange = (s) => { pageSize.value = s; currentPage.value = 1; loadData() }

onMounted(() => {
  loadData()
})
</script>

<template>
  <div class="biz-page">
    <sTable
      ref="tableRef"
      tableName="sales-return-list"
      :data="data"
      :columns="columns"
      :loading="loading"
      :total="total"
      :currentPage="currentPage"
      :pageSize="pageSize"
      :pageSizes="[10, 20, 50, 100]"
      height="100%"
      stripe
      show-pagination
      emptyText="暂无退货单"
      @pageChange="onPageChange"
      @pageSizeChange="onPageSizeChange"
    >
      <template #status="{ row }">
        <el-tag :type="statusMap[row.status]?.type || 'info'">
          {{ statusMap[row.status]?.label || row.status }}
        </el-tag>
      </template>
      <template #op="{ row }">
        <el-button
          v-if="row.status === 'draft'"
          type="primary"
          link
          @click="$router.push(`/business/return/${row.id}`)"
        >编辑</el-button>
        <el-button
          v-if="row.status === 'approved'"
          type="success"
          link
          @click="handleProcess(row)"
        >处理</el-button>
      </template>
    </sTable>
  </div>
</template>
