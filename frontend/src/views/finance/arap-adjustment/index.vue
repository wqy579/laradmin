<template>
	<div class="arap-page fin-page">
		<!-- 筛选卡 -->
		<div class="arap-filter fin-filter">
			<span class="label">往来类型</span>
			<el-select v-model="search.partner_type" placeholder="全部" size="small" style="width: 110px" @change="onFilterTypeChange">
				<el-option label="客户" value="customer" />
				<el-option label="供应商" value="supplier" />
			</el-select>
			<span class="label">往来单位</span>
			<el-select
				v-model="search.partner_id"
				clearable
				filterable
				placeholder="全部单位"
				size="small"
				style="width: 180px"
				@change="onSearch"
			>
				<el-option v-for="p in filterPartners" :key="p.value" :label="p.label" :value="p.value" />
			</el-select>
			<span class="label">状态</span>
			<el-select v-model="search.status" clearable placeholder="全部状态" size="small" style="width: 130px" @change="onSearch">
				<el-option value="">全部</el-option>
				<el-option v-for="(v, k) in ADJUST_STATUS" :key="k" :label="v.label" :value="k" />
			</el-select>
			<span class="label">单据号</span>
			<el-input
				v-model="search.keyword"
				clearable
				placeholder="单据号 / 单位名"
				size="small"
				style="width: 180px"
				@keyup.enter="onSearch"
				@clear="onSearch"
			/>
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
		<div class="arap-toolbar fin-toolbar">
			<span class="toolbar-title">应收应付调整单</span>
			<el-button type="primary" v-permission="'fin:arap:adjust'" @click="openCreate">+ 新增调整单</el-button>
		</div>

		<!-- 表格区 -->
		<div class="arap-content">
			<el-table :data="rows" border stripe height="100%" v-loading="loading" :show-summary="true" :summary-method="summaryMethod">
				<el-table-column prop="no" label="单据号" width="150" align="left">
					<template #default="{ row }">
						<span class="link tabular" @click="openView(row)">{{ row.no }}</span>
					</template>
				</el-table-column>
				<el-table-column prop="partner_name" label="往来单位" min-width="160" align="left">
					<template #default="{ row }">
						<el-tag size="small" effect="plain" class="ptype-tag">{{ PARTNER_TYPE_LABEL[row.partner_type] || '-' }}</el-tag>
						{{ row.partner_name || '-' }}
					</template>
				</el-table-column>
				<el-table-column prop="amount" label="调整金额" width="130" align="right">
					<template #default="{ row }">
						<span class="tabular">{{ fmtMoney(row.amount) }}</span>
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
				<el-table-column prop="reason" label="调整原因" min-width="150" align="left" show-overflow-tooltip>
					<template #default="{ row }">{{ row.reason || '-' }}</template>
				</el-table-column>
				<el-table-column label="调整日期" width="110" align="center">
					<template #default="{ row }"
						><span class="tabular">{{ fmtDate(row.date) }}</span></template
					>
				</el-table-column>
				<el-table-column prop="status" label="状态" width="100" align="center">
					<template #default="{ row }">
						<el-tag size="small" :type="ADJUST_STATUS[row.status]?.tag || 'info'">{{
							ADJUST_STATUS[row.status]?.label || row.status
						}}</el-tag>
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
		<div class="arap-pager fin-pager">
			<el-pagination
				background
				layout="total, prev, pager, next"
				:total="total"
				:page-size="pageSize"
				:current-page="page"
				@current-change="onPageChange"
			/>
		</div>

		<!-- 新增 Dialog -->
		<el-dialog v-model="createVisible" title="新增应收应付调整单" width="900px" class="fin-dialog" :close-on-click-modal="false" destroy-on-close>
			<el-form :model="createForm" label-width="110px">
				<div class="dialog-head">
					<el-form-item label="往来类型" required>
						<el-select v-model="createForm.partner_type" style="width: 100%" @change="onCreateTypeChange">
							<el-option label="客户" value="customer" />
							<el-option label="供应商" value="supplier" />
						</el-select>
					</el-form-item>
					<el-form-item label="往来单位" required>
						<el-select v-model="createForm.partner_id" filterable placeholder="选择往来单位" style="width: 100%" @change="onPartnerPick">
							<el-option v-for="p in createPartners" :key="p.value" :label="p.label" :value="p.value" />
						</el-select>
					</el-form-item>
					<el-form-item label="调整日期" required>
						<el-date-picker v-model="createForm.doc_date" type="date" value-format="YYYY-MM-DD" style="width: 100%" />
					</el-form-item>
					<el-form-item label="调整金额">
						<span class="tabular sum-amount">¥{{ fmtMoney(debitTotal) }}</span>
					</el-form-item>
					<el-form-item label="币种">
						<el-input v-model="createForm.currency" readonly style="width: 100%" />
					</el-form-item>
					<el-form-item label="摘要">
						<el-input v-model="createForm.abstract" placeholder="请输入摘要" style="width: 100%" />
					</el-form-item>
					<!-- 调整原因必填：后端无 reason 直接 422 -->
					<el-form-item label="调整原因" required class="full-width">
						<el-input
							v-model="createForm.reason"
							type="textarea"
							:rows="2"
							maxlength="255"
							show-word-limit
							placeholder="必填，如应收转应付、科目重分类等"
						/>
					</el-form-item>
				</div>

				<div class="dialog-entries">
					<div class="entries-title">明细行</div>
					<el-table :data="createForm.items" border stripe size="small" class="fin-table--dense">
						<el-table-column label="科目" min-width="200">
							<template #default="{ row }">
								<el-select
									v-model="row.subject_code"
									filterable
									placeholder="选择科目"
									style="width: 100%"
									@change="(code) => onSubjectPick(row, code)"
								>
									<el-option v-for="s in subjectOptions" :key="s.id" :label="s.label" :value="s.code" />
								</el-select>
							</template>
						</el-table-column>
						<el-table-column label="方向" width="120" align="center">
							<template #default="{ row }">
								<el-radio-group v-model="row.direction" size="small">
									<el-radio-button value="借">借</el-radio-button>
									<el-radio-button value="贷">贷</el-radio-button>
								</el-radio-group>
							</template>
						</el-table-column>
						<el-table-column label="金额" width="150" align="right">
							<template #default="{ row }">
								<el-input-number v-model="row.amount" :min="0" :precision="2" :controls="false" style="width: 100%" />
							</template>
						</el-table-column>
						<el-table-column label="摘要" min-width="150">
							<template #default="{ row }">
								<el-input v-model="row.abstract" placeholder="行摘要" style="width: 100%" />
							</template>
						</el-table-column>
						<el-table-column label="操作" width="60" align="center">
							<template #default="{ $index }">
								<el-button link type="danger" size="small" :disabled="createForm.items.length <= 1" @click="removeRow($index)"
									>删除</el-button
								>
							</template>
						</el-table-column>
					</el-table>
					<div class="entries-footer">
						<el-button link type="primary" size="small" @click="addRow">+ 添加行</el-button>
						<div class="total-sum">
							借合计：<b class="tabular">¥{{ fmtMoney(debitTotal) }}</b> <span class="sum-sep" />贷合计：<b
								class="tabular"
								:class="{ 'text-danger': !balanced }"
								>¥{{ fmtMoney(creditTotal) }}</b
							>
							<span v-if="!balanced" class="unbalance-hint">借贷不平衡，无法保存</span>
						</div>
					</div>
				</div>
			</el-form>
			<template #footer>
				<el-button size="small" @click="createVisible = false">取消</el-button>
				<el-button type="primary" size="small" :loading="saving" @click="saveCreate">保存</el-button>
			</template>
		</el-dialog>

		<!-- 查看 Dialog -->
		<el-dialog v-model="viewVisible" title="应收应付调整单详情" width="900px" class="fin-dialog" :close-on-click-modal="false" destroy-on-close>
			<template v-if="viewRow">
				<!-- 三段展示：期初 → 当前 → 调整后 -->
				<div class="balance-strip">
					<div class="strip-cell">
						<div class="strip-label">期初余额</div>
						<div class="strip-value tabular">
							{{ fmtMoney(viewRow.opening_balance) }}
						</div>
					</div>
					<div class="strip-arrow">→</div>
					<div class="strip-cell">
						<div class="strip-label">当前余额</div>
						<div class="strip-value tabular">
							{{ fmtMoney(viewRow.current_balance) }}
						</div>
					</div>
					<div class="strip-arrow">→</div>
					<div class="strip-cell" :class="deltaClass(viewRow) === 'text-danger' ? 'is-down' : 'is-up'">
						<div class="strip-label">调整后余额</div>
						<div class="strip-value tabular">
							{{ fmtMoney(viewRow.adjusted_balance) }}
						</div>
					</div>
				</div>

				<el-form label-width="110px" class="view-form">
					<div class="dialog-body">
						<el-form-item label="单据号">
							<span class="tabular">{{ viewRow.no }}</span>
						</el-form-item>
						<el-form-item label="往来单位">
							<el-tag size="small" effect="plain" class="ptype-tag">{{ PARTNER_TYPE_LABEL[viewRow.partner_type] || '-' }}</el-tag>
							{{ viewRow.partner_name || '-' }}
						</el-form-item>
						<el-form-item label="调整金额">
							<span class="tabular">{{ fmtMoney(viewRow.amount) }}</span>
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
						<el-form-item label="调整凭证">
							<el-tag v-if="viewRow.voucher_id" size="small" type="success">已生成</el-tag>
							<span v-else>-</span>
						</el-form-item>
						<el-form-item label="调整原因" class="full-width">{{ viewRow.reason || '-' }}</el-form-item>
						<el-form-item label="摘要" class="full-width">{{ viewRow.abstract || '-' }}</el-form-item>
						<el-form-item label="驳回原因" v-if="viewRow.status === 'rejected'" class="full-width">
							<span class="text-danger">{{ viewRow.reject_reason || '-' }}</span>
						</el-form-item>
					</div>
				</el-form>

				<div class="dialog-entries">
					<div class="entries-title">明细行</div>
					<el-table :data="viewRow.items || []" border stripe size="small" class="fin-table--dense">
						<el-table-column label="科目" min-width="200">
							<template #default="{ row }">
								<span class="tabular">{{ row.subject_code }}</span>
								{{ row.subject }}
							</template>
						</el-table-column>
						<el-table-column label="方向" width="80" align="center" prop="direction" />
						<el-table-column label="金额" width="150" align="right">
							<template #default="{ row }">
								<span class="tabular">{{ fmtMoney(row.amount) }}</span>
							</template>
						</el-table-column>
						<el-table-column label="摘要" min-width="150" prop="abstract" show-overflow-tooltip />
					</el-table>
				</div>
			</template>
			<template #footer>
				<el-button size="small" @click="viewVisible = false">关闭</el-button>
			</template>
		</el-dialog>
	</div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import businessApi from '@/api/business'
import ledgerApi from '@/api/ledger'
import FinEmpty from '../_FinEmpty.vue'

const DOC_TYPE = 'arap_adjust'
const PARTNER_TYPE_LABEL = { customer: '客户', supplier: '供应商' }
const ADJUST_STATUS = {
	pending: { label: '待审核', tag: 'warning' },
	approved: { label: '已审核', tag: 'success' },
	rejected: { label: '已驳回', tag: 'danger' },
}

// 金额千分位 + 两位小数（DS：金额右对齐 tabular-nums）
const fmtMoney = (v) =>
	Number(v || 0).toLocaleString('zh-CN', {
		minimumFractionDigits: 2,
		maximumFractionDigits: 2,
	})
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
const customers = ref([])
const suppliers = ref([])
const subjectOptions = ref([])

const search = reactive({
	partner_type: '',
	partner_id: '',
	status: '',
	keyword: '',
	daterange: [],
})

// 往来单位下拉取真实客户/供应商档案
const loadPartners = async () => {
	try {
		const res = await businessApi.customer.list.get({ page_size: 200 })
		const list = res?.data?.list ?? res?.data?.data?.list ?? []
		customers.value = list.map((c) => ({ value: c.id, label: c.name }))
	} catch {
		customers.value = []
	}
	try {
		const res = await businessApi.supplier.list.get({ page_size: 200 })
		const list = res?.data?.list ?? res?.data?.data?.list ?? []
		suppliers.value = list.map((s) => ({ value: s.id, label: s.name }))
	} catch {
		suppliers.value = []
	}
}

// 末级科目下拉（只读引用 ledger 科目字典，借贷分录按 code 解析）
const loadSubjects = async () => {
	try {
		const res = await ledgerApi.subject.options.get()
		subjectOptions.value = res.data?.options || []
	} catch {
		subjectOptions.value = []
	}
}

const filterPartners = computed(() => {
	if (search.partner_type === 'customer') return customers.value
	if (search.partner_type === 'supplier') return suppliers.value
	return []
})

const onFilterTypeChange = () => {
	search.partner_id = ''
	onSearch()
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
	search.partner_type = ''
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
		await ElMessageBox.confirm(`确认审核调整单 ${row.no}？审核通过将同步调整往来应收应付余额。`, '审核确认', { type: 'warning' })
	} catch {
		return
	}
	try {
		await businessApi.finance.fundOrder.approve.post(DOC_TYPE, row.id)
		ElMessage.success('审核成功')
	} catch (e) {
		// 余额不足等后端中文提示（422）由拦截器统一弹出
		if (e?.message) ElMessage.error(e.message)
	} finally {
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
		await businessApi.finance.fundOrder.reject.post(DOC_TYPE, row.id, {
			reason,
		})
		ElMessage.success('已驳回')
		refresh()
	} catch (e) {
		if (e?.message) ElMessage.error(e.message)
	}
}

/* ---------- 新增 Dialog ---------- */
const createVisible = ref(false)
const emptyForm = () => ({
	partner_type: 'customer',
	partner_id: '',
	partner_name: '',
	currency: 'CNY',
	doc_date: today(),
	reason: '',
	abstract: '',
	items: [{ subject: '', subject_code: '', direction: '借', amount: 0, abstract: '' }],
})
const createForm = reactive(emptyForm())

const createPartners = computed(() => (createForm.partner_type === 'supplier' ? suppliers.value : customers.value))

const openCreate = () => {
	Object.assign(createForm, emptyForm())
	createVisible.value = true
}

const onCreateTypeChange = () => {
	createForm.partner_id = ''
	createForm.partner_name = ''
}

const onPartnerPick = (id) => {
	createForm.partner_name = createPartners.value.find((p) => p.value === id)?.label || ''
}

// 明细行同时携带 subject（名）与 subject_code，后端按 code→name 解析末级科目
const onSubjectPick = (row, code) => {
	row.subject = subjectOptions.value.find((s) => s.code === code)?.name || ''
}

const addRow = () => {
	createForm.items.push({
		subject: '',
		subject_code: '',
		direction: '借',
		amount: 0,
		abstract: '',
	})
}

const removeRow = (i) => {
	createForm.items.splice(i, 1)
}

const sumBy = (dir) => createForm.items.filter((it) => it.direction === dir).reduce((s, it) => s + Number(it.amount || 0), 0)
const debitTotal = computed(() => sumBy('借'))
const creditTotal = computed(() => sumBy('贷'))
const balanced = computed(() => Math.abs(debitTotal.value - creditTotal.value) < 0.005)

const saveCreate = async () => {
	if (!createForm.partner_type || !createForm.partner_id || !createForm.doc_date) {
		return ElMessage.error('请填写必填项')
	}
	if (!createForm.reason?.trim()) {
		return ElMessage.warning('请填写调整原因')
	}
	if (!createForm.items.length) {
		return ElMessage.error('请至少添加一行明细')
	}
	for (const it of createForm.items) {
		if (!it.subject_code) return ElMessage.error('明细行科目未选择完整')
		if (!(Number(it.amount) > 0)) return ElMessage.error('明细行金额必须大于 0')
	}
	if (debitTotal.value <= 0) return ElMessage.error('借方合计必须大于 0')
	if (!balanced.value) return ElMessage.error('借贷不平衡，无法保存')
	saving.value = true
	try {
		const res = await businessApi.finance.fundOrder.add.post(DOC_TYPE, {
			partner_type: createForm.partner_type,
			partner_id: createForm.partner_id,
			partner_name: createForm.partner_name,
			amount: debitTotal.value,
			doc_date: createForm.doc_date,
			reason: createForm.reason.trim(),
			abstract: createForm.abstract,
			items: createForm.items.map((it) => ({
				subject: it.subject,
				subject_code: it.subject_code,
				direction: it.direction,
				amount: Number(it.amount),
				abstract: it.abstract,
			})),
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
	loadSubjects()
	refresh()
})
</script>

<style>
@import '../_shared.css';
</style>

<style scoped>
.arap-page {
	height: 100%;
	display: flex;
	flex-direction: column;
	overflow: hidden;
}
.arap-filter {
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
.arap-toolbar {
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
.arap-content {
	flex: 1;
	min-height: 0;
	padding: 0 12px;
}
.arap-pager {
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
.ptype-tag {
	margin-right: 6px;
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
.dialog-entries :deep(.el-input-number .el-input__inner) {
	text-align: right;
}
.full-width {
	grid-column: span 3;
}
.dialog-head {
	display: grid;
	grid-template-columns: repeat(3, 1fr);
	gap: 16px;
	margin-bottom: 20px;
}
.dialog-head .full-width {
	grid-column: span 3;
}
.sum-amount {
	font-size: var(--font-h2);
	font-weight: 600;
	color: var(--text-primary);
}
.dialog-entries {
	border-top: 1px solid var(--border-lighter);
	padding-top: 16px;
}
.entries-title {
	font-size: 14px;
	font-weight: 600;
	margin-bottom: 12px;
	color: var(--text-primary);
}
.entries-footer {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-top: 12px;
}
.total-sum {
	display: flex;
	align-items: center;
	font-size: var(--font-base);
	color: var(--text-regular);
}
.total-sum b {
	font-size: var(--font-h2);
	color: var(--text-primary);
	margin-left: 4px;
}
.sum-sep {
	margin: 0 16px;
}
.unbalance-hint {
	margin-left: 12px;
	color: var(--color-danger);
}
.view-form :deep(.el-form-item__label) {
	color: var(--text-secondary);
}
</style>
