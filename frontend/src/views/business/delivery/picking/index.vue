<template>
	<div class="biz-list">
		<div class="toolbar">
			<div class="left-panel">
				<el-input v-model="searchForm.picking_no" placeholder="配货单号" style="width: 160px" clearable @keyup.enter="doSearch" />
				<el-input v-model="searchForm.order_no" placeholder="订单号" style="width: 160px" clearable @keyup.enter="doSearch" />
				<el-select v-model="searchForm.customer_id" placeholder="客户" filterable clearable style="width: 160px" @keyup.enter="doSearch">
					<el-option v-for="c in customers" :key="c.id" :label="c.name" :value="c.id" />
				</el-select>
				<el-date-picker v-model="dateRange" type="daterange" range-separator="~" start-placeholder="开始日期" end-placeholder="结束日期" value-format="YYYY-MM-DD" style="width: 240px" @change="onDateChange" />
				<el-select v-model="searchForm.status" placeholder="状态" clearable style="width: 110px" @change="doSearch">
					<el-option label="待配货" value="pending" />
					<el-option label="已配货" value="picked" />
					<el-option label="已取消" value="cancelled" />
				</el-select>
				<el-button type="primary" @click="doSearch">查询</el-button>
				<el-button @click="doReset">重置</el-button>
			</div>
			<div class="right-panel">
				<el-button type="primary" @click="handleAdd">新增配货单</el-button>
			</div>
		</div>

		<sTable ref="tableRef" tableName="business_delivery_picking" :data="data" :columns="columns"
			:loading="loading" :total="total" :currentPage="paginationProps.currentPage" :pageSize="paginationProps.pageSize"
			:pageSizes="paginationProps.pageSizes" rowKey="id" height="100%" stripe
			@refresh="refresh" @pageChange="handlePageChange" @pageSizeChange="handlePageSizeChange">
			<template #amount_default="{ row }">
				<span style="color: #F56C6C">¥{{ fmt(row.total_amount) }}</span>
			</template>
			<template #status_default="{ row }">
				<el-tag :type="statusType(row.status)" size="small">{{ statusLabel(row.status) }}</el-tag>
			</template>
			<template #action_default="{ row }">
				<el-button type="primary" link size="small" @click="handleDetail(row)">查看</el-button>
				<el-button v-if="row.status === 'pending'" type="success" link size="small" @click="handleConfirm(row)">配货</el-button>
				<el-popconfirm v-if="row.status === 'pending'" title="确定删除该配货单吗？" @confirm="handleDelete(row)">
					<template #reference><el-button type="danger" link size="small">删除</el-button></template>
				</el-popconfirm>
			</template>
		</sTable>

		<PickingDialog v-if="dialog.add" v-model:visible="dialog.add" @success="refresh" />
		<PickingDetail v-if="dialog.detail" v-model:visible="dialog.detail" :record="currentRow" @success="refresh" />
	</div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { useTable } from '@/hooks/useTable'
import businessApi from '@/api/business'
import PickingDialog from './components/picking-dialog.vue'
import PickingDetail from './components/picking-detail.vue'

const searchForm = ref({ picking_no: '', order_no: '', customer_id: null, status: '', date_range: [] })
const dateRange = ref([])
const customers = ref([])

const { tableRef, data, total, loading, paginationProps, refresh, search, handlePageChange, handlePageSizeChange } = useTable({
	apiObj: { get: (params) => businessApi.deliveryPicking.list.get(params) },
	searchForm: searchForm.value,
})

const columns = [
	{ prop: 'picking_no', title: '配货单号', width: 160 },
	{ prop: 'order_no', title: '订单号', width: 160 },
	{ prop: 'customer_name', title: '客户名称', width: 180 },
	{ prop: 'picking_date', title: '配货日期', width: 120 },
	{ prop: 'total_skus', title: '商品种类', width: 90, align: 'center' },
	{ prop: 'total_qty', title: '商品总数', width: 90, align: 'center' },
	{ prop: 'total_amount', title: '金额', width: 120, align: 'right', slots: { default: 'amount_default' } },
	{ prop: 'status', title: '状态', width: 100, align: 'center', slots: { default: 'status_default' } },
	{ prop: 'action_col', title: '操作', width: 170, align: 'center', fixed: 'right', slots: { default: 'action_default' } },
]

const dialog = reactive({ add: false, detail: false })
const currentRow = ref(null)

const statusLabel = (s) => ({ pending: '待配货', picked: '已配货', cancelled: '已取消' }[s] || s)
const statusType = (s) => ({ pending: 'warning', picked: 'success', cancelled: 'info' }[s] || 'info')
const fmt = (n) => Number(n || 0).toFixed(2)

const onDateChange = (val) => { searchForm.value.date_range = val || [] }
const doSearch = () => { search() }
const doReset = () => {
	searchForm.value = { picking_no: '', order_no: '', customer_id: null, status: '', date_range: [] }
	dateRange.value = []
	refresh()
}
const handleAdd = () => { dialog.add = true }
const handleDetail = (row) => { currentRow.value = row; dialog.detail = true }
const handleConfirm = async (row) => {
	try {
		await ElMessageBox.confirm('确认配货将冻结库存并生成拣货单，订单状态变为「配货中」，是否继续？', '确认配货', { type: 'warning' })
		const res = await businessApi.deliveryPicking.confirm.post(row.id)
		if (res.code === 200) { ElMessage.success(res.message || '配货确认成功'); refresh() }
	} catch (e) { /* 拦截器已弹错误提示，此处静默吞掉，避免重复 toast */ }
}
const handleDelete = async (row) => {
	try {
		const res = await businessApi.deliveryPicking.delete.delete(row.id)
		if (res.code === 200) { ElMessage.success('删除成功'); refresh() }
	} catch (e) { /* 拦截器已弹错误提示，此处静默吞掉，避免重复 toast */ }
}

onMounted(async () => {
	refresh()
	const cr = await businessApi.customer.list.get({ page_size: 9999 })
	customers.value = cr.data?.list || cr.data || []
})
</script>

<style scoped>
.toolbar { margin-bottom: 12px; display: flex; justify-content: space-between; flex-shrink: 0; gap: 8px; flex-wrap: wrap; }
.left-panel { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
.biz-list { height: 100%; display: flex; flex-direction: column; }
</style>
