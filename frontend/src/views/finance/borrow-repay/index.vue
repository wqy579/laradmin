<template>
	<div class="br-page">
		<!-- 筛选卡 -->
		<div class="br-filter">
			<span class="label">单据类型</span>
			<el-select v-model="search.type" clearable placeholder="全部" size="small" style="width: 130px" @change="refresh">
				<el-option v-for="t in ORDER_TYPES" :key="t.value" :label="t.label" :value="t.value" />
			</el-select>
			<span class="label">往来单位</span>
			<el-select v-model="search.partner" clearable filterable placeholder="全部单位" size="small" style="width: 180px" @change="refresh">
				<el-option v-for="p in PARTNERS" :key="p.value" :label="p.label" :value="p.value" />
			</el-select>
			<span class="label">起止日期</span>
			<el-date-picker
				v-model="search.daterange"
				type="daterange"
				size="small"
				style="width: 240px"
				start-placeholder="开始日期"
				end-placeholder="结束日期"
				value-format="YYYY-MM-DD"
				@change="refresh"
			/>
			<div class="filter-actions">
				<el-button type="primary" size="small" @click="refresh">查询</el-button>
				<el-button size="small" @click="resetSearch">重置</el-button>
			</div>
		</div>

		<!-- 逾期提醒（实时计算：预计还款日 < 今天 且 未清金额 > 0） -->
		<el-alert
			v-if="overdueCount > 0"
			class="br-overdue-alert"
			closable
			type="warning"
			show-icon
			:title="`当前有 ${overdueCount} 笔借款已逾期，合计金额 ¥${fmtMoney(overdueAmount)}`"
		/>

		<!-- 工具条 -->
		<div class="br-toolbar">
			<span class="toolbar-title">借款还款</span>
			<el-button type="primary" size="small" v-permission="'fin:borrow:manage'" @click="openCreate">+ 新增单据</el-button>
		</div>

		<!-- 表格区 -->
		<div class="br-content">
			<el-table
				:data="pagedList"
				border
				height="100%"
				v-loading="loading"
				:show-summary="true"
				:summary-method="summaryMethod"
			>
				<el-table-column prop="type" label="单据类型" width="100" align="center">
					<template #default="{ row }">
						<el-tag size="small" effect="light" :type="row.type === 'borrow' ? '' : 'success'">{{ ORDER_TYPE_LABEL[row.type] }}</el-tag>
					</template>
				</el-table-column>
				<el-table-column prop="no" label="单据号" width="150" align="left">
					<template #default="{ row }">
						<span class="link tabular" @click="openView(row)">{{ row.no }}</span>
					</template>
				</el-table-column>
				<el-table-column prop="partner" label="往来单位" width="180" align="left">
					<template #default="{ row }">{{ PARTNER_LABEL[row.partner] || '-' }}</template>
				</el-table-column>
				<el-table-column prop="amount" label="单据金额" width="120" align="right">
					<template #default="{ row }">
						<span class="tabular">{{ fmtMoney(row.amount) }}</span>
					</template>
				</el-table-column>
				<el-table-column prop="due_date" label="预计还款日" width="120" align="center">
					<template #default="{ row }">
						<span class="tabular">{{ row.due_date || '-' }}</span>
					</template>
				</el-table-column>
				<el-table-column prop="paid" label="已还金额" width="120" align="right">
					<template #default="{ row }">
						<span class="tabular">{{ fmtMoney(row.paid) }}</span>
					</template>
				</el-table-column>
				<el-table-column prop="unpaid" label="未清金额" width="120" align="right">
					<template #default="{ row }">
						<span class="tabular" :class="{ 'text-danger': isOverdue(row) }">{{ fmtMoney(row.unpaid) }}</span>
					</template>
				</el-table-column>
				<el-table-column prop="status" label="状态" width="100" align="center">
					<template #default="{ row }">
						<el-tag size="small" :type="statusTag(row)">{{ statusLabel(row) }}</el-tag>
					</template>
				</el-table-column>
				<el-table-column label="操作" width="160" align="center" fixed="right">
					<template #default="{ row }">
						<el-button link type="primary" size="small" @click="openView(row)">查看</el-button>
						<el-button link type="success" size="small" v-permission="'fin:borrow:manage'" :disabled="!canRepay(row)" @click="openRepay(row)">登记还款</el-button>
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
			<el-pagination background layout="total, prev, pager, next" :total="total" :page-size="pageSize" :current-page="page" @current-change="page = $event" />
		</div>

		<!-- 新增 Dialog -->
		<el-dialog
			v-model="createVisible"
			title="新增单据"
			width="600px"
			:close-on-click-modal="false"
			destroy-on-close
		>
			<el-form :model="createForm" label-width="90px" size="small">
				<div class="dialog-body">
					<el-form-item label="单据类型" required>
						<el-radio-group v-model="createForm.type">
							<el-radio-button label="borrow" value="borrow">借款</el-radio-button>
							<el-radio-button label="repay" value="repay">还款</el-radio-button>
						</el-radio-group>
					</el-form-item>
					<el-form-item label="往来单位" required>
						<el-select v-model="createForm.partner" filterable placeholder="选择往来单位" style="width: 100%">
							<el-option v-for="p in PARTNERS" :key="p.value" :label="p.label" :value="p.value" />
						</el-select>
					</el-form-item>
					<el-form-item label="金额" required>
						<el-input-number v-model="createForm.amount" :min="0.01" :precision="2" :controls="false" style="width: 100%" />
					</el-form-item>
					<el-form-item label="发生日期" required>
						<el-date-picker v-model="createForm.date" type="date" value-format="YYYY-MM-DD" style="width: 100%" />
					</el-form-item>
					<el-form-item v-if="createForm.type === 'borrow'" label="预计还款日" required>
						<el-date-picker v-model="createForm.due_date" type="date" value-format="YYYY-MM-DD" style="width: 100%" />
					</el-form-item>
					<el-form-item label="摘要" class="full-width">
						<el-input v-model="createForm.abstract" placeholder="请输入摘要" style="width: 100%" />
					</el-form-item>
				</div>
			</el-form>
			<template #footer>
				<el-button size="small" @click="createVisible = false">取消</el-button>
				<el-button type="primary" size="small" @click="saveCreate">保存</el-button>
			</template>
		</el-dialog>

		<!-- 查看 Dialog -->
		<el-dialog
			v-model="viewVisible"
			title="单据详情"
			width="640px"
			:close-on-click-modal="false"
			destroy-on-close
		>
			<template v-if="viewRow">
				<el-form label-width="90px" size="small" class="view-form">
					<div class="dialog-body">
						<el-form-item label="单据类型">
							<el-tag size="small" effect="light" :type="viewRow.type === 'borrow' ? '' : 'success'">{{ ORDER_TYPE_LABEL[viewRow.type] }}</el-tag>
						</el-form-item>
						<el-form-item label="单据号">
							<span class="tabular">{{ viewRow.no }}</span>
						</el-form-item>
						<el-form-item label="往来单位">{{ PARTNER_LABEL[viewRow.partner] || '-' }}</el-form-item>
						<el-form-item label="单据金额">
							<span class="tabular">{{ fmtMoney(viewRow.amount) }}</span>
						</el-form-item>
						<el-form-item label="发生日期">
							<span class="tabular">{{ viewRow.date }}</span>
						</el-form-item>
						<el-form-item v-if="viewRow.type === 'borrow'" label="预计还款日">
							<span class="tabular">{{ viewRow.due_date || '-' }}</span>
						</el-form-item>
						<el-form-item label="已还金额">
							<span class="tabular">{{ fmtMoney(viewRow.paid) }}</span>
						</el-form-item>
						<el-form-item label="未清金额">
							<span class="tabular" :class="{ 'text-danger': isOverdue(viewRow) }">{{ fmtMoney(viewRow.unpaid) }}</span>
						</el-form-item>
						<el-form-item label="状态">
							<el-tag size="small" :type="statusTag(viewRow)">{{ statusLabel(viewRow) }}</el-tag>
						</el-form-item>
						<el-form-item label="制单人">{{ viewRow.creator }}</el-form-item>
						<el-form-item label="摘要" class="full-width">{{ viewRow.abstract || '-' }}</el-form-item>
					</div>
				</el-form>
				<div class="repay-records" v-if="viewRow.type === 'borrow'">
					<div class="records-title">还款记录（{{ (viewRow.repays || []).length }}）</div>
					<el-table v-if="viewRow.repays && viewRow.repays.length" :data="viewRow.repays" border size="small">
						<el-table-column prop="date" label="还款日期" width="140" align="center">
							<template #default="{ row }"><span class="tabular">{{ row.date }}</span></template>
						</el-table-column>
						<el-table-column prop="amount" label="还款金额" width="140" align="right">
							<template #default="{ row }"><span class="tabular">{{ fmtMoney(row.amount) }}</span></template>
						</el-table-column>
						<el-table-column prop="abstract" label="摘要" min-width="160" align="left" show-overflow-tooltip />
					</el-table>
					<div v-else class="records-empty">暂无还款记录</div>
				</div>
			</template>
			<template #footer>
				<el-button size="small" @click="viewVisible = false">关闭</el-button>
			</template>
		</el-dialog>

		<!-- 登记还款 Dialog -->
		<el-dialog
			v-model="repayVisible"
			title="登记还款"
			width="520px"
			:close-on-click-modal="false"
			destroy-on-close
		>
			<template v-if="repayRow">
				<el-form :model="repayForm" label-width="90px" size="small">
					<el-form-item label="单据号">
						<span class="tabular">{{ repayRow.no }}</span>
					</el-form-item>
					<el-form-item label="往来单位">{{ PARTNER_LABEL[repayRow.partner] || '-' }}</el-form-item>
					<el-form-item label="未清金额">
						<span class="tabular text-danger">{{ fmtMoney(repayRow.unpaid) }}</span>
					</el-form-item>
					<el-form-item label="还款金额" required>
						<el-input-number v-model="repayForm.amount" :min="0.01" :max="repayRow.unpaid" :precision="2" :controls="false" style="width: 100%" />
					</el-form-item>
					<el-form-item label="还款日期" required>
						<el-date-picker v-model="repayForm.date" type="date" value-format="YYYY-MM-DD" style="width: 100%" />
					</el-form-item>
					<el-form-item label="摘要">
						<el-input v-model="repayForm.abstract" placeholder="请输入摘要" style="width: 100%" />
					</el-form-item>
				</el-form>
			</template>
			<template #footer>
				<el-button size="small" @click="repayVisible = false">取消</el-button>
				<el-button type="primary" size="small" @click="saveRepay">保存</el-button>
			</template>
		</el-dialog>
	</div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import {
	ORDER_TYPES,
	ORDER_TYPE_LABEL,
	ORDER_STATUS,
	PARTNERS,
	PARTNER_LABEL,
	fmtMoney,
	today,
	mockBorrowRepayOrders,
} from '@/mock/borrow-repay'

const loading = ref(false)
const page = ref(1)
const pageSize = 20
const rows = ref([])

const search = reactive({
	type: '',
	partner: '',
	daterange: [],
})

const refresh = () => {
	loading.value = true
	// TODO(api): GET /api/finance/borrow-repay-orders
	setTimeout(() => {
		rows.value = mockBorrowRepayOrders()
		loading.value = false
	}, 300)
}

const resetSearch = () => {
	search.type = ''
	search.partner = ''
	search.daterange = []
	refresh()
}

const filteredList = computed(() => {
	return rows.value.filter((r) => {
		if (search.type && r.type !== search.type) return false
		if (search.partner && r.partner !== search.partner) return false
		if (search.daterange?.length === 2) {
			if (r.date < search.daterange[0] || r.date > search.daterange[1]) return false
		}
		return true
	})
})

const total = computed(() => filteredList.value.length)
const pagedList = computed(() => {
	const start = (page.value - 1) * pageSize
	return filteredList.value.slice(start, start + pageSize)
})

const summaryMethod = ({ columns }) => {
	const sum = filteredList.value.reduce((s, r) => s + Number(r.amount || 0), 0)
	return columns.map((col, i) => {
		if (i === 0) return '合计'
		if (col.property === 'amount') return fmtMoney(sum)
		return ''
	})
}

/* ---------- 逾期（运行时计算态） ---------- */

const isOverdue = (row) => row.type === 'borrow' && !!row.due_date && row.due_date < today() && row.unpaid > 0

const overdueCount = computed(() => rows.value.filter(isOverdue).length)
const overdueAmount = computed(() => rows.value.filter(isOverdue).reduce((s, r) => s + Number(r.unpaid || 0), 0))

const statusOf = (row) => (isOverdue(row) ? 'overdue' : row.status)
const statusLabel = (row) => ORDER_STATUS[statusOf(row)]?.label || row.status
const statusTag = (row) => ORDER_STATUS[statusOf(row)]?.tag || 'info'

/* 登记还款可用：仅借款单且未结清（未结清/部分偿还/已逾期） */
const canRepay = (row) => row.type === 'borrow' && row.unpaid > 0

/* ---------- 新增 Dialog ---------- */
const createVisible = ref(false)
const createForm = reactive({
	type: 'borrow',
	partner: '',
	amount: 0,
	date: '',
	due_date: '',
	abstract: '',
})

const openCreate = () => {
	Object.assign(createForm, {
		type: 'borrow',
		partner: '',
		amount: 0,
		date: today(),
		due_date: '',
		abstract: '',
	})
	createVisible.value = true
}

const saveCreate = () => {
	if (!createForm.type || !createForm.partner || !createForm.amount || !createForm.date) {
		return ElMessage.error('请填写必填项')
	}
	if (createForm.type === 'borrow' && !createForm.due_date) {
		return ElMessage.error('借款单必须填写预计还款日')
	}
	// TODO(api): POST /api/finance/borrow-repay-orders
	const status = createForm.type === 'borrow' ? '未结清' : '已结清'
	ElMessage.success(`保存成功，状态：${status}`)
	createVisible.value = false
	refresh()
}

/* ---------- 查看 Dialog ---------- */
const viewVisible = ref(false)
const viewRow = ref(null)

const openView = (row) => {
	// TODO(api): GET /api/finance/borrow-repay-orders/{id}
	viewRow.value = row
	viewVisible.value = true
}

/* ---------- 登记还款 Dialog ---------- */
const repayVisible = ref(false)
const repayRow = ref(null)
const repayForm = reactive({
	amount: 0,
	date: '',
	abstract: '',
})

const openRepay = (row) => {
	repayRow.value = row
	Object.assign(repayForm, {
		amount: row.unpaid, // 默认填未清金额
		date: today(),
		abstract: '',
	})
	repayVisible.value = true
}

const saveRepay = () => {
	const row = repayRow.value
	if (!row) return
	if (!repayForm.amount || repayForm.amount <= 0) {
		return ElMessage.error('请输入还款金额')
	}
	if (repayForm.amount > row.unpaid) {
		return ElMessage.error(`还款金额不能大于未清金额 ¥${fmtMoney(row.unpaid)}`)
	}
	if (!repayForm.date) {
		return ElMessage.error('请选择还款日期')
	}
	// TODO(api): POST /api/finance/borrow-repay-orders/{id}/repay
	row.paid = Math.round((row.paid + repayForm.amount) * 100) / 100
	row.unpaid = Math.max(0, Math.round((row.amount - row.paid) * 100) / 100)
	row.status = row.paid >= row.amount ? 'settled' : 'partial'
	row.repays = row.repays || []
	row.repays.push({ date: repayForm.date, amount: repayForm.amount, abstract: repayForm.abstract || '' })
	ElMessage.success('登记还款成功')
	repayVisible.value = false
	refresh()
}

onMounted(refresh)
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

/* Dialog 样式 */
.dialog-body {
	display: grid;
	grid-template-columns: repeat(2, 1fr);
	gap: 16px;
}
.full-width {
	grid-column: span 2;
}
.view-form :deep(.el-form-item__label) {
	color: var(--text-secondary);
}
.repay-records {
	border-top: 1px solid var(--border-lighter);
	padding-top: 16px;
}
.records-title {
	font-size: 14px;
	font-weight: 600;
	margin-bottom: 12px;
	color: var(--text-primary);
}
.records-empty {
	padding: 24px 0;
	text-align: center;
	font-size: 13px;
	color: #909399;
}
</style>
