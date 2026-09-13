<template>
	<div class="biz-list">
		<div class="toolbar"><div class="right-panel"><el-button type="primary" @click="handleAdd">新增路线</el-button></div></div>
		<sTable ref="tableRef" tableName="business_route" :data="data" :columns="columns" :searchForm="searchForm"
			:loading="loading" :total="total" :currentPage="paginationProps.currentPage" :pageSize="paginationProps.pageSize"
			:pageSizes="paginationProps.pageSizes" rowKey="id"  stripe
			@refresh="refresh" @search="search" @pageChange="handlePageChange" @pageSizeChange="handlePageSizeChange"
			@selectionChange="onSelectionChange">
			<template #is_active_default="{ row }">
				<el-tag :type="row.is_active ? 'success' : 'info'" size="small">{{ row.is_active ? '启用' : '禁用' }}</el-tag>
			</template>
			<template #action_default="{ row }">
				<el-button type="primary" link size="small" @click="handleEdit(row)">编辑</el-button>
				<el-popconfirm title="确定删除该路线吗？" @confirm="handleDelete(row)">
					<template #reference><el-button type="danger" link size="small">删除</el-button></template>
				</el-popconfirm>
			</template>
		</sTable>

		<RouteDialog v-if="dialog.visible" v-model:visible="dialog.visible" :record="currentRoute" :customers="customers" @success="refresh" />
	</div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import { useTable } from '@/hooks/useTable'
import businessApi from '@/api/business'
import RouteDialog from '../components/route-dialog.vue'

const searchForm = ref({ keyword: '', is_active: null })
const { tableRef, data, total, loading, selectedRows, paginationProps, refresh, search, handlePageChange, handlePageSizeChange, onSelectionChange } = useTable({
	apiObj: { get: (params) => businessApi.route.list.get(params) },
	searchForm: searchForm.value,
})

const columns = [
	{ type: 'checkbox', width: 50 },
	{ prop: 'id', title: 'ID', width: 70, align: 'center' },
	{ prop: 'name', title: '路线名称', width: 180 },
	{ prop: 'code', title: '路线编码', width: 120 },
	{ prop: 'area', title: '区域', width: 120 },
	{ prop: 'customer_count', title: '客户数', width: 80, align: 'center' },
	{ prop: 'sort_order', title: '排序', width: 80, align: 'center' },
	{ prop: 'is_active', title: '状态', width: 80, align: 'center', slots: { default: 'is_active_default' } },
	{ prop: 'action_col', title: '操作', width: 120, align: 'center', fixed: 'right', slots: { default: 'action_default' } },
]

const dialog = reactive({ visible: false })
const currentRoute = ref(null)
const customers = ref([])

const handleAdd = () => { currentRoute.value = null; dialog.visible = true }
const handleEdit = (row) => { currentRoute.value = row; dialog.visible = true }
const handleDelete = async (row) => {
	const res = await businessApi.route.delete.delete(row.id)
	if (res.code === 200) { ElMessage.success('删除成功'); refresh() }
}

onMounted(() => {
	businessApi.customer.list.get({ page_size: 9999 }).then(res => { if (res.code === 200) customers.value = res.data?.list || [] })
	refresh()
})
</script>

<style scoped>
.toolbar { margin-bottom: 12px; display: flex; justify-content: flex-end; flex-shrink: 0; }
.biz-list { height: 100%; display: flex; flex-direction: column; }
</style>
