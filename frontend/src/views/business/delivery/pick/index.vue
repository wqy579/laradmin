<template>
	<div class="biz-list">
		<div class="toolbar">
			<div class="left-panel">
				<el-input v-model="searchForm.pick_no" placeholder="拣货单号" style="width: 160px" clearable @keyup.enter="doSearch" />
				<el-input v-model="searchForm.picking_no" placeholder="配货单号" style="width: 160px" clearable @keyup.enter="doSearch" />
				<el-input v-model="searchForm.picker_name" placeholder="拣货人" style="width: 120px" clearable @keyup.enter="doSearch" />
				<el-select v-model="searchForm.status" placeholder="状态" clearable style="width: 110px" @change="doSearch">
					<el-option label="待拣货" value="pending" />
					<el-option label="拣货中" value="picking" />
					<el-option label="已拣货" value="picked" />
					<el-option label="已取消" value="cancelled" />
				</el-select>
				<el-button type="primary" @click="doSearch">查询</el-button>
				<el-button @click="doReset">重置</el-button>
			</div>
		</div>

		<sTable ref="tableRef" tableName="business_delivery_pick" :data="data" :columns="columns"
			:loading="loading" :total="total" :currentPage="paginationProps.currentPage" :pageSize="paginationProps.pageSize"
			:pageSizes="paginationProps.pageSizes" rowKey="id" height="100%" stripe
			@refresh="refresh" @pageChange="handlePageChange" @pageSizeChange="handlePageSizeChange">
			<template #status_default="{ row }">
				<el-tag :type="statusType(row.status)" size="small">{{ statusLabel(row.status) }}</el-tag>
			</template>
			<template #action_default="{ row }">
				<el-button type="primary" link size="small" @click="handleDetail(row)">查看</el-button>
				<el-button v-if="row.status === 'pending' || row.status === 'picking'" type="success" link size="small" @click="handlePick(row)">拣货</el-button>
			</template>
		</sTable>

		<PickDialog v-if="dialog.pick" v-model:visible="dialog.pick" :record="currentRow" @success="refresh" />
	</div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useTable } from '@/hooks/useTable'
import businessApi from '@/api/business'
import PickDialog from './components/pick-dialog.vue'

const searchForm = ref({ pick_no: '', picking_no: '', picker_name: '', status: '' })
const { tableRef, data, total, loading, paginationProps, refresh, search, handlePageChange, handlePageSizeChange } = useTable({
	apiObj: { get: (params) => businessApi.deliveryPick.list.get(params) },
	searchForm: searchForm.value,
})

const columns = [
	{ prop: 'pick_no', title: '拣货单号', width: 160 },
	{ prop: 'picking_no', title: '配货单号', width: 160 },
	{ prop: 'warehouse_id', title: '仓库', width: 100 },
	{ prop: 'picker_name', title: '拣货人', width: 100 },
	{ prop: 'pick_date', title: '拣货日期', width: 120 },
	{ prop: 'total_skus', title: '商品种类', width: 90, align: 'center' },
	{ prop: 'total_qty', title: '商品总数', width: 90, align: 'center' },
	{ prop: 'short_qty', title: '缺货数', width: 80, align: 'center' },
	{ prop: 'status', title: '状态', width: 100, align: 'center', slots: { default: 'status_default' } },
	{ prop: 'action_col', title: '操作', width: 150, align: 'center', fixed: 'right', slots: { default: 'action_default' } },
]

const dialog = ref({ pick: false })
const currentRow = ref(null)
const statusLabel = (s) => ({ pending: '待拣货', picking: '拣货中', picked: '已拣货', cancelled: '已取消' }[s] || s)
const statusType = (s) => ({ pending: 'warning', picking: 'primary', picked: 'success', cancelled: 'info' }[s] || 'info')

const doSearch = () => search()
const doReset = () => { searchForm.value = { pick_no: '', picking_no: '', picker_name: '', status: '' }; refresh() }
const handleDetail = (row) => { currentRow.value = row; dialog.value.pick = true }
const handlePick = (row) => { currentRow.value = row; dialog.value.pick = true }
onMounted(() => refresh())
</script>

<style scoped>
.toolbar { margin-bottom: 12px; display: flex; justify-content: flex-start; flex-shrink: 0; gap: 8px; flex-wrap: wrap; }
.left-panel { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
.biz-list { height: 100%; display: flex; flex-direction: column; }
</style>
