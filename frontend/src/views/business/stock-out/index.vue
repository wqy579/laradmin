<template>
	<div class="biz-list">
		<div class="toolbar"><div class="right-panel"><el-button type="primary" @click="handleAdd">新增出库单</el-button></div></div>
		<sTable ref="tableRef" tableName="business_stock_out" :data="data" :columns="columns" :searchForm="searchForm"
			:loading="loading" :total="total" :currentPage="paginationProps.currentPage" :pageSize="paginationProps.pageSize"
			:pageSizes="paginationProps.pageSizes" rowKey="id" height="100%" stripe
			@refresh="refresh" @search="search" @pageChange="handlePageChange" @pageSizeChange="handlePageSizeChange">
			<template #quantity_default="{ row }">
				<span class="text-warning">{{ formatStock(row.quantity, row.product?.unit_conversion, row.product?.unit_conversion_medium, row.product?.price_unit, row.product?.barcode_medium_unit, row.product?.price_unit_small) }}</span>
			</template>
			<template #action_default="{ row }">
				<el-button type="primary" link size="small" @click="handleDetail(row)">详情</el-button>
			</template>
		</sTable>
	</div>
	<StockOutDialog v-if="dialog.stockOut" v-model:visible="dialog.stockOut" :record="currentStockOut" :products="products" :warehouses="warehouses" @success="refresh" />
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import { useTable } from '@/hooks/useTable'
import businessApi from '@/api/business'
import { formatStock } from '@/utils/formatStock'
import StockOutDialog from '../components/stock-out-dialog.vue'

const searchForm = ref({ product_id: null, warehouse_id: null })
const { tableRef, data, total, loading, paginationProps, refresh, search, handlePageChange, handlePageSizeChange } = useTable({
	apiObj: { get: (params) => businessApi.stockOut.list.get(params) },
	searchForm: searchForm.value,
})

const columns = [
	{ prop: 'id', title: 'ID', width: 70, align: 'center' },
	{ prop: 'product.name', title: '产品名称', width: 150, showOverflowTooltip: true },
	{ prop: 'product.code', title: '产品编码', width: 100 },
	{ prop: 'warehouse.name', title: '仓库', width: 100 },
	{ prop: 'quantity', title: '出库数量', width: 100, align: 'center', slots: { default: 'quantity_default' } },
	{ prop: 'created_at', title: '出库时间', width: 160 },
	{ prop: 'action_col', title: '操作', width: 80, align: 'center', fixed: 'right', slots: { default: 'action_default' } },
]

const dialog = reactive({ stockOut: false })
const currentStockOut = ref(null)
const products = ref([])
const warehouses = ref([])

const handleAdd = () => { currentStockOut.value = null; dialog.stockOut = true }
const handleDetail = (row) => { currentStockOut.value = row; dialog.stockOut = true }

onMounted(() => {
	Promise.all([
		businessApi.product.list.get({ per_page: 9999 }).then(r => { if (r.code === 200) products.value = r.data?.list || [] }),
		businessApi.warehouse.list.get({ per_page: 9999 }).then(r => { if (r.code === 200) warehouses.value = r.data?.list || [] }),
	]).finally(() => refresh())
})
</script>

<style scoped>
.toolbar { margin-bottom: 12px; display: flex; justify-content: flex-end; }
.text-warning { color: var(--el-color-warning); font-weight: bold; }
</style>
