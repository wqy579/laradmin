<template>
  <div class="voucher-page">
    <!-- ============ 筛选卡 ============ -->
    <div class="voucher-filter">
      <span class="voucher-filter__label">凭证号</span>
      <el-input v-model="search.no" placeholder="模糊匹配" clearable size="small" style="width: 180px" @keyup.enter="refresh" @clear="refresh" />
      <span class="voucher-filter__label">日期</span>
      <el-date-picker
        v-model="search.dateRange"
        type="daterange"
        range-separator="~"
        start-placeholder="开始日期"
        end-placeholder="结束日期"
        value-format="YYYY-MM-DD"
        size="small"
        style="width: 230px"
        @change="refresh"
      />
      <span class="voucher-filter__label">科目</span>
      <el-tree-select
        v-model="search.subject"
        :data="subjectTree"
        :props="{ label: 'label', children: 'children', value: 'value' }"
        node-key="value"
        check-strictly
        filterable
        clearable
        placeholder="全部科目"
        size="small"
        style="width: 180px"
        @change="refresh"
      />
      <span class="voucher-filter__label">经手人</span>
      <el-input v-model="search.employee" placeholder="制单人" clearable size="small" style="width: 130px" @keyup.enter="refresh" @clear="refresh" />
      <span class="voucher-filter__label">状态</span>
      <el-select v-model="search.status" clearable placeholder="全部" size="small" style="width: 110px" @change="refresh">
        <el-option v-for="s in STATUS_OPTIONS" :key="s.value" :label="s.label" :value="s.value" />
      </el-select>
      <el-checkbox v-model="search.includeReversed" @change="refresh">含红冲</el-checkbox>
      <el-button type="primary" size="small" @click="refresh">查询</el-button>
      <el-button size="small" @click="resetSearch">重置</el-button>
      <div style="flex: 1" />
      <el-button size="small" v-permission="'fin:voucher:export'" @click="exportData">导出</el-button>
    </div>

    <!-- ============ 工具条 ============ -->
    <div class="voucher-toolbar">
      <span class="voucher-toolbar__title">财务凭证</span>
      <!-- 凭证录入/审核统一走 ledger 模块（BUG-10），本页为查询视图 -->
<el-button size="small" v-permission="'fin:voucher:view'" :disabled="!activeVoucher" @click="printActive">打印</el-button>
    </div>

    <!-- ============ 凭证表（分录级，按凭证分组） ============ -->
    <div class="voucher-table" v-loading="loading">
      <el-table :data="entryRows" border :row-class-name="rowClassName" @row-click="onRowClick" show-summary :summary-method="summaryMethod">
        <el-table-column type="index" label="序号" width="50" align="center">
          <template #default="{ $index }"><span style="color: #909399">{{ $index + 1 }}</span></template>
        </el-table-column>
        <el-table-column prop="no" label="凭证号" width="160" align="left" show-overflow-tooltip>
          <template #default="{ row }">
            <span class="tabular" :class="{ 'voucher-no--red': row.is_red }">{{ row.no }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="date" label="日期" width="110" align="center">
          <template #default="{ row }"><span class="tabular">{{ row.date }}</span></template>
        </el-table-column>
        <el-table-column label="科目" width="170" align="left" show-overflow-tooltip>
          <template #default="{ row }"><span class="tabular">{{ row.subject }} {{ row.subject_name }}</span></template>
        </el-table-column>
        <el-table-column label="品牌" width="110" align="left" show-overflow-tooltip>
          <template #default="{ row }">{{ row.brand_name || '-' }}</template>
        </el-table-column>
        <el-table-column label="客户/供应商" width="140" align="left" show-overflow-tooltip>
          <template #default="{ row }">{{ row.partner || '-' }}</template>
        </el-table-column>
        <el-table-column prop="abstract" label="摘要" min-width="200" align="left" show-overflow-tooltip />
        <el-table-column label="借方金额" width="120" align="right">
          <template #default="{ row }">
            <span class="tabular" :class="{ 'voucher-amt--red': row.is_red }">{{ row.debit ? fmtMoney(row.debit) : '-' }}</span>
          </template>
        </el-table-column>
        <el-table-column label="贷方金额" width="120" align="right">
          <template #default="{ row }">
            <span class="tabular" :class="{ 'voucher-amt--red': row.is_red }">{{ row.credit ? fmtMoney(row.credit) : '-' }}</span>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="90" align="center">
          <template #default="{ row }">
            <el-tag :type="STATUS_TAG[row.status] || 'info'" size="small" effect="light">{{ row.status_label }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="130" align="right">
          <template #default="{ row }">
            <template v-if="row._groupFirst">
              <!-- 查询视图：仅查看/打印，录入/审核/红冲走 ledger 模块（BUG-10） -->
              <el-button link type="primary" size="small" @click.stop="openView(row._voucher)">查看</el-button>
              <el-button link type="primary" size="small" v-if="row._voucher.status === 'audited'" @click.stop="printOne(row._voucher)">打印</el-button>
            </template>
          </template>
        </el-table-column>
      </el-table>
    </div>

    <!-- ============ 凭证只读查看抽屉（查询视图，BUG-10） ============ -->
    <el-drawer v-model="viewVisible" size="720px" :title="viewNo" destroy-on-close>
      <div v-if="view" class="v-view">
        <div class="v-view__meta">
          <span>凭证号：{{ view.no }}</span>
          <span>日期：{{ view.date }}</span>
          <span>制单人：{{ view.employee }}</span>
          <span>附单据：{{ view.attachment_count }} 张</span>
          <span>状态：{{ view.status_label }}</span>
        </div>
        <el-table :data="view.items" border size="small" :show-summary="true" :summary-method="viewSummary">
          <el-table-column type="index" label="序号" width="52" align="center" />
          <el-table-column label="科目" min-width="150" align="left">
            <template #default="{ row }"><span class="tabular">{{ row.subject }} {{ row.subject_name }}</span></template>
          </el-table-column>
          <el-table-column label="品牌" width="100" align="left">
            <template #default="{ row }">{{ row.brand_name || '-' }}</template>
          </el-table-column>
          <el-table-column label="客户/供应商" width="130" align="left">
            <template #default="{ row }">{{ row.partner || '-' }}</template>
          </el-table-column>
          <el-table-column prop="abstract" label="摘要" min-width="160" align="left" />
          <el-table-column label="借方" width="110" align="right">
            <template #default="{ row }"><span class="tabular">{{ fmtMoney(row.debit) }}</span></template>
          </el-table-column>
          <el-table-column label="贷方" width="110" align="right">
            <template #default="{ row }"><span class="tabular">{{ fmtMoney(row.credit) }}</span></template>
          </el-table-column>
        </el-table>
        <p class="v-view__remark" v-if="view.remark">备注：{{ view.remark }}</p>
      </div>
    </el-drawer>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import businessApi from '@/api/business'
import ledgerApi from '@/api/ledger'

/* ---------- 金额格式化 ---------- */
function fmtMoney(v) {
  const n = Number(v || 0)
  const neg = n < 0
  const s = Math.abs(n).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',')
  return (neg ? '-' : '') + s
}

/* 状态字典：真实 ledger 状态（reversed=已作废，服务端已排除；红冲由 red_of_id/is_red 表达） */
const STATUS_OPTIONS = [
  { value: 'draft', label: '草稿' },
  { value: 'submitted', label: '待审核' },
  { value: 'audited', label: '已审核' },
]
const STATUS_TAG = { draft: 'info', submitted: 'warning', audited: 'success' }

/* ---------- 数据 ---------- */
const loading = ref(false)
const vouchers = ref([]) // 凭证头数组（查询视图，真实 ledger）
const subjectTree = ref([])
const activeVoucher = ref(null) // 工具条「打印」的目标（最近点击行所属凭证）

const search = reactive({
  no: '',
  dateRange: null,
  subject: '',
  employee: '',
  status: '',
  includeReversed: false,
})

onMounted(() => {
  loadSubjectTree()
  refresh()
})

/* ---------- 科目树（ledger，machine-a） ---------- */
const mapSubjectTree = (nodes) =>
  (nodes || []).map((n) => ({
    label: `${n.code} ${n.name}`,
    value: n.code,
    children: mapSubjectTree(n.children || []),
  }))

const loadSubjectTree = async () => {
  try {
    const res = await ledgerApi.subject.tree.get()
    subjectTree.value = mapSubjectTree(res?.data?.tree || [])
  } catch {
    subjectTree.value = []
  }
}

/* ---------- 查询（后端筛选分页，真实 ledger 凭证） ---------- */
const load = async () => {
  const params = {
    page: 1,
    page_size: 1000,
    no: search.no || undefined,
    status: search.status || undefined,
    employee: search.employee || undefined,
    subject: search.subject || undefined,
    include_red: search.includeReversed ? 1 : 0,
  }
  if (search.dateRange?.length === 2) {
    params.start_date = search.dateRange[0]
    params.end_date = search.dateRange[1]
  }
  loading.value = true
  try {
    const res = await businessApi.finance.voucher.get(params)
    const data = res?.data?.data ?? res?.data ?? {}
    vouchers.value = data.list ?? []
  } catch {
    vouchers.value = []
  } finally {
    loading.value = false
  }
}

/* 分录级行（同凭证并列，组间分割）—— 后端已按凭证分组返回 */
const entryRows = computed(() => {
  const rows = []
  vouchers.value.forEach((v) => {
    v.items.forEach((it, i) => {
      rows.push({
        ...it,
        no: v.no,
        date: v.date,
        status: v.status,
        status_label: v.status_label,
        is_red: !!v.is_red,
        _voucher: v,
        _groupFirst: i === 0,
      })
    })
  })
  return rows
})

function rowClassName({ row }) {
  return row._groupFirst ? 'voucher-row--group' : ''
}

function refresh() {
  load()
}

function resetSearch() {
  Object.assign(search, {
    no: '',
    dateRange: null,
    subject: '',
    employee: '',
    status: '',
    includeReversed: false,
  })
  refresh()
}

function onRowClick(row) {
  activeVoucher.value = row._voucher
}

/* ---------- 合计行 ---------- */
function summaryMethod({ columns }) {
  const sums = entryRows.value.reduce(
    (acc, r) => {
      acc.debit += Number(r.debit || 0)
      acc.credit += Number(r.credit || 0)
      return acc
    },
    { debit: 0, credit: 0 },
  )
  const arr = []
  columns.forEach((col, i) => {
    if (i === 1) arr[i] = '合计'
    else if (i === 7) arr[i] = '借方合计 ¥' + fmtMoney(sums.debit)
    else if (i === 8) arr[i] = '贷方合计 ¥' + fmtMoney(sums.credit)
    else arr[i] = ''
  })
  return arr
}

/* ---------- 查看抽屉 ---------- */
const viewVisible = ref(false)
const view = ref(null)
const viewNo = computed(() => (view.value ? `凭证详情 ${view.value.no}` : '凭证详情'))

function viewSummary({ columns }) {
  const d = (view.value?.items || []).reduce((s, it) => s + Number(it.debit || 0), 0)
  const c = (view.value?.items || []).reduce((s, it) => s + Number(it.credit || 0), 0)
  return columns.map((col, i) => {
    if (i === 0) return '合计'
    if (i === 5) return fmtMoney(d)
    if (i === 6) return fmtMoney(c)
    return ''
  })
}

function openView(v) {
  view.value = v
  viewVisible.value = true
}

/* ---------- 导出 ---------- */
function saveBlob(blob, filename) {
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
  URL.revokeObjectURL(url)
}

function exportData() {
  const header = ['凭证号', '日期', '科目编码', '科目名称', '品牌', '客户/供应商', '摘要', '借方金额', '贷方金额', '状态']
  const lines = entryRows.value.map((r) => [
    r.no,
    r.date,
    r.subject,
    r.subject_name,
    r.brand_name || '',
    r.partner || '',
    r.abstract,
    r.debit || '',
    r.credit || '',
    r.status_label,
  ])
  const sums = lines.reduce((acc, r) => ({ d: acc.d + Number(r[7] || 0), c: acc.c + Number(r[8] || 0) }), { d: 0, c: 0 })
  lines.push(['合计', '', '', '', '', '', '', sums.d, sums.c, ''])
  const csv = [header, ...lines].map((r) => r.map((c) => '"' + String(c).replace(/"/g, '""') + '"').join(',')).join('\r\n')
  saveBlob(new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8' }), '财务凭证.csv')
  ElMessage.success('已导出 ' + entryRows.value.length + ' 条分录')
}

/* ---------- 打印 ---------- */
function voucherPrintHtml(v) {
  const rows = v.items
    .map(
      (it, i) =>
        '<tr><td>' +
        (i + 1) +
        '</td><td>' +
        it.subject +
        ' ' +
        it.subject_name +
        '</td><td>' +
        (it.brand_name || '-') +
        '</td><td>' +
        (it.partner || '-') +
        '</td><td>' +
        (it.abstract || '') +
        '</td><td style="text-align:right">' +
        (it.debit ? fmtMoney(it.debit) : '') +
        '</td><td style="text-align:right">' +
        (it.credit ? fmtMoney(it.credit) : '') +
        '</td></tr>',
    )
    .join('')
  const dSum = v.items.reduce((s, it) => s + Number(it.debit || 0), 0)
  const cSum = v.items.reduce((s, it) => s + Number(it.credit || 0), 0)
  return (
    '<html><head><meta charset="utf-8"><title>' +
    v.no +
    '</title><style>body{font-family:SimSun,serif;padding:24px}table{border-collapse:collapse;width:100%}th,td{border:1px solid #333;padding:6px 10px;font-size:13px}th{background:#f5f7fa}h2{text-align:center}.meta{margin:8px 0 16px}.meta span{margin-right:24px}</style></head><body>' +
    '<h2>记账凭证</h2>' +
    '<div class="meta"><span>凭证号：' +
    v.no +
    '</span><span>日期：' +
    v.date +
    '</span><span>制单人：' +
    (v.employee || '-') +
    '</span><span>附单据：' +
    v.attachment_count +
    ' 张</span></div>' +
    '<table><thead><tr><th>序号</th><th>科目</th><th>品牌</th><th>客户/供应商</th><th>摘要</th><th>借方</th><th>贷方</th></tr></thead><tbody>' +
    rows +
    '</tbody><tfoot><tr><th colspan="5">合计</th><th style="text-align:right">¥' +
    fmtMoney(dSum) +
    '</th><th style="text-align:right">¥' +
    fmtMoney(cSum) +
    '</th></tr></tfoot></table>' +
    '<p>备注：' +
    (v.remark || '') +
    '</p></body></html>'
  )
}

function printVoucherDoc(v) {
  const w = window.open('', '_blank')
  if (!w) {
    ElMessage.warning('浏览器拦截了打印窗口，请允许弹出窗口')
    return
  }
  w.document.write(voucherPrintHtml(v))
  w.document.close()
  w.focus()
  setTimeout(() => w.print(), 300)
}

function printOne(v) {
  printVoucherDoc(v)
}

function printActive() {
  if (activeVoucher.value) printVoucherDoc(activeVoucher.value)
}
</script>

<style scoped>
.voucher-page {
  padding: 12px;
}

/* 筛选卡 */
.voucher-filter {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
  padding: 10px 12px;
  background: #fff;
  border-radius: 4px;
  margin-bottom: 12px;
}
.voucher-filter__label {
  font-size: 13px;
  color: #606266;
  margin-left: 4px;
}

/* 工具条 */
.voucher-toolbar {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 12px;
}
.voucher-toolbar__title {
  font-size: 15px;
  font-weight: 600;
  color: #303133;
  margin-right: 8px;
}

/* 表格 */
.voucher-table :deep(.el-table__header th) {
  background: #f5f7fa !important;
  height: 44px;
  font-size: 13px;
  font-weight: 600;
  color: #606266;
}
.voucher-table :deep(.el-table__body td) {
  height: 40px;
  font-size: 13px;
  color: #303133;
}
.voucher-table :deep(.el-table__footer td) {
  background: #f5f7fa;
  font-size: 13px;
  font-weight: 600;
  color: #303133;
}
.voucher-table :deep(.voucher-row--group td) {
  border-top: 1px solid #dcdfe6 !important;
}
.tabular {
  font-variant-numeric: tabular-nums;
}
.voucher-no--red {
  color: #f56c6c;
}
.voucher-amt--red {
  color: #f56c6c;
}

/* 只读查看抽屉 */
:deep(.el-drawer__header) {
  height: 50px;
  margin-bottom: 8px;
  padding-top: 0;
  padding-bottom: 0;
  border-bottom: 1px solid #ebeeF5;
}
:deep(.el-drawer__title) {
  font-size: 16px;
  font-weight: 600;
  color: #303133;
  height: 50px;
  line-height: 50px;
}
.v-view {
  font-size: 13px;
  color: #303133;
}
.v-view__meta {
  display: flex;
  flex-wrap: wrap;
  gap: 6px 24px;
  padding: 14px 16px;
  background: #f5f7fa;
  border: 1px solid #ebeeF5;
  border-radius: 4px;
  margin-bottom: 16px;
  color: #606266;
}
.v-view__remark {
  margin-top: 16px;
  color: #606266;
  font-size: 13px;
}
</style>