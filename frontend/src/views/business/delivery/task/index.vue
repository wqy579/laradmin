<template>
	<div class="biz-list">
		<div class="toolbar">
			<div class="left-panel">
				<el-input v-model="searchForm.task_no" placeholder="任务单号" style="width: 160px" clearable @keyup.enter="doSearch" />
				<el-input v-model="searchForm.delivery_person_name" placeholder="配送员" style="width: 120px" clearable @keyup.enter="doSearch" />
				<el-select v-model="searchForm.status" placeholder="状态" clearable style="width: 110px" @change="doSearch">
					<el-option label="待配送" value="pending" />
					<el-option label="配送中" value="delivering" />
					<el-option label="已送达" value="delivered" />
					<el-option label="已收款" value="paid" />
					<el-option label="异常" value="exception" />
				</el-select>
				<el-button type="primary" @click="doSearch">查询</el-button>
				<el-button @click="doReset">重置</el-button>
			</div>
		</div>

		<sTable ref="tableRef" tableName="business_delivery_task" :data="data" :columns="columns"
			:loading="loading" :total="total" :currentPage="paginationProps.currentPage" :pageSize="paginationProps.pageSize"
			:pageSizes="paginationProps.pageSizes" rowKey="id" height="100%" stripe
			@refresh="refresh" @pageChange="handlePageChange" @pageSizeChange="handlePageSizeChange">
			<template #order_amount_default="{ row }">
				<span style="color: #F56C6C">¥{{ fmt(row.order_amount) }}</span>
			</template>
			<template #status_default="{ row }">
				<el-tag :type="statusType(row.status)" size="small">{{ statusLabel(row.status) }}</el-tag>
			</template>
			<template #action_default="{ row }">
				<el-button type="primary" link size="small" @click="handleDetail(row)">查看</el-button>
				<el-button v-if="row.status === 'pending'" type="success" link size="small" @click="handleStart(row)">开始配送</el-button>
				<el-button v-if="row.status === 'delivering'" type="success" link size="small" @click="handleDeliver(row)">确认送达</el-button>
				<el-button v-if="row.status === 'delivering' || row.status === 'delivered'" type="danger" link size="small" @click="handleException(row)">异常登记</el-button>
			</template>
		</sTable>

		<TaskDetail v-if="dialog.detail" v-model:visible="dialog.detail" :record="currentRow" @success="refresh" />
	</div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { useTable } from '@/hooks/useTable'
import businessApi from '@/api/business'
import TaskDetail from './components/task-detail.vue'

const searchForm = ref({ task_no: '', delivery_person_name: '', status: '' })
const { tableRef, data, total, loading, paginationProps, refresh, search, handlePageChange, handlePageSizeChange } = useTable({
	apiObj: { get: (params) => businessApi.deliveryTask.list.get(params) },
	searchForm: searchForm.value,
})

const columns = [
	{ prop: 'task_no', title: '任务单号', width: 160 },
	{ prop: 'load_no', title: '装车单号', width: 160 },
	{ prop: 'delivery_person_name', title: '配送员', width: 100 },
	{ prop: 'customer_name', title: '客户名称', width: 160 },
	{ prop: 'address', title: '送货地址', width: 200, showOverflowTooltip: true },
	{ prop: 'phone', title: '联系电话', width: 120 },
	{ prop: 'order_amount', title: '订单金额', width: 110, align: 'right', slots: { default: 'order_amount_default' } },
	{ prop: 'status', title: '状态', width: 100, align: 'center', slots: { default: 'status_default' } },
	{ prop: 'action_col', title: '操作', width: 240, align: 'center', fixed: 'right', slots: { default: 'action_default' } },
]

const dialog = reactive({ detail: false })
const currentRow = ref(null)
const statusLabel = (s) => ({ pending: '待配送', delivering: '配送中', delivered: '已送达', paid: '已收款', exception: '异常' }[s] || s)
const statusType = (s) => ({ pending: 'info', delivering: 'primary', delivered: 'warning', paid: 'success', exception: 'danger' }[s] || 'info')
const fmt = (n) => Number(n || 0).toFixed(2)

const doSearch = () => search()
const doReset = () => { searchForm.value = { task_no: '', delivery_person_name: '', status: '' }; refresh() }
const handleDetail = (row) => { currentRow.value = row; dialog.detail = true }
const handleStart = async (row) => {
	const res = await businessApi.deliveryTask.start.post(row.id)
	if (res.code === 200) { ElMessage.success(res.message || '开始配送'); refresh() }
}
const handleDeliver = async (row) => {
	try {
		await ElMessageBox.confirm('确认已送达客户？', '确认送达', { type: 'warning' })
		const res = await businessApi.deliveryTask.deliver.post(row.id)
		if (res.code === 200) { ElMessage.success(res.message || '已确认送达'); refresh() }
	} catch (e) { if (e && e.message) ElMessage.error(e.message) }
}
const handleException = (row) => { currentRow.value = row; dialog.detail = true }
onMounted(() => refresh())
</script>

<style scoped>
.toolbar { margin-bottom: 12px; display: flex; justify-content: flex-start; flex-shrink: 0; gap: 8px; flex-wrap: wrap; }
.left-panel { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
.biz-list { height: 100%; display: flex; flex-direction: column; }
</style>
