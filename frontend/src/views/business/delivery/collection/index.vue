<template>
	<div class="biz-list">
		<div class="toolbar">
			<div class="left-panel">
				<el-input v-model="searchForm.collection_no" placeholder="收款单号" style="width: 160px" clearable @keyup.enter="doSearch" />
				<el-input v-model="searchForm.task_no" placeholder="配送单号" style="width: 160px" clearable @keyup.enter="doSearch" />
				<el-input v-model="searchForm.delivery_person_name" placeholder="配送员" style="width: 120px" clearable @keyup.enter="doSearch" />
				<el-select v-model="searchForm.status" placeholder="状态" clearable style="width: 110px" @change="doSearch">
					<el-option label="待收款" value="pending" />
					<el-option label="部分收款" value="partial" />
					<el-option label="已收款" value="paid" />
				</el-select>
				<el-button type="primary" @click="doSearch">查询</el-button>
				<el-button @click="doReset">重置</el-button>
			</div>
			<div class="right-panel">
				<el-button type="primary" @click="handleAdd">新增收款</el-button>
			</div>
		</div>

		<sTable ref="tableRef" tableName="business_delivery_collection" :data="data" :columns="columns"
			:loading="loading" :total="total" :currentPage="paginationProps.currentPage" :pageSize="paginationProps.pageSize"
			:pageSizes="paginationProps.pageSizes" rowKey="id" height="100%" stripe
			@refresh="refresh" @pageChange="handlePageChange" @pageSizeChange="handlePageSizeChange">
			<template #receivable_amount_default="{ row }">
				<span style="color: #F56C6C">¥{{ fmt(row.receivable_amount) }}</span>
			</template>
			<template #received_amount_default="{ row }">
				<span style="color: #67C23A">¥{{ fmt(row.received_amount) }}</span>
			</template>
			<template #status_default="{ row }">
				<el-tag :type="statusType(row.status)" size="small">{{ statusLabel(row.status) }}</el-tag>
			</template>
		</sTable>

		<CollectionDialog v-if="dialog.add" v-model:visible="dialog.add" @success="refresh" />
	</div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { useTable } from '@/hooks/useTable'
import businessApi from '@/api/business'
import CollectionDialog from './components/collection-dialog.vue'

const searchForm = ref({ collection_no: '', task_no: '', delivery_person_name: '', status: '' })
const { tableRef, data, total, loading, paginationProps, refresh, search, handlePageChange, handlePageSizeChange } = useTable({
	apiObj: { get: (params) => businessApi.deliveryCollection.list.get(params) },
	searchForm: searchForm.value,
})

const columns = [
	{ prop: 'collection_no', title: '收款单号', width: 160 },
	{ prop: 'task_no', title: '配送单号', width: 160 },
	{ prop: 'delivery_person_name', title: '配送员', width: 100 },
	{ prop: 'customer_name', title: '客户名称', width: 160 },
	{ prop: 'receivable_amount', title: '应收金额', width: 110, align: 'right', slots: { default: 'receivable_amount_default' } },
	{ prop: 'received_amount', title: '已收金额', width: 110, align: 'right', slots: { default: 'received_amount_default' } },
	{ prop: 'payment_method', title: '收款方式', width: 100 },
	{ prop: 'collect_date', title: '收款日期', width: 120 },
	{ prop: 'status', title: '状态', width: 100, align: 'center', slots: { default: 'status_default' } },
]

const dialog = reactive({ add: false })
const statusLabel = (s) => ({ pending: '待收款', partial: '部分收款', paid: '已收款' }[s] || s)
const statusType = (s) => ({ pending: 'warning', partial: '', paid: 'success' }[s] || 'info')
const fmt = (n) => Number(n || 0).toFixed(2)

const doSearch = () => search()
const doReset = () => { searchForm.value = { collection_no: '', task_no: '', delivery_person_name: '', status: '' }; refresh() }
const handleAdd = () => { dialog.add = true }
onMounted(() => refresh())
</script>

<style scoped>
.toolbar { margin-bottom: 12px; display: flex; justify-content: space-between; flex-shrink: 0; gap: 8px; flex-wrap: wrap; }
.left-panel { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
.biz-list { height: 100%; display: flex; flex-direction: column; }
</style>
