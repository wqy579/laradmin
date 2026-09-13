<template>
	<sPageSplit side-title="部门" :side-width="'260px'">
		<template #side>
			<div class="dept-side">
				<div class="dept-side__header">
					<el-input v-model="deptKeyword" placeholder="搜索部门..." clearable @input="handleDeptSearch">
						<template #prefix>
							<el-icon><ElIconSearch /></el-icon>
						</template>
					</el-input>
				</div>
				<div class="dept-side__body">
					<el-tree v-if="filteredDeptTree.length > 0" v-model:currentKey="currentDeptKey" :data="filteredDeptTree" :props="{ label: 'name', children: 'children' }" node-key="id" highlight-current default-expand-all @node-click="onDeptSelect">
						<template #default="{ data }">
							<el-icon v-if="data.children && data.children.length" style="margin-right: 4px"><ElIconOfficeBuilding /></el-icon>
							<el-icon v-else style="margin-right: 4px"><ElIconUser /></el-icon>
							<span>{{ data.name }}</span>
						</template>
					</el-tree>
					<el-empty v-else description="暂无部门数据" :image-size="60" />
				</div>
			</div>
		</template>

		<div class="tool-bar">
			<div class="right-panel">
				<el-dropdown :disabled="!selectedRows.length">
					<el-button :disabled="!selectedRows.length">
						批量操作
						<el-icon><ElIconArrowDown /></el-icon>
					</el-button>
					<template #dropdown>
						<el-dropdown-menu>
							<el-dropdown-item @click="handleBatchStatus">批量启用/禁用</el-dropdown-item>
							<el-dropdown-item @click="handleBatchDepartment">批量分配部门</el-dropdown-item>
							<el-dropdown-item @click="handleBatchRoles">批量分配角色</el-dropdown-item>
							<el-dropdown-item @click="handleBatchDelete" divided style="color: var(--el-color-danger)">批量删除</el-dropdown-item>
						</el-dropdown-menu>
					</template>
				</el-dropdown>
				<el-button @click="handleExport">
					<el-icon><ElIconDownload /></el-icon>
					导出
				</el-button>
				<el-button type="primary" @click="handleAdd">
					<el-icon><ElIconPlus /></el-icon>
					新增
				</el-button>
			</div>
		</div>

		<sTable
			ref="tableRef"
			tableName="auth_user"
			:data="data"
			:columns="columns"
			:searchForm="searchForm"
			:loading="loading"
			:total="total"
			:currentPage="paginationProps.currentPage"
			:pageSize="paginationProps.pageSize"
			:pageSizes="paginationProps.pageSizes"
			rowKey="id"
			height="100%"
			stripe
			remoteSort
			@refresh="refresh"
			@search="search"
			@pageChange="handlePageChange"
			@pageSizeChange="handlePageSizeChange"
			@sortChange="handleSortChange"
			@selectionChange="onSelectionChange"
		>
			<template #avatar_default="{ row }">
				<el-avatar :src="row.avatar" :size="32">
					<el-icon><ElIconUser /></el-icon>
				</el-avatar>
			</template>
			<template #status_default="{ row }">
				<el-tag :type="row.status === 1 ? 'success' : 'danger'" size="small">
					{{ row.status === 1 ? '正常' : '禁用' }}
				</el-tag>
			</template>
			<template #department_default="{ row }">
				{{ row.department?.name || '-' }}
			</template>
			<template #roles_default="{ row }">
				<el-tag v-for="role in row.roles || []" :key="role.id" size="small" style="margin-right: 4px">
					{{ role.name }}
				</el-tag>
			</template>
			<template #action_default="{ row }">
				<div class="action-buttons">
					<el-button type="primary" link size="small" @click="handleEdit(row)">编辑</el-button>
					<el-dropdown trigger="click" @command="(cmd) => handleAction(cmd, row)">
						<el-button type="primary" link size="small">
							更多
							<el-icon class="el-icon--right"><ElIconArrowDown /></el-icon>
						</el-button>
						<template #dropdown>
							<el-dropdown-menu>
								<el-dropdown-item command="view">查看</el-dropdown-item>
								<el-dropdown-item command="role">分配角色</el-dropdown-item>
								<el-dropdown-item command="resetPassword">重置密码</el-dropdown-item>
								<el-dropdown-item command="delete" divided style="color: var(--el-color-danger)">删除</el-dropdown-item>
							</el-dropdown-menu>
						</template>
					</el-dropdown>
				</div>
			</template>
		</sTable>
	</sPageSplit>

	<save-dialog v-if="dialog.save" ref="saveDialogRef" @success="refresh" @closed="dialog.save = false" />
	<role-dialog v-if="dialog.role" ref="roleDialogRef" @success="refresh" @closed="dialog.role = false" />
	<department-dialog v-if="dialog.department" ref="departmentDialogRef" @success="onBatchSuccess" @closed="dialog.department = false" />
	<batch-role-dialog v-if="dialog.batchRole" ref="batchRoleDialogRef" @success="onBatchSuccess" @closed="dialog.batchRole = false" />
	<s-export ref="exportRef" :apiMethod="authApi.user.export.post" :exportFields="exportFields" :defaultFields="defaultExportFields" :filters="exportFilters" @success="onExportSuccess" />
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import authApi from '@/api/auth'
import { useTable } from '@/hooks/useTable'
import saveDialog from './components/save.vue'
import roleDialog from './components/role.vue'
import departmentDialog from './components/department.vue'
import batchRoleDialog from './components/batch-role.vue'
import sExport from '@/components/sExport/index.vue'
import sPageSplit from '@/components/sPageSplit/index.vue'

const { tableRef, data, total, loading, selectedRows, searchForm, paginationProps, refresh, search, handlePageChange, handlePageSizeChange, handleSortChange, onSelectionChange, clearSelection } = useTable({
	apiObj: { get: (params) => authApi.user.list.get(params) },
	searchForm: {
		username: '',
		real_name: '',
		email: '',
		phone: '',
		status: null,
		department_id: null,
		role_id: null,
	},
	remoteSort: true,
})

const saveDialogRef = ref(null)
const roleDialogRef = ref(null)
const departmentDialogRef = ref(null)
const batchRoleDialogRef = ref(null)
const exportRef = ref(null)

const dialog = reactive({
	save: false,
	role: false,
	department: false,
	batchRole: false,
})

const deptTree = ref([])
const filteredDeptTree = ref([])
const deptKeyword = ref('')
const currentDeptKey = ref(null)
const roleOptions = ref([])

// 部门下拉选项（树形结构扁平化，按层级缩进）
const deptOptions = computed(() => {
	const result = []
	const walk = (nodes, depth = 0) => {
		for (const node of nodes || []) {
			result.push({ label: '　'.repeat(depth) + node.name, value: node.id })
			if (node.children?.length) walk(node.children, depth + 1)
		}
	}
	walk(deptTree.value)
	return result
})

const columns = computed(() => [
	{ type: 'checkbox', width: 50 },
	{ prop: 'avatar', title: '头像', width: 80, align: 'center', slots: { default: 'avatar_default' } },
	{ prop: 'username', title: '用户名', width: 130, filter: true },
	{ prop: 'real_name', title: '姓名', width: 100, filter: true },
	{ prop: 'email', title: '邮箱', width: 180, filter: true, showOverflowTooltip: true },
	{ prop: 'phone', title: '手机号', width: 130, filter: true },
	{ prop: 'department', title: '部门', width: 140, filter: { type: 'select', options: deptOptions.value, field: 'department_id' }, slots: { default: 'department_default' }, showOverflowTooltip: true },
	{ prop: 'roles', title: '角色', width: 160, filter: { type: 'select', options: roleOptions.value, field: 'role_id' }, slots: { default: 'roles_default' }, showOverflowTooltip: true },
	{
		prop: 'status',
		title: '状态',
		width: 100,
		align: 'center',
		filter: {
			type: 'select',
			options: [
				{ label: '正常', value: 1 },
				{ label: '禁用', value: 0 },
			],
		},
		slots: { default: 'status_default' },
	},
	{ prop: 'last_login_at', title: '最后登录', width: 170 },
	{ prop: 'action', title: '操作', width: 120, align: 'center', fixed: 'right', slots: { default: 'action_default' } },
])

const exportFields = [
	{ label: '用户名', value: 'username' },
	{ label: '姓名', value: 'real_name' },
	{ label: '邮箱', value: 'email' },
	{ label: '手机号', value: 'phone' },
	{ label: '部门', value: 'department' },
	{ label: '角色', value: 'roles' },
	{ label: '状态', value: 'status' },
	{ label: '最后登录时间', value: 'last_login_at' },
	{ label: '创建时间', value: 'created_at' },
]

const defaultExportFields = ['username', 'real_name', 'email', 'phone', 'department', 'roles', 'status']

const exportFilters = computed(() => ({
	username: searchForm.username,
	real_name: searchForm.real_name,
	email: searchForm.email,
	phone: searchForm.phone,
	status: searchForm.status,
	department_id: searchForm.department_id,
	role_id: searchForm.role_id,
}))

// ---- department ----

async function loadDepartmentTree() {
	try {
		const res = await authApi.department.tree.get()
		deptTree.value = res.data || []
		filteredDeptTree.value = res.data || []
	} catch (error) {
		console.error('加载部门树失败:', error)
	}
}

async function loadRoles() {
	try {
		const res = await authApi.role.all.get()
		roleOptions.value = (res.data || []).map((r) => ({ label: r.name, value: r.id }))
	} catch (error) {
		console.error('加载角色失败:', error)
	}
}

function handleDeptSearch(val) {
	if (!val) {
		filteredDeptTree.value = deptTree.value
		return
	}
	const keyword = val.toLowerCase()
	const filterTree = (nodes) => {
		return nodes.reduce((acc, node) => {
			const isMatch = node.name.toLowerCase().includes(keyword)
			const children = node.children ? filterTree(node.children) : []
			if (isMatch || children.length > 0) {
				acc.push({ ...node, children: children.length > 0 ? children : undefined })
			}
			return acc
		}, [])
	}
	filteredDeptTree.value = filterTree(deptTree.value)
}

function onDeptSelect(node) {
	searchForm.department_id = node.id
	search()
}

// 部门下拉与左侧树通过 department_id 联动
watch(
	() => searchForm.department_id,
	(val) => {
		currentDeptKey.value = val ?? null
	},
)

// ---- CRUD ----

function handleAdd() {
	dialog.save = true
	setTimeout(() => saveDialogRef.value?.open('add'), 0)
}

function handleView(row) {
	dialog.save = true
	setTimeout(() => saveDialogRef.value?.open('show', row), 0)
}

function handleEdit(row) {
	dialog.save = true
	setTimeout(() => saveDialogRef.value?.open('edit', row), 0)
}

function handleRole(row) {
	dialog.role = true
	setTimeout(() => {
		roleDialogRef.value?.open()
		roleDialogRef.value?.setData(row)
	}, 0)
}

function handleDelete(row) {
	ElMessageBox.confirm('确定删除该用户吗？', '提示', { type: 'warning' })
		.then(async () => {
			try {
				await authApi.user.delete.delete(row.id)
				ElMessage.success('删除成功')
				refresh()
			} catch (error) {
				console.error('删除用户失败:', error)
			}
		})
		.catch(() => {})
}

// 操作列下拉指令分发
function handleAction(command, row) {
	const actions = { view: handleView, role: handleRole, resetPassword: handleResetPassword, delete: handleDelete }
	actions[command]?.(row)
}

function handleResetPassword(row) {
	ElMessageBox.confirm('确定要重置该用户的密码吗？重置后密码为: 123456', '重置密码', { type: 'warning' })
		.then(async () => {
			try {
				await authApi.user.resetPassword.post(row.id)
				ElMessage.success('密码重置成功')
			} catch (error) {
				console.error('重置密码失败:', error)
			}
		})
		.catch(() => {})
}

// ---- batch ----

function handleBatchStatus() {
	if (!selectedRows.value.length) {
		ElMessage.warning('请选择要操作的用户')
		return
	}
	ElMessageBox.confirm(`确定要批量启用/禁用选中的 ${selectedRows.value.length} 个用户吗？`, '确认操作', { type: 'warning' })
		.then(async () => {
			try {
				const ids = selectedRows.value.map((r) => r.id)
				const status = selectedRows.value[0].status === 1 ? 0 : 1
				await authApi.user.batchStatus.post({ ids, status })
				ElMessage.success('操作成功')
				clearSelection()
				refresh()
			} catch (error) {
				console.error('批量更新状态失败:', error)
			}
		})
		.catch(() => {})
}

function handleBatchDelete() {
	if (!selectedRows.value.length) {
		ElMessage.warning('请选择要删除的用户')
		return
	}
	ElMessageBox.confirm(`确定删除选中的 ${selectedRows.value.length} 个用户吗？`, '确认删除', { type: 'warning' })
		.then(async () => {
			try {
				const ids = selectedRows.value.map((r) => r.id)
				await authApi.user.batchDelete.post({ ids })
				ElMessage.success('删除成功')
				clearSelection()
				refresh()
			} catch (error) {
				console.error('批量删除失败:', error)
			}
		})
		.catch(() => {})
}

function handleBatchDepartment() {
	if (!selectedRows.value.length) {
		ElMessage.warning('请选择要分配部门的用户')
		return
	}
	dialog.department = true
	setTimeout(() => {
		departmentDialogRef.value?.open(selectedRows.value.map((r) => r.id))
	}, 0)
}

function handleBatchRoles() {
	if (!selectedRows.value.length) {
		ElMessage.warning('请选择要分配角色的用户')
		return
	}
	dialog.batchRole = true
	setTimeout(() => {
		batchRoleDialogRef.value?.open(selectedRows.value.map((r) => r.id))
	}, 0)
}

function onBatchSuccess() {
	clearSelection()
	refresh()
}

function handleExport() {
	exportRef.value?.open()
}

function onExportSuccess(data) {
	// 导出成功，可以通过系统通知发送下载链接
	console.log('导出任务已创建:', data)
}

onMounted(() => {
	loadDepartmentTree()
	loadRoles()
})
</script>

<style scoped>
/* 部门侧栏内部布局（容器本身由 sPageSplit 提供） */
.dept-side {
	display: flex;
	flex-direction: column;
	height: 100%;
	overflow: hidden;
}
.dept-side__header {
	padding: 12px;
	border-bottom: 1px solid var(--layout-border-light, var(--el-border-color-lighter));
	flex-shrink: 0;
}
.dept-side__body {
	flex: 1;
	overflow-y: auto;
	padding: 12px;
}

/* sPageSplit 的 main 是 flex column：tool-bar 固定高度，sTable 占满剩余 */
.tool-bar {
	flex-shrink: 0;
	justify-content: flex-end;
}
.sTable {
	flex: 1;
	min-height: 0;
}

/* 操作列按钮水平对齐 */
.action-buttons {
	display: inline-flex;
	align-items: center;
	gap: 4px;
}
</style>
