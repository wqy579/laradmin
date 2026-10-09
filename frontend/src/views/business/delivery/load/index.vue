<template>
	<div class="biz-list">
		<div class="toolbar">
			<div class="left-panel">
				<el-input v-model="searchForm.load_no" placeholder="装车单号" style="width: 160px" clearable @keyup.enter="doSearch" />
				<el-input v-model="searchForm.delivery_person_name" placeholder="配送员" style="width: 120px" clearable @keyup.enter="doSearch" />
				<el-input v-model="searchForm.plate_no" placeholder="车牌号" style="width: 120px" clearable @keyup.enter="doSearch" />
				<el-select v-model="searchForm.status" placeholder="状态" clearable style="width: 110px" @change="doSearch">
					<el-option label="待装车" value="pending" />
					<el-option label="已装车" value="loaded" />
					<el-option label="配送中" value="delivering" />
					<el-option label="已完成" value="completed" />
				</el-select>
				<el-button type="primary" @click="doSearch">查询</el-button>
				<el-button @click="doReset">重置</el-button>
			</div>
			<div class="right-panel">
				<el-button type="primary" @click="handleAdd">新增装车单</el-button>
			</div>
		</div>

		<sTable ref="tableRef" tableName="business_delivery_load" :data="data" :columns="columns"
			:loading="loading" :total="total" :currentPage="paginationProps.currentPage" :pageSize="paginationProps.pageSize"
			:pageSizes="paginationProps.pageSizes" rowKey="id" height="100%" stripe
			@refresh="refresh" @pageChange="handlePageChange" @pageSizeChange="handlePageSizeChange">
			<template #total_amount_default="{ row }">
				<span style="color: #F56C6C">¥{{ fmt(row.total_amount) }}</span>
			</template>
			<template #status_default="{ row }">
				<el-tag :type="statusType(row.status)" size="small">{{ statusLabel(row.status) }}</el-tag>
			</template>
			<template #action_default="{ row }">
				<el-button type="primary" link size="small" @click="handleDetail(row)">查看</el-button>
				<el-button v-if="row.status === 'pending'" type="success" link size="small" @click="handleConfirm(row)">装车</el-button>
			</template>
		</sTable>

		<LoadDialog v-if="dialog.add" v-model:visible="dialog.add" @success="refresh" />
	</div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { useTable } from '@/hooks/useTable'
import businessApi from '@/api/business'
import LoadDialog from './components/load-dialog.vue'

const searchForm = ref({ load_no: '', delivery_person_name: '', plate_no: '', status: '' })
const { tableRef, data, total, loading, paginationProps, refresh, search, handlePageChange, handlePageSizeChange } = useTable({
	apiObj: { get: (params) => businessApi.deliveryLoad.list.get(params) },
	searchForm: searchForm.value,
})

const columns = [
	{ prop: 'load_no', title: '装车单号', width: 160 },
	{ prop: 'delivery_person_name', title: '配送员', width: 100 },
	{ prop: 'plate_no', title: '车牌号', width: 110 },
	{ prop: 'load_date', title: '装车日期', width: 120 },
	{ prop: 'order_count', title: '订单数量', width: 90, align: 'center' },
	{ prop: 'total_skus', title: '商品种类', width: 90, align: 'center' },
	{ prop: 'total_qty', title: '商品总数', width: 90, align: 'center' },
	{ prop: 'total_amount', title: '金额', width: 120, align: 'right', slots: { default: 'total_amount_default' } },
	{ prop: 'status', title: '状态', width: 100, align: 'center', slots: { default: 'status_default' } },
	{ prop: 'action_col', title: '操作', width: 150, align: 'center', fixed: 'right', slots: { default: 'action_default' } },
]

const dialog = reactive({ add: false })
const statusLabel = (s) => ({ pending: '待装车', loaded: '已装车', delivering: '配送中', completed: '已完成' }[s] || s)
const statusType = (s) => ({ pending: 'warning', loaded: '', delivering: 'primary', completed: 'success' }[s] || 'info')
const fmt = (n) => Number(n || 0).toFixed(2)

const doSearch = () => search()
const doReset = () => { searchForm.value = { load_no: '', delivery_person_name: '', plate_no: '', status: '' }; refresh() }
const handleAdd = () => { dialog.add = true }
const handleConfirm = async (row) => {
	try {
		await ElMessageBox.confirm('确认装车将扣减库存并生成配送任务，是否继续？', '确认装车', { type: 'warning' })
		const res = await businessApi.deliveryLoad.confirm.post(row.id)
		if (res.code === 200) { ElMessage.success(res.message || '装车确认成功'); refresh() }
	} catch (e) { if (e && e.message) ElMessage.error(e.message) }
}
const handleDetail = (row) => { /* 详情可后续扩展 */ }
onMounted(() => refresh())
</script>

<style scoped>
.toolbar { margin-bottom: 12px; display: flex; justify-content: space-between; flex-shrink: 0; gap: 8px; flex-wrap: wrap; }
.left-panel { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
.biz-list { height: 100%; display: flex; flex-direction: column; }
</style>
