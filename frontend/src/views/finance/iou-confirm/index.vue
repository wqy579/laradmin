<template>
	<div class="iou-page">
		<!-- 筛选卡 -->
		<div class="iou-filter">
			<span class="label">欠款人</span>
			<el-select v-model="search.partner" clearable filterable placeholder="全部单位" size="small" style="width: 180px" @change="refresh">
				<el-option v-for="p in PARTNERS" :key="p.value" :label="p.label" :value="p.value" />
			</el-select>
			<span class="label">状态</span>
			<el-select v-model="search.status" clearable placeholder="全部状态" size="small" style="width: 130px" @change="refresh">
				<el-option value="">全部</el-option>
				<el-option v-for="(v, k) in IOU_STATUS" :key="k" :label="v.label" :value="k" />
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

		<!-- 工具条 -->
		<div class="iou-toolbar">
			<span class="toolbar-title">欠条确认</span>
			<el-button type="primary" size="small" v-permission="'fin:iou:confirm'" @click="openCreate">+ 新增欠条</el-button>
		</div>

		<!-- 表格区 -->
		<div class="iou-content">
			<el-table
				:data="pagedList"
				border
				height="100%"
				v-loading="loading"
			>
				<el-table-column prop="no" label="欠条编号" width="150" align="left">
					<template #default="{ row }">
						<span class="link tabular" @click="openView(row)">{{ row.no }}</span>
					</template>
				</el-table-column>
				<el-table-column prop="partner" label="欠款人" width="180" align="left">
					<template #default="{ row }">{{ PARTNER_LABEL[row.partner] || '-' }}</template>
				</el-table-column>
				<el-table-column prop="amount" label="欠款金额" width="130" align="right">
					<template #default="{ row }">
						<span class="tabular">{{ fmtMoney(row.amount) }}</span>
					</template>
				</el-table-column>
				<el-table-column prop="date" label="立据日期" width="120" align="center">
					<template #default="{ row }">
						<span class="tabular">{{ row.date }}</span>
					</template>
				</el-table-column>
				<el-table-column prop="due_date" label="约定还款日" width="120" align="center">
					<template #default="{ row }">
						<span class="tabular">{{ row.due_date || '-' }}</span>
					</template>
				</el-table-column>
				<el-table-column prop="status" label="状态" width="100" align="center">
					<template #default="{ row }">
						<el-tag size="small" :type="IOU_STATUS[row.status]?.tag || 'info'">{{ IOU_STATUS[row.status]?.label || row.status }}</el-tag>
					</template>
				</el-table-column>
				<el-table-column label="签字图" width="100" align="center">
					<template #default="{ row }">
						<el-image
							style="width: 40px; height: 30px; object-fit: contain; cursor: zoom-in; border: 1px solid #eee"
							:src="row.signature_url"
							:preview-src="row.signature_url"
							preview-teleported
							fit="contain"
						/>
					</template>
				</el-table-column>
				<el-table-column label="操作" width="180" align="center" fixed="right">
					<template #default="{ row }">
						<el-button link type="primary" size="small" @click="openView(row)">查看</el-button>
						<template v-if="row.status === 'pending'">
							<el-button link type="success" size="small" @click="handleConfirm(row)">确认</el-button>
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
		<div class="iou-pager">
			<el-pagination background layout="total, prev, pager, next" :total="total" :page-size="pageSize" :current-page="page" @current-change="page = $event" />
		</div>

		<!-- 新增 Dialog -->
		<el-dialog
			v-model="createVisible"
			title="新增欠条"
			width="600px"
			:close-on-click-modal="false"
			destroy-on-close
		>
			<el-form :model="createForm" label-width="90px" size="small">
				<div class="dialog-body">
					<el-form-item label="欠款人" required>
						<el-select v-model="createForm.partner" filterable placeholder="选择欠款人" style="width: 100%">
							<el-option v-for="p in PARTNERS" :key="p.value" :label="p.label" :value="p.value" />
						</el-select>
					</el-form-item>
					<el-form-item label="欠款金额" required>
						<el-input-number v-model="createForm.amount" :min="0.01" :precision="2" :controls="false" style="width: 100%" />
					</el-form-item>
					<el-form-item label="立据日期" required>
						<el-date-picker v-model="createForm.date" type="date" value-format="YYYY-MM-DD" style="width: 100%" />
					</el-form-item>
					<el-form-item label="约定还款日" required>
						<el-date-picker v-model="createForm.due_date" type="date" value-format="YYYY-MM-DD" style="width: 100%" />
					</el-form-item>
					<el-form-item label="摘要" class="full-width">
						<el-input v-model="createForm.abstract" placeholder="请输入摘要" style="width: 100%" />
					</el-form-item>
					<el-form-item label="签字图" class="full-width">
						<el-upload
							action="#"
							list-type="picture-card"
							:auto-upload="false"
							:on-change="handleSignatureChange"
							accept="image/*"
						>
							<el-icon><Plus /></el-icon>
							<template #tip>
								<div class="el-upload__tip">请上传欠款人签字图片</div>
							</template>
						</el-upload>
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
			title="欠条详情"
			width="640px"
			:close-on-click-modal="false"
			destroy-on-close
		>
			<template v-if="viewRow">
				<el-form label-width="90px" size="small" class="view-form">
					<div class="dialog-body">
						<el-form-item label="欠条编号">
							<span class="tabular">{{ viewRow.no }}</span>
						</el-form-item>
						<el-form-item label="欠款人">{{ PARTNER_LABEL[viewRow.partner] || '-' }}</el-form-item>
						<el-form-item label="欠款金额">
							<span class="tabular">{{ fmtMoney(viewRow.amount) }}</span>
						</el-form-item>
						<el-form-item label="立据日期">
							<span class="tabular">{{ viewRow.date }}</span>
						</el-form-item>
						<el-form-item label="约定还款日">
							<span class="tabular">{{ viewRow.due_date || '-' }}</span>
						</el-form-item>
						<el-form-item label="状态">
							<el-tag size="small" :type="IOU_STATUS[viewRow.status]?.tag || 'info'">{{ IOU_STATUS[viewRow.status]?.label || viewRow.status }}</el-tag>
						</el-form-item>
						<el-form-item label="制单人">{{ viewRow.creator }}</el-form-item>
						<el-form-item label="摘要" class="full-width">{{ viewRow.abstract || '-' }}</el-form-item>
						<el-form-item label="驳回原因" v-if="viewRow.status === 'rejected'" class="full-width">
							<span class="text-danger">{{ viewRow.reject_reason || '-' }}</span>
						</el-form-item>
						<el-form-item label="签字图" class="full-width" align="center">
							<el-image
								style="max-width: 300px; border: 1px solid #eee; padding: 4px"
								:src="viewRow.signature_url"
								fit="contain"
							/>
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
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import {
	PARTNERS,
	PARTNER_LABEL,
	IOU_STATUS,
	IOU_STATUS_LABEL,
	fmtMoney,
	today,
	mockIous,
	genIouNo,
} from '@/mock/iou-confirm'

const loading = ref(false)
const page = ref(1)
const pageSize = 20
const rows = ref([])

const search = reactive({
	partner: '',
	status: '',
	daterange: [],
})

const refresh = () => {
	loading.value = true
	// TODO(api): GET /api/finance/ious
	setTimeout(() => {
		rows.value = mockIous()
		loading.value = false
	}, 300)
}

const resetSearch = () => {
	search.partner = ''
	search.status = ''
	search.daterange = []
	refresh()
}

const filteredList = computed(() => {
	return rows.value.filter((r) => {
		if (search.partner && r.partner !== search.partner) return false
		if (search.status && r.status !== search.status) return false
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

/* ---------- 交互逻辑 ---------- */

const handleConfirm = async (row) => {
	try {
		await ElMessageBox.confirm(`确认欠条 ${row.no} 为正确无误？`, '确认确认', { type: 'warning' })
		// TODO(api): POST /api/finance/ious/{id}/confirm
		row.status = 'confirmed'
		ElMessage.success('确认成功')
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
		// TODO(api): POST /api/finance/ious/{id}/reject
		row.status = 'rejected'
		row.reject_reason = reason
		ElMessage.success('已驳回')
	} catch (e) {}
}

/* ---------- 新增 Dialog ---------- */
const createVisible = ref(false)
const createForm = reactive({
	partner: '',
	amount: 0,
	date: today(),
	due_date: '',
	abstract: '',
	signature_file: '',
	signature_url: '',
})

const openCreate = () => {
	Object.assign(createForm, {
		partner: '',
		amount: 0,
		date: today(),
		due_date: '',
		abstract: '',
		signature_file: '',
		signature_url: '',
	})
	createVisible.value = true
}

const handleSignatureChange = (file) => {
	// 纯前端 mock：使用 URL.createObjectURL 预览
	createForm.signature_file = file.name
	createForm.signature_url = URL.createObjectURL(file.raw)
}

const saveCreate = () => {
	if (!createForm.partner || !createForm.amount || !createForm.date || !createForm.due_date) {
		return ElMessage.error('请填写必填项')
	}
	if (!createForm.signature_url) {
		return ElMessage.error('请上传签字图')
	}
	// TODO(api): POST /api/finance/ious
	ElMessage.success(`保存成功，欠条编号：${genIouNo(createForm.date)}，状态：待确认`)
	createVisible.value = false
	refresh()
}

/* ---------- 查看 Dialog ---------- */
const viewVisible = ref(false)
const viewRow = ref(null)

const openView = (row) => {
	// TODO(api): GET /api/finance/ious/{id}
	viewRow.value = row
	viewVisible.value = true
}

onMounted(refresh)
</script>

<style scoped>
.iou-page {
	height: 100%;
	display: flex;
	flex-direction: column;
	overflow: hidden;
}
.iou-filter {
	display: flex;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
	padding: 16px 20px;
	background: var(--bg-card);
	border-bottom: 1px solid var(--border-base);
}
.iou-filter .label {
	font-size: var(--font-base);
	color: var(--text-regular);
}
.filter-actions {
	margin-left: auto;
	display: flex;
	gap: 12px;
}
.iou-toolbar {
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
.iou-content {
	flex: 1;
	min-height: 0;
	padding: 0 12px;
}
.iou-pager {
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
</style>
