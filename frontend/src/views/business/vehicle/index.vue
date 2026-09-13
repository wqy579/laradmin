<template>
	<div class="biz-list">
		<div class="toolbar"><div class="right-panel"><el-button type="primary" @click="handleAdd">新增车辆</el-button></div></div>
		<sTable ref="tableRef" tableName="business_vehicle" :data="data" :columns="columns" :searchForm="searchForm"
			:loading="loading" :total="total" :currentPage="paginationProps.currentPage" :pageSize="paginationProps.pageSize"
			:pageSizes="paginationProps.pageSizes" rowKey="id" height="100%" stripe
			@refresh="refresh" @search="search" @pageChange="handlePageChange" @pageSizeChange="handlePageSizeChange"
			@selectionChange="onSelectionChange">
			<template #is_active_default="{ row }">
				<el-tag :type="row.is_active ? 'success' : 'info'" size="small">{{ row.is_active ? '启用' : '禁用' }}</el-tag>
			</template>
			<template #action_default="{ row }">
				<el-button type="primary" link size="small" @click="handleEdit(row)">编辑</el-button>
				<el-popconfirm title="确定删除该车辆吗？" @confirm="handleDelete(row)">
					<template #reference><el-button type="danger" link size="small">删除</el-button></template>
				</el-popconfirm>
			</template>
		</sTable>

		<VehicleDialog v-if="dialog.visible" v-model:visible="dialog.visible" :record="currentVehicle" @success="refresh" />
	</div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import { useTable } from '@/hooks/useTable'
import businessApi from '@/api/business'
import VehicleDialog from '../components/vehicle-dialog.vue'

const searchForm = ref({ keyword: '', is_active: null })
const { tableRef, data, total, loading, selectedRows, paginationProps, refresh, search, handlePageChange, handlePageSizeChange, onSelectionChange } = useTable({
	apiObj: { get: (params) => businessApi.vehicle.list.get(params) },
	searchForm: searchForm.value,
})

const columns = [
	{ type: 'checkbox', width: 50 },
	{ prop: 'id', title: 'ID', width: 70, align: 'center' },
	{ prop: 'plate_no', title: '车牌号', width: 130 },
	{ prop: 'driver_name', title: '司机姓名', width: 120 },
	{ prop: 'driver_phone', title: '司机电话', width: 130 },
	{ prop: 'vehicle_type', title: '车型', width: 100 },
	{ prop: 'load_capacity', title: '载重(吨)', width: 100, align: 'right' },
	{ prop: 'is_active', title: '状态', width: 80, align: 'center', slots: { default: 'is_active_default' } },
	{ prop: 'action_col', title: '操作', width: 120, align: 'center', fixed: 'right', slots: { default: 'action_default' } },
]

const dialog = reactive({ visible: false })
const currentVehicle = ref(null)

const handleAdd = () => { currentVehicle.value = null; dialog.visible = true }
const handleEdit = (row) => { currentVehicle.value = row; dialog.visible = true }
const handleDelete = async (row) => {
	const res = await businessApi.vehicle.delete.delete(row.id)
	if (res.code === 200) { ElMessage.success('删除成功'); refresh() }
}

onMounted(() => refresh())
</script>

<style scoped>
.toolbar { margin-bottom: 12px; display: flex; justify-content: flex-end; }
</style>
