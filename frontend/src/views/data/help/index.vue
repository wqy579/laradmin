<template>
	<div class="help-page">
		<div class="header">
			<span class="title">帮助中心</span>
		</div>

		<div class="main">
			<div class="layout">
				<!-- 左侧分组导航：计数由后端一起给，省一次请求，也避免翻页后计数只统计当前页 -->
				<div class="rail">
					<div class="rail-item" :class="{ active: !search.category }" @click="pickCategory('')">
						<span class="rail-name">全部文档</span>
						<span class="rail-count">{{ totalAll }}</span>
					</div>
					<div
						v-for="c in categories"
						:key="c.name"
						class="rail-item"
						:class="{ active: search.category === c.name }"
						@click="pickCategory(c.name)"
					>
						<span class="rail-name">{{ c.name }}</span>
						<span class="rail-count">{{ c.count }}</span>
					</div>
				</div>

				<div class="content">
					<div class="filter-bar">
						<el-input
							v-model="search.keyword"
							placeholder="搜索标题或正文"
							clearable
							style="width:220px"
							@clear="onSearch"
							@keyup.enter="onSearch"
						/>
						<el-select v-model="search.status" placeholder="全部状态" clearable style="width:130px" @change="onSearch">
							<el-option label="已发布" value="published" />
							<el-option label="草稿" value="draft" />
						</el-select>
						<div class="filter-actions">
							<el-button class="btn-query" @click="onSearch">查询</el-button>
							<el-button class="btn-reset" @click="reset">重置</el-button>
						</div>
					</div>

					<div class="action-bar">
						<el-button v-permission="'data:help:create'" class="btn-add" @click="openForm()">
							<el-icon class="btn-icon"><PlusOutlined /></el-icon>
							<span>新增文档</span>
						</el-button>
						<span class="tip-hint">正文按白名单净化后存库，脚本与事件属性会被直接丢弃</span>
					</div>

					<el-table
						:data="list"
						v-loading="loading"
						style="width:100%"
						:header-cell-style="headerStyle"
						:row-style="rowStyle"
					>
						<el-table-column label="标题" min-width="260" align="left" show-overflow-tooltip>
							<template #default="{ row }">
								<span class="doc-title" @click="openReader(row)">{{ row.title }}</span>
								<span v-if="row.is_top" class="top-tag">置顶</span>
							</template>
						</el-table-column>
						<el-table-column prop="category" label="分组" width="110" align="center" />
						<el-table-column label="摘要" min-width="240" align="left" show-overflow-tooltip>
							<template #default="{ row }">{{ row.summary || '-' }}</template>
						</el-table-column>
						<el-table-column label="别名" width="160" align="left" class-name="col-code" show-overflow-tooltip>
							<template #default="{ row }">{{ row.slug || '-' }}</template>
						</el-table-column>
						<el-table-column prop="version" label="版本" width="80" align="center">
							<template #default="{ row }">{{ row.version || '-' }}</template>
						</el-table-column>
						<el-table-column label="状态" width="90" align="center">
							<template #default="{ row }">
								<span class="status-tag" :class="row.status === 'published' ? 'status-on' : 'status-off'">
									{{ row.status === 'published' ? '已发布' : '草稿' }}
								</span>
							</template>
						</el-table-column>
						<el-table-column prop="views" label="阅读" width="80" align="center" class-name="col-sort" />
						<el-table-column prop="sort" label="排序" width="70" align="center" class-name="col-sort" />
						<el-table-column label="更新时间" width="150" align="center" class-name="col-time">
							<template #default="{ row }">{{ row.updated_at ? row.updated_at.slice(0, 16) : '-' }}</template>
						</el-table-column>
						<el-table-column label="操作" width="200" align="center" fixed="right">
							<template #default="{ row }">
								<span class="op-link" @click="openReader(row)">阅读</span>
								<span v-permission="'data:help:edit'" class="op-link" @click="openForm(row)">编辑</span>
								<span
									v-if="row.status === 'draft'"
									v-permission="'data:help:edit'"
									class="op-link"
									@click="onPublish(row)"
								>发布</span>
								<span v-else v-permission="'data:help:edit'" class="op-link" @click="onWithdraw(row)">撤回</span>
								<span v-permission="'data:help:delete'" class="op-link op-danger" @click="onDelete(row)">删除</span>
							</template>
						</el-table-column>
						<template #empty>
							<span class="empty-hint">当前筛选条件下暂无帮助文档</span>
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
			</div>
		</div>

		<!-- 阅读弹窗 -->
		<el-dialog v-model="readerVisible" :title="reader?.title || '帮助文档'" width="860px" class="archive-dialog">
			<div v-if="reader" class="reader-meta">
				<span>{{ reader.category }}</span>
				<span v-if="reader.version">版本 {{ reader.version }}</span>
				<span>阅读 {{ reader.views }}</span>
				<span v-if="reader.published_at">发布 {{ reader.published_at.slice(0, 10) }}</span>
				<span>{{ reader.updated_by_name || reader.created_by_name || '-' }}</span>
			</div>
			<!--
				这里 v-html 的内容一律来自后端（HtmlSanitizer 白名单净化后的 content 列），
				不是用户提交的原始串：这一层是本项目唯一的 XSS 边界，前端不再重复过滤。
			-->
			<div class="reader-body" v-html="reader?.content || '<p>（暂无正文）</p>'"></div>
			<template #footer>
				<el-button class="btn-cancel" @click="readerVisible = false">关闭</el-button>
			</template>
		</el-dialog>

		<!-- 新增 / 编辑 -->
		<el-dialog
			v-model="formVisible"
			:title="form.id ? '编辑文档' : '新增文档'"
			width="860px"
			:close-on-click-modal="false"
			class="archive-dialog"
			@closed="onFormClosed"
		>
			<div class="form-body">
				<el-form ref="formRef" :model="form" :rules="rules" label-width="90px">
					<el-row :gutter="12">
						<el-col :span="14">
							<el-form-item label="标题" prop="title">
								<el-input v-model="form.title" placeholder="如 开账第一步：录入期初库存" maxlength="150" show-word-limit />
							</el-form-item>
						</el-col>
						<el-col :span="10">
							<el-form-item label="分组" prop="category">
								<el-select v-model="form.category" style="width:100%">
									<el-option v-for="c in categoryNames" :key="c" :label="c" :value="c" />
								</el-select>
							</el-form-item>
						</el-col>
					</el-row>
					<el-row :gutter="12">
						<el-col :span="14">
							<el-form-item label="别名" prop="slug">
								<el-input v-model="form.slug" placeholder="留空按标题自动生成，仅小写字母/数字/连字符" maxlength="150" />
							</el-form-item>
						</el-col>
						<el-col :span="5">
							<el-form-item label="版本" prop="version">
								<el-input v-model="form.version" placeholder="如 V1.2" maxlength="20" />
							</el-form-item>
						</el-col>
						<el-col :span="5">
							<el-form-item label="排序" prop="sort">
								<el-input-number v-model="form.sort" :min="0" :step="1" style="width:100%" />
							</el-form-item>
						</el-col>
					</el-row>
					<el-form-item label="摘要" prop="summary">
						<el-input
							v-model="form.summary"
							type="textarea"
							:rows="2"
							maxlength="255"
							show-word-limit
							placeholder="留空则自动取正文前 120 字"
						/>
					</el-form-item>

					<!--
						故意用「编辑 HTML + 后端回显预览」而不是假 WYSIWYG：
						手搓 contenteditable 工具栏只会做出一套和 sanitizer 白名单不一致的
						按钮（点了没反应的加粗比没有按钮更糟），而第三方富文本编辑器属新增依赖，
						本次受 lock 文件门禁约束不能引入。
					-->
					<el-form-item label="正文" prop="content">
						<el-tabs v-model="contentTab" class="content-tabs">
							<el-tab-pane label="编辑 HTML" name="edit">
								<el-input
									v-model="form.content"
									type="textarea"
									:rows="14"
									placeholder="直接写 HTML，只支持安全标签：p/br/strong/em/ul/ol/li/h1-h5/blockquote/pre/code/table 系列/a/img"
								/>
								<div class="form-hint block">
									脚本、样式、iframe、事件属性（on*）、javascript: 链接会被直接丢弃；
									链接只允许 http/https/mailto/tel 与站内相对路径。
								</div>
							</el-tab-pane>
							<el-tab-pane label="预览" name="preview">
								<div v-loading="previewing" class="preview-pane">
									<el-alert
										v-if="previewHadExecutable"
										type="warning"
										:closable="false"
										show-icon
										title="正文含脚本或事件属性，保存后这部分会被丢弃"
										class="preview-alert"
									/>
									<!-- 预览内容同样来自后端净化结果，不放原始 textarea 串 -->
									<div class="reader-body" v-html="previewHtml || '<p>（暂无正文）</p>'"></div>
								</div>
							</el-tab-pane>
						</el-tabs>
					</el-form-item>

					<el-row :gutter="12">
						<el-col :span="8">
							<el-form-item label="置顶" prop="is_top">
								<el-switch v-model="form.is_top" />
							</el-form-item>
						</el-col>
						<el-col :span="10">
							<el-form-item label="状态" prop="status">
								<el-radio-group v-model="form.status">
									<el-radio value="draft">草稿</el-radio>
									<el-radio value="published">已发布</el-radio>
								</el-radio-group>
							</el-form-item>
						</el-col>
					</el-row>
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
import { ref, reactive, computed, watch, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { PlusOutlined } from '@ant-design/icons-vue'
import dataApi from '@/api/data.js'

const list = ref([])
const categories = ref([])
const total = ref(0)
const loading = ref(false)
const page = ref(1)
const pageSize = ref(30)
const saving = ref(false)

const formVisible = ref(false)
const formRef = ref(null)
const contentTab = ref('edit')
const previewing = ref(false)
const previewHtml = ref('')
const previewHadExecutable = ref(false)

const readerVisible = ref(false)
const reader = ref(null)

const search = reactive({ keyword: '', category: '', status: '' })

const emptyForm = () => ({
	id: null,
	title: '',
	slug: '',
	category: '通用',
	summary: '',
	content: '',
	version: '',
	sort: 0,
	is_top: false,
	status: 'draft',
})
const form = ref(emptyForm())

const rules = {
	title: [{ required: true, message: '请输入标题', trigger: 'blur' }],
	category: [{ required: true, message: '请选择分组', trigger: 'change' }],
}

// 后端 categories() 固定返回全部候选（含计数为 0 的），下拉直接用它，
// 不另写一份常量，否则加个分组要改两处
const categoryNames = computed(() => categories.value.map((c) => c.name))
const totalAll = computed(() => categories.value.reduce((sum, c) => sum + (c.count || 0), 0))

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
		const res = await dataApi.help.list.get(buildQuery())
		list.value = res.data?.list || []
		total.value = res.data?.total ?? 0
	} finally {
		loading.value = false
	}
}

const loadCategories = async () => {
	try {
		const res = await dataApi.help.categories.get()
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
const pickCategory = (name) => {
	search.category = name
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

const openForm = async (row) => {
	contentTab.value = 'edit'
	previewHtml.value = ''
	previewHadExecutable.value = false
	if (!row) {
		form.value = emptyForm()
		formVisible.value = true
		return
	}
	// 列表接口不返回 content（列表页不需要整篇正文），编辑要单独取详情
	form.value = { ...emptyForm(), ...row }
	formVisible.value = true
	try {
		const res = await dataApi.help.detail.get(row.id)
		form.value.content = res.data?.content || ''
	} catch { /* 拦截器已提示 */ }
}

const onFormClosed = () => {
	form.value = emptyForm()
	formRef.value?.clearValidate()
	contentTab.value = 'edit'
	previewHtml.value = ''
	previewHadExecutable.value = false
}

const doPreview = async () => {
	previewing.value = true
	try {
		const res = await dataApi.help.preview.post({ content: form.value.content })
		previewHtml.value = res.data?.content || ''
		previewHadExecutable.value = !!res.data?.had_executable
	} catch { /* 拦截器已提示 */ } finally {
		previewing.value = false
	}
}

// 切到预览页签才请求：每次按键都打一次后端纯属浪费，而正文改动只在想看时才看
watch(contentTab, (tab) => {
	if (tab === 'preview') doPreview()
})

const onSave = async () => {
	await formRef.value.validate()

	const payload = { ...form.value }
	delete payload.id
	Object.keys(payload).forEach((key) => {
		if (payload[key] === '') payload[key] = null
	})
	payload.sort = Number(payload.sort) || 0

	saving.value = true
	try {
		if (form.value.id) {
			await dataApi.help.edit.put(form.value.id, payload)
			ElMessage.success('已更新')
		} else {
			await dataApi.help.add.post(payload)
			ElMessage.success('已创建')
		}
		formVisible.value = false
		refreshAll()
	} catch { /* 拦截器已弹具体原因（如别名冲突），不重复弹 */ } finally {
		saving.value = false
	}
}

const openReader = async (row) => {
	reader.value = null
	readerVisible.value = true
	try {
		const res = await dataApi.help.detail.get(row.id)
		reader.value = res.data || null
		// 阅读数单独接口 +1：编辑时打开详情不算阅读，否则阅读数会变成"打开次数"
		if (reader.value) {
			const readRes = await dataApi.help.read.post(row.id)
			reader.value.views = readRes.data?.views ?? reader.value.views
			load()
		}
	} catch { /* 拦截器已提示 */ }
}

const onPublish = (row) => {
	ElMessageBox.confirm(`确认发布「${row.title}」？发布后所有用户可在帮助中心看到。`, '操作确认', {
		confirmButtonText: '发布',
		cancelButtonText: '取消',
		type: 'warning',
	})
		.then(async () => {
			try {
				await dataApi.help.publish.post(row.id)
				ElMessage.success('已发布')
				refreshAll()
			} catch { /* 正文为空等拒绝原因由拦截器弹出 */ }
		})
		.catch(() => {})
}

const onWithdraw = (row) => {
	ElMessageBox.confirm(`确认将「${row.title}」撤回为草稿？`, '操作确认', {
		confirmButtonText: '撤回',
		cancelButtonText: '取消',
		type: 'warning',
	})
		.then(async () => {
			try {
				await dataApi.help.withdraw.post(row.id)
				ElMessage.success('已撤回为草稿')
				refreshAll()
			} catch { /* 拦截器已提示 */ }
		})
		.catch(() => {})
}

const onDelete = (row) => {
	ElMessageBox.confirm(`确认删除文档「${row.title}」？删除后不可恢复。`, '操作确认', {
		confirmButtonText: '删除',
		cancelButtonText: '取消',
		type: 'warning',
	})
		.then(async () => {
			try {
				await dataApi.help.delete.delete(row.id)
				ElMessage.success('已删除')
				refreshAll()
			} catch { /* 拦截器已提示 */ }
		})
		.catch(() => {})
}

onMounted(refreshAll)
</script>

<style scoped>
.help-page {
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

.layout {
	display: flex;
	align-items: flex-start;
	gap: 16px;
}

/* ---------- 左侧分组导航 ---------- */
.rail {
	flex: 0 0 180px;
	padding: 8px 0;
	background: #ffffff;
	border: 1px solid #EBEEF5;
	border-radius: 4px;
}

.rail-item {
	display: flex;
	align-items: center;
	justify-content: space-between;
	height: 40px;
	padding: 0 14px;
	font-size: 14px;
	color: #606266;
	cursor: pointer;
}

.rail-item:hover {
	background: #F5F7FA;
}

.rail-item.active {
	color: #409EFF;
	background: #ECF5FF;
	font-weight: 500;
}

.rail-count {
	font-size: 12px;
	color: #909399;
}

.rail-item.active .rail-count {
	color: #409EFF;
}

.content {
	flex: 1 1 auto;
	min-width: 0;
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

.col-code {
	color: #909399;
	font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
}

.doc-title {
	color: #409EFF;
	cursor: pointer;
}

.doc-title:hover {
	text-decoration: underline;
}

.top-tag {
	display: inline-block;
	margin-left: 6px;
	padding: 1px 6px;
	font-size: 12px;
	color: #E6A23C;
	background: #FDF6EC;
	border-radius: 4px;
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
	width: 90px;
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

.form-hint.block {
	display: block;
	margin-left: 0;
	font-size: 12px;
	line-height: 18px;
	color: #909399;
}

.content-tabs {
	width: 100%;
}

.preview-pane {
	min-height: 280px;
	max-height: 380px;
	overflow-y: auto;
	padding: 12px 14px;
	border: 1px solid #DCDFE6;
	border-radius: 4px;
}

.preview-alert {
	margin-bottom: 10px;
}

.reader-meta {
	display: flex;
	flex-wrap: wrap;
	gap: 14px;
	margin-bottom: 12px;
	font-size: 12px;
	color: #909399;
}

/*
	富文本正文样式：净化后只剩白名单标签，这里给它们一套和系统一致的排版，
	否则表格与代码块会退回浏览器默认样式（表格无边框、代码块不换行溢出）。
*/
.reader-body {
	font-size: 14px;
	line-height: 26px;
	color: #606266;
	word-break: break-word;
	max-height: 60vh;
	overflow-y: auto;
}

.reader-body :deep(h1),
.reader-body :deep(h2),
.reader-body :deep(h3),
.reader-body :deep(h4),
.reader-body :deep(h5) {
	margin: 16px 0 8px;
	font-weight: 700;
	color: #303133;
}

.reader-body :deep(h1) { font-size: 20px; }
.reader-body :deep(h2) { font-size: 18px; }
.reader-body :deep(h3) { font-size: 16px; }
.reader-body :deep(h4),
.reader-body :deep(h5) { font-size: 15px; }

.reader-body :deep(p) {
	margin: 0 0 10px;
}

.reader-body :deep(ul),
.reader-body :deep(ol) {
	margin: 0 0 10px;
	padding-left: 24px;
}

.reader-body :deep(blockquote) {
	margin: 10px 0;
	padding: 8px 12px;
	color: #909399;
	background: #F5F7FA;
	border-left: 3px solid #DCDFE6;
}

.reader-body :deep(pre) {
	margin: 10px 0;
	padding: 10px 12px;
	background: #F5F7FA;
	border-radius: 4px;
	overflow-x: auto;
	white-space: pre-wrap;
	word-break: break-all;
}

.reader-body :deep(code) {
	font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
	font-size: 13px;
}

.reader-body :deep(table) {
	width: 100%;
	margin: 10px 0;
	border-collapse: collapse;
}

.reader-body :deep(th) {
	background: #F5F7FA;
	font-size: 13px;
	font-weight: 700;
	color: #909399;
	height: 32px;
}

.reader-body :deep(td),
.reader-body :deep(th) {
	border: 1px solid #EBEEF5;
	padding: 6px 8px;
}

.reader-body :deep(a) {
	color: #409EFF;
}

.reader-body :deep(img) {
	max-width: 100%;
	height: auto;
}

.reader-body :deep(hr) {
	margin: 14px 0;
	border: none;
	border-top: 1px solid #EBEEF5;
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
