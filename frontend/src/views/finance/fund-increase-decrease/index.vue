<template>
	<div class="fund-page">
		<!-- 筛选卡 -->
		<div class="fund-filter">
			<span class="label">增减类型</span>
			<el-select v-model="search.type" clearable placeholder="全部类型" size="small" style="width: 150px" @change="refresh">
				<el-option v-for="t in FUND_TYPES" :key="t.value" :label="t.label" :value="t.value" />
			</el-select>
			<span class="label">资金账户</span>
			<el-select v-model="search.account" clearable placeholder="全部账户" size="small" style="width: 180px" @change="refresh">
				<el-option v-for="a in FUND_ACCOUNTS" :key="a.value" :label="a.label" :value="a.value" />
			</el-select>
			<span class="label">日期范围</span>
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

		<!-- 工具条 -->
		<div class="fund-toolbar">
			<span class="toolbar-title">资金增减单</span>
			<el-button type="primary" size="small" @click="openCreate">+ 新增资金增减单</el-button>
		</div>

		<!-- 表格区 -->
		<div class="fund-content">
			<el-table
				:data="pagedList"
				border
				height="100%"
				v-loading="loading"
				:show-summary="true"
				:summary-method="summaryMethod"
			>
				<el-table-column prop="type" label="增减类型" width="120" align="left">
					<template #default="{ row }">
						<el-tag size="small" effect="light" :type="typeTag(row.type)">{{ FUND_TYPE_LABEL[row.type] }}</el-tag>
					</template>
				</el-table-column>
				<el-table-column prop="no" label="单据号" width="150" align="left">
					<template #default="{ row }">
						<span class="link tabular" @click="openView(row)">{{ row.no }}</span>
					</template>
				</el-table-column>
				<el-table-column prop="account" label="资金账户" width="150" align="left">
					<template #default="{ row }">{{ FUND_ACCOUNT_LABEL[row.account] }}</template>
				</el-table-column>
				<el-table-column prop="partner" label="往来单位" width="180" align="left">
					<template #default="{ row }">{{ PARTNER_LABEL[row.partner] || '-' }}</template>
				</el-table-column>
				<el-table-column prop="amount" label="金额" width="130" align="right">
					<template #default="{ row }">
						<span class="tabular">{{ fmtMoney(row.amount) }}</span>
					</template>
				</el-table-column>
				<el-table-column prop="abstract" label="摘要" min-width="200" align="left" show-overflow-tooltip />
				<el-table-column prop="creator" label="制单人" width="100" align="center" />
				<el-table-column prop="created_at" label="制单日期" width="110" align="center">
					<template #default="{ row }"><span class="tabular">{{ row.created_at }}</span></template>
				</el-table-column>
				<el-table-column label="操作" width="100" align="center">
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
			<el-pagination background layout="total, prev, pager, next" :total="total" :page-size="pageSize" :current-page="page" @current-change="page = $event" />
		</div>

		<!-- 新增/查看 Dialog -->
		<el-dialog
			v-model="dialogVisible"
			:title="dialogTitle"
			width="600px"
			:close-on-click-modal="false"
			destroy-on-close
		>
			<el-form :model="form" label-width="90px" size="small" :disabled="mode === 'view'">
				<div class="dialog-body">
					<el-form-item label="资金账户" required>
						<el-select v-model="form.account" style="width: 100%">
							<el-option v-for="a in FUND_ACCOUNTS" :key="a.value" :label="a.label" :value="a.value" />
						</el-select>
					</el-form-item>
					<el-form-item label="增减类型" required>
						<el-radio-group v-model="form.type" size="small">
							<el-radio-button label="increase" value="increase">资金增加</el-radio-button>
							<el-radio-button label="decrease" value="decrease">资金减少</el-radio-button>
						</el-radio-group>
					</el-form-item>
					<el-form-item label="金额" required>
						<el-input-number v-model="form.amount" :min="0" :precision="2" style="width: 100%" />
					</el-form-item>
					<el-form-item label="发生日期" required>
						<el-date-picker v-model="form.date" type="date" value-format="YYYY-MM-DD" style="width: 100%" />
					</el-form-item>
					<el-form-item label="往来单位">
						<el-select v-model="form.partner" clearable filterable placeholder="选择或输入往来单位" style="width: 100%">
							<el-option v-for="p in PARTNERS" :key="p.value" :label="p.label" :value="p.value" />
						</el-select>
					</el-form-item>
					<el-form-item label="摘要" class="full-width">
						<el-input v-model="form.abstract" placeholder="请输入摘要" style="width: 100%" />
					</el-form-item>
				</div>
			</el-form>
			<template #footer>
				<el-button size="small" @click="dialogVisible = false">取消</el-button>
				<template v-if="mode !== 'view'">
					<el-button type="primary" size="small" @click="saveForm">保存</el-button>
				</template>
			</template>
		</el-dialog>
	</div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import {
	FUND_TYPES,
	FUND_TYPE_LABEL,
	FUND_ACCOUNTS,
	FUND_ACCOUNT_LABEL,
	PARTNERS,
	PARTNER_LABEL,
	fmtMoney,
	mockFundIncreaseDecreaseOrders,
} from '@/mock/fund-increase-decrease'

const loading = ref(false)
const page = ref(1)
const pageSize = 20
const rows = ref([])

const search = reactive({
	type: '',
	account: '',
	daterange: [],
})

const refresh = () => {
	loading.value = true
	// TODO(api): GET /api/finance/fund-increase-decrease-orders
	setTimeout(() => {
		rows.value = mockFundIncreaseDecreaseOrders()
		loading.value = false
	}, 300)
}

const resetSearch = () => {
	search.type = ''
	search.account = ''
	search.daterange = []
	refresh()
}

const filteredList = computed(() => {
	return rows.value.filter((r) => {
		if (search.type && r.type !== search.type) return false
		if (search.account && r.account !== search.account) return false
		if (search.daterange?.length === 2) {
			if (r.created_at < search.daterange[0] || r.created_at > search.daterange[1]) return false
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
	const sum = filteredList.value.reduce((s, r) => {
		const amt = Number(r.amount || 0)
		return r.type === 'increase' ? s + amt : s - amt
	}, 0)
	return columns.map((col, i) => {
		if (i === 0) return '净增减'
		if (col.property === 'amount') return fmtMoney(sum)
		return ''
	})
}

const typeTag = (type) => {
	return type === 'increase' ? 'success' : 'danger'
}

// Dialog 逻辑
const dialogVisible = ref(false)
const mode = ref('create') // create | view
const dialogTitle = computed(() => (mode.value === 'create' ? '新增资金增减单' : '查看资金增减单'))

const form = reactive({
	type: 'increase',
	account: '',
	amount: 0,
	date: '',
	partner: '',
	abstract: '',
})

const openCreate = () => {
	mode.value = 'create'
	Object.assign(form, {
		type: 'increase',
		account: '',
		amount: 0,
		date: new Date().toISOString().split('T')[0],
		partner: '',
		abstract: '',
	})
	dialogVisible.value = true
}

const openView = (row) => {
	mode.value = 'view'
	Object.assign(form, { ...row })
	dialogVisible.value = true
}

const saveForm = () => {
	if (!form.type || !form.account || !form.amount || !form.date) {
		return ElMessage.error('请填写必填项')
	}
	// TODO(api): POST /api/finance/fund-increase-decrease-orders
	ElMessage.success('保存成功，资金已实时生效')
	dialogVisible.value = false
	refresh()
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
.link {
	color: var(--color-primary);
	cursor: pointer;
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
</style>
