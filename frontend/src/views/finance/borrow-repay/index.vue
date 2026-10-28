<template>
	<div class="br-page">
		<!-- 筛选卡 -->
		<div class="br-filter">
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
				<el-option v-for="p in partners" :key="p.value" :label="p.label" :value="p.value" />
			</el-select>
			<span class="label">状态</span>
			<el-select v-model="search.status" clearable placeholder="全部状态" size="small" style="width: 130px" @change="onSearch">
				<el-option v-for="(v, k) in BR_STATUS" :key="k" :label="v.label" :value="k" />
			</el-select>
			<span class="label">关键字</span>
			<el-input
				v-model="search.keyword"
				clearable
				placeholder="单据号/单位"
				size="small"
				style="width: 160px"
				@keyup.enter="onSearch"
				@clear="onSearch"
			/>
			<span class="label">起止日期</span>
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
			<div class="filter-actions">
				<el-button type="primary" size="small" @click="onSearch">查询</el-button>
				<el-button size="small" @click="resetSearch">重置</el-button>
			</div>
		</div>

		<!-- 逾期提醒（当前页实时计算：is_overdue 由后端判定） -->
		<el-alert
			v-if="overdueCount > 0"
			class="br-overdue-alert"
			closable
			type="warning"
			show-icon
			:title="`当前有 ${overdueCount} 笔借款已逾期，合计未清 ¥${fmtMoney(overdueAmount)}`"
		/>

		<!-- 工具条 -->
		<div class="br-toolbar">
			<span class="toolbar-title">借款还款</span>
			<el-button type="primary" size="small" v-permission="'fin:borrow:manage'" @click="openCreate">+ 新增借款单</el-button>
		</div>

		<!-- 表格区 -->
		<div class="br-content">
			<el-table :data="rows" border height="100%" v-loading="loading" :show-summary="true" :summary-method="summaryMethod">
				<el-table-column prop="no" label="单据号" width="150" align="left">
					<template #default="{ row }">
						<span class="link tabular" @click="openView(row)">{{ row.no }}</span>
					</template>
				</el-table-column>
				<el-table-column prop="partner_name" label="往来单位" min-width="160" align="left">
					<template #default="{ row }">{{ row.partner_name || '-' }}</template>
				</el-table-column>
				<el-table-column label="借款总额" width="130" align="right">
					<template #default="{ row }">
						<span class="tabular">{{ fmtMoney(row.paid + row.unpaid) }}</span>
					</template>
				</el-table-column>
				<el-table-column prop="date" label="发生日期" width="110" align="center">
					<template #default="{ row }">
						<span class="tabular">{{ row.date }}</span>
					</template>
				</el-table-column>
				<el-table-column prop="due_date" label="预计还款日" width="140" align="center">
					<template #default="{ row }">
						<span class="tabular" :class="{ 'text-danger': row.is_overdue }">{{ row.due_date || '-' }}</span>
						<el-tag v-if="row.is_overdue" size="small" type="danger" class="overdue-tag">已逾期</el-tag>
					</template>
				</el-table-column>
				<el-table-column prop="paid" label="已还金额" width="120" align="right">
					<template #default="{ row }">
						<span class="tabular">{{ fmtMoney(row.paid) }}</span>
					</template>
				</el-table-column>
				<el-table-column prop="unpaid" label="未清金额" width="120" align="right">
					<template #default="{ row }">
						<span class="tabular" :class="{ 'text-danger': row.is_overdue }">{{ fmtMoney(row.unpaid) }}</span>
					</template>
				</el-table-column>
				<el-table-column prop="status" label="状态" width="100" align="center">
					<template #default="{ row }">
						<el-tag size="small" :type="BR_STATUS[row.status]?.tag || 'info'">{{ BR_STATUS[row.status]?.label || row.status }}</el-tag>
					</template>
				</el-table-column>
				<el-table-column label="操作" width="160" align="center" fixed="right">
					<template #default="{ row }">
						<el-button link type="primary" size="small" @click="openView(row)">查看</el-button>
						<el-button
							link
							type="success"
							size="small"
							v-permission="'fin:borrow:manage'"
							:disabled="!canRepay(row)"
							@click="openRepay(row)"
							>登记还款</el-button
						>
					</template>
				</el-table-column>
				<template #empty>
					<div class="empty-state">
						<el-empty description="暂无数据" />
					</div>
				</template>
			</el-table>
		</div>

		<!-- 分页 -->
		<div class="br-pager">
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
		<el-dialog v-model="createVisible" title="新增借款单" width="680px" :close-on-click-modal="false" destroy-on-close>
			<el-form :model="createForm" label-width="90px" size="small">
				<div class="dialog-body">
					<el-form-item label="往来单位" required>
						<el-select v-model="createForm.partner_id" filterable placeholder="选择往来单位" style="width: 100%" @change="onPartnerPick">
							<el-option v-for="p in partners" :key="p.value" :label="p.label" :value="p.value" />
						</el-select>
					</el-form-item>
					<el-form-item label="借款金额" required>
						<el-input-number
							v-model="createForm.amount"
							:min="0.01"
							:step="100"
							:precision="2"
							:controls-position="'right'"
							style="width: 100%"
						/>
					</el-form-item>
					<el-form-item label="发生日期" required>
						<el-date-picker v-model="createForm.doc_date" type="date" value-format="YYYY-MM-DD" style="width: 100%" />
					</el-form-item>
					<el-form-item label="预计还款日">
						<el-date-picker v-model="createForm.due_date" type="date" value-format="YYYY-MM-DD" style="width: 100%" />
					</el-form-item>
					<el-form-item label="付款方式">
						<el-select v-model="createForm.payment_method" style="width: 100%">
							<el-option v-for="m in PAYMENT_METHODS" :key="m" :label="m" :value="m" />
						</el-select>
					</el-form-item>
					<el-form-item label="摘要" class="full-width">
						<el-input v-model="createForm.abstract" placeholder="请输入摘要" style="width: 100%" />
					</el-form-item>
				</div>
			</el-form>
			<template #footer>
				<el-button size="small" @click="createVisible = false">取消</el-button>
				<el-button type="primary" size="small" :loading="saving" @click="saveCreate">保存</el-button>
			</template>
		</el-dialog>

		<!-- 查看 Dialog -->
		<el-dialog v-model="viewVisible" title="借款单详情" width="680px" :close-on-click-modal="false" destroy-on-close>
			<template v-if="viewRow">
				<el-form label-width="90px" size="small" class="view-form">
					<div class="dialog-body">
						<el-form-item label="单据号">
							<span class="tabular">{{ viewRow.no }}</span>
						</el-form-item>
						<el-form-item label="往来单位">{{ viewRow.partner_name || '-' }}</el-form-item>
						<el-form-item label="借款总额">
							<span class="tabular">{{ fmtMoney(viewRow.paid + viewRow.unpaid) }}</span>
						</el-form-item>
						<el-form-item label="发生日期">
							<span class="tabular">{{ viewRow.date }}</span>
						</el-form-item>
						<el-form-item label="预计还款日">
							<span class="tabular" :class="{ 'text-danger': viewRow.is_overdue }">{{ viewRow.due_date || '-' }}</span>
							<el-tag v-if="viewRow.is_overdue" size="small" type="danger" class="overdue-tag">已逾期</el-tag>
						</el-form-item>
						<el-form-item label="已还金额">
							<span class="tabular">{{ fmtMoney(viewRow.paid) }}</span>
						</el-form-item>
						<el-form-item label="未清金额">
							<span class="tabular" :class="{ 'text-danger': viewRow.is_overdue }">{{ fmtMoney(viewRow.unpaid) }}</span>
						</el-form-item>
						<el-form-item label="付款方式">{{ viewRow.payment_method || '-' }}</el-form-item>
						<el-form-item label="状态">
							<el-tag size="small" :type="BR_STATUS[viewRow.status]?.tag || 'info'">{{
								BR_STATUS[viewRow.status]?.label || viewRow.status
							}}</el-tag>
						</el-form-item>
						<el-form-item label="资金流水">
							<span class="tabular">{{ viewRow.cash_flow_no || '-' }}</span>
						</el-form-item>
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

		<!-- 登记还款 Dialog -->
		<el-dialog v-model="repayVisible" title="登记还款" width="680px" :close-on-click-modal="false" destroy-on-close>
			<template v-if="repayRow">
				<el-form :model="repayForm" label-width="90px" size="small">
					<div class="dialog-body">
						<el-form-item label="单据号">
							<span class="tabular">{{ repayRow.no }}</span>
						</el-form-item>
						<el-form-item label="往来单位">{{ repayRow.partner_name || '-' }}</el-form-item>
						<el-form-item label="未清金额">
							<span class="tabular text-danger">{{ fmtMoney(repayRow.unpaid) }}</span>
						</el-form-item>
						<el-form-item label="还款金额" required>
							<el-input-number
								v-model="repayForm.amount"
								:min="0.01"
								:max="repayRow.unpaid"
								:step="100"
								:precision="2"
								:controls-position="'right'"
								style="width: 100%"
							/>
						</el-form-item>
						<el-form-item label="还款日期" required>
							<el-date-picker v-model="repayForm.doc_date" type="date" value-format="YYYY-MM-DD" style="width: 100%" />
						</el-form-item>
						<el-form-item label="付款方式">
							<el-select v-model="repayForm.payment_method" style="width: 100%">
								<el-option v-for="m in PAYMENT_METHODS" :key="m" :label="m" :value="m" />
							</el-select>
						</el-form-item>
						<el-form-item label="摘要" class="full-width">
							<el-input v-model="repayForm.abstract" placeholder="请输入摘要" style="width: 100%" />
						</el-form-item>
					</div>
				</el-form>
			</template>
			<template #footer>
				<el-button size="small" @click="repayVisible = false">取消</el-button>
				<el-button type="primary" size="small" :loading="saving" @click="saveRepay">保存</el-button>
			</template>
		</el-dialog>
	</div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import businessApi from '@/api/business'

const DOC_TYPE = 'borrow_repay'
// 后端状态：confirmed/unsettled 未结清，partial 部分还款，settled 已结清，rejected 已驳回
const BR_STATUS = {
	confirmed: { label: '未结清', tag: 'warning' },
	unsettled: { label: '未结清', tag: 'warning' },
	partial: { label: '部分还款', tag: 'primary' },
	settled: { label: '已结清', tag: 'success' },
	rejected: { label: '已驳回', tag: 'danger' },
}
const PAYMENT_METHODS = ['现金', '银行', '转账']

// 金额千分位 + 两位小数（DS：金额右对齐 tabular-nums）
const fmtMoney = (v) => Number(v || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const today = () => new Date().toISOString().slice(0, 10)

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

// 往来单位下拉：借款挂在客户往来上，取真实客户档案
const loadPartners = async () => {
	try {
		const res = await businessApi.customer.list.get({ page_size: 200 })
		const list = res?.data?.list ?? res?.data?.data?.list ?? []
		partners.value = list.map((c) => ({ value: c.id, label: c.name }))
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
	const sum = rows.value.reduce((s, r) => s + Number(r.paid + r.unpaid || 0), 0)
	return columns.map((col, i) => {
		if (i === 0) return '合计'
		if (i === 2) return fmtMoney(sum)
		return ''
	})
}

/* ---------- 逾期（后端 is_overdue 标记） ---------- */

const overdueCount = computed(() => rows.value.filter((r) => r.is_overdue).length)
const overdueAmount = computed(() => rows.value.filter((r) => r.is_overdue).reduce((s, r) => s + Number(r.unpaid || 0), 0))

/* 登记还款可用：未结清/部分还款且尚有未清金额 */
const canRepay = (row) => row.status !== 'settled' && row.status !== 'rejected' && Number(row.unpaid || 0) > 0

/* ---------- 新增 Dialog ---------- */
const createVisible = ref(false)
const emptyForm = () => ({
	partner_id: '',
	partner_name: '',
	amount: 0,
	doc_date: today(),
	due_date: '',
	payment_method: '现金',
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
	if (!createForm.partner_id || !createForm.amount || !createForm.doc_date) {
		return ElMessage.error('请填写必填项')
	}
	saving.value = true
	try {
		const res = await businessApi.finance.fundOrder.add.post(DOC_TYPE, {
			partner_type: 'customer',
			partner_id: createForm.partner_id,
			partner_name: createForm.partner_name,
			amount: createForm.amount,
			doc_date: createForm.doc_date,
			due_date: createForm.due_date || null,
			payment_method: createForm.payment_method,
			abstract: createForm.abstract,
		})
		const no = res?.data?.data?.no || ''
		ElMessage.success(`保存成功，单据编号：${no}，状态：未结清`)
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
		// 列表行已有主体字段，详情失败不影响展示
	}
}

/* ---------- 登记还款 Dialog ---------- */
const repayVisible = ref(false)
const repayRow = ref(null)
const repayForm = reactive({
	amount: 0,
	doc_date: '',
	payment_method: '现金',
	abstract: '',
})

const openRepay = (row) => {
	repayRow.value = row
	Object.assign(repayForm, {
		amount: row.unpaid, // 默认填未清金额
		doc_date: today(),
		payment_method: row.payment_method || '现金',
		abstract: '',
	})
	repayVisible.value = true
}

const saveRepay = async () => {
	const row = repayRow.value
	if (!row) return
	if (!repayForm.amount || repayForm.amount <= 0) {
		return ElMessage.error('请输入还款金额')
	}
	if (repayForm.amount > row.unpaid) {
		return ElMessage.error(`还款金额不能大于未清金额 ¥${fmtMoney(row.unpaid)}`)
	}
	if (!repayForm.doc_date) {
		return ElMessage.error('请选择还款日期')
	}
	saving.value = true
	try {
		await businessApi.finance.fundOrder.repay.post(DOC_TYPE, row.id, {
			amount: repayForm.amount,
			doc_date: repayForm.doc_date,
			payment_method: repayForm.payment_method,
			abstract: repayForm.abstract,
		})
		ElMessage.success('登记还款成功')
		repayVisible.value = false
		refresh()
	} catch (e) {
		if (e?.message) ElMessage.error(e.message)
	} finally {
		saving.value = false
	}
}

onMounted(() => {
	loadPartners()
	refresh()
})
</script>

<style scoped>
.br-page {
	height: 100%;
	display: flex;
	flex-direction: column;
	overflow: hidden;
}
.br-filter {
	display: flex;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
	padding: 16px 20px;
	background: var(--bg-card);
	border-bottom: 1px solid var(--border-base);
}
.br-filter .label {
	font-size: var(--font-base);
	color: var(--text-regular);
}
.filter-actions {
	margin-left: auto;
	display: flex;
	gap: 12px;
}
.br-overdue-alert {
	margin: 12px 20px 0;
}
.br-toolbar {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 12px 20px;
	background: #fff;
}
.toolbar-title {
	font-size: 15px;
	font-weight: 600;
	color: var(--text-primary);
}
.br-content {
	flex: 1;
	min-height: 0;
	padding: 0 12px;
}
.br-pager {
	display: flex;
	justify-content: flex-end;
	padding: 10px 12px;
}
.empty-state {
	display: flex;
	justify-content: center;
	align-items: center;
	height: 100%;
}
.empty-state :deep(.el-empty__description p) {
	font-size: 13px;
	color: #909399;
}
.link {
	color: var(--color-primary);
	cursor: pointer;
}
.text-danger {
	color: var(--color-danger);
}
.overdue-tag {
	margin-left: 6px;
}

/* 表格样式对齐卷00 */
:deep(.el-table) {
	font-size: var(--font-base);
	color: var(--text-primary);
}
:deep(.el-table th.el-table__cell) {
	background: var(--bg-hover);
	height: 44px;
	color: var(--text-regular);
	font-weight: 600;
}
:deep(.el-table .el-table__row) {
	height: 40px;
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

/* 金额等宽对齐 */
.tabular {
	font-variant-numeric: tabular-nums;
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
.view-form :deep(.el-form-item__label) {
	color: var(--text-secondary);
}
</style>
