<template>
	<div class="iou-page fin-page">
		<!-- 筛选卡 -->
		<div class="iou-filter fin-filter">
			<span class="label">欠款人</span>
			<el-select v-model="search.partner_id" clearable filterable placeholder="全部单位" size="small" style="width: 180px" @change="onSearch">
				<el-option v-for="p in partners" :key="p.value" :label="p.label" :value="p.value" />
			</el-select>
			<span class="label">状态</span>
			<el-select v-model="search.status" clearable placeholder="全部状态" size="small" style="width: 130px" @change="onSearch">
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
				@change="onSearch"
			/>
			<div class="filter-actions fin-toolbar">
				<el-button type="primary" @click="onSearch">查询</el-button>
				<el-button @click="resetSearch">重置</el-button>
			</div>
		</div>

		<!-- 工具条 -->
		<div class="iou-toolbar fin-toolbar">
			<span class="toolbar-title">欠条确认</span>
			<el-button type="primary" v-permission="'fin:iou:confirm'" @click="openCreate">+ 新增欠条</el-button>
		</div>

		<!-- 表格区 -->
		<div class="iou-content">
			<el-table :data="rows" border height="100%" v-loading="loading" stripe>
				<el-table-column prop="no" label="欠条编号" width="150" align="left">
					<template #default="{ row }">
						<span class="link tabular" @click="openView(row)">{{ row.no }}</span>
					</template>
				</el-table-column>
				<el-table-column prop="partner_name" label="欠款人" min-width="180" align="left">
					<template #default="{ row }">{{ row.partner_name || '-' }}</template>
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
				<el-table-column prop="due_date" label="约定还款日" width="130" align="center">
					<template #default="{ row }">
						<span class="tabular" :class="{ 'text-danger': row.is_overdue }">{{ row.due_date || '-' }}</span>
						<el-tag v-if="row.is_overdue" size="small" type="danger" class="overdue-tag">已逾期</el-tag>
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
							v-if="row.signature_url"
							style="width: 40px; height: 30px; object-fit: contain; cursor: zoom-in; border: 1px solid var(--border-lighter)"
							:src="row.signature_url"
							:preview-src="row.signature_url"
							preview-teleported
							fit="contain"
						/>
						<span v-else>-</span>
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
					<FinEmpty />
				</template>
			</el-table>
		</div>

		<!-- 分页 -->
		<div class="fin-pager">
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
		<el-dialog v-model="createVisible" title="新增欠条" width="680px" class="fin-dialog" :close-on-click-modal="false" destroy-on-close>
			<el-form :model="createForm" label-width="110px">
				<div class="dialog-body">
					<el-form-item label="欠款人" required>
						<el-select v-model="createForm.partner_id" filterable placeholder="选择欠款人" style="width: 100%" @change="onPartnerPick">
							<el-option v-for="p in partners" :key="p.value" :label="p.label" :value="p.value" />
						</el-select>
					</el-form-item>
					<el-form-item label="欠款金额" required>
						<el-input-number
							v-model="createForm.amount"
							:min="0"
							:step="100"
							:precision="2"
							:controls-position="'right'"
							style="width: 100%"
						/>
					</el-form-item>
					<el-form-item label="立据日期" required>
						<el-date-picker v-model="createForm.doc_date" type="date" value-format="YYYY-MM-DD" style="width: 100%" />
					</el-form-item>
					<el-form-item label="约定还款日" required>
						<el-date-picker v-model="createForm.due_date" type="date" value-format="YYYY-MM-DD" style="width: 100%" />
					</el-form-item>
					<el-form-item label="摘要" class="full-width">
						<el-input v-model="createForm.abstract" placeholder="请输入摘要" style="width: 100%" />
					</el-form-item>
					<el-form-item label="签字图" required class="full-width">
						<el-upload action="#" list-type="picture-card" :auto-upload="false" :on-change="handleSignatureChange" accept="image/*">
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
				<el-button type="primary" size="small" :loading="saving" @click="saveCreate">保存</el-button>
			</template>
		</el-dialog>

		<!-- 查看 Dialog -->
		<el-dialog v-model="viewVisible" title="欠条详情" width="680px" class="fin-dialog" :close-on-click-modal="false" destroy-on-close>
			<template v-if="viewRow">
				<el-form label-width="110px" class="view-form">
					<div class="dialog-body">
						<el-form-item label="欠条编号">
							<span class="tabular">{{ viewRow.no }}</span>
						</el-form-item>
						<el-form-item label="欠款人">{{ viewRow.partner_name || '-' }}</el-form-item>
						<el-form-item label="欠款金额">
							<span class="tabular">{{ fmtMoney(viewRow.amount) }}</span>
						</el-form-item>
						<el-form-item label="立据日期">
							<span class="tabular">{{ viewRow.date }}</span>
						</el-form-item>
						<el-form-item label="约定还款日">
							<span class="tabular" :class="{ 'text-danger': viewRow.is_overdue }">{{ viewRow.due_date || '-' }}</span>
							<el-tag v-if="viewRow.is_overdue" size="small" type="danger" class="overdue-tag">已逾期</el-tag>
						</el-form-item>
						<el-form-item label="状态">
							<el-tag size="small" :type="IOU_STATUS[viewRow.status]?.tag || 'info'">{{
								IOU_STATUS[viewRow.status]?.label || viewRow.status
							}}</el-tag>
						</el-form-item>
						<el-form-item label="资金流水">
							<span class="tabular">{{ viewRow.cash_flow_no || '-' }}</span>
						</el-form-item>
						<el-form-item label="摘要" class="full-width">{{ viewRow.abstract || '-' }}</el-form-item>
						<el-form-item label="驳回原因" v-if="viewRow.status === 'rejected'" class="full-width">
							<span class="text-danger">{{ viewRow.reject_reason || '-' }}</span>
						</el-form-item>
						<el-form-item label="签字图" class="full-width" align="center">
							<el-image v-if="viewRow.signature_url" style="max-width: 300px; border: 1px solid var(--border-lighter); padding: 4px" :src="viewRow.signature_url" fit="contain" />
							<span v-else>-</span>
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
import { Plus } from '@element-plus/icons-vue'
import businessApi from '@/api/business'
import FinEmpty from '../_FinEmpty.vue'

const DOC_TYPE = 'iou'
const IOU_STATUS = {
	pending: { label: '待确认', tag: 'warning' },
	confirmed: { label: '已确认', tag: 'success' },
	rejected: { label: '已驳回', tag: 'danger' },
}

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
	daterange: [],
})

// 欠款人下拉：欠条挂在客户往来上，取真实客户档案
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
	search.daterange = []
	onSearch()
}

/* ---------- 交互逻辑 ---------- */

const handleConfirm = async (row) => {
	try {
		await ElMessageBox.confirm(`确认欠条 ${row.no} 为正确无误？`, '确认确认', { type: 'warning' })
	} catch {
		return
	}
	try {
		await businessApi.finance.fundOrder.confirm.post(DOC_TYPE, row.id)
		ElMessage.success('确认成功')
		refresh()
	} catch (e) {
		if (e?.message) ElMessage.error(e.message)
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
	due_date: '',
	abstract: '',
	signature_url: '',
})
const createForm = reactive(emptyForm())

const openCreate = () => {
	Object.assign(createForm, emptyForm())
	createVisible.value = true
}

const onPartnerPick = (id) => {
	createForm.partner_name = partners.value.find((p) => p.value === id)?.label || ''
}

const handleSignatureChange = (file) => {
	// 签字图仅前端预览（本域无上传端点，勿跨界新增）；建单只提交预览地址
	createForm.signature_url = URL.createObjectURL(file.raw)
}

const saveCreate = async () => {
	if (!createForm.partner_id || !createForm.amount || !createForm.doc_date || !createForm.due_date) {
		return ElMessage.error('请填写必填项')
	}
	if (!createForm.signature_url) {
		return ElMessage.error('请上传签字图')
	}
	saving.value = true
	try {
		const res = await businessApi.finance.fundOrder.add.post(DOC_TYPE, {
			partner_type: 'customer',
			partner_id: createForm.partner_id,
			partner_name: createForm.partner_name,
			amount: createForm.amount,
			doc_date: createForm.doc_date,
			due_date: createForm.due_date,
			abstract: createForm.abstract,
			signature_url: createForm.signature_url,
		})
		const no = res?.data?.data?.no || ''
		ElMessage.success(`保存成功，欠条编号：${no}，状态：待确认`)
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

onMounted(() => {
	loadPartners()
	refresh()
})
</script>

<style>
@import '../_shared.css';
</style>

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
	background: var(--bg-card);
}
.toolbar-title {
	font-size: var(--font-h3);
	font-weight: 600;
	color: var(--text-primary);
}
.iou-content {
	flex: 1;
	min-height: 0;
	padding: 0 12px;
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
:deep(.el-table) {
	font-size: var(--font-base);
	color: var(--text-primary);
}
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
