<template>
	<div class="company-page">
		<div class="header">
			<span class="title">公司档案管理</span>
		</div>

		<div class="main">
			<!-- 查询区 -->
			<div class="filter-bar">
				<el-input
					v-model="search.name"
					placeholder="请输入公司名称"
					clearable
					style="width:180px"
					@clear="onSearch"
					@keyup.enter="onSearch"
				/>
				<el-input
					v-model="search.code"
					placeholder="请输入公司编码"
					clearable
					style="width:160px"
					@clear="onSearch"
					@keyup.enter="onSearch"
				/>
				<el-select v-model="search.status" placeholder="全部" clearable style="width:120px" @change="onSearch">
					<el-option label="启用" value="enabled" />
					<el-option label="停用" value="disabled" />
				</el-select>

				<div class="filter-actions">
					<el-button class="btn-query" @click="onSearch">查询</el-button>
					<el-button class="btn-reset" @click="reset">重置</el-button>
				</div>
			</div>

			<!-- 操作按钮区 -->
			<div class="action-bar">
				<el-button v-permission="'data:company:create'" class="btn-add" @click="openForm()">
					<el-icon class="btn-icon"><PlusOutlined /></el-icon>
					<span>新增公司</span>
				</el-button>
				<el-button
					v-permission="'data:company:edit'"
					class="btn-enable"
					:disabled="!selection.length"
					@click="batchStatus('enabled')"
				>批量启用</el-button>
				<el-button
					v-permission="'data:company:edit'"
					class="btn-disable"
					:disabled="!selection.length"
					@click="batchStatus('disabled')"
				>批量停用</el-button>
				<el-button v-permission="'data:company:export'" class="btn-export" @click="handleExport">
					<el-icon class="btn-icon"><DownloadOutlined /></el-icon>
					<span>导出</span>
				</el-button>
				<span v-if="selection.length" class="selected-hint">已选 {{ selection.length }} 项</span>
			</div>

			<!-- 列表 -->
			<el-table
				:data="list"
				v-loading="loading"
				style="width:100%"
				:header-cell-style="headerStyle"
				:row-style="rowStyle"
				@selection-change="onSelectionChange"
			>
				<el-table-column type="selection" width="45" align="center" />
				<el-table-column prop="code" label="公司编码" width="110" align="center" class-name="col-code" />
				<el-table-column label="公司名称" min-width="200" align="left" show-overflow-tooltip>
					<template #default="{ row }">
						<span class="company-name" @click="openDetail(row)">{{ row.name }}</span>
						<span v-if="row.is_default" class="default-tag">默认</span>
					</template>
				</el-table-column>
				<el-table-column prop="short_name" label="简称" width="110" align="left" show-overflow-tooltip>
					<template #default="{ row }">{{ row.short_name || '-' }}</template>
				</el-table-column>
				<el-table-column prop="tax_no" label="纳税人识别号" width="180" align="left" class-name="col-code" show-overflow-tooltip>
					<template #default="{ row }">{{ row.tax_no || '-' }}</template>
				</el-table-column>
				<el-table-column label="开票名称" min-width="160" align="left" show-overflow-tooltip>
					<template #default="{ row }">{{ row.invoice_title || '-' }}</template>
				</el-table-column>
				<el-table-column label="开户行 / 账号" min-width="200" align="left" show-overflow-tooltip>
					<template #default="{ row }">{{ bankText(row) }}</template>
				</el-table-column>
				<el-table-column label="联系人" width="140" align="left" show-overflow-tooltip>
					<template #default="{ row }">{{ contactText(row) }}</template>
				</el-table-column>
				<el-table-column label="事业部" width="90" align="center">
					<template #default="{ row }">
						<span class="child-count">{{ row.division_count }}</span>
					</template>
				</el-table-column>
				<el-table-column prop="sort" label="排序" width="70" align="center" class-name="col-sort" />
				<el-table-column label="状态" width="90" align="center">
					<template #default="{ row }">
						<span class="status-tag" :class="row.status === 'enabled' ? 'status-on' : 'status-off'">
							{{ row.status === 'enabled' ? '启用' : '停用' }}
						</span>
					</template>
				</el-table-column>
				<el-table-column label="创建时间" width="150" align="center" class-name="col-time">
					<template #default="{ row }">{{ row.created_at ? row.created_at.slice(0, 16) : '-' }}</template>
				</el-table-column>
				<el-table-column label="操作" width="140" align="center" fixed="right">
					<template #default="{ row }">
						<span v-permission="'data:company:edit'" class="op-link" @click="openForm(row)">编辑</span>
						<span v-permission="'data:company:delete'" class="op-link op-danger" @click="onDelete(row)">删除</span>
					</template>
				</el-table-column>
				<template #empty>
					<span class="empty-hint">当前筛选条件下暂无公司档案</span>
				</template>
			</el-table>

			<!-- 分页 -->
			<el-pagination
				:current-page="page"
				:page-size="pageSize"
				:total="total"
				:page-sizes="[10, 20, 30, 50]"
				layout="total, prev, pager, next, sizes"
				@current-change="onPageChange"
				@size-change="onPageSizeChange"
			/>
		</div>

		<!-- 新增 / 编辑弹窗 -->
		<el-dialog
			v-model="formVisible"
			:title="form.id ? '编辑公司' : '新增公司'"
			width="680px"
			:close-on-click-modal="false"
			class="archive-dialog"
			@closed="onFormClosed"
		>
			<div class="form-body">
				<el-form ref="formRef" :model="form" :rules="rules" label-width="110px">
					<el-form-item label="公司编码" prop="code">
						<el-input v-model="form.code" placeholder="人工登记，需与单据/发票口径一致，如 GS01" maxlength="30" />
					</el-form-item>
					<el-form-item label="公司名称" prop="name">
						<el-input v-model="form.name" placeholder="营业执照全称" maxlength="100" show-word-limit />
					</el-form-item>
					<el-form-item label="简称" prop="short_name">
						<el-input v-model="form.short_name" placeholder="列表与报表里显示的简称" maxlength="50" />
					</el-form-item>
					<el-form-item label="纳税人识别号" prop="tax_no">
						<el-input v-model="form.tax_no" placeholder="15/18/20 位统一社会信用代码" maxlength="30" />
					</el-form-item>
					<el-form-item label="开票名称" prop="invoice_title">
						<el-input v-model="form.invoice_title" placeholder="留空则与公司名称一致" maxlength="100" />
					</el-form-item>
					<el-form-item label="开户银行" prop="bank_name">
						<el-input v-model="form.bank_name" placeholder="如 中国工商银行 XX 支行" maxlength="100" />
					</el-form-item>
					<el-form-item label="银行账号" prop="bank_account">
						<el-input v-model="form.bank_account" placeholder="对公账号" maxlength="40" />
					</el-form-item>
					<el-form-item label="联系人" prop="contact_name">
						<el-input v-model="form.contact_name" maxlength="30" />
					</el-form-item>
					<el-form-item label="联系电话" prop="contact_phone">
						<el-input v-model="form.contact_phone" maxlength="20" />
					</el-form-item>
					<el-form-item label="注册地址" prop="address">
						<el-input v-model="form.address" maxlength="200" />
					</el-form-item>
					<el-form-item label="默认公司" prop="is_default">
						<el-switch v-model="form.is_default" />
						<span class="form-hint">全系统至多一个默认，设为默认会自动取消原默认</span>
					</el-form-item>
					<el-form-item label="排序" prop="sort">
						<el-input-number v-model="form.sort" :min="0" :step="1" style="width:120px" />
					</el-form-item>
					<el-form-item label="状态" prop="status">
						<el-radio-group v-model="form.status">
							<el-radio value="enabled">启用</el-radio>
							<el-radio value="disabled">停用</el-radio>
						</el-radio-group>
					</el-form-item>
					<el-form-item label="备注" prop="remark">
						<el-input v-model="form.remark" type="textarea" :rows="3" maxlength="255" show-word-limit />
					</el-form-item>
				</el-form>
			</div>
			<template #footer>
				<el-button class="btn-cancel" @click="formVisible = false">取消</el-button>
				<el-button class="btn-confirm" :loading="saving" @click="onSave">确定</el-button>
			</template>
		</el-dialog>

		<!-- 详情：主体要素 + 名下事业部 -->
		<el-dialog v-model="detailVisible" title="公司详情" width="640px" class="archive-dialog">
			<el-descriptions v-if="detail" :column="2" border size="small">
				<el-descriptions-item label="公司编码">{{ detail.code || '-' }}</el-descriptions-item>
				<el-descriptions-item label="状态">{{ detail.status === 'enabled' ? '启用' : '停用' }}</el-descriptions-item>
				<el-descriptions-item label="公司名称">{{ detail.name }}</el-descriptions-item>
				<el-descriptions-item label="简称">{{ detail.short_name || '-' }}</el-descriptions-item>
				<el-descriptions-item label="纳税人识别号">{{ detail.tax_no || '-' }}</el-descriptions-item>
				<el-descriptions-item label="开票名称">{{ detail.invoice_title || '-' }}</el-descriptions-item>
				<el-descriptions-item label="开户银行">{{ detail.bank_name || '-' }}</el-descriptions-item>
				<el-descriptions-item label="银行账号">{{ detail.bank_account || '-' }}</el-descriptions-item>
				<el-descriptions-item label="联系人">{{ detail.contact_name || '-' }}</el-descriptions-item>
				<el-descriptions-item label="联系电话">{{ detail.contact_phone || '-' }}</el-descriptions-item>
				<el-descriptions-item label="注册地址" :span="2">{{ detail.address || '-' }}</el-descriptions-item>
				<el-descriptions-item label="备注" :span="2">{{ detail.remark || '-' }}</el-descriptions-item>
			</el-descriptions>
			<div class="detail-subtitle">名下事业部（{{ detail?.divisions?.length ?? 0 }} 个）</div>
			<el-table :data="detail?.divisions || []" border size="small" :header-cell-style="headerStyle" :row-style="rowStyle">
				<el-table-column prop="code" label="事业部编码" width="130" align="center" class-name="col-code" />
				<el-table-column prop="name" label="事业部名称" min-width="160" show-overflow-tooltip />
				<el-table-column label="状态" width="90" align="center">
					<template #default="{ row }">
						<span class="status-tag" :class="row.status === 'enabled' ? 'status-on' : 'status-off'">
							{{ row.status === 'enabled' ? '启用' : '停用' }}
						</span>
					</template>
				</el-table-column>
				<template #empty>
					<span class="empty-hint">该公司下暂无事业部</span>
				</template>
			</el-table>
			<template #footer>
				<el-button class="btn-cancel" @click="detailVisible = false">关闭</el-button>
			</template>
		</el-dialog>
	</div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { PlusOutlined, DownloadOutlined } from '@ant-design/icons-vue'
import dataApi from '@/api/data.js'
import { downloadApiResponse } from '@/utils/download'

const list = ref([])
const total = ref(0)
const loading = ref(false)
const page = ref(1)
const pageSize = ref(30)
const selection = ref([])
const saving = ref(false)
const formVisible = ref(false)
const formRef = ref(null)
const detailVisible = ref(false)
const detail = ref(null)

const search = reactive({ name: '', code: '', status: '' })

const emptyForm = () => ({
	id: null,
	code: '',
	name: '',
	short_name: '',
	tax_no: '',
	invoice_title: '',
	bank_name: '',
	bank_account: '',
	contact_name: '',
	contact_phone: '',
	address: '',
	is_default: false,
	status: 'enabled',
	sort: 0,
	remark: '',
})
const form = ref(emptyForm())

const rules = {
	name: [{ required: true, message: '请输入公司名称', trigger: 'blur' }],
}

// 表头/表体按规格定制：48px 行高、斑马纹、悬停高亮
const headerStyle = {
	background: '#F5F7FA',
	color: '#606266',
	fontWeight: '500',
	fontSize: '14px',
	height: '48px',
	borderBottom: '1px solid #EBEEF5',
}
const rowStyle = ({ rowIndex }) => ({
	height: '48px',
	fontSize: '14px',
	color: '#606266',
	background: rowIndex % 2 === 0 ? '#ffffff' : '#FAFAFA',
})

const bankText = (row) => {
	if (!row.bank_name && !row.bank_account) return '-'
	return [row.bank_name, row.bank_account].filter(Boolean).join(' / ')
}

const contactText = (row) => {
	if (!row.contact_name && !row.contact_phone) return '-'
	return [row.contact_name, row.contact_phone].filter(Boolean).join(' ')
}

// 空值整个省略：后端用 $request->filled() 判断，传空串虽不会误筛，
// 但会让导出的 CSV 与列表口径带上无意义的查询条件。
const buildQuery = () => {
	const params = { page: page.value, page_size: pageSize.value }
	if (search.name) params.name = search.name
	if (search.code) params.code = search.code
	if (search.status) params.status = search.status
	return params
}

const load = async () => {
	loading.value = true
	try {
		const res = await dataApi.company.list.get(buildQuery())
		list.value = res.data?.list || []
		total.value = res.data?.total ?? 0
	} finally {
		loading.value = false
	}
}

const onSearch = () => {
	page.value = 1
	load()
}
const reset = () => {
	search.name = ''
	search.code = ''
	search.status = ''
	page.value = 1
	load()
}
const onPageChange = (p) => {
	page.value = p
	load()
}
const onPageSizeChange = (size) => {
	pageSize.value = size
	page.value = 1
	load()
}
const onSelectionChange = (rows) => {
	selection.value = rows
}

const openForm = (row) => {
	form.value = row
		? {
				...emptyForm(),
				...row,
				code: row.code ?? '',
				remark: row.remark ?? '',
				is_default: !!row.is_default,
				status: row.status || 'enabled',
				sort: row.sort ?? 0,
			}
		: emptyForm()
	formVisible.value = true
}

const onFormClosed = () => {
	form.value = emptyForm()
	formRef.value?.clearValidate()
}

const openDetail = async (row) => {
	detail.value = null
	detailVisible.value = true
	try {
		const res = await dataApi.company.detail.get(row.id)
		detail.value = res.data || null
	} catch {
		detailVisible.value = false
	}
}

const onSave = async () => {
	await formRef.value.validate()

	const payload = { ...form.value }
	delete payload.id
	// 空串归一成 null：编码留空表示「不登记」，写成 '' 会撞 code 唯一索引
	// （sqlite 允许多行 ''，MySQL 也只允许一行），且导出会显示成空列。
	Object.keys(payload).forEach((key) => {
		if (payload[key] === '') payload[key] = null
	})
	payload.sort = Number(payload.sort) || 0

	saving.value = true
	try {
		if (form.value.id) {
			await dataApi.company.edit.put(form.value.id, payload)
			ElMessage.success('已更新')
		} else {
			await dataApi.company.add.post(payload)
			ElMessage.success('已创建')
		}
		formVisible.value = false
		load()
	} catch {
		// 拦截器已弹过具体错误（如「公司编码已存在」），这里不重复弹
	} finally {
		saving.value = false
	}
}

// 删除是软删但仍不可逆（历史单据主体缺省），按规格用「操作确认」标题二次确认
const onDelete = (row) => {
	ElMessageBox.confirm(`确认删除公司「${row.name}」？删除后不可恢复。`, '操作确认', {
		confirmButtonText: '删除',
		cancelButtonText: '取消',
		type: 'warning',
	})
		.then(async () => {
			try {
				await dataApi.company.delete.delete(row.id)
				ElMessage.success('已删除')
				load()
			} catch { /* 后端拒绝文案（默认公司 / 名下有事业部）由拦截器弹出 */ }
		})
		.catch(() => {})
}

const batchStatus = (status) => {
	const ids = selection.value.map((r) => r.id)
	if (!ids.length) return
	const label = status === 'enabled' ? '启用' : '停用'
	ElMessageBox.confirm(`确认${label}已选的 ${ids.length} 家公司？`, '操作确认', {
		confirmButtonText: '确定',
		cancelButtonText: '取消',
		type: 'warning',
	})
		.then(async () => {
			try {
				await dataApi.company.batchUpdateStatus.post({ ids, status })
				ElMessage.success('已更新')
				load()
			} catch { /* 拦截器已提示 */ }
		})
		.catch(() => {})
}

const handleExport = async () => {
	try {
		const res = await dataApi.company.export(buildQuery())
		downloadApiResponse(res, 'companies.csv')
	} catch { /* 拦截器已提示 */ }
}

onMounted(load)
</script>

<style scoped>
.company-page {
	min-height: 100vh;
}

.header {
	display: flex;
	align-items: center;
	height: 60px;
	padding: 0 24px;
	background: #ffffff;
	border-bottom: 1px solid #EBEEF5;
}

.title {
	font-size: 18px;
	font-weight: 700;
	color: #303133;
}

.main {
	padding: 16px 24px;
}

/* ---------- 查询区 ---------- */
.filter-bar {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 16px 20px;
	background: #ffffff;
	border-bottom: 1px solid #EBEEF5;
	border-radius: 4px 4px 0 0;
}

.filter-bar :deep(.el-input__inner),
.filter-bar :deep(.el-select) {
	font-size: 14px;
	color: #606266;
}

.filter-actions {
	display: flex;
	gap: 8px;
	margin-left: auto;
}

.btn-query {
	width: 80px;
	height: 32px;
	font-size: 14px;
	border-radius: 4px;
	border: none;
	background: #409EFF;
}

.btn-reset {
	width: 80px;
	height: 32px;
	font-size: 14px;
	border-radius: 4px;
	background: #ffffff;
	border: 1px solid #DCDFE6;
	color: #606266;
}

/* ---------- 操作按钮区 ---------- */
.action-bar {
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 12px 20px;
	background: #ffffff;
	border-bottom: 1px solid #EBEEF5;
}

.btn-add,
.btn-enable,
.btn-disable {
	height: 36px;
	font-size: 14px;
	border-radius: 4px;
	border: none;
	color: #ffffff;
}

.btn-add {
	width: 110px;
	background: #409EFF;
}

.btn-enable {
	width: 100px;
	background: #67C23A;
}

.btn-disable {
	width: 100px;
	background: #E6A23C;
}

.btn-export {
	height: 36px;
	width: 90px;
	font-size: 14px;
	border-radius: 4px;
	background: #ffffff;
	border: 1px solid #DCDFE6;
	color: #606266;
}

.btn-add,
.btn-export {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	justify-content: center;
}

.btn-icon {
	font-size: 14px;
}

.btn-add .btn-icon {
	color: #ffffff;
}

.selected-hint {
	font-size: 12px;
	color: #909399;
	margin-left: 4px;
}

/* ---------- 表格 ---------- */
:deep(.el-table) {
	background: #ffffff;
	border-radius: 0 0 4px 4px;
}

:deep(.el-table--border::after),
:deep(.el-table--border .el-table__inner-wrapper::before) {
	background-color: #EBEEF5;
}

/* 斑马纹由 row-style 内联样式写入，优先级高于 EP 的 hover 类，
   这里把它压回去，否则规格要求的 hover #F5F7FA 永远不生效 */
:deep(.el-table__body tr:hover > td.el-table__cell) {
	background-color: #F5F7FA !important;
}

.col-code {
	color: #909399;
	font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
}

.company-name {
	color: #409EFF;
	cursor: pointer;
}

.company-name:hover {
	text-decoration: underline;
}

.default-tag {
	display: inline-block;
	margin-left: 6px;
	padding: 1px 6px;
	font-size: 12px;
	color: #409EFF;
	background: #ECF5FF;
	border-radius: 4px;
}

.child-count {
	color: #F56C6C;
	font-weight: 500;
}

.col-sort,
.col-time {
	color: #909399;
}

.status-tag {
	display: inline-block;
	padding: 2px 10px;
	font-size: 12px;
	border-radius: 4px;
}

.status-on {
	color: #67C23A;
	background: #F0F9EB;
}

.status-off {
	color: #909399;
	background: #F4F4F5;
}

.empty-hint {
	font-size: 14px;
	color: #909399;
}

.op-link {
	color: #409EFF;
	cursor: pointer;
	font-size: 14px;
}

.op-link + .op-link {
	margin-left: 8px;
}

.op-link:hover {
	text-decoration: underline;
}

.op-danger {
	color: #F56C6C;
}

/* ---------- 分页 ---------- */
:deep(.el-pagination) {
	margin-top: 16px;
	justify-content: flex-end;
	font-size: 14px;
	color: #606266;
}

/* ---------- 弹窗 ---------- */
.archive-dialog :deep(.el-dialog) {
	border-radius: 4px;
	box-shadow: 0 2px 12px rgba(0, 0, 0, 0.1);
}

.archive-dialog :deep(.el-dialog__header) {
	height: 50px;
	padding: 0 20px;
	border-bottom: 1px solid #EBEEF5;
	margin-right: 0;
	display: flex;
	align-items: center;
}

.archive-dialog :deep(.el-dialog__title) {
	font-size: 16px;
	font-weight: 700;
	color: #303133;
}

.archive-dialog :deep(.el-dialog__headerbtn) {
	top: 16px;
	color: #909399;
}

.archive-dialog :deep(.el-dialog__body) {
	padding: 20px;
}

.archive-dialog :deep(.el-dialog__footer) {
	height: 60px;
	padding: 0 20px;
	border-top: 1px solid #EBEEF5;
	display: flex;
	align-items: center;
	justify-content: flex-end;
}

.form-body :deep(.el-form-item__label) {
	width: 110px;
	text-align: right;
	font-size: 14px;
	color: #606266;
}

.form-body :deep(.el-input__inner),
.form-body :deep(.el-textarea__inner),
.form-body :deep(.el-select__input) {
	font-size: 14px;
	height: 36px;
	border: 1px solid #DCDFE6;
	border-radius: 4px;
}

.form-body :deep(.el-textarea__inner) {
	height: 80px;
}

.form-body :deep(.el-input.is-focus .el-input__inner),
.form-body :deep(.el-textarea__inner:focus) {
	border-color: #409EFF;
}

.form-body :deep(.el-input-number) {
	width: 120px;
}

.form-body :deep(.el-radio__label) {
	font-size: 14px;
	color: #606266;
}

.form-body :deep(.el-radio__input.is-checked .el-radio__inner) {
	border-color: #409EFF;
}

.form-body :deep(.el-radio__input.is-checked + .el-radio__label) {
	color: #409EFF;
}

.form-hint {
	margin-left: 12px;
	font-size: 12px;
	color: #909399;
}

.detail-subtitle {
	margin: 16px 0 8px;
	font-size: 14px;
	font-weight: 600;
	color: #303133;
}

.btn-cancel {
	width: 80px;
	height: 36px;
	margin-right: 12px;
	font-size: 14px;
	border-radius: 4px;
	background: #ffffff;
	border: 1px solid #DCDFE6;
	color: #606266;
}

.btn-confirm {
	width: 80px;
	height: 36px;
	font-size: 14px;
	border-radius: 4px;
	border: none;
	background: #409EFF;
}
</style>
