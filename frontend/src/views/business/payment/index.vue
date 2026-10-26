<template>
  <sPageSplit side-title="付款管理">
    <div class="biz-list">
      <el-card shadow="never" class="filter-card">
        <el-form :inline="true" :model="filters" size="small">
          <el-form-item label="付款单号">
            <el-input v-model="filters.keyword" placeholder="单据号" clearable style="width:160px" @keyup.enter="fetchData" @clear="fetchData" />
          </el-form-item>
          <el-form-item label="状态">
            <el-select v-model="filters.status" placeholder="全部" clearable style="width:110px">
              <el-option label="草稿" :value="0" />
              <el-option label="已审核" :value="1" />
            </el-select>
          </el-form-item>
          <el-form-item label="日期">
            <el-date-picker v-model="filters.start_date" type="date" placeholder="开始" value-format="YYYY-MM-DD" style="width:130px" />
            <span style="margin:0 4px">至</span>
            <el-date-picker v-model="filters.end_date" type="date" placeholder="结束" value-format="YYYY-MM-DD" style="width:130px" />
          </el-form-item>
          <el-form-item>
            <el-button type="primary" @click="fetchData">查询</el-button>
            <el-button @click="handleReset">重置</el-button>
          </el-form-item>
        </el-form>
      </el-card>

      <el-card shadow="never">
        <template #header>
          <div class="card-header">
            <span>付款单列表</span>
            <span class="summary">累计付款：<b class="text-green">¥{{ totalAmount }}</b></span>
          </div>
        </template>
        <sTable ref="tableRef" tableName="finance_payment" :columns="columns" :data="data" :loading="loading" height="100%" stripe @page-change="handlePageChange" @size-change="handleSizeChange">
          <template #status="{ row }">
            <el-tag :type="row.status === 1 ? 'success' : 'info'" size="small">{{ row.status === 1 ? '已审核' : '草稿' }}</el-tag>
          </template>
          <template #supplier_name="{ row }">{{ row.supplier?.name || '—' }}</template>
          <template #amount="{ row }">
            <span style="font-variant-numeric: tabular-nums; text-align:right; display:inline-block; min-width:90px">¥{{ Number(row.amount).toFixed(2) }}</span>
          </template>
        </sTable>
      </el-card>
    </div>
  </sPageSplit>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import businessApi from '@/api/business'
import sTable from '@/components/sTable/index.vue'
import sPageSplit from '@/components/sPageSplit/index.vue'

const data = ref([])
const loading = ref(false)
const filters = reactive({ keyword: '', status: '', start_date: '', end_date: '' })
const pagination = reactive({ page: 1, page_size: 20, total: 0 })

const columns = [
  { prop: 'pay_no', title: '付款单号', width: 160 },
  { prop: 'supplier_name', title: '供应商', minWidth: 180, slots: { default: 'supplier_name' } },
  { prop: 'amount', title: '付款金额', width: 130, align: 'right', slots: { default: 'amount' } },
  { prop: 'pay_date', title: '付款日期', width: 110 },
  { prop: 'payment_method', title: '支付方式', width: 100 },
  { prop: 'status', title: '状态', width: 90, slots: { default: 'status' } },
]

const totalAmount = computed(() => data.value.reduce((s, r) => s + Number(r.amount || 0), 0).toFixed(2))

async function fetchData() {
  loading.value = true
  try {
    const res = await businessApi.payment.list.get({ ...filters, ...pagination })
    if (res.code === 200) {
      data.value = res.data?.list || []
      pagination.total = res.data?.total || 0
    }
  } catch { ElMessage.error('加载失败') }
  finally { loading.value = false }
}
function handleReset() {
  filters.keyword = ''; filters.status = ''; filters.start_date = ''; filters.end_date = ''
  pagination.page = 1
  fetchData()
}
function handlePageChange(p) { pagination.page = p; fetchData() }
function handleSizeChange(s) { pagination.page_size = s; pagination.page = 1; fetchData() }

onMounted(fetchData)
</script>

<style scoped>
.biz-list { display: flex; flex-direction: column; gap: 12px; }
.filter-card { --el-card-padding: 12px; }
.card-header { display: flex; align-items: center; justify-content: space-between; }
.summary { font-size: 13px; color: #606266; }
.text-green { color: #67c23a; font-weight: 600; font-variant-numeric: tabular-nums; }
</style>