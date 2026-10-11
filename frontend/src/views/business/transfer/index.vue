<script setup>
import { ref, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import sTable from '@/components/sTable/index.vue'
import api from '@/api/business.js'

const tableRef = ref(null)
const loading = ref(false)
const searchForm = ref({ keyword: '', status: '' })
const warehouses = ref([])

const data = ref([])
const total = ref(0)
const currentPage = ref(1)
const pageSize = ref(20)

const columns = [
  { field: 'order_no', title: '调拨单号', width: 180 },
  { field: 'from_warehouse.name', title: '源仓库', width: 120 },
  { field: 'to_warehouse.name', title: '目标仓库', width: 120 },
  { field: 'transfer_date', title: '调拨日期', width: 120 },
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

const loadData = async (params = {}) => {
  loading.value = true
  try {
    // 后端 TransferController::index 用 per_page 分页
    const query = { ...searchForm.value, page: currentPage.value, per_page: pageSize.value, ...params }
    const res = await api.transfer.list.get(query)
    // 后端 TransferController::index 返回 { data: paginator, warehouses }，paginator 自带 data/total
    const pager = res.data?.data ?? res.data ?? {}
    data.value = pager.data || pager.list || []
    total.value = pager.total || 0
    // 仓库列表需单独获取
    if (!warehouses.value.length) {
      // 仓库列表接口走标准 paginated()，参数名为 page_size
      const w = await api.warehouse.list.get({ type: 'normal', page_size: 9999 })
      warehouses.value = w.data?.list || []
    }
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
  searchForm.value = { keyword: '', status: '' }
  currentPage.value = 1
  loadData()
}

const handleExecute = async (row) => {
  try {
    await api.transfer.execute.post(row.id)
    ElMessage.success('已执行')
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
      tableName="transfer"
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
      emptyText="暂无调拨单"
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
