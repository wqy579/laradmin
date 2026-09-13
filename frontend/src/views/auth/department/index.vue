<template>
	<div class="page-container">
		<div class="tool-bar">
			<div class="right-panel">
				<el-button type="primary" @click="handleExpandAll">{{ isAllExpanded ? '全部折叠' : '全部展开' }}</el-button>
				<el-button type="primary" @click="handleAdd(null)">
					<el-icon><ElIconPlus /></el-icon>
					新增
				</el-button>
			</div>
		</div>
		<div class="table-content">
			<sTable
				ref="tableRef"
				tableName="auth_department"
				:data="treeData"
				:columns="columns"
				:searchForm="searchForm"
				:loading="loading"
				rowKey="id"
				height="100%"
				hidePagination
				:tree-config="{ childrenField: 'children', accordion: false, showLine: true, expandAll: true }"
				@refresh="loadData"
				@search="handleSearch"
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
									<el-dropdown-item command="addChild">新增子级</el-dropdown-item>
									<el-dropdown-item command="delete" divided style="color: var(--el-color-danger)">删除</el-dropdown-item>
								</el-dropdown-menu>
							</template>
						</el-dropdown>
					</div>
				</template>
			</sTable>
		</div>
	</div>

	<save-dialog v-if="dialog.save" ref="saveDialogRef" @success="loadData" @closed="dialog.save = false" />
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import authApi from '@/api/auth'
import saveDialog from './components/save.vue'

const tableRef = ref(null)
const saveDialogRef = ref(null)
const loading = ref(false)
const treeData = ref([])
const rawTree = ref([])
const searchForm = reactive({
	name: '',
	code: '',
	leader: '',
	status: null,
})
const isAllExpanded = ref(true)
const selectedRows = ref([])

const dialog = reactive({
	save: false,
})

const columns = [
	{ type: 'checkbox', width: 50 },
	{ prop: 'name', title: '部门名称', minWidth: 200, treeNode: true, filter: true },
	{ prop: 'code', title: '部门标识', width: 150, filter: true },
	{ prop: 'leader', title: '负责人', width: 120, filter: true },
	{ prop: 'phone', title: '联系电话', width: 140 },
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

async function loadData() {
	loading.value = true
	try {
		const res = await authApi.department.tree.get()
		rawTree.value = res.data || []
		treeData.value = res.data || []
	} catch (error) {
		console.error('加载部门树失败:', error)
	} finally {
		loading.value = false
	}
}

// 基于浮动筛选项对原始树做多字段递归过滤，命中节点的祖先链一并保留
function handleSearch() {
	const { name, code, leader, status } = searchForm
	const hasName = name && name.trim()
	const hasCode = code && code.trim()
	const hasLeader = leader && leader.trim()
	const hasStatus = status !== null && status !== ''
	const hasFilter = hasName || hasCode || hasLeader || hasStatus
	if (!hasFilter) {
		treeData.value = rawTree.value
		return
	}
	const matchNode = (node) => {
		if (hasName && !(node.name || '').toLowerCase().includes(name.trim().toLowerCase())) return false
		if (hasCode && !(node.code || '').toLowerCase().includes(code.trim().toLowerCase())) return false
		if (hasLeader && !(node.leader || '').toLowerCase().includes(leader.trim().toLowerCase())) return false
		if (hasStatus && node.status !== status) return false
		return true
	}
	const filterTree = (nodes) => {
		return nodes.reduce((acc, node) => {
			const children = node.children ? filterTree(node.children) : []
			if (matchNode(node) || children.length > 0) {
				acc.push({ ...node, children: children.length > 0 ? children : undefined })
			}
			return acc
		}, [])
	}
	treeData.value = filterTree(rawTree.value)
}

function handleExpandAll() {
	const grid = tableRef.value?.getGridInstance?.()
	if (!grid) return
	if (isAllExpanded.value) {
		grid.clearTreeExpand()
	} else {
		grid.setAllTreeExpand(true)
	}
	isAllExpanded.value = !isAllExpanded.value
}

function onSelectionChange(rows) {
	selectedRows.value = rows
}

// ---- CRUD ----

function handleAdd(parentRow) {
	dialog.save = true
	setTimeout(() => saveDialogRef.value?.open('add', null, parentRow), 0)
}

function handleView(row) {
	dialog.save = true
	setTimeout(() => saveDialogRef.value?.open('show', row), 0)
}

function handleEdit(row) {
	dialog.save = true
	setTimeout(() => saveDialogRef.value?.open('edit', row), 0)
}

async function handleDelete(row) {
	ElMessageBox.confirm('确定删除该部门吗？', '提示', { type: 'warning' })
		.then(async () => {
			try {
				await authApi.department.delete.delete(row.id)
				ElMessage.success('删除成功')
				loadData()
			} catch (error) {
				console.error('删除部门失败:', error)
			}
		})
		.catch(() => {})
}

// 操作列下拉指令分发
function handleAction(command, row) {
	const actions = { view: handleView, addChild: (r) => handleAdd(r), delete: handleDelete }
	actions[command]?.(row)
}

onMounted(() => {
	loadData()
})
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
