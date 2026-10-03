<template>
  <div class="statement-page">
    <!-- 筛选区 -->
    <div class="filter-bar">
      <div class="filter-item">
        <label>客户</label>
        <el-select v-model="filters.customer_id" placeholder="请选择客户" filterable clearable style="width:280px;height:34px" @change="onCustomerChange">
          <el-option v-for="c in customers" :key="c.id" :label="c.name" :value="c.id" />
        </el-select>
      </div>
      <div class="filter-item">
        <label>开始日期</label>
        <el-date-picker v-model="filters.start_date" type="date" placeholder="开始日期" value-format="YYYY-MM-DD" style="width:160px;height:34px" />
      </div>
      <div class="filter-item">
        <label>结束日期</label>
        <el-date-picker v-model="filters.end_date" type="date" placeholder="结束日期" value-format="YYYY-MM-DD" style="width:160px;height:34px" />
      </div>
      <el-button type="primary" style="width:80px;height:34px;margin-left:8px" @click="handleSearch">查询</el-button>
      <el-button style="width:80px;height:34px;margin-left:8px" @click="handleReset">重置</el-button>
      <div style="flex:1" />
      <el-button style="width:100px;height:34px;color:#1890ff;border-color:#1890ff" @click="handlePrint" :disabled="!filters.customer_id">
        <el-icon><Printer /></el-icon>
        打印对账单
      </el-button>
      <el-button type="success" style="width:100px;height:34px;margin-left:8px;background:#52c41a;border-color:#52c41a" @click="handleExport" :disabled="!filters.customer_id">
        <el-icon><Download /></el-icon>
        导出Excel
      </el-button>
    </div>

    <!-- 汇总信息区 -->
    <div class="summary-box" v-if="filters.customer_id">
      <div class="summary-item">
        <div class="summary-label">期初余额</div>
        <div class="summary-value" :class="statement.opening_balance < 0 ? 'text-red' : ''">¥{{ formatAmount(statement.opening_balance) }}</div>
      </div>
      <div class="summary-item">
        <div class="summary-label">本期应收</div>
        <div class="summary-value text-red">¥{{ formatAmount(statement.total_receivable) }}</div>
      </div>
      <div class="summary-item">
        <div class="summary-label">本期已收</div>
        <div class="summary-value text-green">¥{{ formatAmount(statement.total_received) }}</div>
      </div>
      <div class="summary-item">
        <div class="summary-label">期末余额
          <span class="summary-tag">（客户欠款）</span>
        </div>
        <div class="summary-value text-blue">¥{{ formatAmount(statement.closing_balance) }}</div>
      </div>
    </div>

    <!-- 明细表格 -->
    <div class="table-container" v-loading="loading">
      <vxe-table
        ref="tableRef"
        :data="statement.items"
        :column-config="{ drag: true, resizable: true }"
        :row-config="{ keyField: '__key', isHover: true }"
        border
        show-header-border
        class="statement-table"
      >
        <vxe-column title="序号" width="60" align="center">
          <template #default="{ $index }">{{ $index + 1 }}</template>
        </vxe-column>
        <vxe-column title="单据日期" field="date" width="120" align="center">
          <template #default="{ row }">{{ row.date || '-' }}</template>
        </vxe-column>
        <vxe-column title="单据编号" field="doc_no" width="180">
          <template #default="{ row }">
            <a style="color:#1890ff;cursor:pointer;text-decoration:none" @click="handleViewDoc(row)">{{ row.doc_no || '-' }}</a>
          </template>
        </vxe-column>
        <vxe-column title="单据类型" width="100" align="center">
          <template #default="{ row }">
            <span :class="getTypeClass(row.doc_type)">{{ row.doc_type || '-' }}</span>
          </template>
        </vxe-column>
        <vxe-column title="摘要" field="summary" width="200" show-overflow-tooltip />
        <vxe-column title="应收金额" width="120" align="right">
          <template #default="{ row }">
            <span v-if="row.receivable !== null && row.receivable !== undefined" class="text-red">{{ formatAmount(row.receivable) }}</span>
            <span v-else>-</span>
          </template>
        </vxe-column>
        <vxe-column title="已收金额" width="120" align="right">
          <template #default="{ row }">
            <span v-if="row.received !== null && row.received !== undefined" class="text-green">{{ formatAmount(row.received) }}</span>
            <span v-else>-</span>
          </template>
        </vxe-column>
        <vxe-column title="余额" width="120" align="right">
          <template #default="{ row }">
            <span :class="row.balance < 0 ? 'text-red' : ''" style="font-weight:bold">{{ formatAmount(row.balance) }}</span>
          </template>
        </vxe-column>
        <vxe-column title="经办人" width="100" align="center">
          <template #default="{ row }">{{ row.operator || '-' }}</template>
        </vxe-column>
      </vxe-table>

      <!-- 合计行 -->
      <div class="summary-footer" v-if="statement.items.length > 0">
        <div class="summary-footer-item">合计</div>
        <div class="summary-footer-item"></div>
        <div class="summary-footer-item"></div>
        <div class="summary-footer-item"></div>
        <div class="summary-footer-item"></div>
        <div class="summary-footer-item text-red">¥{{ formatAmount(statement.total_receivable) }}</div>
        <div class="summary-footer-item text-green">¥{{ formatAmount(statement.total_received) }}</div>
        <div class="summary-footer-item text-blue" style="font-weight:bold">¥{{ formatAmount(statement.closing_balance) }}</div>
        <div class="summary-footer-item"></div>
      </div>

      <!-- 空数据状态 -->
      <div v-if="!loading && filters.customer_id && statement.items.length === 0" class="empty-state">
        <div class="empty-icon">📁</div>
        <div class="empty-text">暂无对账数据</div>
        <div class="empty-hint">请选择客户和日期范围后点击查询</div>
      </div>
    </div>

    <!-- 分页 -->
    <div class="pagination-bar" v-if="statement.items.length > 0">
      <span style="color:#666;font-size:14px">共 {{ statement.items.length }} 条</span>
    </div>

    <!-- 打印弹窗 -->
    <el-dialog v-model="printVisible" title="客户对账单" width="900px" top="3vh" destroy-on-close>
      <div v-if="statement.customer_name" class="print-content">
        <div class="print-title">客户对账单</div>
        <div class="print-subtitle">{{ statement.customer_name }} | {{ statement.start_date }} 至 {{ statement.end_date }}</div>

        <div class="print-summary">
          <span>期初余额：¥{{ formatAmount(statement.opening_balance) }}</span>
          <span>本期应收：¥{{ formatAmount(statement.total_receivable) }}</span>
          <span>本期已收：¥{{ formatAmount(statement.total_received) }}</span>
          <span>期末余额：¥{{ formatAmount(statement.closing_balance) }}</span>
        </div>

        <table class="print-table">
          <thead>
            <tr>
              <th>序号</th>
              <th>单据日期</th>
              <th>单据编号</th>
              <th>单据类型</th>
              <th>摘要</th>
              <th>应收金额</th>
              <th>已收金额</th>
              <th>余额</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(item, idx) in statement.items" :key="idx">
              <td>{{ idx + 1 }}</td>
              <td>{{ item.date }}</td>
              <td>{{ item.doc_no }}</td>
              <td>{{ item.doc_type }}</td>
              <td>{{ item.summary }}</td>
              <td :class="item.receivable !== null ? 'text-red' : ''">{{ item.receivable !== null ? formatAmount(item.receivable) : '-' }}</td>
              <td :class="item.received !== null ? 'text-green' : ''">{{ item.received !== null ? formatAmount(item.received) : '-' }}</td>
              <td style="font-weight:bold">{{ formatAmount(item.balance) }}</td>
            </tr>
            <tr class="print-total">
              <td colspan="5">合计</td>
              <td class="text-red">¥{{ formatAmount(statement.total_receivable) }}</td>
              <td class="text-green">¥{{ formatAmount(statement.total_received) }}</td>
              <td style="font-weight:bold">¥{{ formatAmount(statement.closing_balance) }}</td>
            </tr>
          </tbody>
        </table>

        <div class="print-signature">
          <span>制单人：__________</span>
          <span>审核人：__________</span>
          <span>客户确认：__________</span>
        </div>
      </div>
      <template #footer>
        <el-button style="width:80px;height:36px;margin-right:12px" @click="printVisible = false">取消</el-button>
        <el-button type="primary" style="width:80px;height:36px" @click="doPrint">打印</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import { Printer, Download } from '@element-plus/icons-vue'
import businessApi from '@/api/business'

// 筛选条件
const filters = reactive({
  customer_id: null,
  start_date: new Date().toISOString().slice(0, 10),
  end_date: new Date().toISOString().slice(0, 10),
})

// 客户列表
const customers = ref([])

// 对账数据
const statement = reactive({
  customer_name: '',
  start_date: '',
  end_date: '',
  opening_balance: 0,
  total_receivable: 0,
  total_received: 0,
  closing_balance: 0,
  items: [],
})

const loading = ref(false)
const printVisible = ref(false)
const tableRef = ref(null)

// 加载客户列表
const fetchCustomers = async () => {
  try {
    const res = await businessApi.customer.list.get({ page_size: 1000 })
    customers.value = res.data?.list || []
  } catch {}
}

// 客户变更时重置日期
const onCustomerChange = () => {
  statement.items = []
  statement.opening_balance = 0
  statement.total_receivable = 0
  statement.total_received = 0
  statement.closing_balance = 0
}

// 查询
const handleSearch = async () => {
  if (!filters.customer_id) {
    ElMessage.warning('请选择客户')
    return
  }
  if (!filters.start_date || !filters.end_date) {
    ElMessage.warning('请选择日期范围')
    return
  }

  loading.value = true
  try {
    const res = await businessApi.customerStatement.get({
      customer_id: filters.customer_id,
      start_date: filters.start_date,
      end_date: filters.end_date,
    })
    if (res.code === 200) {
      Object.assign(statement, res.data)
    }
  } catch (e) {
    ElMessage.error('加载失败')
  } finally {
    loading.value = false
  }
}

// 重置
const handleReset = () => {
  filters.customer_id = null
  filters.start_date = ''
  filters.end_date = ''
  statement.items = []
  statement.opening_balance = 0
  statement.total_receivable = 0
  statement.total_received = 0
  statement.closing_balance = 0
}

// 打印
const handlePrint = () => {
  printVisible.value = true
}

const doPrint = () => {
  window.print()
}

// 查看单据
const handleViewDoc = (row) => {
  if (!row.doc_no) return
  // 根据单据类型跳转
  if (row.doc_type === '销售订单') {
    // TODO: 跳转到订单详情
    ElMessage.info('跳转到销售订单详情')
  } else if (row.doc_type === '收款单') {
    // TODO: 跳转到收款单详情
    ElMessage.info('跳转到收款单详情')
  }
}

// 导出Excel
const handleExport = async () => {
  try {
    const res = await businessApi.customerStatement.get({
      customer_id: filters.customer_id,
      start_date: filters.start_date,
      end_date: filters.end_date,
    })
    if (res.code === 200) {
      // 构建CSV内容
      let csv = '﻿' // BOM for Excel
      csv += `客户:${res.data.customer_name}\n`
      csv += `对账期间:${res.data.start_date} 至 ${res.data.end_date}\n\n`
      csv += `期初余额,${res.data.opening_balance}\n`
      csv += `本期应收,${res.data.total_receivable}\n`
      csv += `本期已收,${res.data.total_received}\n`
      csv += `期末余额,${res.data.closing_balance}\n\n`
      csv += '序号,单据日期,单据编号,单据类型,摘要,应收金额,已收金额,余额,经办人\n'
      res.data.items.forEach((item, idx) => {
        csv += `${idx + 1},${item.date},${item.doc_no},${item.doc_type},"${item.summary}",${item.receivable || ''},${item.received || ''},${item.balance},${item.operator || ''}\n`
      })
      csv += `合计,,,{res.data.total_receivable},{res.data.total_received},{res.data.closing_balance}\n`

      // 下载
      const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' })
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = `对账单_${res.data.customer_name}_${res.data.start_date}_${res.data.end_date}.csv`
      a.click()
      URL.revokeObjectURL(url)
    }
  } catch {
    ElMessage.error('导出失败')
  }
}

// 格式化金额
const formatAmount = (val) => {
  if (val === null || val === undefined) return '0.00'
  return Number(val).toFixed(2)
}

// 获取类型样式类
const getTypeClass = (type) => {
  const map = {
    '销售订单': 'tag-blue',
    '收款单': 'tag-green',
    '红冲单': 'tag-orange',
    '费用单': 'tag-purple',
    '其他收入': 'tag-yellow',
  }
  return map[type] || ''
}

onMounted(() => {
  fetchCustomers()
  // 设置默认日期范围为当月
  const now = new Date()
  const firstDay = new Date(now.getFullYear(), now.getMonth(), 1)
  filters.start_date = firstDay.toISOString().slice(0, 10)
  filters.end_date = now.toISOString().slice(0, 10)
})
</script>

<style scoped>
.statement-page {
  height: 100%;
  display: flex;
  flex-direction: column;
  background: #f0f2f5;
}

.filter-bar {
  height: 80px;
  background: #fff;
  border-bottom: 1px solid #e8e8e8;
  padding: 16px 24px;
  display: flex;
  align-items: flex-end;
  gap: 16px;
}

.filter-item {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.filter-item label {
  font-size: 14px;
  color: #333;
}

.summary-box {
  height: 60px;
  background: #fafafa;
  border: 1px solid #f0f0f0;
  border-radius: 4px;
  margin: 16px 0;
  padding: 0 24px;
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.summary-item {
  width: 200px;
  text-align: center;
}

.summary-label {
  font-size: 12px;
  color: #999;
  margin-bottom: 4px;
}

.summary-value {
  font-size: 20px;
  font-weight: bold;
  color: #333;
}

.summary-tag {
  font-size: 12px;
  color: #999;
  font-weight: normal;
  margin-left: 8px;
}

.table-container {
  flex: 1;
  background: #fff;
  border-radius: 4px;
  padding: 16px 24px;
  min-height: 500px;
  display: flex;
  flex-direction: column;
}

.statement-table {
  width: 100%;
}

.summary-footer {
  height: 44px;
  background: #fafafa;
  border-top: 2px solid #e8e8e8;
  display: flex;
  align-items: center;
  padding: 0 16px;
  margin-top: -1px;
}

.summary-footer-item {
  flex: 1;
  text-align: center;
  font-size: 14px;
  color: #333;
  font-weight: bold;
}

.empty-state {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  height: 300px;
}

.empty-icon {
  font-size: 64px;
  opacity: 0.3;
}

.empty-text {
  font-size: 14px;
  color: #999;
  margin-top: 16px;
}

.empty-hint {
  font-size: 12px;
  color: #bfbfbf;
  margin-top: 8px;
}

.pagination-bar {
  height: 50px;
  display: flex;
  justify-content: flex-end;
  align-items: center;
  padding-right: 24px;
}

/* 打印弹窗样式 */
.print-content {
  padding: 24px;
}

.print-title {
  font-size: 24px;
  text-align: center;
  font-weight: bold;
  color: #333;
  margin-bottom: 8px;
}

.print-subtitle {
  font-size: 14px;
  text-align: center;
  color: #666;
  margin-bottom: 24px;
}

.print-summary {
  display: flex;
  gap: 24px;
  font-size: 14px;
  color: #333;
  margin-bottom: 16px;
  padding-bottom: 16px;
  border-bottom: 1px solid #f0f0f0;
}

.print-table {
  width: 100%;
  border: 1px solid #333;
  border-collapse: collapse;
  font-size: 12px;
  color: #333;
}

.print-table th,
.print-table td {
  border: 1px solid #333;
  padding: 8px;
  text-align: center;
}

.print-table thead th {
  background: #f5f5f5;
  font-weight: bold;
}

.print-table .print-total {
  font-weight: bold;
  border-top: 2px solid #333;
}

.print-signature {
  margin-top: 40px;
  display: flex;
  justify-content: space-between;
  font-size: 14px;
  color: #333;
}

/* 颜色类 */
.text-red { color: #f5222d; }
.text-green { color: #52c41a; }
.text-blue { color: #1890ff; }

/* 标签样式 */
.tag-blue { background: #e6f7ff; color: #1890ff; padding: 2px 8px; border-radius: 4px; font-size: 12px; }
.tag-green { background: #f6ffed; color: #52c41a; padding: 2px 8px; border-radius: 4px; font-size: 12px; }
.tag-orange { background: #fff2e8; color: #fa8c16; padding: 2px 8px; border-radius: 4px; font-size: 12px; }
.tag-purple { background: #f9f0ff; color: #722ed1; padding: 2px 8px; border-radius: 4px; font-size: 12px; }
.tag-yellow { background: #fffbe6; color: #faad14; padding: 2px 8px; border-radius: 4px; font-size: 12px; }

/* 打印样式 */
@media print {
  .filter-bar, .summary-box, .pagination-bar, .el-dialog__footer {
    display: none !important;
  }
  .statement-page {
    background: #fff;
  }
  .table-container {
    padding: 0;
  }
}
</style>
