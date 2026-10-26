<template>
  <div class="voucher-page">
    <!-- ============ 筛选卡 ============ -->
    <div class="voucher-filter">
      <span class="voucher-filter__label">凭证号</span>
      <el-input v-model="search.no" placeholder="模糊匹配" clearable size="small" style="width: 180px" @keyup.enter="refresh" @clear="refresh" />
      <span class="voucher-filter__label">凭证字</span>
      <el-select v-model="search.type" clearable placeholder="全部" size="small" style="width: 120px" @change="refresh">
        <el-option v-for="t in VOUCHER_TYPES" :key="t.value" :label="t.label" :value="t.value" />
      </el-select>
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
        node-key="value"
        check-strictly
        filterable
        clearable
        placeholder="全部科目"
        size="small"
        style="width: 180px"
        @change="refresh"
      />
      <span class="voucher-filter__label">部门</span>
      <el-select v-model="search.department" clearable placeholder="全部" size="small" style="width: 130px" @change="refresh">
        <el-option v-for="d in DEPARTMENTS" :key="d.value" :label="d.label" :value="d.value" />
      </el-select>
      <span class="voucher-filter__label">经手人</span>
      <el-select v-model="search.employee" clearable placeholder="全部" size="small" style="width: 120px" @change="refresh">
        <el-option v-for="e in EMPLOYEES" :key="e.value" :label="e.label" :value="e.value" />
      </el-select>
      <span class="voucher-filter__label">状态</span>
      <el-select v-model="search.status" clearable placeholder="全部" size="small" style="width: 110px" @change="refresh">
        <el-option label="草稿" value="draft" />
        <el-option label="已审核" value="audited" />
        <el-option label="已红冲" value="reversed" />
      </el-select>
      <el-checkbox v-model="search.includeReversed" @change="refresh">含红冲</el-checkbox>
      <el-button type="primary" size="small" @click="refresh">查询</el-button>
      <el-button size="small" @click="resetSearch">重置</el-button>
      <div style="flex: 1" />
      <el-button size="small" v-permission="'fin:voucher:export'" @click="exportData">导出 Excel</el-button>
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
        <el-table-column prop="no" label="凭证号" width="150" align="left" show-overflow-tooltip>
          <template #default="{ row }">
            <span class="tabular" :class="{ 'voucher-no--reversed': row.status === 'reversed' }">{{ row.no }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="date" label="日期" width="110" align="center">
          <template #default="{ row }"><span class="tabular">{{ row.date }}</span></template>
        </el-table-column>
        <el-table-column label="科目" width="160" align="left" show-overflow-tooltip>
          <template #default="{ row }"><span class="tabular">{{ row.subject }} {{ row.subject_name }}</span></template>
        </el-table-column>
        <el-table-column label="品牌" width="120" align="left" show-overflow-tooltip>
          <template #default="{ row }">{{ row.brand ? BRAND_LABEL[row.brand] || row.brand : '-' }}</template>
        </el-table-column>
        <el-table-column label="客户/供应商" width="140" align="left" show-overflow-tooltip>
          <template #default="{ row }">{{ row.partner ? PARTNER_LABEL[row.partner] || row.partner : '-' }}</template>
        </el-table-column>
        <el-table-column prop="abstract" label="摘要" min-width="200" align="left" show-overflow-tooltip />
        <el-table-column label="借方金额" width="120" align="right">
          <template #default="{ row }">
            <span class="tabular" :class="{ 'voucher-amt--reversed': row.status === 'reversed' }">{{ row.debit ? fmtMoney(row.debit) : '-' }}</span>
          </template>
        </el-table-column>
        <el-table-column label="贷方金额" width="120" align="right">
          <template #default="{ row }">
            <span class="tabular" :class="{ 'voucher-amt--reversed': row.status === 'reversed' }">{{ row.credit ? fmtMoney(row.credit) : '-' }}</span>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="90" align="center">
          <template #default="{ row }">
            <el-tag :type="VOUCHER_STATUS[row.status].tag" size="small" effect="light">{{ VOUCHER_STATUS[row.status].label }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="150" align="right">
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

    <!-- ============ 新增/编辑/查看凭证弹窗 ============ -->
    <el-dialog
      v-model="dialogVisible"
      :title="dialogTitle"
      width="1000px"
      :close-on-click-modal="false"
      destroy-on-close
      @closed="onDialogClosed"
    >
      <el-form :model="form" label-width="96px" size="small" :disabled="mode === 'view'">
        <div class="voucher-dialog__head">
          <el-form-item label="凭证字" required>
            <el-select v-model="form.voucher_type" style="width: 100%">
              <el-option v-for="t in VOUCHER_TYPES" :key="t.value" :label="t.label" :value="t.value" />
            </el-select>
          </el-form-item>
          <el-form-item label="凭证日期" required>
            <el-date-picker v-model="form.date" type="date" value-format="YYYY-MM-DD" :disabled-date="disableFuture" style="width: 100%" />
          </el-form-item>
          <el-form-item label="凭证号">
            <el-input v-model="form.no" readonly />
          </el-form-item>
          <el-form-item label="部门">
            <el-select v-model="form.department" clearable placeholder="选填" style="width: 100%">
              <el-option v-for="d in DEPARTMENTS" :key="d.value" :label="d.label" :value="d.value" />
            </el-select>
          </el-form-item>
          <el-form-item label="经手人" required>
            <el-select v-model="form.employee" placeholder="必填" style="width: 100%">
              <el-option v-for="e in EMPLOYEES" :key="e.value" :label="e.label" :value="e.value" />
            </el-select>
          </el-form-item>
          <el-form-item label="附单张数">
            <el-input-number v-model="form.attachment_count" :min="0" :max="999" style="width: 100%" />
          </el-form-item>
          <el-form-item label="自动审核">
            <el-switch v-model="form.auto_audit" />
          </el-form-item>
          <el-form-item label="备注" class="voucher-dialog__remark">
            <el-input v-model="form.remark" type="textarea" :rows="2" maxlength="200" show-word-limit placeholder="不超过 200 字" />
          </el-form-item>
        </div>

        <!-- 分录表 -->
        <div class="voucher-dialog__entries">
          <el-table :data="form.items" border size="small" :row-class-name="entryRowClass">
            <el-table-column type="index" label="序号" width="46" align="center" />
            <el-table-column label="科目" min-width="170">
              <template #default="{ row }">
                <el-tree-select
                  v-model="row.subject"
                  :data="subjectTree"
                  node-key="value"
                  check-strictly
                  filterable
                  placeholder="选择科目"
                  style="width: 100%"
                  @change="onEntrySubjectChange(row)"
                />
              </template>
            </el-table-column>
            <el-table-column label="品牌" width="130">
              <template #default="{ row }">
                <el-select v-model="row.brand" :disabled="!subjectHasBrand(row.subject)" clearable placeholder="—" style="width: 100%">
                  <el-option v-for="b in BRANDS" :key="b.value" :label="b.label" :value="b.value" />
                </el-select>
                <div v-if="subjectHasBrand(row.subject) && !row.brand" class="voucher-brand-warn">请选择品牌</div>
              </template>
            </el-table-column>
            <el-table-column label="客户/供应商" width="140">
              <template #default="{ row }">
                <el-select v-model="row.partner" clearable filterable placeholder="—" style="width: 100%">
                  <el-option v-for="p in PARTNERS" :key="p.value" :label="p.label" :value="p.value" />
                </el-select>
              </template>
            </el-table-column>
            <el-table-column label="摘要" min-width="150">
              <template #default="{ row }">
                <el-input v-model="row.abstract" placeholder="摘要" maxlength="50" />
              </template>
            </el-table-column>
            <el-table-column label="借方金额" width="120" align="right">
              <template #default="{ row }">
                <el-input-number v-model="row.debit" :min="0" :precision="2" :controls="false" style="width: 100%" @change="onEntryAmountChange(row, 'debit')" />
              </template>
            </el-table-column>
            <el-table-column label="贷方金额" width="120" align="right">
              <template #default="{ row }">
                <el-input-number v-model="row.credit" :min="0" :precision="2" :controls="false" style="width: 100%" @change="onEntryAmountChange(row, 'credit')" />
              </template>
            </el-table-column>
            <el-table-column label="操作" width="60" align="center">
              <template #default="{ $index }">
                <el-button link type="danger" size="small" :disabled="form.items.length <= 2" @click="removeEntry($index)">删除</el-button>
              </template>
            </el-table-column>
            <template #empty>
              <el-button link type="primary" size="small" @click="addEntry">+ 添加分录</el-button>
            </template>
          </el-table>
          <div class="voucher-dialog__entries-foot">
            <el-button link type="primary" size="small" @click="addEntry">+ 添加分录</el-button>
            <span class="voucher-dialog__sum">
              借方合计 <b class="tabular">¥{{ fmtMoney(debitTotal) }}</b>
              <span class="voucher-dialog__sum-sep">/</span>
              贷方合计 <b class="tabular" :class="{ 'voucher-sum--error': !balanced }">¥{{ fmtMoney(creditTotal) }}</b>
              <span v-if="!balanced" class="voucher-dialog__sum-tip">借贷不平，禁止保存</span>
            </span>
          </div>
        </div>
      </el-form>

      <template #footer>
        <el-button size="small" @click="dialogVisible = false">关闭</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  VOUCHER_TYPES,
  DEPARTMENTS,
  EMPLOYEES,
  BRANDS,
  PARTNERS,
  VOUCHER_STATUS,
  DEPT_LABEL,
  BRAND_LABEL,
  PARTNER_LABEL,
  EMP_LABEL,
  SUBJECT_TREE,
  subjectHasBrand,
  fmtMoney,
  today,
  mockVouchers,
  mockSubjectTree,
} from '@/mock/voucher.js'

/* ---------- 数据 ---------- */
const loading = ref(false)
const vouchers = ref([]) // 凭证头数组（内存态，全部来自 mock）
const subjectTree = ref([])
const activeVoucher = ref(null) // 工具条「打印」的目标（最近点击行所属凭证）

const search = reactive({
  no: '',
  type: '',
  dateRange: null,
  subject: '',
  department: '',
  employee: '',
  status: '',
  includeReversed: false,
})

onMounted(() => {
  vouchers.value = mockVouchers()
  subjectTree.value = mockSubjectTree()
})

/* ---------- 筛选 ---------- */
function subjectDescendantCodes(code) {
  const out = new Set([code])
  const walk = (nodes) => {
    ;(nodes || []).forEach((n) => {
      if (code === n.value || out.has(n.value)) {
        const collect = (m) => {
          out.add(m.value)
          ;(m.children || []).forEach(collect)
        }
        collect(n)
      }
      walk(n.children)
    })
  }
  walk(SUBJECT_TREE)
  return out
}

const filteredVouchers = computed(() => {
  return vouchers.value.filter((v) => {
    if (!search.includeReversed && v.status === 'reversed') return false
    if (search.type && v.voucher_type !== search.type) return false
    if (search.department && v.department !== search.department) return false
    if (search.employee && v.employee !== search.employee) return false
    if (search.status && v.status !== search.status) return false
    if (search.no && !v.no.includes(search.no.trim())) return false
    if (search.dateRange && search.dateRange.length === 2) {
      if (v.date < search.dateRange[0] || v.date > search.dateRange[1]) return false
    }
    if (search.subject) {
      const codes = subjectDescendantCodes(search.subject)
      if (!v.items.some((it) => codes.has(it.subject))) return false
    }
    return true
  })
})

/* 分录级行（同凭证并列，组间分割） */
const entryRows = computed(() => {
  const rows = []
  filteredVouchers.value.forEach((v) => {
    v.items.forEach((it, i) => {
      rows.push({
        ...it,
        no: v.no,
        date: v.date,
        status: v.status,
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
  /* 计算属性已响应 search，此处保留显式入口（查询按钮/回车） */
}

function resetSearch() {
  Object.assign(search, {
    no: '',
    type: '',
    dateRange: null,
    subject: '',
    department: '',
    employee: '',
    status: '',
    includeReversed: false,
  })
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
  const header = ['凭证号', '凭证字', '日期', '科目编码', '科目名称', '品牌', '客户/供应商', '摘要', '借方金额', '贷方金额', '状态']
  const lines = entryRows.value.map((r) => [
    r.no,
    r._voucher.voucher_type,
    r.date,
    r.subject,
    r.subject_name,
    r.brand ? BRAND_LABEL[r.brand] || r.brand : '',
    r.partner ? PARTNER_LABEL[r.partner] || r.partner : '',
    r.abstract,
    r.debit || '',
    r.credit || '',
    VOUCHER_STATUS[r.status].label,
  ])
  const sums = lines.reduce((acc, r) => ({ d: acc.d + Number(r[8] || 0), c: acc.c + Number(r[9] || 0) }), { d: 0, c: 0 })
  lines.push(['合计', '', '', '', '', '', '', '', sums.d, sums.c, ''])
  const csv = [header, ...lines].map((r) => r.map((c) => '"' + String(c).replace(/"/g, '""') + '"').join(',')).join('\r\n')
  saveBlob(new Blob(['\ufeff' + csv], { type: 'text/csv;charset=utf-8' }), '财务凭证.csv')
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
        (it.brand ? BRAND_LABEL[it.brand] || it.brand : '-') +
        '</td><td>' +
        (it.partner ? PARTNER_LABEL[it.partner] || it.partner : '-') +
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
    '</span><span>部门：' +
    (DEPT_LABEL[v.department] || '-') +
    '</span><span>经手人：' +
    (EMP_LABEL[v.employee] || '-') +
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

/* ---------- 新增/编辑/查看弹窗 ---------- */
const dialogVisible = ref(false)
const mode = ref('view') // 查询视图：仅 view；录入/审核走 ledger 模块（BUG-10）
const editingId = ref('')

const emptyForm = () => ({
  no: '',
  voucher_type: '记',
  date: today(),
  department: '',
  employee: '',
  attachment_count: 0,
  auto_audit: false,
  remark: '',
  status: 'draft',
  items: [],
})

const form = ref(emptyForm())

const dialogTitle = computed(() => (mode.value === 'create' ? '新增凭证' : mode.value === 'edit' ? '编辑凭证' : '查看凭证'))

const debitTotal = computed(() => form.value.items.reduce((s, it) => s + Number(it.debit || 0), 0))
const creditTotal = computed(() => form.value.items.reduce((s, it) => s + Number(it.credit || 0), 0))
const balanced = computed(() => debitTotal.value > 0 && debitTotal.value === creditTotal.value)

function newEntry() {
  return { subject: '', subject_name: '', brand: '', partner: '', abstract: '', debit: 0, credit: 0 }
}

function addEntry() {
  form.value.items.push(newEntry())
}

function removeEntry(i) {
  if (form.value.items.length <= 2) return
  form.value.items.splice(i, 1)
}

function onEntrySubjectChange(row) {
  const node = findSubjectNode(SUBJECT_TREE, row.subject)
  row.subject_name = node ? node.label : ''
  if (!subjectHasBrand(row.subject)) row.brand = ''
}

function findSubjectNode(nodes, value) {
  for (const n of nodes || []) {
    if (n.value === value) return n
    const hit = findSubjectNode(n.children, value)
    if (hit) return hit
  }
  return null
}

function onEntryAmountChange(row, field) {
  /* 同行借贷只填一个 */
  if (Number(row[field] || 0) > 0) {
    if (field === 'debit') row.credit = 0
    else row.debit = 0
  }
}

function entryRowClassName({ row }) {
  return subjectHasBrand(row.subject) && !row.brand ? 'voucher-entry--brand-missing' : ''
}

function disableFuture(date) {
  return date && date.getTime() > Date.now()
}

function openView(v) {
  mode.value = 'view'
  editingId.value = v.id
  form.value = {
    no: v.no,
    voucher_type: v.voucher_type,
    date: v.date,
    department: v.department,
    employee: v.employee,
    attachment_count: v.attachment_count,
    auto_audit: v.auto_audit,
    remark: v.remark,
    status: v.status,
    items: v.items.map((it) => ({ ...it })),
  }
  dialogVisible.value = true
}

function onDialogClosed() {
  form.value = emptyForm()
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
.voucher-no--reversed {
  color: #f56c6c;
}
.voucher-amt--reversed {
  color: #f56c6c;
}

/* 弹窗 */
.voucher-dialog__head {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  column-gap: 12px;
}
.voucher-dialog__remark {
  grid-column: span 3;
}
.voucher-dialog__entries {
  margin-top: 4px;
}
.voucher-dialog__entries :deep(.el-table__body td) {
  height: 34px;
  padding: 2px 0;
}
.voucher-dialog__entries-foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-top: 8px;
}
.voucher-dialog__sum {
  font-size: 13px;
  color: #606266;
}
.voucher-dialog__sum b {
  color: #303133;
}
.voucher-dialog__sum-sep {
  margin: 0 12px;
  color: #dcdfe6;
}
.voucher-sum--error {
  color: #f56c6c !important;
}
.voucher-brand-warn {
  font-size: 12px;
  color: #f56c6c;
  line-height: 1.2;
  padding-top: 1px;
}
.voucher-dialog__sum-tip {
  margin-left: 12px;
  font-size: 12px;
  color: #f56c6c;
}
/* 挂品牌辅助核算但未选品牌：12px 红字提示 */
.voucher-entry--brand-missing :deep(.el-table__cell .el-select) {
  position: relative;
}
</style>
