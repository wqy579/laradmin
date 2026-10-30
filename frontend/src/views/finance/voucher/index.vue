<template>
  <div class="voucher-page fin-page">
    <!-- ============ 数据源提示（QA-06/QA-11：凭证唯一数据源=总账） ============ -->
    <el-alert class="voucher-notice" type="info" show-icon :closable="false">
      凭证录入与审核请前往
      <span class="voucher-notice__link" @click="goLedgerVoucher">总账管理 → 记账凭证</span>
      ，本页为财务视角只读查询
    </el-alert>

    <!-- ============ 筛选卡 ============ -->
    <div class="voucher-filter fin-filter">
      <span class="label">凭证号</span>
      <el-input v-model="search.voucher_no" placeholder="模糊匹配" clearable size="small" style="width: 180px" @keyup.enter="refresh" @clear="refresh" />
      <span class="label">日期</span>
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
      <span class="label">科目</span>
      <!-- ledger 侧按分录末级科目精确匹配，故用末级科目下拉（凭证分录本就只允许末级科目） -->
      <el-select
        v-model="search.subject_id"
        filterable
        clearable
        placeholder="全部科目"
        size="small"
        style="width: 200px"
        @change="refresh"
      >
        <el-option v-for="s in subjectOptions" :key="s.id" :label="s.label" :value="s.id" />
      </el-select>
      <span class="label">经手人</span>
      <el-input v-model="search.maker_name" placeholder="制单人" clearable size="small" style="width: 130px" @keyup.enter="refresh" @clear="refresh" />
      <span class="label">状态</span>
      <el-select v-model="search.status" clearable placeholder="全部" size="small" style="width: 110px" @change="refresh">
        <el-option v-for="s in STATUS_OPTIONS" :key="s.value" :label="s.label" :value="s.value" />
      </el-select>
      <el-checkbox v-model="search.includeRed" @change="refresh">含红冲</el-checkbox>
      <div class="voucher-actions fin-toolbar">
        <el-button type="primary" @click="refresh">查询</el-button>
        <el-button @click="resetSearch">重置</el-button>
      </div>
      <div style="flex: 1" />
      <div class="voucher-actions fin-toolbar">
        <el-button v-permission="'fin:voucher:export'" :loading="exporting" @click="exportData">导出</el-button>
      </div>
    </div>

    <!-- ============ 工具条 ============ -->
    <div class="voucher-toolbar fin-toolbar">
      <span class="voucher-toolbar__title">财务凭证</span>
      <!-- 凭证录入/审核统一走 ledger 模块（BUG-10），本页为查询视图 -->
      <el-button v-permission="'fin:voucher:view'" :disabled="!activeVoucher" @click="printActive">打印</el-button>
    </div>

    <!-- ============ 凭证表（凭证头级，分录在查看抽屉内展开） ============ -->
    <div class="voucher-table" v-loading="loading">
      <el-table :data="vouchers" border stripe @row-click="onRowClick">
        <el-table-column type="index" label="序号" width="50" align="center">
          <template #default="{ $index }"><span style="color: var(--text-secondary)">{{ (page - 1) * pageSize + $index + 1 }}</span></template>
        </el-table-column>
        <el-table-column prop="voucher_no" label="凭证号" width="160" align="left" show-overflow-tooltip>
          <template #default="{ row }">
            <span class="tabular" :class="{ 'voucher-no--red': row.is_red }">{{ row.voucher_no }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="voucher_date" label="日期" width="110" align="center">
          <template #default="{ row }"><span class="tabular">{{ row.voucher_date }}</span></template>
        </el-table-column>
        <el-table-column prop="period" label="期间" width="90" align="center">
          <template #default="{ row }"><span class="tabular">{{ row.period }}</span></template>
        </el-table-column>
        <el-table-column prop="summary" label="摘要" min-width="200" align="left" show-overflow-tooltip>
          <template #default="{ row }">{{ row.summary || '-' }}</template>
        </el-table-column>
        <el-table-column label="借方合计" width="130" align="right">
          <template #default="{ row }">
            <span class="tabular" :class="{ 'voucher-amt--red': row.is_red }">{{ fmtMoney(row.total_debit) }}</span>
          </template>
        </el-table-column>
        <el-table-column label="贷方合计" width="130" align="right">
          <template #default="{ row }">
            <span class="tabular" :class="{ 'voucher-amt--red': row.is_red }">{{ fmtMoney(row.total_credit) }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="maker_name" label="制单人" width="110" align="left" show-overflow-tooltip>
          <template #default="{ row }">{{ row.maker_name || '-' }}</template>
        </el-table-column>
        <el-table-column label="状态" width="90" align="center">
          <template #default="{ row }">
            <el-tag :type="STATUS_TAG[row.status] || 'info'" size="small" effect="light">{{ row.status_label }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="130" align="right">
          <template #default="{ row }">
            <!-- 查询视图：仅查看/打印，录入/审核/红冲走 ledger 模块（BUG-10） -->
            <el-button link type="primary" size="small" @click.stop="openView(row)">查看</el-button>
            <el-button link type="primary" size="small" v-if="row.status === 'audited'" @click.stop="printOne(row)">打印</el-button>
          </template>
        </el-table-column>
        <template #empty>
          <FinEmpty />
        </template>
      </el-table>
    </div>

    <div class="voucher-pager fin-pager">
      <el-pagination
        background
        layout="total, prev, pager, next"
        :total="total"
        :page-size="pageSize"
        :current-page="page"
        @current-change="onPageChange"
      />
    </div>

    <!-- ============ 凭证只读查看抽屉（查询视图，BUG-10） ============ -->
    <el-drawer v-model="viewVisible" size="720px" :title="viewNo" destroy-on-close>
      <div v-if="view" v-loading="viewLoading" class="v-view">
        <div class="v-view__meta">
          <span>凭证号：{{ view.voucher_no }}</span>
          <span>日期：{{ view.voucher_date }}</span>
          <span>制单人：{{ view.maker_name || '-' }}</span>
          <span>附单据：{{ view.attachments_count || 0 }} 张</span>
          <span>状态：{{ view.status_label }}</span>
        </div>
        <el-table :data="view.entries" border stripe class="fin-table--dense" size="small" :show-summary="true" :summary-method="viewSummary">
          <el-table-column type="index" label="序号" width="52" align="center" />
          <el-table-column label="科目" min-width="150" align="left">
            <template #default="{ row }"><span class="tabular">{{ row.subject_code }} {{ row.subject_name }}</span></template>
          </el-table-column>
          <el-table-column label="品牌" width="100" align="left">
            <template #default="{ row }">{{ row.brand_name || '-' }}</template>
          </el-table-column>
          <el-table-column label="客户/供应商" width="130" align="left">
            <template #default="{ row }">{{ row.aux_name || '-' }}</template>
          </el-table-column>
          <el-table-column prop="summary" label="摘要" min-width="160" align="left" />
          <el-table-column label="借方" width="110" align="right">
            <template #default="{ row }"><span class="tabular">{{ fmtMoney(row.debit_amount) }}</span></template>
          </el-table-column>
          <el-table-column label="贷方" width="110" align="right">
            <template #default="{ row }"><span class="tabular">{{ fmtMoney(row.credit_amount) }}</span></template>
          </el-table-column>
        </el-table>
        <p class="v-view__remark" v-if="view.remark">备注：{{ view.remark }}</p>
      </div>
    </el-drawer>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import { saveBlob } from '@/utils/fileDownload'
import ledgerApi from '@/api/ledger'
import FinEmpty from '../_FinEmpty.vue'

// QA-06/QA-11：凭证唯一数据源为 Ledger，本页直接取 ledgerApi.voucher.*（不再走 Business 兼容接口）
const router = useRouter()
const goLedgerVoucher = () => router.push('/ledger/voucher')

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
const exporting = ref(false)
const viewLoading = ref(false)
const vouchers = ref([]) // 凭证头数组（ledger/vouchers 分页返回）
const subjectOptions = ref([]) // 末级科目下拉（ledger/subjects/options）
const activeVoucher = ref(null) // 工具条「打印」的目标（最近点击行）
const total = ref(0)
const page = ref(1)
const pageSize = 30

const search = reactive({
  voucher_no: '',
  dateRange: null,
  subject_id: '',
  maker_name: '',
  status: '',
  includeRed: false,
})

onMounted(() => {
  loadSubjectOptions()
  refresh()
})

/* ---------- 科目下拉（ledger 末级科目；ledger 侧按分录 subject_id 精确匹配） ---------- */
const loadSubjectOptions = async () => {
  try {
    const res = await ledgerApi.subject.options.get()
    subjectOptions.value = res?.data?.options || []
  } catch {
    subjectOptions.value = []
  }
}

/* 当前筛选条件 → ledger/vouchers 查询参数（列表与导出共用） */
function queryParams() {
  const params = {
    voucher_no: search.voucher_no || undefined,
    status: search.status || undefined,
    subject_id: search.subject_id || undefined,
    maker_name: search.maker_name || undefined,
    include_red: search.includeRed ? 1 : 0,
  }
  if (search.dateRange?.length === 2) {
    params.start_date = search.dateRange[0]
    params.end_date = search.dateRange[1]
  }
  return params
}

/* ---------- 查询（后端筛选分页，真实 ledger 凭证） ---------- */
const load = async () => {
  loading.value = true
  try {
    const res = await ledgerApi.voucher.list.get({ ...queryParams(), page: page.value, page_size: pageSize })
    const data = res?.data ?? {}
    vouchers.value = data.list ?? []
    total.value = data.total ?? 0
  } catch {
    vouchers.value = []
    total.value = 0
  } finally {
    loading.value = false
  }
}

function refresh() {
  page.value = 1
  load()
}

function onPageChange(p) {
  page.value = p
  load()
}

function resetSearch() {
  Object.assign(search, {
    voucher_no: '',
    dateRange: null,
    subject_id: '',
    maker_name: '',
    status: '',
    includeRed: false,
  })
  refresh()
}

function onRowClick(row) {
  activeVoucher.value = row
}

/* ---------- 查看抽屉（分录走 ledger 凭证详情，列表不含 entries） ---------- */
const viewVisible = ref(false)
const view = ref(null)
const viewNo = computed(() => (view.value ? `凭证详情 ${view.value.voucher_no}` : '凭证详情'))

function viewSummary({ columns }) {
  const d = (view.value?.entries || []).reduce((s, it) => s + Number(it.debit_amount || 0), 0)
  const c = (view.value?.entries || []).reduce((s, it) => s + Number(it.credit_amount || 0), 0)
  return columns.map((col, i) => {
    if (i === 0) return '合计'
    if (i === 5) return fmtMoney(d)
    if (i === 6) return fmtMoney(c)
    return ''
  })
}

async function openView(row) {
  if (!row) return
  view.value = { ...row, entries: [] } // 先用凭证头占位，避免抽屉空白
  viewVisible.value = true
  viewLoading.value = true
  try {
    const res = await ledgerApi.voucher.detail.get(row.id)
    view.value = res?.data?.voucher || view.value
  } catch {
    ElMessage.error('凭证详情加载失败')
  } finally {
    viewLoading.value = false
  }
}

/* ---------- 导出（ledger/vouchers/export 直接返回 CSV 流，按当前筛选导出全集） ---------- */
async function exportData() {
  exporting.value = true
  try {
    const blob = await ledgerApi.voucher.export(queryParams())
    saveBlob(blob, '财务凭证.csv')
    ElMessage.success('导出成功')
  } catch {
    ElMessage.error('导出失败')
  } finally {
    exporting.value = false
  }
}

/* ---------- 打印（分录取自 ledger 凭证详情） ---------- */
function voucherPrintHtml(v) {
  const entries = v.entries || []
  const rows = entries
    .map(
      (it, i) =>
        '<tr><td>' +
        (i + 1) +
        '</td><td>' +
        (it.subject_code || '') +
        ' ' +
        (it.subject_name || '') +
        '</td><td>' +
        (it.brand_name || '-') +
        '</td><td>' +
        (it.aux_name || '-') +
        '</td><td>' +
        (it.summary || '') +
        '</td><td style="text-align:right">' +
        (it.debit_amount ? fmtMoney(it.debit_amount) : '') +
        '</td><td style="text-align:right">' +
        (it.credit_amount ? fmtMoney(it.credit_amount) : '') +
        '</td></tr>',
    )
    .join('')
  const dSum = entries.reduce((s, it) => s + Number(it.debit_amount || 0), 0)
  const cSum = entries.reduce((s, it) => s + Number(it.credit_amount || 0), 0)
  return (
    '<html><head><meta charset="utf-8"><title>' +
    v.voucher_no +
    '</title><style>body{font-family:SimSun,serif;padding:24px}table{border-collapse:collapse;width:100%}th,td{border:1px solid #303133;padding:6px 10px;font-size:13px}th{background:#f5f7fa}h2{text-align:center}.meta{margin:8px 0 16px}.meta span{margin-right:24px}</style></head><body>' +
    '<h2>记账凭证</h2>' +
    '<div class="meta"><span>凭证号：' +
    v.voucher_no +
    '</span><span>日期：' +
    v.voucher_date +
    '</span><span>制单人：' +
    (v.maker_name || '-') +
    '</span><span>附单据：' +
    (v.attachments_count || 0) +
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

// 列表行只有凭证头，打印前按 id 取详情（含分录）
async function printRow(row) {
  if (!row) return
  try {
    const res = await ledgerApi.voucher.detail.get(row.id)
    printVoucherDoc(res?.data?.voucher || row)
  } catch {
    ElMessage.error('凭证详情加载失败')
  }
}

function printOne(row) {
  printRow(row)
}

function printActive() {
  printRow(activeVoucher.value)
}
</script>

<style>
@import '../_shared.css';
</style>

<style scoped>
.voucher-page {
  padding: 12px;
}

/* 数据源提示条（沿用 el-alert info 现有写法，不新造配色） */
.voucher-notice {
  margin-bottom: 12px;
}
.voucher-notice__link {
  color: var(--color-primary);
  cursor: pointer;
}

/* 筛选卡（白底/padding/标签由 _shared.css .fin-filter 提供） */
.voucher-filter {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
  margin-bottom: 12px;
}
.voucher-filter .label {
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
  font-size: var(--font-h3);
  font-weight: 600;
  color: var(--text-primary);
  margin-right: 8px;
}

/* 表格（表头 44px/500、行高 48、斑马纹由 _shared.css 提供） */
.voucher-table :deep(.el-table__body td) {
  font-size: var(--font-base);
  color: var(--text-primary);
}
.voucher-table :deep(.el-table__footer td) {
  background: var(--bg-hover);
  font-size: var(--font-base);
  font-weight: 600;
  color: var(--text-primary);
}
.voucher-table :deep(.voucher-row--group td) {
  border-top: 1px solid var(--border-base) !important;
}
.voucher-no--red {
  color: var(--color-danger);
}
.voucher-amt--red {
  color: var(--color-danger);
}

/* 只读查看抽屉 */
:deep(.el-drawer__header) {
  height: 50px;
  margin-bottom: 8px;
  padding-top: 0;
  padding-bottom: 0;
  border-bottom: 1px solid var(--border-lighter);
}
:deep(.el-drawer__title) {
  font-size: var(--font-h2);
  font-weight: 600;
  color: var(--text-primary);
  height: 50px;
  line-height: 50px;
  padding-left: 20px;
}
.v-view {
  font-size: var(--font-base);
  color: var(--text-primary);
}
.v-view__meta {
  display: flex;
  flex-wrap: wrap;
  gap: 6px 24px;
  padding: 14px 16px;
  background: var(--bg-hover);
  border: 1px solid var(--border-lighter);
  border-radius: var(--radius-md);
  margin-bottom: 16px;
  color: var(--text-regular);
}
.v-view__remark {
  margin-top: 16px;
  color: var(--text-regular);
  font-size: var(--font-base);
}
</style>