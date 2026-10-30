<template>
	<div class="download-page">
		<div class="header">
			<span class="title">下载中心</span>
		</div>

		<div class="main">
			<div class="filter-bar">
				<el-input
					v-model="search.keyword"
					placeholder="请输入文件名称"
					clearable
					style="width:200px"
					@clear="onSearch"
					@keyup.enter="onSearch"
				/>
				<el-select v-model="search.category" placeholder="全部分组" clearable style="width:130px" @change="onSearch">
					<el-option v-for="c in categoryNames" :key="c" :label="c" :value="c" />
				</el-select>
				<el-select v-model="search.status" placeholder="全部状态" clearable style="width:130px" @change="onSearch">
					<el-option label="已发布" value="published" />
					<el-option label="已下架" value="disabled" />
				</el-select>
				<div class="filter-actions">
					<el-button class="btn-query" @click="onSearch">查询</el-button>
					<el-button class="btn-reset" @click="reset">重置</el-button>
				</div>
			</div>

			<div class="action-bar">
				<el-button v-permission="'data:download:create'" class="btn-add" @click="openUpload()">
					<el-icon class="btn-icon"><PlusOutlined /></el-icon>
					<span>上传文件</span>
				</el-button>
				<span class="tip-hint">
					支持 {{ ALLOWED_EXT.join('/') }}，单个不超过 20MB；文件存于非公开目录，只能经本页面下载
				</span>
			</div>

			<el-table :data="list" v-loading="loading" style="width:100%" :header-cell-style="headerStyle" :row-style="rowStyle">
				<el-table-column label="文件名称" min-width="240" align="left" show-overflow-tooltip>
					<template #default="{ row }">
						<span class="file-name" @click="onDownload(row)">{{ row.file_name }}</span>
					</template>
				</el-table-column>
				<el-table-column prop="name" label="显示名称" min-width="180" align="left" show-overflow-tooltip />
				<el-table-column prop="category" label="分组" width="90" align="center" />
				<el-table-column label="大小" width="100" align="right" class-name="col-size">
					<template #default="{ row }">{{ humanSize(row.size) }}</template>
				</el-table-column>
				<el-table-column prop="extension" label="类型" width="80" align="center">
					<template #default="{ row }">{{ (row.extension || '').toUpperCase() }}</template>
				</el-table-column>
				<el-table-column prop="version" label="版本" width="80" align="center">
					<template #default="{ row }">{{ row.version || '-' }}</template>
				</el-table-column>
				<el-table-column label="说明" min-width="180" align="left" show-overflow-tooltip>
					<template #default="{ row }">{{ row.description || '-' }}</template>
				</el-table-column>
				<el-table-column prop="download_count" label="下载" width="80" align="center" class-name="col-sort" />
				<el-table-column label="状态" width="90" align="center">
					<template #default="{ row }">
						<span class="status-tag" :class="row.status === 'published' ? 'status-on' : 'status-off'">
							{{ row.status === 'published' ? '已发布' : '已下架' }}
						</span>
					</template>
				</el-table-column>
				<el-table-column label="上传人" width="110" align="center" show-overflow-tooltip>
					<template #default="{ row }">{{ row.created_by_name || '-' }}</template>
				</el-table-column>
				<el-table-column label="上传时间" width="150" align="center" class-name="col-time">
					<template #default="{ row }">{{ row.created_at ? row.created_at.slice(0, 16) : '-' }}</template>
				</el-table-column>
				<el-table-column label="操作" width="180" align="center" fixed="right">
					<template #default="{ row }">
						<span v-permission="'data:download:download'" class="op-link" @click="onDownload(row)">下载</span>
						<span v-permission="'data:download:edit'" class="op-link" @click="openForm(row)">编辑</span>
						<span v-permission="'data:download:delete'" class="op-link op-danger" @click="onDelete(row)">删除</span>
					</template>
				</el-table-column>
				<template #empty>
					<span class="empty-hint">当前筛选条件下暂无可下载文件</span>
				</template>
			</el-table>

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

		<!-- 上传（文件不可替换，所以只在新增时选文件） -->
		<el-dialog
			v-model="uploadVisible"
			title="上传文件"
			width="620px"
			:close-on-click-modal="false"
			class="archive-dialog"
			@closed="onUploadClosed"
		>
			<div class="form-body">
				<el-form ref="uploadFormRef" :model="uploadForm" :rules="uploadRules" label-width="100px">
					<el-form-item label="选择文件" prop="file">
						<!--
							不用 el-upload 的 action 直传：那条路走的是浏览器自己的 XHR，
							带不上 JWT 头（401），而且后端的安全拒绝原因（"探测到 text/html"）
							会在 el-upload 的回调里丢掉。这里只借它的选框与列表，提交手动走 axios。
						-->
						<el-upload
							action="#"
							:auto-upload="false"
							:limit="1"
							:file-list="fileList"
							:on-change="onFileChange"
							:on-remove="onFileRemove"
							:on-exceed="onExceed"
						>
							<el-button class="btn-pick">选择文件</el-button>
							<template #tip>
								<div class="upload-tip">{{ ALLOWED_EXT.join(' / ') }}，≤ 20MB</div>
							</template>
						</el-upload>
					</el-form-item>
					<el-form-item label="分组" prop="category">
						<el-select v-model="uploadForm.category" style="width:180px">
							<el-option v-for="c in categoryNames" :key="c" :label="c" :value="c" />
						</el-select>
					</el-form-item>
					<el-form-item label="显示名称" prop="name">
						<el-input v-model="uploadForm.name" placeholder="留空则用文件名" maxlength="150" />
					</el-form-item>
					<el-form-item label="版本" prop="version">
						<el-input v-model="uploadForm.version" placeholder="如 V1.0" maxlength="20" style="width:180px" />
					</el-form-item>
					<el-form-item label="说明" prop="description">
						<el-input v-model="uploadForm.description" type="textarea" :rows="2" maxlength="255" show-word-limit />
					</el-form-item>
					<el-form-item label="排序" prop="sort">
						<el-input-number v-model="uploadForm.sort" :min="0" :step="1" style="width:120px" />
					</el-form-item>
					<el-form-item label="状态" prop="status">
						<el-radio-group v-model="uploadForm.status">
							<el-radio value="published">已发布</el-radio>
							<el-radio value="disabled">已下架</el-radio>
						</el-radio-group>
					</el-form-item>
				</el-form>
			</div>
			<template #footer>
				<el-button class="btn-cancel" @click="uploadVisible = false">取消</el-button>
				<el-button class="btn-confirm" :loading="saving" @click="onUpload">上传</el-button>
			</template>
		</el-dialog>

		<!-- 编辑元信息（不含文件本体） -->
		<el-dialog v-model="formVisible" title="编辑文件信息" width="620px" class="archive-dialog" @closed="onFormClosed">
			<div class="form-body">
				<el-form ref="formRef" :model="form" :rules="rules" label-width="100px">
					<el-form-item label="磁盘文件">
						<span class="static-text">{{ form.file_name }}</span>
						<span class="form-hint">文件本体不可替换，要换文件请删掉重传（保留旧文件会导致同名两份并存）</span>
					</el-form-item>
					<el-form-item label="显示名称" prop="name">
						<el-input v-model="form.name" maxlength="150" />
					</el-form-item>
					<el-form-item label="分组" prop="category">
						<el-select v-model="form.category" style="width:180px">
							<el-option v-for="c in categoryNames" :key="c" :label="c" :value="c" />
						</el-select>
					</el-form-item>
					<el-form-item label="版本" prop="version">
						<el-input v-model="form.version" maxlength="20" style="width:180px" />
					</el-form-item>
					<el-form-item label="说明" prop="description">
						<el-input v-model="form.description" type="textarea" :rows="2" maxlength="255" show-word-limit />
					</el-form-item>
					<el-form-item label="排序" prop="sort">
						<el-input-number v-model="form.sort" :min="0" :step="1" style="width:120px" />
					</el-form-item>
					<el-form-item label="状态" prop="status">
						<el-radio-group v-model="form.status">
							<el-radio value="published">已发布</el-radio>
							<el-radio value="disabled">已下架</el-radio>
						</el-radio-group>
					</el-form-item>
				</el-form>
			</div>
			<template #footer>
				<el-button class="btn-cancel" @click="formVisible = false">取消</el-button>
				<el-button class="btn-confirm" :loading="saving" @click="onSave">保存</el-button>
			</template>
		</el-dialog>
	</div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { PlusOutlined } from '@ant-design/icons-vue'
import dataApi from '@/api/data.js'
import { saveBlob } from '@/utils/fileDownload'

// 与后端 DownloadFileService::ALLOWED_EXTENSIONS 一致的提示文案。
// 真正的拦截在后端（扩展名 + 真实 MIME 自洽），这里只是少让人白填一次，
// 所以不做前端硬校验 —— 前端规则一旦和后端不同步，就会拒掉后端其实允许的文件。
const ALLOWED_EXT = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip', 'txt', 'csv', 'png', 'jpg', 'jpeg']
const MAX_SIZE = 20 * 1024 * 1024

const list = ref([])
const categories = ref([])
const total = ref(0)
const loading = ref(false)
const page = ref(1)
const pageSize = ref(30)
const saving = ref(false)

const uploadVisible = ref(false)
const uploadFormRef = ref(null)
const fileList = ref([])
const pickedFile = ref(null)

const formVisible = ref(false)
const formRef = ref(null)

const search = reactive({ keyword: '', category: '', status: '' })

const emptyUpload = () => ({
	category: '模板',
	name: '',
	version: '',
	description: '',
	sort: 0,
	status: 'published',
})
const uploadForm = ref(emptyUpload())

const emptyForm = () => ({
	id: null,
	file_name: '',
	name: '',
	category: '模板',
	version: '',
	description: '',
	sort: 0,
	status: 'published',
})
const form = ref(emptyForm())

const uploadRules = {
	category: [{ required: true, message: '请选择分组', trigger: 'change' }],
}
const rules = {
	name: [{ required: true, message: '请输入显示名称', trigger: 'blur' }],
	category: [{ required: true, message: '请选择分组', trigger: 'change' }],
}

const categoryNames = computed(() => categories.value.map((c) => c.name))

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

const humanSize = (size) => {
	const n = Number(size) || 0
	if (n < 1024) return `${n} B`
	if (n < 1024 * 1024) return `${(n / 1024).toFixed(1)} KB`
	return `${(n / 1024 / 1024).toFixed(2)} MB`
}

const buildQuery = () => {
	const params = { page: page.value, page_size: pageSize.value }
	if (search.keyword) params.keyword = search.keyword
	if (search.category) params.category = search.category
	if (search.status) params.status = search.status
	return params
}

const load = async () => {
	loading.value = true
	try {
		const res = await dataApi.download.list.get(buildQuery())
		list.value = res.data?.list || []
		total.value = res.data?.total ?? 0
	} finally {
		loading.value = false
	}
}

const loadCategories = async () => {
	try {
		const res = await dataApi.download.categories.get()
		categories.value = res.data?.list || []
	} catch { /* 拦截器已提示 */ }
}

const refreshAll = () => {
	load()
	loadCategories()
}

const onSearch = () => {
	page.value = 1
	load()
}
const reset = () => {
	search.keyword = ''
	search.category = ''
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

const openUpload = () => {
	uploadForm.value = emptyUpload()
	fileList.value = []
	pickedFile.value = null
	uploadVisible.value = true
}

const onUploadClosed = () => {
	uploadForm.value = emptyUpload()
	fileList.value = []
	pickedFile.value = null
	uploadFormRef.value?.clearValidate()
}

const onFileChange = (file, files) => {
	// limit=1：多出来的直接丢，避免用户以为"选了两个传两个"
	pickedFile.value = file.raw || null
	fileList.value = files.slice(-1)
	if (file.raw && file.raw.size > MAX_SIZE) {
		ElMessage.warning(`「${file.name}」${humanSize(file.raw.size)}，超过 20MB 上限，上传会被拒绝`)
	}
	if (!uploadForm.value.name && file.name) {
		uploadForm.value.name = file.name.replace(/\.[^.]+$/, '').slice(0, 150)
	}
}

const onFileRemove = () => {
	pickedFile.value = null
	fileList.value = []
}

const onExceed = () => {
	ElMessage.warning('一次只能上传一个文件，请先移除已选的')
}

const onUpload = async () => {
	if (!pickedFile.value) {
		ElMessage.warning('请先选择文件')
		return
	}
	await uploadFormRef.value.validate()

	const fd = new FormData()
	fd.append('file', pickedFile.value)
	fd.append('category', uploadForm.value.category)
	if (uploadForm.value.name) fd.append('name', uploadForm.value.name)
	if (uploadForm.value.version) fd.append('version', uploadForm.value.version)
	if (uploadForm.value.description) fd.append('description', uploadForm.value.description)
	fd.append('sort', String(uploadForm.value.sort || 0))
	fd.append('status', uploadForm.value.status)

	saving.value = true
	try {
		await dataApi.download.upload(fd)
		ElMessage.success('已上传')
		uploadVisible.value = false
		refreshAll()
	} catch { /* 安全拒绝原因（扩展名/MIME 不符/过大）由拦截器弹出 */ } finally {
		saving.value = false
	}
}

const openForm = (row) => {
	form.value = {
		...emptyForm(),
		...row,
		name: row.name || row.file_name,
		version: row.version || '',
		description: row.description || '',
		sort: row.sort ?? 0,
		status: row.status || 'published',
	}
	formVisible.value = true
}

const onFormClosed = () => {
	form.value = emptyForm()
	formRef.value?.clearValidate()
}

const onSave = async () => {
	await formRef.value.validate()

	const payload = { ...form.value }
	delete payload.id
	delete payload.file_name
	Object.keys(payload).forEach((key) => {
		if (payload[key] === '') payload[key] = null
	})
	payload.sort = Number(payload.sort) || 0

	saving.value = true
	try {
		await dataApi.download.edit.put(form.value.id, payload)
		ElMessage.success('已更新')
		formVisible.value = false
		refreshAll()
	} catch { /* 拦截器已提示 */ } finally {
		saving.value = false
	}
}

const onDownload = async (row) => {
	try {
		const res = await dataApi.download.download(row.id)
		// 用记录里的展示名落盘：后端 Content-Disposition 给的也是这个名字，
		// 但 axios 拿到的是 blob，头信息在这一层已经不方便读，直接用同一份数据更直白
		saveBlob(res.data, row.file_name || row.name)
		load()
	} catch { /* 已下架 / 文件丢失 的提示由拦截器弹出 */ }
}

const onDelete = (row) => {
	ElMessageBox.confirm(`确认删除「${row.file_name}」？删除后记录不再显示，磁盘文件会保留。`, '操作确认', {
		confirmButtonText: '删除',
		cancelButtonText: '取消',
		type: 'warning',
	})
		.then(async () => {
			try {
				await dataApi.download.delete.delete(row.id)
				ElMessage.success('已删除')
				refreshAll()
			} catch { /* 拦截器已提示 */ }
		})
		.catch(() => {})
}

onMounted(refreshAll)
</script>

<style scoped>
.download-page {
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

.btn-add {
	height: 36px;
	width: 110px;
	font-size: 14px;
	border-radius: 4px;
	border: none;
	color: #ffffff;
	background: #409EFF;
	display: inline-flex;
	align-items: center;
	gap: 6px;
	justify-content: center;
}

.btn-icon {
	font-size: 14px;
}

.tip-hint {
	font-size: 12px;
	color: #909399;
}

/* ---------- 表格 ---------- */
:deep(.el-table) {
	background: #ffffff;
	border-radius: 0 0 4px 4px;
}

:deep(.el-table__body tr:hover > td.el-table__cell) {
	background-color: #F5F7FA !important;
}

.file-name {
	color: #409EFF;
	cursor: pointer;
}

.file-name:hover {
	text-decoration: underline;
}

.col-size {
	font-variant-numeric: tabular-nums;
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
	width: 100px;
	text-align: right;
	font-size: 14px;
	color: #606266;
}

.form-body :deep(.el-input__inner),
.form-body :deep(.el-textarea__inner) {
	font-size: 14px;
	border: 1px solid #DCDFE6;
	border-radius: 4px;
}

.form-body :deep(.el-input.is-focus .el-input__inner),
.form-body :deep(.el-textarea__inner:focus) {
	border-color: #409EFF;
}

.btn-pick {
	height: 32px;
	font-size: 14px;
	border-radius: 4px;
	background: #ffffff;
	border: 1px solid #DCDFE6;
	color: #606266;
}

.upload-tip {
	font-size: 12px;
	color: #909399;
	line-height: 20px;
}

.static-text {
	font-size: 14px;
	color: #303133;
	word-break: break-all;
}

.form-hint {
	display: block;
	margin-left: 0;
	font-size: 12px;
	line-height: 18px;
	color: #909399;
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
