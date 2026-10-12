<template>
	<div class="brand-page">
		<div class="header">
			<span class="title">品牌档案管理</span>
		</div>

		<div class="main">
			<!-- 查询区 -->
			<div class="filter-bar">
				<el-input
					v-model="search.name"
					placeholder="请输入品牌名称"
					clearable
					style="width:180px"
					@clear="onSearch"
					@keyup.enter="onSearch"
				/>
				<el-input
					v-model="search.code"
					placeholder="请输入品牌编码"
					clearable
					style="width:160px"
					@clear="onSearch"
					@keyup.enter="onSearch"
				/>
				<el-select
					v-model="search.is_active"
					placeholder="全部"
					clearable
					style="width:120px"
					@change="onSearch"
				>
					<el-option label="启用" :value="1" />
					<el-option label="停用" :value="0" />
				</el-select>

				<div class="filter-actions">
					<el-button class="btn-query" @click="onSearch">查询</el-button>
					<el-button class="btn-reset" @click="reset">重置</el-button>
				</div>
			</div>

			<!-- 操作按钮区 -->
			<div class="action-bar">
				<el-button class="btn-add" @click="openForm()">
					<el-icon class="btn-icon"><PlusOutlined /></el-icon>
					<span>新增品牌</span>
				</el-button>
				<el-button class="btn-enable" :disabled="!selection.length" @click="batchStatus(true)">批量启用</el-button>
				<el-button class="btn-disable" :disabled="!selection.length" @click="batchStatus(false)">批量停用</el-button>
				<el-button class="btn-export" @click="handleExport">
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
				<el-table-column prop="code" label="品牌编码" width="120" align="center" class-name="col-code" />
				<el-table-column label="品牌名称" width="240" align="left" show-overflow-tooltip>
					<template #default="{ row }">
						<span class="brand-name" @click="viewProducts(row)">{{ row.name }}</span>
					</template>
				</el-table-column>
				<el-table-column label="关联分类" width="160" align="left" show-overflow-tooltip>
					<template #default="{ row }">{{ row.category?.name || '-' }}</template>
				</el-table-column>
				<el-table-column label="商品数量" width="100" align="center">
					<template #default="{ row }">
						<span class="product-count">{{ row.product_count }}</span>
					</template>
				</el-table-column>
				<el-table-column prop="sort" label="排序" width="80" align="center" class-name="col-sort" />
				<el-table-column label="状态" width="100" align="center">
					<template #default="{ row }">
						<span class="status-tag" :class="row.is_active ? 'status-on' : 'status-off'">
							{{ row.is_active ? '启用' : '停用' }}
						</span>
					</template>
				</el-table-column>
				<el-table-column label="创建时间" width="160" align="center" class-name="col-time">
					<template #default="{ row }">{{ row.created_at ? row.created_at.slice(0, 16) : '-' }}</template>
				</el-table-column>
				<el-table-column label="操作" width="150" align="center">
					<template #default="{ row }">
						<span class="op-link" @click="viewProducts(row)">查看</span>
						<span class="op-link" @click="openForm(row)">编辑</span>
						<span class="op-link op-danger" @click="onDelete(row)">删除</span>
					</template>
				</el-table-column>
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
			:title="form.id ? '编辑品牌' : '新增品牌'"
			width="600px"
			:close-on-click-modal="false"
			class="brand-dialog"
			@closed="onFormClosed"
		>
			<div class="form-body">
				<el-form ref="formRef" :model="form" :rules="rules" label-width="100px">
					<el-form-item label="品牌编码" prop="code">
						<el-input v-model="form.code" placeholder="BR+4位数字，如 BR0012；留空按关联分类自动生成" maxlength="6" />
					</el-form-item>
					<el-form-item label="品牌名称" prop="name">
						<el-input v-model="form.name" placeholder="请输入品牌名称" maxlength="100" show-word-limit />
					</el-form-item>
					<el-form-item label="关联分类" prop="category_id">
						<el-select
							v-model="form.category_id"
							placeholder="选择一级分类（可选）"
							filterable
							clearable
							style="width:100%"
							@change="onCategoryChange"
						>
							<el-option v-for="c in mainCategories" :key="c.id" :label="c.name" :value="c.id" />
						</el-select>
					</el-form-item>
					<el-form-item label="排序" prop="sort">
						<el-input-number v-model="form.sort" :min="0" :step="1" style="width:120px" />
					</el-form-item>
					<el-form-item label="状态" prop="is_active">
						<el-radio-group v-model="form.is_active">
							<el-radio :value="1">启用</el-radio>
							<el-radio :value="0">停用</el-radio>
						</el-radio-group>
					</el-form-item>
					<el-form-item label="备注" prop="remark">
						<el-input
							v-model="form.remark"
							type="textarea"
							:rows="3"
							maxlength="500"
							placeholder="请输入备注"
							style="width:100%"
						/>
					</el-form-item>
				</el-form>
			</div>
			<template #footer>
				<el-button class="btn-cancel" @click="formVisible = false">取消</el-button>
				<el-button class="btn-confirm" :loading="saving" @click="onSave">确定</el-button>
			</template>
		</el-dialog>
	</div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { PlusOutlined, DownloadOutlined } from '@ant-design/icons-vue'
import api from '@/api/business.js'
import { downloadApiResponse } from '@/utils/download'

const router = useRouter()

const list = ref([])
const total = ref(0)
const loading = ref(false)
const page = ref(1)
const pageSize = ref(30)
const selection = ref([])
const saving = ref(false)
const formVisible = ref(false)
const formRef = ref(null)
const mainCategories = ref([])

const search = reactive({ name: '', code: '', is_active: '' })

const emptyForm = () => ({
	id: null,
	code: '',
	name: '',
	category_id: '',
	sort: 0,
	is_active: 1,
	remark: '',
})
const form = ref(emptyForm())

const rules = {
	name: [{ required: true, message: '请输入品牌名称', trigger: 'blur' }],
	// 编码允许留空（由分类自动生成），填写时才校验格式
	code: [
		{
			validator: (_rule, value, callback) => {
				if (value && !/^BR\d{4}$/.test(value)) {
					callback(new Error('格式应为 BR+4位数字，如 BR0012'))
					return
				}
				callback()
			},
			trigger: 'blur',
		},
	],
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

// 组装查询参数：空值必须整个省略，不能传空串。
// 后端判断用 $request->has('is_active')，`is_active=`（空串）也算「已传」，
// 会被当成停用（bool('') === false），导致「全部」只显示停用品牌。
const buildQuery = () => {
	const params = { page: page.value, page_size: pageSize.value }
	if (search.name) params.name = search.name
	if (search.code) params.code = search.code
	if (search.is_active !== '' && search.is_active !== null && search.is_active !== undefined) {
		params.is_active = search.is_active
	}
	return params
}

const load = async () => {
	loading.value = true
	try {
		const res = await api.brand.list.get(buildQuery())
		list.value = res.data?.list || []
		total.value = res.data?.total ?? 0
	} finally {
		loading.value = false
	}
}

// 复用商品档案的分类接口，只取一级分类（is_main=true）
const loadCategories = async () => {
	const res = await api.product.category.list.get()
	mainCategories.value = (res.data || []).map((c) => ({ id: c.id, name: c.name }))
}

const onSearch = () => {
	page.value = 1
	load()
}
const reset = () => {
	search.name = ''
	search.code = ''
	search.is_active = ''
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
				// 模型把 is_active 转成布尔，API 返回 true/false；表单单选值是 1/0，
				// 不归一化则编辑时两项都不选中，保存时又会按默认值写回。
				is_active: row.is_active ? 1 : 0,
				sort: row.sort ?? 0,
				category_id: row.category_id ?? '',
			}
		: emptyForm()
	formVisible.value = true
}

const onFormClosed = () => {
	form.value = emptyForm()
	formRef.value?.clearValidate()
}

// 新增时选择分类 → 自动填充品牌名 = 分类名，编码 = BR+4位分类ID。用户仍可修改。
// 编辑时不自动改名称：改名应由用户显式操作，换分类不应连带改写已有品牌名。
const onCategoryChange = (categoryId) => {
	if (form.value.id) return
	const cat = mainCategories.value.find((c) => c.id === categoryId)
	if (!cat) return
	form.value.name = cat.name
	form.value.code = 'BR' + String(cat.id).padStart(4, '0')
}

const onSave = async () => {
	await formRef.value.validate()

	const payload = { ...form.value }
	delete payload.id
	// 分类 ID 空串归一成 null，避免后端把 '' 当作整型 0
	if (payload.category_id === '' || payload.category_id === undefined) {
		payload.category_id = null
	}

	saving.value = true
	try {
		if (form.value.id) {
			// 名称是否变化：与编辑前的列表数据比。弹窗里的行一定来自当前页，可靠。
			const original = list.value.find((r) => r.id === form.value.id)
			const nameChanged = !!original && original.name !== payload.name

			let submit = payload
			// 名称变了且关联分类存在：按规格先问是否同步分类名，再一次请求发出，
			// 避免「先保存再询问」造成两次写入（第一次已按默认同步过一次分类名）。
			if (nameChanged && payload.category_id) {
				const confirmed = await ElMessageBox.confirm(
					'是否同步更新分类名称？',
					'确认',
					{ confirmButtonText: '同步', cancelButtonText: '不同步', type: 'warning' }
				)
					.then(() => true)
					.catch(() => false)
				submit = { ...payload, sync_category: confirmed }
			}

			await api.brand.edit.put(form.value.id, submit)
			ElMessage.success('已更新')
		} else {
			await api.brand.add.post(payload)
			ElMessage.success('已创建')
		}

		formVisible.value = false
		load()
	} catch (err) {
		// 拦截器已弹过具体错误，这里不重复弹
	} finally {
		saving.value = false
	}
}

const onDelete = (row) => {
	ElMessageBox.confirm(`确认删除品牌「${row.name}」？`, '提示', {
		confirmButtonText: '删除',
		cancelButtonText: '取消',
		type: 'warning',
	})
		.then(async () => {
			await api.brand.delete.delete(row.id)
			ElMessage.success('已删除')
			load()
		})
		.catch(() => {})
}

const batchStatus = (active) => {
	const ids = selection.value.map((r) => r.id)
	if (!ids.length) return
	ElMessageBox.confirm(
		active ? `确认启用已选的 ${ids.length} 个品牌？` : `确认停用已选的 ${ids.length} 个品牌？`,
		'提示',
		{ confirmButtonText: '确定', cancelButtonText: '取消', type: 'warning' }
	)
		.then(async () => {
			await api.brand.batchUpdateStatus.post({ ids, is_active: active })
			ElMessage.success('已更新')
			load()
		})
		.catch(() => {})
}

// 点击品牌名 / 商品数量 / 查看 → 跳商品档案并按品牌筛选
const viewProducts = (row) => {
	router.push({ path: '/business/product', query: { brand_id: row.id } })
}

const handleExport = async () => {
	const res = await api.brand.export()
	downloadApiResponse(res, 'brands.csv')
}

onMounted(() => {
	load()
	loadCategories()
})
</script>

<style scoped>
.brand-page {
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

.col-code {
	color: #909399;
	font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
}

.brand-name {
	color: #409EFF;
	cursor: pointer;
}

.brand-name:hover {
	text-decoration: underline;
}

.product-count {
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
.brand-dialog :deep(.el-dialog) {
	border-radius: 4px;
	box-shadow: 0 2px 12px rgba(0, 0, 0, 0.1);
}

.brand-dialog :deep(.el-dialog__header) {
	height: 50px;
	padding: 0 20px;
	border-bottom: 1px solid #EBEEF5;
	margin-right: 0;
	display: flex;
	align-items: center;
}

.brand-dialog :deep(.el-dialog__title) {
	font-size: 16px;
	font-weight: 700;
	color: #303133;
}

.brand-dialog :deep(.el-dialog__headerbtn) {
	top: 16px;
	color: #909399;
}

.brand-dialog :deep(.el-dialog__headerbtn:hover) {
	color: #606266;
}

.brand-dialog :deep(.el-dialog__body) {
	padding: 20px;
}

.brand-dialog :deep(.el-dialog__footer) {
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
