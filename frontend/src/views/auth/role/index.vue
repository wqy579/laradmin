<template>
	<div class="page-container">
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
							<el-dropdown-item @click="handleBatchDelete" divided style="color: var(--el-color-danger)">批量删除</el-dropdown-item>
						</el-dropdown-menu>
					</template>
				</el-dropdown>
				<el-button type="primary" @click="handleAdd">
					<el-icon><ElIconPlus /></el-icon>
					新增
				</el-button>
			</div>
		</div>
		<div class="table-content">
			<sTable
				ref="tableRef"
				tableName="auth_role"
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
				<template #status_default="{ row }">
					<el-tag :type="row.status === 1 ? 'success' : 'danger'" size="small">
						{{ row.status === 1 ? '正常' : '禁用' }}
					</el-tag>
				</template>
				<template #action_default="{ row }">
					<div class="row-action">
						<el-button type="primary" link size="small" @click="handleEdit(row)">编辑</el-button>
						<el-dropdown trigger="click" @command="(cmd) => handleAction(cmd, row)">
							<el-button type="primary" link size="small">
								更多<el-icon class="el-icon--right"><ElIconArrowDown /></el-icon>
							</el-button>
							<template #dropdown>
								<el-dropdown-menu>
									<el-dropdown-item command="view">查看</el-dropdown-item>
									<el-dropdown-item command="permission">权限</el-dropdown-item>
									<el-dropdown-item command="delete" divided style="color: var(--el-color-danger)">删除</el-dropdown-item>
								</el-dropdown-menu>
							</template>
						</el-dropdown>
					</div>
				</template>
			</sTable>
		</div>
	</div>

	<save-dialog v-if="dialog.save" ref="saveDialogRef" @success="refresh" @closed="dialog.save = false" />
	<permission-dialog v-if="dialog.permission" ref="permissionDialogRef" @success="refresh" @closed="dialog.permission = false" />
</template>

<script setup>
import { ref, reactive } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import authApi from '@/api/auth'
import { useTable } from '@/hooks/useTable'
import saveDialog from './components/save.vue'
import permissionDialog from './components/permission.vue'

const { tableRef, data, total, loading, selectedRows, searchForm, paginationProps, refresh, search, handlePageChange, handlePageSizeChange, handleSortChange, onSelectionChange, clearSelection } = useTable({
	apiObj: { get: (params) => authApi.role.list.get(params) },
	searchForm: {
		name: '',
		code: '',
		status: null,
	},
	remoteSort: true,
})

const saveDialogRef = ref(null)
const permissionDialogRef = ref(null)

const dialog = reactive({
	save: false,
	permission: false,
})

const columns = [
	{ type: 'checkbox', width: 50 },
	{ prop: 'name', title: '角色名称', width: 150, filter: true },
	{ prop: 'code', title: '角色标识', width: 150, filter: true },
	{ prop: 'description', title: '描述', minWidth: 200, showOverflowTooltip: true },
	{ prop: 'sort', title: '排序', width: 80, align: 'center' },
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
	{ prop: 'created_at', title: '创建时间', width: 170 },
	{ prop: 'action', title: '操作', width: 120, align: 'center', fixed: 'right', showOverflowTooltip: false, slots: { default: 'action_default' } },
]

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

function handlePermission(row) {
	dialog.permission = true
	setTimeout(() => {
		permissionDialogRef.value?.open(row)
	}, 0)
}

async function handleDelete(row) {
	ElMessageBox.confirm('确定删除该角色吗？', '提示', { type: 'warning' })
		.then(async () => {
			try {
				await authApi.role.delete.delete(row.id)
				ElMessage.success('删除成功')
				refresh()
			} catch (error) {
				console.error('删除角色失败:', error)
			}
		})
		.catch(() => {})
}

// 操作列下拉指令分发
function handleAction(command, row) {
	const actions = { view: handleView, permission: handlePermission, delete: handleDelete }
	actions[command]?.(row)
}

// ---- batch ----

function handleBatchStatus() {
	if (!selectedRows.value.length) {
		ElMessage.warning('请选择要操作的角色')
		return
	}
	ElMessageBox.confirm(`确定要批量启用/禁用选中的 ${selectedRows.value.length} 个角色吗？`, '确认操作', { type: 'warning' })
		.then(async () => {
			try {
				const ids = selectedRows.value.map((r) => r.id)
				const status = selectedRows.value[0].status === 1 ? 0 : 1
				await authApi.role.batchStatus.post({ ids, status })
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
		ElMessage.warning('请选择要删除的角色')
		return
	}
	ElMessageBox.confirm(`确定删除选中的 ${selectedRows.value.length} 个角色吗？`, '确认删除', { type: 'warning' })
		.then(async () => {
			try {
				const ids = selectedRows.value.map((r) => r.id)
				await authApi.role.batchDelete.post({ ids })
				ElMessage.success('删除成功')
				clearSelection()
				refresh()
			} catch (error) {
				console.error('批量删除失败:', error)
			}
		})
		.catch(() => {})
}
</script>

<style scoped>
/* 页面结构样式已抽取到全局 components.css，响应式规则见 responsive.css */

/* 操作列：编辑 + 更多 按钮水平居中对齐 */
.row-action {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	gap: 4px;
}
</style>
