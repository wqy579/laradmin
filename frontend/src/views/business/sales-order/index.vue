<template>
	<div class="sales-order-page">
		<aside class="summary-aside">
			<OrderSummary :summary="summary" :activeFilter="activeFilter" @filter="onSummaryFilter" />
		</aside>
		<div class="biz-list">
			<div class="toolbar"><div class="right-panel"><el-button type="primary" @click="handleAdd('normal')">新增订单</el-button><el-button @click="handleAdd('return')">新增退货</el-button></div></div>
			<sTable ref="tableRef" tableName="business_sales_order" :data="data" :columns="columns" :searchForm="searchForm"
				:loading="loading" :total="total" :currentPage="paginationProps.currentPage" :pageSize="paginationProps.pageSize"
				:pageSizes="paginationProps.pageSizes" rowKey="id" height="100%" stripe
				@refresh="refresh" @search="doSearch" @pageChange="handlePageChange" @pageSizeChange="handlePageSizeChange">
				<template #status_default="{ row }">
					<el-tag :type="statusType(row.status)" size="small">{{ statusLabel(row.status) }}</el-tag>
				</template>
				<template #total_amount_default="{ row }">
					<span>¥{{ Number(row.total_amount || 0).toFixed(2) }}</span>
				</template>
				<template #action_default="{ row }">
					<el-button type="primary" link size="small" @click="handleEdit(row)">编辑</el-button>
					<el-button v-if="row.status==='draft'" type="success" link size="small" @click="handleApprove(row)">审批</el-button>
					<el-popconfirm v-if="row.status==='draft'" title="确定删除该订单吗？" @confirm="handleDelete(row)">
						<template #reference><el-button type="danger" link size="small">删除</el-button></template>
					</el-popconfirm>
				</template>
			</sTable>
		</div>
	</div>
	<SalesOrderDialog v-if="dialog.order" v-model:visible="dialog.order" :orderType="orderType" :record="currentOrder" :customers="customers" :suppliers="suppliers" :warehouses="warehouses" :salesmen="salesmen" @success="doRefresh" />
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import { useTable } from '@/hooks/useTable'
import businessApi from '@/api/business'
import SalesOrderDialog from '../components/sales-order-dialog.vue'
import OrderSummary from './components/order-summary.vue'

const searchForm = ref({ keyword: '', status: null, customer_id: null, salesman_id: null, vehicle_id: null, route_id: null })
const { tableRef, data, total, loading, paginationProps, refresh, search, handlePageChange, handlePageSizeChange } = useTable({
	apiObj: { get: (params) => businessApi.salesOrder.list.get(params) },
	searchForm: searchForm.value,
})

const columns = [
	{ prop: 'id', title: 'ID', width: 70, align: 'center' },
	{ prop: 'order_no', title: '订单编号', width: 160 },
	{ prop: 'customer_name', title: '客户', width: 150 },
	{ prop: 'warehouse_name', title: '仓库', width: 120 },
	{ prop: 'order_date', title: '日期', width: 110 },
	{ prop: 'total_qty', title: '数量', width: 80, align: 'center' },
	{ prop: 'total_amount', title: '金额', width: 100, align: 'right', slots: { default: 'total_amount_default' } },
	{ prop: 'status', title: '状态', width: 90, align: 'center', slots: { default: 'status_default' } },
	{ prop: 'action_col', title: '操作', width: 160, align: 'center', fixed: 'right', slots: { default: 'action_default' } },
]

const dialog = reactive({ order: false })
const currentOrder = ref(null)
const customers = ref([])
const suppliers = ref([])
const warehouses = ref([])
const salesmen = ref([])
const orderType = ref('normal')

// 左侧汇总面板：跟随右侧搜索条件统计，点项联动过滤
const summary = ref({ totals: {}, bySalesman: [], byVehicle: [], byRoute: [] })
const activeFilter = ref({ salesman: null, vehicle: null, route: null })
const loadSummary = async () => {
	const res = await businessApi.salesOrder.summary.get(searchForm.value)
	if (res.code === 200) summary.value = res.data || {}
}
const doSearch = (...args) => { search(...args); loadSummary() }
const doRefresh = () => { refresh(); loadSummary() }
const onSummaryFilter = ({ type, id }) => {
	const field = type === 'salesman' ? 'salesman_id' : type === 'vehicle' ? 'vehicle_id' : 'route_id'
	searchForm.value[field] = id
	activeFilter.value[type] = id
	search()
	loadSummary()
}

const statusType = (s) => ({ draft: 'info', approved: 'success', cancelled: 'danger' }[s] || 'info')
const statusLabel = (s) => ({ draft: '草稿', approved: '已审批', cancelled: '已取消' }[s] || s)

const handleAdd = (type = 'normal') => { currentOrder.value = null; orderType.value = type; dialog.order = true }
const handleEdit = (row) => { currentOrder.value = row; dialog.order = true }
const handleDelete = async (row) => {
	const res = await businessApi.salesOrder.delete.delete(row.id)
	if (res.code === 200) { ElMessage.success('删除成功'); doRefresh() }
}
const handleApprove = async (row) => {
	const res = await businessApi.salesOrder.approve.post(row.id)
	if (res.code === 200) { ElMessage.success('审批成功'); doRefresh() }
}

onMounted(() => {
	Promise.all([
		businessApi.customer.list.get({ page_size: 9999 }).then(r => { if (r.code === 200) customers.value = r.data?.list || [] }),
		businessApi.warehouse.list.get({ page_size: 9999 }).then(r => { if (r.code === 200) warehouses.value = r.data?.list || [] }),
		businessApi.employee.list.get({ is_active: 1, page_size: 9999 }).then(r => { if (r.code === 200) salesmen.value = r.data?.list || [] }),
		businessApi.supplier.list.get({ page_size: 9999 }).then(r => { if (r.code === 200) suppliers.value = r.data?.list || [] }),
	]).finally(() => { refresh(); loadSummary() })
})
</script>

<style scoped>
.sales-order-page { display: flex; height: 100%; min-height: 0; }
.summary-aside { width: 300px; flex-shrink: 0; border-right: 1px solid var(--el-border-color); overflow: hidden; }
</style>
