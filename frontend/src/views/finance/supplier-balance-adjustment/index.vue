<template>
	<div class="bal-page fin-page">
		<!-- 筛选卡 -->
		<div class="bal-filter fin-filter">
			<span class="label">供应商</span>
			<el-select v-model="search.partner_id" clearable filterable placeholder="全部供应商" size="small" style="width: 180px" @change="onSearch">
				<el-option v-for="p in partners" :key="p.value" :label="p.label" :value="p.value" />
			</el-select>
			<span class="label">状态</span>
			<el-select v-model="search.status" clearable placeholder="全部状态" size="small" style="width: 130px" @change="onSearch">
				<el-option value="">全部</el-option>
				<el-option v-for="(v, k) in ADJUST_STATUS" :key="k" :label="v.label" :value="k" />
			</el-select>
			<span class="label">单据号</span>
			<el-input v-model="search.keyword" clearable placeholder="单据号 / 供应商名" size="small" style="width: 180px" @keyup.enter="onSearch" @clear="onSearch" />
			<span class="label">日期范围</span>
			<el-date-picker
				v-model="search.daterange"
				type="daterange"
				size="small"
				style="width: 240px"
				start-placeholder="开始日期"
				end-placeholder="结束日期"
				value-format="YYYY-MM-DD"
				@change="onSearch"
			/>
			<div class="filter-actions fin-toolbar">
				<el-button type="primary" @click="onSearch">查询</el-button>
				<el-button @click="resetSearch">重置</el-button>
			</div>
		</div>

		<!-- 工具条 -->
		<div class="bal-toolbar fin-toolbar">
			<span class="toolbar-title">供应商余额调整单</span>
			<el-button type="primary" v-permission="'fin:balance:adjust'" @click="openCreate">+ 新增调整单</el-button>
		</div>

		<!-- 表格区 -->
		<div class="bal-content">
			<el-table :data="rows" border stripe height="100%" v-loading="loading" :show-summary="true" :summary-method="summaryMethod">
				<el-table-column prop="no" label="单据号" width="150" align="left">
					<template #default="{ row }">
						<span class="link tabular" @click="openView(row)">{{ row.no }}</span>
					</template>
				</el-table-column>
				<el-table-column prop="partner_name" label="供应商" min-width="160" align="left">
					<template #default="{ row }">{{ row.partner_name || '-' }}</template>
				</el-table-column>
				<el-table-column prop="amount" label="调整金额" width="130" align="right">
					<template #default="{ row }">
						<span class="tabular" :class="{ 'text-danger': row.amount < 0 }">{{ fmtMoney(row.amount) }}</span>
					</template>
				</el-table-column>
				<!-- 调整单三段展示：期初 / 当前 / 调整后 -->
				<el-table-column prop="opening_balance" label="期初余额" width="130" align="right">
					<template #default="{ row }">
						<span class="tabular">{{ fmtMoney(row.opening_balance) }}</span>
					</template>
				</el-table-column>
				<el-table-column prop="current_balance" label="当前余额" width="130" align="right">
					<template #default="{ row }">
						<span class="tabular">{{ fmtMoney(row.current_balance) }}</span>
					</template>
				</el-table-column>
				<el-table-column prop="adjusted_balance" label="调整后余额" width="130" align="right">
					<template #default="{ row }">
						<span class="tabular" :class="deltaClass(row)">{{ fmtMoney(row.adjusted_balance) }}</span>
					</template>
				</el-table-column>
				<el-table-column prop="reason" label="调整原因" min-width="160" align="left" show-overflow-tooltip>
					<template #default="{ row }">{{ row.reason || '-' }}</template>
				</el-table-column>
				<el-table-column label="制单日期" width="110" align="center">
					<template #default="{ row }"><span class="tabular">{{ fmtDate(row.date) }}</span></template>
				</el-table-column>
				<el-table-column prop="status" label="状态" width="100" align="center">
					<template #default="{ row }">
						<el-tag size="small" :type="ADJUST_STATUS[row.status]?.tag || 'info'">{{ ADJUST_STATUS[row.status]?.label || row.status }}</el-tag>
					</template>
				</el-table-column>
				<el-table-column label="操作" width="180" align="center" fixed="right">
					<template #default="{ row }">
						<el-button link type="primary" size="small" @click="openView(row)">查看</el-button>
						<template v-if="row.status === 'pending'">
							<el-button link type="success" size="small" @click="handleAudit(row)">审核</el-button>
							<el-button link type="danger" size="small" @click="handleReject(row)">驳回</el-button>
						</template>
					</template>
				</el-table-column>
				<template #empty>
					<FinEmpty />
				</template>
			</el-table>
		</div>

		<!-- 分页 -->
		<div class="bal-pager fin-pager">
			<el-pagination background layout="total, prev, pager, next" :total="total" :page-size="pageSize" :current-page="page" @current-change="onPageChange" />
		</div>

		<!-- 新增 Dialog -->
		<el-dialog v-model="createVisible" title="新增供应商余额调整单" width="680px" class="fin-dialog" :close-on-click-modal="false" destroy-on-close>
			<el-form :model="createForm" label-width="110px">
				<div class="dialog-body">
					<el-form-item label="供应商" required>
						<el-select v-model="createForm.partner_id" filterable placeholder="选择供应商" style="width: 100%" @change="onPartnerPick">
							<el-option v-for="p in partners" :key="p.value" :label="p.label" :value="p.value" />
						</el-select>
					</el-form-item>
					<el-form-item label="调整金额" required>
						<el-input-number
							v-model="createForm.amount"
							:step="100"
							:precision="2"
							:controls-position="'right'"
							style="width: 100%"
						/>
					</el-form-item>
					<el-form-item label="调整日期" required>
						<el-date-picker v-model="createForm.doc_date" type="date" value-format="YYYY-MM-DD" style="width: 100%" />
					</el-form-item>
					<!-- 调整原因必填：后端无 reason 直接 422 -->
					<el-form-item label="调整原因" required class="full-width">
						<el-input v-model="createForm.reason" type="textarea" :rows="2" maxlength="255" show-word-limit placeholder="必填，如付款折让、对账差异、汇差调整等" />
					</el-form-item>
					<el-form-item label="摘要" class="full-width">
						<el-input v-model="createForm.abstract" placeholder="请输入摘要" style="width: 100%" />
					</el-form-item>
					<div class="amount-hint full-width">调整金额正数为调增供应商余额，负数为调减；调减超过当前往来余额时审核将被阻断。</div>
				</div>
			</el-form>
			<template #footer>
				<el-button size="small" @click="createVisible = false">取消</el-button>
				<el-button type="primary" size="small" :loading="saving" @click="saveCreate">保存</el-button>
			</template>
		</el-dialog>

		<!-- 查看 Dialog -->
		<el-dialog v-model="viewVisible" title="供应商余额调整单详情" width="680px" class="fin-dialog" :close-on-click-modal="false" destroy-on-close>
			<template v-if="viewRow">
				<!-- 三段展示：期初 → 当前 → 调整后 -->
				<div class="balance-strip">
					<div class="strip-cell">
						<div class="strip-label">期初余额</div>
						<div class="strip-value tabular">{{ fmtMoney(viewRow.opening_balance) }}</div>
					</div>
					<div class="strip-arrow">→</div>
					<div class="strip-cell">
						<div class="strip-label">当前余额</div>
						<div class="strip-value tabular">{{ fmtMoney(viewRow.current_balance) }}</div>
					</div>
					<div class="strip-arrow">→</div>
					<div class="strip-cell" :class="deltaClass(viewRow) === 'text-danger' ? 'is-down' : 'is-up'">
						<div class="strip-label">调整后余额</div>
						<div class="strip-value tabular">{{ fmtMoney(viewRow.adjusted_balance) }}</div>
					</div>
				</div>

				<el-form label-width="110px" class="view-form">
					<div class="dialog-body">
						<el-form-item label="单据号">
							<span class="tabular">{{ viewRow.no }}</span>
						</el-form-item>
						<el-form-item label="供应商">{{ viewRow.partner_name || '-' }}</el-form-item>
						<el-form-item label="调整金额">
							<span class="tabular" :class="{ 'text-danger': viewRow.amount < 0 }">{{ fmtMoney(viewRow.amount) }}</span>
						</el-form-item>
						<el-form-item label="调整日期">
							<span class="tabular">{{ fmtDate(viewRow.date) }}</span>
						</el-form-item>
						<el-form-item label="状态">
							<el-tag size="small" :type="ADJUST_STATUS[viewRow.status]?.tag || 'info'">{{
								ADJUST_STATUS[viewRow.status]?.label || viewRow.status
							}}</el-tag>
						</el-form-item>
						<el-form-item label="资金流水">
							<span class="tabular">{{ viewRow.cash_flow_no || '-' }}</span>
						</el-form-item>
						<el-form-item label="调整原因" class="full-width">{{ viewRow.reason || '-' }}</el-form-item>
						<el-form-item label="摘要" class="full-width">{{ viewRow.abstract || '-' }}</el-form-item>
						<el-form-item label="驳回原因" v-if="viewRow.status === 'rejected'" class="full-width">
							<span class="text-danger">{{ viewRow.reject_reason || '-' }}</span>
						</el-form-item>
					</div>
				</el-form>
			</template>
			<template #footer>
				<el-button size="small" @click="viewVisible = false">关闭</el-button>
			</template>
		</el-dialog>
	</div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import businessApi from '@/api/business'
import FinEmpty from '../_FinEmpty.vue'

const DOC_TYPE = 'supplier_balance'
const PARTNER_TYPE = 'supplier'
const PARTNER_LABEL = '供应商'
const ADJUST_STATUS = {
	pending: { label: '待审核', tag: 'warning' },
	approved: { label: '已审核', tag: 'success' },
	rejected: { label: '已驳回', tag: 'danger' },
}

// 金额千分位 + 两位小数（DS：金额右对齐 tabular-nums）
const fmtMoney = (v) => Number(v || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const fmtDate = (v) => (v ? String(v).slice(0, 10) : '-')
const today = () => new Date().toISOString().slice(0, 10)
// 调整后余额相对当前余额的着色：调减用危险色，调增用主色
const deltaClass = (row) => ((row.adjusted_balance ?? 0) < (row.current_balance ?? 0) ? 'text-danger' : 'text-primary')

const loading = ref(false)
const saving = ref(false)
const page = ref(1)
const pageSize = 20
const rows = ref([])
const total = ref(0)
const partners = ref([])

const search = reactive({
	partner_id: '',
	status: '',
	keyword: '',
	daterange: [],
})

// 供应商下拉取真实供应商档案
const loadPartners = async () => {
	try {
		const res = await businessApi.supplier.list.get({ page_size: 200 })
		const list = res?.data?.list ?? res?.data?.data?.list ?? []
		partners.value = list.map((s) => ({ value: s.id, label: s.name }))
	} catch {
		partners.value = []
	}
}

// 分页 / 筛选全部下传后端，前端不再本地过滤
const refresh = async () => {
	loading.value = true
	try {
		const params = {
			status: search.status || undefined,
			partner_id: search.partner_id || undefined,
			keyword: search.keyword || undefined,
			page: page.value,
			page_size: pageSize,
		}
		if (search.daterange?.length === 2) {
			params.start_date = search.daterange[0]
			params.end_date = search.daterange[1]
		}
		const res = await businessApi.finance.fundOrder.list.get(DOC_TYPE, params)
		const data = res?.data?.data ?? res?.data ?? {}
		rows.value = data.list || []
		total.value = data.total || 0
	} catch {
		rows.value = []
		total.value = 0
	} finally {
		loading.value = false
	}
}

const onSearch = () => {
	page.value = 1
	refresh()
}
const onPageChange = (p) => {
	page.value = p
	refresh()
}
const resetSearch = () => {
	search.partner_id = ''
	search.status = ''
	search.keyword = ''
	search.daterange = []
	onSearch()
}

const summaryMethod = ({ columns }) => {
	const sum = rows.value.reduce((s, r) => s + Number(r.amount || 0), 0)
	return columns.map((col, i) => {
		if (i === 0) return '合计'
		if (col.property === 'amount') return fmtMoney(sum)
		return ''
	})
}

/* ---------- 审核 / 驳回 ---------- */

const handleAudit = async (row) => {
	try {
		await ElMessageBox.confirm(`确认审核调整单 ${row.no}？审核通过将同步调整供应商应付余额。`, '审核确认', { type: 'warning' })
	} catch {
		return
	}
	try {
		await businessApi.finance.fundOrder.approve.post(DOC_TYPE, row.id)
		ElMessage.success('审核成功')
		refresh()
	} catch {
		// 余额不足等后端中文提示由拦截器统一弹出，此处仅刷新
		refresh()
	}
}

const handleReject = async (row) => {
	let reason
	try {
		const { value } = await ElMessageBox.prompt('请输入驳回原因', '驳回确认', {
			confirmButtonText: '确定',
			cancelButtonText: '取消',
			inputValue: '',
		})
		reason = value
	} catch {
		return
	}
	if (!reason) return ElMessage.warning('请填写驳回原因')
	try {
		await businessApi.finance.fundOrder.reject.post(DOC_TYPE, row.id, { reason })
		ElMessage.success('已驳回')
		refresh()
	} catch (e) {
		if (e?.message) ElMessage.error(e.message)
	}
}

/* ---------- 新增 Dialog ---------- */
const createVisible = ref(false)
const emptyForm = () => ({
	partner_id: '',
	partner_name: '',
	amount: 0,
	doc_date: today(),
	reason: '',
	abstract: '',
})
const createForm = reactive(emptyForm())

const openCreate = () => {
	Object.assign(createForm, emptyForm())
	createVisible.value = true
}

const onPartnerPick = (id) => {
	createForm.partner_name = partners.value.find((p) => p.value === id)?.label || ''
}

const saveCreate = async () => {
	if (!createForm.partner_id || !createForm.doc_date) {
		return ElMessage.error('请填写必填项')
	}
	if (!createForm.amount) {
		return ElMessage.error('调整金额不可为 0')
	}
	if (!createForm.reason?.trim()) {
		return ElMessage.warning(`请填写${PARTNER_LABEL}余额调整原因`)
	}
	saving.value = true
	try {
		const res = await businessApi.finance.fundOrder.add.post(DOC_TYPE, {
			partner_type: PARTNER_TYPE,
			partner_id: createForm.partner_id,
			partner_name: createForm.partner_name,
			amount: createForm.amount,
			doc_date: createForm.doc_date,
			reason: createForm.reason.trim(),
			abstract: createForm.abstract,
		})
		const no = res?.data?.data?.no || ''
		ElMessage.success(`保存成功，单据号：${no}，状态：待审核`)
		createVisible.value = false
		onSearch()
	} catch (e) {
		if (e?.message) ElMessage.error(e.message)
	} finally {
		saving.value = false
	}
}

/* ---------- 查看 Dialog ---------- */
const viewVisible = ref(false)
const viewRow = ref(null)

const openView = async (row) => {
	viewRow.value = row
	viewVisible.value = true
	try {
		const res = await businessApi.finance.fundOrder.detail.get(DOC_TYPE, row.id)
		viewRow.value = res?.data?.data ?? row
	} catch {
		// 列表行已有三段余额快照，详情失败不影响展示
	}
}

onMounted(() => {
	loadPartners()
	refresh()
})
</script>

<style>
@import '../_shared.css';
</style>

<style scoped>
.bal-page {
	height: 100%;
	display: flex;
	flex-direction: column;
	overflow: hidden;
}
.bal-filter {
	display: flex;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
}
.filter-actions {
	margin-left: auto;
	display: flex;
	gap: 12px;
}
.bal-toolbar {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 12px 20px;
	background: var(--bg-card);
}
.toolbar-title {
	font-size: var(--font-h3);
	font-weight: 600;
	color: var(--text-primary);
}
.bal-content {
	flex: 1;
	min-height: 0;
	padding: 0 12px;
}
.bal-pager {
	padding: 0 12px;
}
.link {
	color: var(--color-primary);
	cursor: pointer;
}
.text-danger {
	color: var(--color-danger);
}
.text-primary {
	color: var(--color-primary);
}

/* 表格样式对齐卷00（表头/行高/斑马纹由 _shared.css 提供） */
:deep(.el-table) {
	font-size: var(--font-base);
	color: var(--text-primary);
}
:deep(.el-table td.el-table__cell),
:deep(.el-table th.el-table__cell) {
	border-bottom-color: var(--border-lighter);
}
:deep(.el-table__footer td) {
	background: var(--bg-hover);
	color: var(--text-primary);
	font-weight: 600;
}

/* 三段余额展示条 */
.balance-strip {
	display: flex;
	align-items: stretch;
	gap: 8px;
	margin-bottom: 20px;
}
.strip-cell {
	flex: 1;
	padding: 12px 16px;
	border: 1px solid var(--border-lighter);
	border-radius: 4px;
	background: var(--bg-hover);
}
.strip-cell.is-up {
	background: var(--color-primary-light);
	border-color: var(--color-primary-light);
}
.strip-cell.is-down {
	background: #fef0f0;
	border-color: #fef0f0;
}
.strip-label {
	font-size: 14px;
	color: var(--text-regular);
	margin-bottom: 6px;
}
.strip-value {
	font-size: 16px;
	font-weight: 600;
	text-align: right;
	color: var(--text-primary);
}
.strip-cell.is-up .strip-value {
	color: var(--color-primary);
}
.strip-cell.is-down .strip-value {
	color: var(--color-danger);
}
.strip-arrow {
	align-self: center;
	font-size: 16px;
	color: var(--text-secondary);
}

/* Dialog 样式：一行 3 列（B4 统一弹窗规范） */
.dialog-body {
	display: grid;
	grid-template-columns: repeat(3, 1fr);
	gap: 16px;
}
.dialog-body :deep(.el-input-number .el-input__inner) {
	text-align: right;
}
.full-width {
	grid-column: span 3;
}
.amount-hint {
	font-size: 14px;
	color: var(--text-secondary);
	line-height: 20px;
	margin-top: -8px;
}
.view-form :deep(.el-form-item__label) {
	color: var(--text-secondary);
}
</style>
