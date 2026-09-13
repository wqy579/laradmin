<template>
	<div class="biz-list">
		<div class="toolbar">
			<div class="right-panel">
				<el-dropdown :disabled="!selectedRows.length">
					<el-button :disabled="!selectedRows.length">批量操作</el-button>
					<template #dropdown>
						<el-dropdown-menu>
							<el-dropdown-item @click="handleBatchStatus(true)">启用</el-dropdown-item>
							<el-dropdown-item @click="handleBatchStatus(false)">禁用</el-dropdown-item>
							<el-dropdown-item divided style="color:var(--el-color-danger)" @click="handleBatchDelete">批量删除</el-dropdown-item>
						</el-dropdown-menu>
					</template>
				</el-dropdown>
				<el-button type="primary" @click="handleAdd">新增员工</el-button>
			</div>
		</div>

		<sTable
			ref="tableRef"
			tableName="business_employee"
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
			@refresh="refresh"
			@search="search"
			@pageChange="handlePageChange"
			@pageSizeChange="handlePageSizeChange"
			@selectionChange="onSelectionChange"
		>
			<template #gender_default="{ row }">
				<el-tag v-if="row.gender === '男'" type="primary" size="small">男</el-tag>
				<el-tag v-else-if="row.gender === '女'" type="danger" size="small">女</el-tag>
				<span v-else>-</span>
			</template>
			<template #role_default="{ row }">
				<el-tag v-if="row.role === 'admin'" type="danger" size="small">管理员</el-tag>
				<el-tag v-else-if="row.role === 'manager'" type="warning" size="small">主管</el-tag>
				<el-tag v-else-if="row.role === 'salesman'" type="success" size="small">销售</el-tag>
				<el-tag v-else size="small">员工</el-tag>
			</template>
			<template #is_active_default="{ row }">
				<el-tag :type="row.is_active ? 'success' : 'info'" size="small">{{ row.is_active ? '启用' : '禁用' }}</el-tag>
			</template>
			<template #base_salary_default="{ row }">
				<span>{{ formatMoney(row.base_salary) }}</span>
			</template>
			<template #action_default="{ row }">
				<el-button type="primary" link size="small" @click="handleEdit(row)">编辑</el-button>
				<el-popconfirm title="确定删除该员工吗？" @confirm="handleDelete(row)">
					<template #reference><el-button type="danger" link size="small">删除</el-button></template>
				</el-popconfirm>
			</template>
		</sTable>

		<EmployeeDialog v-if="dialog.visible" v-model:visible="dialog.visible" :record="currentEmployee" @success="refresh" />
	</div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { useTable } from '@/hooks/useTable'
import businessApi from '@/api/business'
import EmployeeDialog from '../components/employee-dialog.vue'

const searchForm = ref({
	keyword: '',
	role: null,
	is_active: null,
})

const { tableRef, data, total, loading, selectedRows, paginationProps, refresh, search, handlePageChange, handlePageSizeChange, onSelectionChange } = useTable({
	apiObj: { get: (params) => businessApi.employee.list.get(params) },
	searchForm: searchForm.value,
})

const columns = [
	{ type: 'checkbox', width: 50 },
	{ prop: 'id', title: 'ID', width: 70, align: 'center' },
	{ prop: 'code', title: '工号', width: 100 },
	{ prop: 'name', title: '姓名', width: 100 },
	{ prop: 'gender', title: '性别', width: 70, align: 'center', slots: { default: 'gender_default' } },
	{ prop: 'position', title: '职位', width: 100 },
	{ prop: 'role', title: '角色', width: 80, align: 'center', slots: { default: 'role_default' } },
	{ prop: 'phone', title: '电话', width: 120 },
	{ prop: 'base_salary', title: '基本工资', width: 100, align: 'right', slots: { default: 'base_salary_default' } },
	{ prop: 'hire_date', title: '入职日期', width: 100 },
	{ prop: 'is_active', title: '状态', width: 80, align: 'center', slots: { default: 'is_active_default' } },
	{ prop: 'action_col', title: '操作', width: 120, align: 'center', fixed: 'right', slots: { default: 'action_default' } },
]

const dialog = reactive({ visible: false })
const currentEmployee = ref(null)

const formatMoney = (val) => val ? '¥' + Number(val).toFixed(2) : '-'

const handleAdd = () => { currentEmployee.value = null; dialog.visible = true }
const handleEdit = (row) => { currentEmployee.value = row; dialog.visible = true }
const handleDelete = async (row) => {
	const res = await businessApi.employee.delete.delete(row.id)
	if (res.code === 200) { ElMessage.success('删除成功'); refresh() }
}
const handleBatchDelete = async () => {
	const res = await businessApi.employee.batchDelete.post({ ids: selectedRows.value.map(r => r.id) })
	if (res.code === 200) { ElMessage.success('删除成功'); refresh() }
}
const handleBatchStatus = async (active) => {
	const res = await businessApi.employee.batchUpdateStatus.post({ ids: selectedRows.value.map(r => r.id), is_active: active })
	if (res.code === 200) { ElMessage.success('操作成功'); refresh() }
}

onMounted(() => {
	refresh()
})
</script>

<style scoped>
.toolbar { margin-bottom: 12px; display: flex; justify-content: flex-end; flex-shrink: 0; }
.biz-list { height: 100%; display: flex; flex-direction: column; }
</style>
