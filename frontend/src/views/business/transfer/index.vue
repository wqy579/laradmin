<script setup>
import { ref, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import sTable from '@/components/sTable/index.vue'

const tableRef = ref(null)
const loading = ref(false)
const searchForm = ref({ keyword: '', status: '' })
const warehouses = ref([])

const columns = [
  { field: 'order_no', title: '调拨单号', width: 180 },
  { field: 'from_warehouse.name', title: '源仓库', width: 120 },
  { field: 'to_warehouse.name', title: '目标仓库', width: 120 },
  { field: 'transfer_date', title: '调拨日期', width: 120 },
  { field: 'total_qty', title: '总数量', width: 100 },
  { field: 'total_amount', title: '总金额', width: 120 },
  { field: 'status', title: '状态', width: 100 },
  { title: '操作', width: 200, fixed: 'right' }
]

const statusMap = {
  draft: { label: '草稿', type: 'info' },
  approved: { label: '已审批', type: 'success' },
  completed: { label: '已完成', type: 'primary' }
}

const loadData = async (params = {}) => {
  loading.value = true
  try {
    const token = localStorage.getItem('token')
    const res = await fetch('/admin/business/transfer', {
      headers: { 'Authorization': `Bearer ${token}` }
    })
    const data = await res.json()
    if (data.code === 200) {
      tableRef.value?.reload(data.data)
      if (warehouses.value.length === 0) {
        warehouses.value = data.warehouses || []
      }
    }
  } catch (e) {
    ElMessage.error('加载失败')
  } finally {
    loading.value = false
  }
}

const handleSearch = () => {
  loadData(searchForm.value)
}

const handleReset = () => {
  searchForm.value = { keyword: '', status: '' }
  loadData()
}

onMounted(() => {
  loadData()
})
</script>

<template>
  <div class="biz-page">
    <sTable
      ref="tableRef"
      :columns="columns"
      :load-data="loadData"
      :loading="loading"
      :search-form="searchForm"
      @search="handleSearch"
      @reset="handleReset"
    >
      <template #status="{ row }">
        <el-tag :type="statusMap[row.status]?.type || 'info'">
          {{ statusMap[row.status]?.label || row.status }}
        </el-tag>
      </template>
      <template #operation="{ row }">
        <el-button
          v-if="row.status === 'draft'"
          type="primary"
          link
          @click="$router.push(`/business/transfer/${row.id}`)"
        >编辑</el-button>
        <el-button
          v-if="row.status === 'approved'"
          type="success"
          link
          @click="handleExecute(row)"
        >执行</el-button>
      </template>
    </sTable>
  </div>
</template>
