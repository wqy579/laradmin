<template>
	<div class="biz-list">
		<div class="toolbar">
			<div class="left-panel">
				<el-input v-model="searchForm.check_no" placeholder="验货单号" style="width: 160px" clearable @keyup.enter="doSearch" />
				<el-input v-model="searchForm.pick_no" placeholder="拣货单号" style="width: 160px" clearable @keyup.enter="doSearch" />
				<el-input v-model="searchForm.checker_name" placeholder="验货人" style="width: 120px" clearable @keyup.enter="doSearch" />
				<el-select v-model="searchForm.status" placeholder="状态" clearable style="width: 110px" @change="doSearch">
					<el-option label="待验货" value="pending" />
					<el-option label="验货中" value="checking" />
					<el-option label="已验货" value="checked" />
					<el-option label="验货异常" value="exception" />
				</el-select>
				<el-button type="primary" @click="doSearch">查询</el-button>
				<el-button @click="doReset">重置</el-button>
			</div>
		</div>

		<sTable ref="tableRef" tableName="business_delivery_check" :data="data" :columns="columns"
			:loading="loading" :total="total" :currentPage="paginationProps.currentPage" :pageSize="paginationProps.pageSize"
			:pageSizes="paginationProps.pageSizes" rowKey="id" height="100%" stripe
			@refresh="refresh" @pageChange="handlePageChange" @pageSizeChange="handlePageSizeChange">
			<template #diff_qty_default="{ row }">
				<span :style="{ color: row.diff_qty ? '#F56C6C' : '#606266' }">{{ row.diff_qty }}</span>
			</template>
			<template #status_default="{ row }">
				<el-tag :type="statusType(row.status)" size="small">{{ statusLabel(row.status) }}</el-tag>
			</template>
			<template #action_default="{ row }">
				<el-button type="primary" link size="small" @click="handleDetail(row)">查看</el-button>
				<el-button v-if="row.status === 'pending' || row.status === 'checking'" type="success" link size="small" @click="handleCheck(row)">验货</el-button>
			</template>
		</sTable>

		<CheckDialog v-if="dialog.check" v-model:visible="dialog.check" :record="currentRow" @success="refresh" />
	</div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useTable } from '@/hooks/useTable'
import businessApi from '@/api/business'
import CheckDialog from './components/check-dialog.vue'

const searchForm = ref({ check_no: '', pick_no: '', checker_name: '', status: '' })
const { tableRef, data, total, loading, paginationProps, refresh, search, handlePageChange, handlePageSizeChange } = useTable({
	apiObj: { get: (params) => businessApi.deliveryCheck.list.get(params) },
	searchForm: searchForm.value,
})

const columns = [
	{ prop: 'check_no', title: '验货单号', width: 160 },
	{ prop: 'pick_no', title: '拣货单号', width: 160 },
	{ prop: 'checker_name', title: '验货人', width: 100 },
	{ prop: 'check_date', title: '验货日期', width: 120 },
	{ prop: 'total_skus', title: '商品种类', width: 90, align: 'center' },
	{ prop: 'expected_qty', title: '应验数量', width: 90, align: 'center' },
	{ prop: 'actual_qty', title: '实验数量', width: 90, align: 'center' },
	{ prop: 'diff_qty', title: '差异数量', width: 90, align: 'center', slots: { default: 'diff_qty_default' } },
	{ prop: 'status', title: '状态', width: 100, align: 'center', slots: { default: 'status_default' } },
	{ prop: 'action_col', title: '操作', width: 150, align: 'center', fixed: 'right', slots: { default: 'action_default' } },
]

const dialog = ref({ check: false })
const currentRow = ref(null)
const statusLabel = (s) => ({ pending: '待验货', checking: '验货中', checked: '已验货', exception: '验货异常' }[s] || s)
const statusType = (s) => ({ pending: 'warning', checking: 'primary', checked: 'success', exception: 'danger' }[s] || 'info')

const doSearch = () => search()
const doReset = () => { searchForm.value = { check_no: '', pick_no: '', checker_name: '', status: '' }; refresh() }
const handleDetail = (row) => { currentRow.value = row; dialog.value.check = true }
const handleCheck = (row) => { currentRow.value = row; dialog.value.check = true }
onMounted(() => refresh())
</script>

<style scoped>
.toolbar { margin-bottom: 12px; display: flex; justify-content: flex-start; flex-shrink: 0; gap: 8px; flex-wrap: wrap; }
.left-panel { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
.biz-list { height: 100%; display: flex; flex-direction: column; }
</style>
