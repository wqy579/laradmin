<template>
	<sPageSplit side-title="权限树" :side-width="'280px'">
		<template #side>
			<div class="perm-side">
				<div class="perm-side__header">
					<el-input v-model="keyword" placeholder="搜索权限..." clearable @input="handleSearch">
						<template #prefix>
							<el-icon><ElIconSearch /></el-icon>
						</template>
					</el-input>
				</div>
				<div class="perm-side__body">
					<el-tree v-if="filteredTree.length > 0" v-model:currentKey="currentKey" :data="filteredTree" :props="treeProps" node-key="id" highlight-current default-expand-all @node-click="onNodeSelect">
						<template #default="{ data }">
							<span class="tree-node-label">
								<el-icon v-if="data.meta?.icon" style="margin-right: 4px"><component :is="data.meta.icon" /></el-icon>
								<el-icon v-else-if="data.type === 'menu'" style="margin-right: 4px"><ElIconFolder /></el-icon>
								<el-icon v-else-if="data.type === 'button'" style="margin-right: 4px"><ElIconGrid /></el-icon>
								<el-icon v-else style="margin-right: 4px"><ElIconDocument /></el-icon>
								<span>{{ getNodeLabel(data) }}</span>
							</span>
						</template>
					</el-tree>
					<el-empty v-else description="暂无权限数据" :image-size="60" />
				</div>
			</div>
		</template>

		<div class="panel-header">
			<span class="form-title">{{ formMode === 'add' ? '新增权限' : formMode === 'edit' ? '编辑权限' : '权限详情' }}</span>
			<div class="form-actions">
				<el-button type="primary" size="small" @click="handleAddTop" v-if="formMode === 'empty'">
					<el-icon><ElIconPlus /></el-icon>
					新增顶级权限
				</el-button>
				<template v-else>
					<el-button type="primary" size="small" @click="handleAddChild" v-if="formMode !== 'add'">
						<el-icon><ElIconPlus /></el-icon>
						新增子级
					</el-button>
					<el-button size="small" @click="handleToggleEdit" v-if="formMode === 'show'"> 编辑 </el-button>
					<el-popconfirm title="确定删除该权限吗？删除后不可恢复。" @confirm="handleDelete" v-if="formMode !== 'add'">
						<template #reference>
							<el-button type="danger" size="small">删除</el-button>
						</template>
					</el-popconfirm>
				</template>
			</div>
		</div>
		<div class="panel-body form-body" v-if="formMode !== 'empty'">
			<el-form ref="formRef" :model="form" :rules="rules" :disabled="formMode === 'show'" label-width="80px" label-position="right">
				<div class="form-section">
					<div class="form-section-title">基本信息</div>
					<el-form-item label="上级权限" prop="parent_id">
						<el-tree-select v-model="form.parent_id" :data="permissionTree" :props="treeSelectProps" placeholder="顶级权限" clearable check-strictly filterable style="width: 100%" />
					</el-form-item>
					<el-form-item label="权限类型" prop="type">
						<el-radio-group v-model="form.type">
							<el-radio value="menu">菜单</el-radio>
							<el-radio value="page">页面</el-radio>
							<el-radio value="button">按钮</el-radio>
						</el-radio-group>
					</el-form-item>
					<el-row :gutter="16">
						<el-col :span="12">
							<el-form-item label="名称" prop="title">
								<el-input v-model="form.title" placeholder="请输入权限名称" clearable />
							</el-form-item>
						</el-col>
						<el-col :span="12">
							<el-form-item label="标识" prop="name">
								<el-input v-model="form.name" placeholder="请输入权限标识" clearable :disabled="formMode === 'edit'" />
							</el-form-item>
						</el-col>
					</el-row>
				</div>

				<div class="form-section" v-if="form.type !== 'button'">
					<div class="form-section-title">路由配置</div>
					<el-row :gutter="16">
						<el-col :span="12">
							<el-form-item label="路由路径" prop="path">
								<el-input v-model="form.path" placeholder="/example/path" clearable />
							</el-form-item>
						</el-col>
						<el-col :span="12">
							<el-form-item label="组件路径" prop="component">
								<el-input v-model="form.component" placeholder="auth/permission" clearable />
							</el-form-item>
						</el-col>
					</el-row>
					<el-form-item label="图标" prop="icon" v-if="form.type === 'menu'">
						<sIconSelect v-model="form.icon" placeholder="请选择图标" style="width: 200px" />
					</el-form-item>
				</div>

				<div class="form-section" v-if="form.type !== 'button'">
					<div class="form-section-title">页面设置</div>
					<el-row :gutter="16">
						<el-col :span="8">
							<el-form-item label="隐藏菜单">
								<el-switch v-model="form.hidden" />
							</el-form-item>
						</el-col>
						<el-col :span="8">
							<el-form-item label="缓存页面">
								<el-switch v-model="form.keepAlive" />
							</el-form-item>
						</el-col>
						<el-col :span="8">
							<el-form-item label="固定标签">
								<el-switch v-model="form.affix" :active-value="1" :inactive-value="0" />
							</el-form-item>
						</el-col>
					</el-row>
				</div>

				<div class="form-section">
					<div class="form-section-title">其他</div>
					<el-row :gutter="16">
						<el-col :span="12">
							<el-form-item label="排序" prop="sort">
								<el-input-number v-model="form.sort" :min="0" :max="9999" controls-position="right" style="width: 100%" />
							</el-form-item>
						</el-col>
						<el-col :span="12">
							<el-form-item label="状态" prop="status">
								<el-radio-group v-model="form.status">
									<el-radio :value="1">正常</el-radio>
									<el-radio :value="0">禁用</el-radio>
								</el-radio-group>
							</el-form-item>
						</el-col>
					</el-row>
				</div>

				<el-form-item v-if="formMode !== 'show'" style="padding-top: 8px">
					<el-button type="primary" :loading="saving" @click="handleSubmit">保存</el-button>
					<el-button @click="handleCancel">取消</el-button>
				</el-form-item>
			</el-form>
		</div>
		<div class="panel-body form-empty" v-else>
			<el-empty description="请在左侧选择权限节点进行操作" :image-size="100" />
		</div>
	</sPageSplit>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import authApi from '@/api/auth'
import sPageSplit from '@/components/sPageSplit/index.vue'

const formRef = ref(null)
const saving = ref(false)
const permissionTree = ref([])
const filteredTree = ref([])
const keyword = ref('')
const currentKey = ref(null)
const selectedNode = ref(null)
const formMode = ref('empty')

const form = reactive({
	id: '',
	parent_id: null,
	type: 'menu',
	title: '',
	name: '',
	path: '',
	component: '',
	icon: '',
	hidden: false,
	keepAlive: false,
	affix: 0,
	sort: 0,
	status: 1,
})

const rules = {
	title: [
		{ required: true, message: '请输入权限名称', trigger: 'blur' },
		{ min: 2, max: 50, message: '权限名称长度在 2 到 50 个字符', trigger: 'blur' },
	],
	name: [
		{ required: true, message: '请输入权限标识', trigger: 'blur' },
		{ min: 2, max: 50, message: '权限标识长度在 2 到 50 个字符', trigger: 'blur' },
	],
}

function getNodeLabel(data) {
	return data.title || data.name || data.label || ''
}

const treeProps = {
	label: (data) => getNodeLabel(data),
	children: 'children',
}

const treeSelectProps = {
	label: (data) => getNodeLabel(data),
	children: 'children',
	value: 'id',
}

// ---- load tree ----

async function loadTree() {
	try {
		const res = await authApi.permission.tree.get()
		permissionTree.value = res.data || []
		filteredTree.value = res.data || []
	} catch (error) {
		console.error('加载权限树失败:', error)
	}
}

// ---- search ----

function handleSearch(val) {
	if (!val) {
		filteredTree.value = permissionTree.value
		return
	}
	const kw = val.toLowerCase()
	const filterTree = (nodes) => {
		return nodes.reduce((acc, node) => {
			const label = getNodeLabel(node).toLowerCase()
			const name = (node.name || '').toLowerCase()
			const isMatch = label.includes(kw) || name.includes(kw)
			const children = node.children ? filterTree(node.children) : []
			if (isMatch || children.length > 0) {
				acc.push({ ...node, children: children.length > 0 ? children : undefined })
			}
			return acc
		}, [])
	}
	filteredTree.value = filterTree(permissionTree.value)
}

// ---- tree select ----

function onNodeSelect(node) {
	selectedNode.value = node
	currentKey.value = node.id
	formMode.value = 'show'
	fillForm(node)
}

function fillForm(data) {
	Object.assign(form, {
		id: data.id,
		parent_id: data.parent_id || null,
		type: data.type || 'menu',
		title: data.title || '',
		name: data.name || '',
		path: data.path || '',
		component: data.component || '',
		icon: data.meta?.icon || '',
		hidden: data.meta?.hidden || false,
		keepAlive: data.meta?.keepAlive || false,
		affix: data.meta?.affix || 0,
		sort: data.sort ?? 0,
		status: data.status ?? 1,
	})
}

function resetForm() {
	Object.assign(form, {
		id: '',
		parent_id: null,
		type: 'menu',
		title: '',
		name: '',
		path: '',
		component: '',
		icon: '',
		hidden: false,
		keepAlive: false,
		affix: 0,
		sort: 0,
		status: 1,
	})
}

// ---- CRUD ----

function handleAddTop() {
	resetForm()
	formMode.value = 'add'
}

function handleAddChild() {
	if (!selectedNode.value) return
	resetForm()
	form.parent_id = selectedNode.value.id
	formMode.value = 'add'
}

function handleToggleEdit() {
	formMode.value = 'edit'
}

function handleCancel() {
	if (selectedNode.value) {
		formMode.value = 'show'
		fillForm(selectedNode.value)
	} else {
		formMode.value = 'empty'
		resetForm()
	}
}

async function handleSubmit() {
	try {
		await formRef.value.validate()
		saving.value = true

		const submitData = {
			parent_id: form.parent_id,
			type: form.type,
			title: form.title,
			name: form.name,
			path: form.path,
			component: form.component,
			meta: { icon: form.icon, hidden: form.hidden, keepAlive: form.keepAlive, affix: form.affix },
			sort: form.sort,
			status: form.status,
		}

		if (formMode.value === 'add') {
			await authApi.permission.add.post(submitData)
		} else {
			await authApi.permission.edit.put(form.id, submitData)
		}

		ElMessage.success('操作成功')
		await loadTree()
		currentKey.value = form.id || null
		formMode.value = 'show'
	} catch (error) {
		if (error !== false) {
			console.error('保存权限失败:', error)
		}
	} finally {
		saving.value = false
	}
}

function handleDelete() {
	if (!selectedNode.value) return
	ElMessageBox.confirm('确定删除该权限吗？删除后不可恢复。', '确认删除', { type: 'warning' })
		.then(async () => {
			try {
				await authApi.permission.delete.delete(selectedNode.value.id)
				ElMessage.success('删除成功')
				selectedNode.value = null
				currentKey.value = null
				formMode.value = 'empty'
				resetForm()
				await loadTree()
			} catch (error) {
				console.error('删除权限失败:', error)
			}
		})
		.catch(() => {})
}

onMounted(() => {
	loadTree()
})
</script>

<style scoped>
/* 侧栏内部布局（容器本身由 sPageSplit 提供） */
.perm-side {
	display: flex;
	flex-direction: column;
	height: 100%;
	overflow: hidden;
}
.perm-side__header {
	padding: 12px;
	border-bottom: 1px solid var(--layout-border-light, var(--el-border-color-lighter));
	flex-shrink: 0;
}
.perm-side__body {
	flex: 1;
	overflow-y: auto;
	padding: 12px;
}

/* 右侧表单面板（作为 sPageSplit main 的直接子元素） */
.panel-header {
	height: 56px;
	min-height: 56px;
	padding: 0 16px;
	border-bottom: 1px solid var(--layout-border-light);
	display: flex;
	align-items: center;
	gap: 8px;
	background: var(--layout-surface);
	flex-shrink: 0;
}

.panel-body {
	flex: 1;
	overflow-y: auto;
	min-height: 0;
	background: var(--layout-surface);
}

.tree-node-label {
	display: flex;
	align-items: center;
	font-size: 13px;
}

.form-title {
	font-size: 14px;
	font-weight: 600;
	color: var(--el-text-color-primary);
	padding-left: 10px;
	border-left: 3px solid var(--el-color-primary);
}

.form-actions {
	margin-left: auto;
	display: flex;
	gap: 8px;
}

.form-body {
	padding: 20px 24px;
}

.form-body .el-form {
	max-width: 640px;
}

.form-section {
	margin-bottom: 20px;
	padding-bottom: 16px;
	border-bottom: 1px solid var(--el-border-color-lighter);
}

.form-section:last-of-type {
	border-bottom: none;
	margin-bottom: 0;
	padding-bottom: 0;
}

.form-section-title {
	font-size: 13px;
	font-weight: 600;
	color: var(--el-text-color-primary);
	margin-bottom: 16px;
	padding-left: 10px;
	border-left: 3px solid var(--el-color-primary);
}

.form-empty {
	display: flex;
	align-items: center;
	justify-content: center;
}

@media (max-width: 768px) {
	.panel-header {
		flex-wrap: wrap;
		height: auto;
		min-height: 56px;
		padding: 10px 16px;
	}
	.form-actions {
		margin-left: 0;
		width: 100%;
		justify-content: flex-end;
	}
	.form-body {
		padding: 16px;
	}
	.form-body .el-form {
		max-width: 100%;
	}
	.form-body .el-form :deep(.el-col) {
		max-width: 100%;
		flex: 0 0 100%;
	}
	.form-body .el-form :deep(.el-form-item__label) {
		text-align: left;
	}
}
</style>
