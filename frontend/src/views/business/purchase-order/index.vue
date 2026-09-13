<template>
	<div class="biz-list">
		<div class="toolbar"><div class="right-panel"><el-button type="primary" @click="handleAdd">新增采购订单</el-button></div></div>
		<sTable ref="tableRef" tableName="business_purchase_order" :data="data" :columns="columns" :searchForm="searchForm"
			:loading="loading" :total="total" :currentPage="paginationProps.currentPage" :pageSize="paginationProps.pageSize"
			:pageSizes="paginationProps.pageSizes" rowKey="id" height="100%" stripe
			@refresh="refresh" @search="search" @pageChange="handlePageChange" @pageSizeChange="handlePageSizeChange">
			<template #status_default="{ row }">
				<el-tag :type="statusType(row.status)" size="small">{{ statusLabel(row.status) }}</el-tag>
			</template>
			<template #total_amount_default="{ row }">
				<span>¥{{ Number(row.total_amount || 0).toFixed(2) }}</span>
			</template>
			<template #action_default="{ row }">
				<el-button type="primary" link size="small" @click="handleEdit(row)">编辑</el-button>
				<el-button v-if="row.status==='draft'" type="success" link size="small" @click="handleApprove(row)">审批</el-button>
				<el-button v-if="row.status==='approved'" type="warning" link size="small" @click="handleReceive(row)">入库</el-button>
				<el-popconfirm v-if="row.status==='draft'" title="确定删除该订单吗？" @confirm="handleDelete(row)">
					<template #reference><el-button type="danger" link size="small">删除</el-button></template>
				</el-popconfirm>
			</template>
		</sTable>
	</div>
	<PurchaseOrderDialog v-if="dialog.order" v-model:visible="dialog.order" :record="currentOrder" :suppliers="suppliers" :warehouses="warehouses" @success="refresh" />
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import { useTable } from '@/hooks/useTable'
import businessApi from '@/api/business'
import PurchaseOrderDialog from '../components/purchase-order-dialog.vue'

const searchForm = ref({ keyword: '', status: null, supplier_id: null })
const { tableRef, data, total, loading, paginationProps, refresh, search, handlePageChange, handlePageSizeChange } = useTable({
	apiObj: { get: (params) => businessApi.purchaseOrder.list.get(params) },
	searchForm: searchForm.value,
})

const columns = [
	{ prop: 'id', title: 'ID', width: 70, align: 'center' },
	{ prop: 'order_no', title: '订单编号', width: 160 },
	{ prop: 'supplier_name', title: '供应商', width: 150 },
	{ prop: 'warehouse_name', title: '仓库', width: 120 },
	{ prop: 'order_date', title: '日期', width: 110 },
	{ prop: 'total_qty', title: '数量', width: 80, align: 'center' },
	{ prop: 'total_amount', title: '金额', width: 100, align: 'right', slots: { default: 'total_amount_default' } },
	{ prop: 'status', title: '状态', width: 90, align: 'center', slots: { default: 'status_default' } },
	{ prop: 'action_col', title: '操作', width: 180, align: 'center', fixed: 'right', slots: { default: 'action_default' } },
]

const dialog = reactive({ order: false })
const currentOrder = ref(null)
const suppliers = ref([])
const warehouses = ref([])

const statusType = (s) => ({ draft: 'info', approved: 'success', cancelled: 'danger' }[s] || 'info')
const statusLabel = (s) => ({ draft: '草稿', approved: '已审批', cancelled: '已取消' }[s] || s)

const handleAdd = () => { currentOrder.value = null; dialog.order = true }
const handleEdit = (row) => { currentOrder.value = row; dialog.order = true }
const handleDelete = async (row) => {
	const res = await businessApi.purchaseOrder.delete.post(row.id)
	if (res.code === 200) { ElMessage.success('删除成功'); refresh() }
}
const handleApprove = async (row) => {
	const res = await businessApi.purchaseOrder.approve.post(row.id)
	if (res.code === 200) { ElMessage.success('审批成功'); refresh() }
}
const handleReceive = async (row) => {
	const res = await businessApi.purchaseOrder.receive.post(row.id)
	if (res.code === 200) { ElMessage.success('入库成功'); refresh() }
}

onMounted(() => {
	Promise.all([
		businessApi.supplier.list.get({ per_page: 9999 }).then(r => { if (r.code === 200) suppliers.value = r.data?.list || [] }),
		businessApi.warehouse.list.get({ per_page: 9999 }).then(r => { if (r.code === 200) warehouses.value = r.data?.list || [] }),
	]).finally(() => refresh())
})
</script>

<style scoped>
.toolbar { margin-bottom: 12px; display: flex; justify-content: flex-end; }
</style>
