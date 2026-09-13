<template>
	<div class="biz-list">
		<div class="toolbar">
			<div class="right-panel">
				<el-button type="primary" @click="handleAdd">新增供应商</el-button>
			</div>
		</div>

		<sTable ref="tableRef" tableName="business_supplier" :data="data" :columns="columns" :searchForm="searchForm"
			:loading="loading" :total="total" :currentPage="paginationProps.currentPage" :pageSize="paginationProps.pageSize"
			:pageSizes="paginationProps.pageSizes" rowKey="id" height="100%" stripe
			@refresh="refresh" @search="search" @pageChange="handlePageChange" @pageSizeChange="handlePageSizeChange"
			@selectionChange="onSelectionChange">
			<template #is_active_default="{ row }">
				<el-tag :type="row.is_active ? 'success' : 'info'" size="small">{{ row.is_active ? '启用' : '禁用' }}</el-tag>
			</template>
			<template #action_default="{ row }">
				<el-button type="primary" link size="small" @click="handleEdit(row)">编辑</el-button>
				<el-popconfirm title="确定删除该供应商吗？" @confirm="handleDelete(row)">
					<template #reference><el-button type="danger" link size="small">删除</el-button></template>
				</el-popconfirm>
			</template>
		</sTable>

		<SupplierDialog v-if="dialog.visible" v-model:visible="dialog.visible" :record="currentSupplier" @success="refresh" />
	</div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import { useTable } from '@/hooks/useTable'
import businessApi from '@/api/business'
import SupplierDialog from '../components/supplier-dialog.vue'

const searchForm = ref({ keyword: '', is_active: null })

const { tableRef, data, total, loading, selectedRows, paginationProps, refresh, search, handlePageChange, handlePageSizeChange, onSelectionChange } = useTable({
	apiObj: { get: (params) => businessApi.supplier.list.get(params) },
	searchForm: searchForm.value,
})

const columns = [
	{ type: 'checkbox', width: 50 },
	{ prop: 'id', title: 'ID', width: 70, align: 'center' },
	{ prop: 'name', title: '供应商名称', width: 180 },
	{ prop: 'code', title: '供应商编码', width: 120 },
	{ prop: 'contact', title: '联系人', width: 100 },
	{ prop: 'phone', title: '联系电话', width: 130 },
	{ prop: 'address', title: '地址', width: 200, showOverflowTooltip: true },
	{ prop: 'balance', title: '欠款', width: 100, align: 'right' },
	{ prop: 'is_active', title: '状态', width: 80, align: 'center', slots: { default: 'is_active_default' } },
	{ prop: 'action_col', title: '操作', width: 120, align: 'center', fixed: 'right', slots: { default: 'action_default' } },
]

const dialog = reactive({ visible: false })
const currentSupplier = ref(null)

const handleAdd = () => { currentSupplier.value = null; dialog.visible = true }
const handleEdit = (row) => { currentSupplier.value = row; dialog.visible = true }
const handleDelete = async (row) => {
	const res = await businessApi.supplier.delete.delete(row.id)
	if (res.code === 200) { ElMessage.success('删除成功'); refresh() }
}

onMounted(() => { refresh() })
</script>

<style scoped>
.toolbar { margin-bottom: 12px; display: flex; justify-content: flex-end; flex-shrink: 0; }
.biz-list { height: 100%; display: flex; flex-direction: column; }
</style>
