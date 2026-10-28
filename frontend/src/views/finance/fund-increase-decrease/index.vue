<template>
	<div class="fund-page">
		<!-- 筛选卡 -->
		<div class="fund-filter">
			<span class="label">增减类型</span>
			<el-select v-model="search.direction" clearable placeholder="全部类型" size="small" style="width: 150px" @change="onSearch">
				<el-option v-for="t in FUND_DIRECTIONS" :key="t.value" :label="t.label" :value="t.value" />
			</el-select>
			<span class="label">关键字</span>
			<el-input
				v-model="search.keyword"
				clearable
				placeholder="单据号/往来单位"
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
			<div class="filter-actions">
				<el-button type="primary" size="small" @click="onSearch">查询</el-button>
				<el-button size="small" @click="resetSearch">重置</el-button>
			</div>
		</div>

		<!-- 工具条 -->
		<div class="fund-toolbar">
			<span class="toolbar-title">资金增减单</span>
			<el-button type="primary" size="small" v-permission="'fin:fund:adjust'" @click="openCreate">+ 新增资金增减单</el-button>
		</div>

		<!-- 表格区 -->
		<div class="fund-content">
			<el-table :data="rows" border height="100%" v-loading="loading" :show-summary="true" :summary-method="summaryMethod">
				<el-table-column prop="direction" label="增减类型" width="120" align="left">
					<template #default="{ row }">
						<el-tag size="small" effect="light" :type="row.direction === 'increase' ? 'success' : 'danger'">
							{{ FUND_DIRECTION_LABEL[row.direction] || row.direction }}
						</el-tag>
					</template>
				</el-table-column>
				<el-table-column prop="no" label="单据号" width="150" align="left">
					<template #default="{ row }">
						<span class="link tabular" @click="openView(row)">{{ row.no }}</span>
					</template>
				</el-table-column>
				<el-table-column prop="account" label="资金账户" width="150" align="left">
					<template #default="{ row }">{{ row.account || '-' }}</template>
				</el-table-column>
				<el-table-column prop="partner_name" label="往来单位" width="180" align="left">
					<template #default="{ row }">{{ row.partner_name || '-' }}</template>
				</el-table-column>
				<el-table-column prop="amount" label="金额" width="130" align="right">
					<template #default="{ row }">
						<span class="tabular" :class="{ 'text-danger': row.amount < 0 }">{{ fmtMoney(row.amount) }}</span>
					</template>
				</el-table-column>
				<el-table-column prop="payment_method" label="付款方式" width="100" align="center">
					<template #default="{ row }">{{ row.payment_method || '-' }}</template>
				</el-table-column>
				<el-table-column prop="abstract" label="摘要" min-width="180" align="left" show-overflow-tooltip />
				<el-table-column prop="cash_flow_no" label="资金流水号" width="170" align="left">
					<template #default="{ row }">
						<span class="tabular">{{ row.cash_flow_no || '-' }}</span>
					</template>
				</el-table-column>
				<el-table-column prop="status" label="状态" width="90" align="center">
					<template #default="{ row }">
						<el-tag size="small" :type="FUND_STATUS[row.status]?.tag || 'info'">{{ FUND_STATUS[row.status]?.label || row.status }}</el-tag>
					</template>
				</el-table-column>
				<el-table-column prop="date" label="发生日期" width="110" align="center">
					<template #default="{ row }"
						><span class="tabular">{{ row.date }}</span></template
					>
				</el-table-column>
				<el-table-column label="操作" width="80" align="center" fixed="right">
					<template #default="{ row }">
						<el-button link type="primary" size="small" @click="openView(row)">查看</el-button>
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
		<div class="fund-pager">
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
		<el-dialog v-model="createVisible" title="新增资金增减单" width="680px" :close-on-click-modal="false" destroy-on-close>
			<el-form :model="createForm" label-width="90px" size="small">
				<div class="dialog-body">
					<el-form-item label="增减类型" required>
						<el-radio-group v-model="createForm.direction" size="small">
							<el-radio-button value="increase">资金增加</el-radio-button>
							<el-radio-button value="decrease">资金减少</el-radio-button>
						</el-radio-group>
					</el-form-item>
					<el-form-item label="金额" required>
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
					<el-form-item label="资金账户" required>
						<el-select v-model="createForm.account" placeholder="选择资金账户" style="width: 100%">
							<el-option v-for="a in FUND_ACCOUNTS" :key="a" :label="a" :value="a" />
						</el-select>
					</el-form-item>
					<el-form-item label="付款方式">
						<el-select v-model="createForm.payment_method" style="width: 100%">
							<el-option label="现金" value="现金" />
							<el-option label="银行" value="银行" />
						</el-select>
					</el-form-item>
					<el-form-item label="往来单位">
						<el-input v-model="createForm.partner_name" placeholder="选填" style="width: 100%" />
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
		<el-dialog v-model="viewVisible" title="资金增减单详情" width="680px" :close-on-click-modal="false" destroy-on-close>
			<template v-if="viewRow">
				<el-form label-width="90px" size="small" class="view-form">
					<div class="dialog-body">
						<el-form-item label="单据号">
							<span class="tabular">{{ viewRow.no }}</span>
						</el-form-item>
						<el-form-item label="增减类型">
							<el-tag size="small" effect="light" :type="viewRow.direction === 'increase' ? 'success' : 'danger'">
								{{ FUND_DIRECTION_LABEL[viewRow.direction] || viewRow.direction }}
							</el-tag>
						</el-form-item>
						<el-form-item label="金额">
							<span class="tabular" :class="{ 'text-danger': viewRow.amount < 0 }">{{ fmtMoney(viewRow.amount) }}</span>
						</el-form-item>
						<el-form-item label="资金账户">{{ viewRow.account || '-' }}</el-form-item>
						<el-form-item label="付款方式">{{ viewRow.payment_method || '-' }}</el-form-item>
						<el-form-item label="发生日期">
							<span class="tabular">{{ viewRow.date }}</span>
						</el-form-item>
						<el-form-item label="往来单位">{{ viewRow.partner_name || '-' }}</el-form-item>
						<el-form-item label="状态">
							<el-tag size="small" :type="FUND_STATUS[viewRow.status]?.tag || 'info'">{{
								FUND_STATUS[viewRow.status]?.label || viewRow.status
							}}</el-tag>
						</el-form-item>
						<el-form-item label="资金流水">
							<span class="tabular">{{ viewRow.cash_flow_no || '-' }}</span>
						</el-form-item>
						<el-form-item label="摘要" class="full-width">{{ viewRow.abstract || '-' }}</el-form-item>
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
import { ElMessage } from 'element-plus'
import businessApi from '@/api/business'

const DOC_TYPE = 'fund_io'
const FUND_DIRECTIONS = [
	{ value: 'increase', label: '资金增加' },
	{ value: 'decrease', label: '资金减少' },
]
const FUND_DIRECTION_LABEL = Object.fromEntries(FUND_DIRECTIONS.map((t) => [t.value, t.label]))
const FUND_ACCOUNTS = ['现金', '工商银行', '招商银行', '建设银行']
// 建单即生效并写资金流水，无审核流
const FUND_STATUS = { confirmed: { label: '已入账', tag: 'success' } }

// 金额千分位 + 两位小数（DS：金额右对齐 tabular-nums）
const fmtMoney = (v) =>
	Number(v || 0).toLocaleString('zh-CN', {
		minimumFractionDigits: 2,
		maximumFractionDigits: 2,
	})
const today = () => new Date().toISOString().slice(0, 10)

const loading = ref(false)
const saving = ref(false)
const page = ref(1)
const pageSize = 20
const rows = ref([])
const total = ref(0)

const search = reactive({
	direction: '',
	keyword: '',
	daterange: [],
})

// 分页 / 筛选全部下传后端，前端不再本地过滤
const refresh = async () => {
	loading.value = true
	try {
		const params = {
			direction: search.direction || undefined,
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
	search.direction = ''
	search.keyword = ''
	search.daterange = []
	onSearch()
}

// 净增减：本页有符号金额合计（减少方向后端存负额）
const summaryMethod = ({ columns }) => {
	const sum = rows.value.reduce((s, r) => s + Number(r.amount || 0), 0)
	return columns.map((col, i) => {
		if (i === 0) return '净增减'
		if (col.property === 'amount') return fmtMoney(sum)
		return ''
	})
}

/* ---------- 新增 Dialog ---------- */
const createVisible = ref(false)
const emptyForm = () => ({
	direction: 'increase',
	amount: 0,
	doc_date: today(),
	account: '',
	payment_method: '现金',
	partner_name: '',
	abstract: '',
})
const createForm = reactive(emptyForm())

const openCreate = () => {
	Object.assign(createForm, emptyForm())
	createVisible.value = true
}

const saveCreate = async () => {
	if (!createForm.account || !createForm.amount || !createForm.doc_date) {
		return ElMessage.error('请填写必填项')
	}
	saving.value = true
	try {
		// 资金流水按金额正负号判定收付：减少方向提交负额
		const signed = createForm.direction === 'decrease' ? -Math.abs(createForm.amount) : Math.abs(createForm.amount)
		const res = await businessApi.finance.fundOrder.add.post(DOC_TYPE, {
			direction: createForm.direction,
			amount: signed,
			doc_date: createForm.doc_date,
			account: createForm.account,
			payment_method: createForm.payment_method,
			partner_name: createForm.partner_name || undefined,
			abstract: createForm.abstract,
		})
		const no = res?.data?.data?.no || ''
		ElMessage.success(`保存成功，单据号：${no}，资金已实时生效`)
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

onMounted(refresh)
</script>

<style scoped>
.fund-page {
	height: 100%;
	display: flex;
	flex-direction: column;
	overflow: hidden;
}
.fund-filter {
	display: flex;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
	padding: 16px 20px;
	background: var(--bg-card);
	border-bottom: 1px solid var(--border-base);
}
.fund-filter .label {
	font-size: var(--font-base);
	color: var(--text-regular);
}
.filter-actions {
	margin-left: auto;
	display: flex;
	gap: 12px;
}
.fund-toolbar {
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
.fund-content {
	flex: 1;
	min-height: 0;
	padding: 0 12px;
}
.fund-pager {
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
.full-width {
	grid-column: span 3;
}
.view-form :deep(.el-form-item__label) {
	color: var(--text-secondary);
}
</style>
