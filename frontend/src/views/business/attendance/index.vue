<template>
	<sPageSplit side-title="考勤管理">
		<div class="toolbar">
			<div class="right-panel">
				<el-button type="primary" @click="handleAdd">新增考勤</el-button>
				<el-button v-permission="'fin:attendance:export'" :loading="exporting" @click="handleExport">导出</el-button>
			</div>
		</div>

		<sTable
			ref="tableRef"
			tableName="business_attendance"
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
		>
			<template #status_default="{ row }">
				<el-tag v-if="row.status === 1" type="success" size="small">正常</el-tag>
				<el-tag v-else-if="row.status === 2" type="warning" size="small">迟到</el-tag>
				<el-tag v-else-if="row.status === 3" type="danger" size="small">缺勤</el-tag>
				<el-tag v-else size="small">未知</el-tag>
			</template>
			<template #check_in_default="{ row }">
				<span>{{ row.check_in || '-' }}</span>
			</template>
			<template #check_out_default="{ row }">
				<span>{{ row.check_out || '-' }}</span>
			</template>
			<template #action_default="{ row }">
				<el-button type="primary" link size="small" @click="handleEdit(row)">编辑</el-button>
				<el-popconfirm title="确定删除该考勤记录吗？" @confirm="handleDelete(row)">
					<template #reference><el-button type="danger" link size="small">删除</el-button></template>
				</el-popconfirm>
			</template>
		</sTable>
	</sPageSplit>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { useTable } from '@/hooks/useTable'
import businessApi from '@/api/business'
import sPageSplit from '@/components/sPageSplit/index.vue'
import { downloadApiResponse } from '@/utils/download'

const searchForm = ref({
	employee_id: null,
	start_date: '',
	end_date: '',
})

const { tableRef, data, total, loading, paginationProps, refresh, search, handlePageChange, handlePageSizeChange } = useTable({
	apiObj: { get: (params) => businessApi.attendance.list.get(params) },
	searchForm: searchForm.value,
})

const columns = [
	{ prop: 'id', title: 'ID', width: 70, align: 'center' },
	{ prop: 'employee.name', title: '员工姓名', width: 100 },
	{ prop: 'date', title: '日期', width: 100 },
	{ prop: 'check_in', title: '签到时间', width: 120, slots: { default: 'check_in_default' } },
	{ prop: 'check_out', title: '签退时间', width: 120, slots: { default: 'check_out_default' } },
	{ prop: 'status', title: '状态', width: 80, align: 'center', slots: { default: 'status_default' } },
	{ prop: 'remark', title: '备注', width: 150, showOverflowTooltip: true },
	{ prop: 'action_col', title: '操作', width: 120, align: 'center', fixed: 'right', slots: { default: 'action_default' } },
]

const dialog = reactive({ visible: false, type: 'add' })
const currentAttendance = ref(null)

const handleAdd = () => {
	currentAttendance.value = null
	dialog.type = 'add'
	dialog.visible = true
}

const handleEdit = (row) => {
	currentAttendance.value = row
	dialog.type = 'edit'
	dialog.visible = true
}

const handleDelete = async (row) => {
	const res = await businessApi.attendance.delete.delete(row.id)
	if (res.code === 200) {
		ElMessage.success('删除成功')
		refresh()
	}
}

const today = () => new Date().toISOString().slice(0, 10)
const exporting = ref(false)
const handleExport = async () => {
	exporting.value = true
	try {
		const res = await businessApi.attendance.export.get(searchForm.value)
		downloadApiResponse(res, `考勤记录_${today()}.csv`)
		ElMessage.success('导出成功')
	} catch (e) {
		if (e?.message) ElMessage.error(e.message || '导出失败')
	} finally {
		exporting.value = false
	}
}

onMounted(() => {
	refresh()
})
</script>

<style scoped>
.toolbar { margin-bottom: 12px; display: flex; justify-content: flex-end; }
</style>
