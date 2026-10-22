<template>
	<div class="arap-page">
		<!-- 筛选卡 -->
		<div class="arap-filter">
			<span class="label">调整类型</span>
			<el-select v-model="search.type" clearable placeholder="全部类型" size="small" style="width: 150px" @change="refresh">
				<el-option v-for="t in ADJUST_TYPES" :key="t.value" :label="t.label" :value="t.value" />
			</el-select>
			<span class="label">往来单位</span>
			<el-select v-model="search.partner" clearable placeholder="全部单位" size="small" style="width: 180px" @change="refresh">
				<el-option v-for="p in PARTNERS" :key="p.value" :label="p.label" :value="p.value" />
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
		<div class="arap-toolbar">
			<span class="toolbar-title">应收应付调整单</span>
			<el-button type="primary" size="small" @click="openCreate">+ 新增调整单</el-button>
		</div>

		<!-- 表格区 -->
		<div class="arap-content">
			<el-table
				:data="pagedList"
				border
				height="100%"
				v-loading="loading"
				:show-summary="true"
				:summary-method="summaryMethod"
			>
				<el-table-column prop="type" label="调整类型" width="120" align="left">
					<template #default="{ row }">
						<el-tag size="small" effect="light" :type="typeTag(row.type)">{{ ADJUST_TYPE_LABEL[row.type] }}</el-tag>
					</template>
				</el-table-column>
				<el-table-column prop="no" label="单据号" width="150" align="left">
					<template #default="{ row }">
						<span class="link tabular" @click="openView(row)">{{ row.no }}</span>
					</template>
				</el-table-column>
				<el-table-column prop="partner" label="往来单位" width="180" align="left">
					<template #default="{ row }">{{ PARTNER_LABEL[row.partner] }}</template>
				</el-table-column>
				<el-table-column prop="amount" label="调整金额" width="130" align="right">
					<template #default="{ row }">
						<span class="tabular">{{ fmtMoney(row.amount) }}</span>
					</template>
				</el-table-column>
				<el-table-column prop="abstract" label="摘要" min-width="200" align="left" show-overflow-tooltip />
				<el-table-column prop="creator" label="制单人" width="100" align="center" />
				<el-table-column prop="created_at" label="制单日期" width="110" align="center">
					<template #default="{ row }"><span class="tabular">{{ row.created_at }}</span></template>
				</el-table-column>
				<el-table-column prop="status" label="状态" width="100" align="center">
					<template #default="{ row }">
						<el-tag size="small" :type="ADJUST_STATUS[row.status].tag">{{ ADJUST_STATUS[row.status].label }}</el-tag>
					</template>
				</el-table-column>
				<el-table-column label="操作" width="200" align="center">
					<template #default="{ row }">
						<el-button link type="primary" size="small" @click="openView(row)">查看</el-button>
						<template v-if="row.status === 'pending'">
							<el-button link type="success" size="small" @click="handleAudit(row)">审核</el-button>
							<el-button link type="danger" size="small" @click="handleReject(row)">驳回</el-button>
						</template>
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
		<div class="arap-pager">
			<el-pagination background layout="total, prev, pager, next" :total="total" :page-size="pageSize" :current-page="page" @current-change="page = $event" />
		</div>

		<!-- 新增/查看 Dialog -->
		<el-dialog
			v-model="dialogVisible"
			:title="dialogTitle"
			width="900px"
			:close-on-click-modal="false"
			destroy-on-close
		>
			<el-form :model="form" label-width="90px" size="small" :disabled="mode === 'view'">
				<div class="dialog-head">
					<el-form-item label="调整类型" required>
						<el-select v-model="form.type" style="width: 100%">
							<el-option v-for="t in ADJUST_TYPES" :key="t.value" :label="t.label" :value="t.value" />
						</el-select>
					</el-form-item>
					<el-form-item label="往来单位" required>
						<el-select v-model="form.partner" style="width: 100%">
							<el-option v-for="p in PARTNERS" :key="p.value" :label="p.label" :value="p.value" />
						</el-select>
					</el-form-item>
					<el-form-item label="币种">
						<el-input v-model="form.currency" readonly style="width: 100%" />
					</el-form-item>
					<el-form-item label="调整日期" required>
						<el-date-picker v-model="form.date" type="date" value-format="YYYY-MM-DD" style="width: 100%" />
					</el-form-item>
					<el-form-item label="摘要" class="full-width">
						<el-input v-model="form.abstract" placeholder="请输入摘要" style="width: 100%" />
					</el-form-item>
				</div>

				<div class="dialog-entries">
					<div class="entries-title">明细行</div>
					<el-table :data="form.items" border size="small">
						<el-table-column label="科目" min-width="180">
							<template #default="{ row }">
								<el-select v-model="row.subject" filterable placeholder="选择科目" style="width: 100%">
									<el-option v-for="s in ADJUST_SUBJECTS" :key="s.value" :label="s.label" :value="s.value" />
								</el-select>
							</template>
						</el-table-column>
						<el-table-column label="方向" width="80" align="center">
							<template #default="{ row }">
								<el-radio-group v-model="row.direction" size="small">
									<el-radio-button label="借" value="借">借</el-radio-button>
									<el-radio-button label="贷" value="贷">贷</el-radio-button>
								</el-radio-group>
							</template>
						</el-table-column>
						<el-table-column label="金额" width="130" align="right">
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
								<el-button link type="danger" size="small" :disabled="form.items.length <= 1" @click="removeRow($index)">删除</el-button>
							</template>
						</el-table-column>
					</el-table>
					<div class="entries-footer">
						<el-button link type="primary" size="small" @click="addRow">+ 添加行</el-button>
						<div class="total-sum">
							合计金额：<b class="tabular">¥{{ fmtMoney(totalAmount) }}</b>
						</div>
					</div>
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
import { ElMessage, ElMessageBox } from 'element-plus'
import {
	ADJUST_TYPES,
	ADJUST_TYPE_LABEL,
	ADJUST_STATUS,
	PARTNERS,
	PARTNER_LABEL,
	ADJUST_SUBJECTS,
	fmtMoney,
	mockArapAdjustments,
} from '@/mock/arap-adjustment'

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
	// TODO(api): GET /api/finance/arap-adjustments
	setTimeout(() => {
		rows.value = mockArapAdjustments()
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

const typeTag = (type) => {
	const map = { ar2ap: 'success', ap2ar: 'info', reclass: 'warning' }
	return map[type] || ''
}

// 审核/驳回
const handleAudit = async (row) => {
	try {
		await ElMessageBox.confirm(`确认审核调整单 ${row.no}？`, '审核确认', { type: 'warning' })
		// TODO(api): POST /api/finance/arap-adjustments/{id}/approve
		row.status = 'approved'
		ElMessage.success('审核成功')
	} catch (e) {}
}

const handleReject = async (row) => {
	try {
		const { value: reason } = await ElMessageBox.prompt('请输入驳回原因', '驳回确认', {
			confirmButtonText: '确定',
			cancelButtonText: '取消',
			inputValue: '',
		})
		if (!reason) return ElMessage.warning('请填写驳回原因')
		// TODO(api): POST /api/finance/arap-adjustments/{id}/reject
		row.status = 'rejected'
		row.reject_reason = reason
		ElMessage.success('已驳回')
	} catch (e) {}
}

// Dialog 逻辑
const dialogVisible = ref(false)
const mode = ref('create') // create | view
const dialogTitle = computed(() => (mode.value === 'create' ? '新增调整单' : '查看调整单'))

const form = reactive({
	type: '',
	partner: '',
	currency: 'CNY',
	date: '',
	abstract: '',
	items: [],
})

const openCreate = () => {
	mode.value = 'create'
	Object.assign(form, {
		type: '',
		partner: '',
		currency: 'CNY',
		date: new Date().toISOString().split('T')[0],
		abstract: '',
		items: [{ subject: '', direction: '借', amount: 0, abstract: '' }],
	})
	dialogVisible.value = true
}

const openView = (row) => {
	mode.value = 'view'
	Object.assign(form, { ...row })
	dialogVisible.value = true
}

const addRow = () => {
	form.items.push({ subject: '', direction: '借', amount: 0, abstract: '' })
}

const removeRow = (i) => {
	form.items.splice(i, 1)
}

const totalAmount = computed(() => {
	return form.items.reduce((s, it) => s + Number(it.amount || 0), 0)
})

const saveForm = () => {
	if (!form.type || !form.partner || !form.date) {
		return ElMessage.error('请填写必填项')
	}
	// TODO(api): POST /api/finance/arap-adjustments
	ElMessage.success('保存成功，状态：待审核')
	dialogVisible.value = false
	refresh()
}

onMounted(refresh)
</script>

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
	padding: 16px 20px;
	background: var(--bg-card);
	border-bottom: 1px solid var(--border-base);
}
.arap-filter .label {
	font-size: var(--font-base);
	color: var(--text-regular);
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
	background: #fff;
}
.toolbar-title {
	font-size: 15px;
	font-weight: 600;
	color: var(--text-primary);
}
.arap-content {
	flex: 1;
	min-height: 0;
	padding: 0 12px;
}
.arap-pager {
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
.dialog-head {
	display: grid;
	grid-template-columns: repeat(2, 1fr);
	gap: 16px;
	margin-bottom: 20px;
}
.full-width {
	grid-column: span 2;
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
	font-size: 13px;
	color: var(--text-regular);
}
.total-sum b {
	font-size: 15px;
	color: var(--text-primary);
	margin-left: 4px;
}
</style>
