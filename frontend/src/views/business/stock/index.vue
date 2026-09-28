<template>
	<sPageSplit side-title="库存管理">
		<div class="toolbar">
			<div class="right-panel">
				<el-button type="primary" @click="handleStockIn">入库</el-button>
				<el-button type="warning" @click="handleStockOut">出库</el-button>
			</div>
		</div>

		<sTable
			ref="tableRef"
			tableName="business_stock"
			:data="data"
			:columns="columns"
			:searchForm="searchForm"
			:loading="loading"
			:total="total"
			:currentPage="paginationProps.currentPage"
			:pageSize="paginationProps.pageSize"
			:pageSizes="paginationProps.pageSizes"
			rowKey="id"
			height="100%"
			stripe
			@refresh="refresh"
			@search="search"
			@pageChange="handlePageChange"
			@pageSizeChange="handlePageSizeChange"
		>
			<template #quantity_default="{ row }">
				<span :class="row.quantity < 10 ? 'text-danger' : ''">{{ formatStock(row.quantity, row.product?.unit_conversion, row.product?.unit_conversion_medium, row.product?.price_unit, row.product?.barcode_medium_unit, row.product?.price_unit_small) }}</span>
			</template>
			<template #frozen_qty_default="{ row }">
				<span>{{ formatStock(row.frozen_qty, row.product?.unit_conversion, row.product?.unit_conversion_medium, row.product?.price_unit, row.product?.barcode_medium_unit, row.product?.price_unit_small) }}</span>
			</template>
			<template #total_amount_default="{ row }">
				<span>{{ formatMoney(row.total_amount) }}</span>
			</template>
			<template #action_default="{ row }">
				<el-button type="primary" link size="small" @click="handleStockInRow(row)">入库</el-button>
				<el-button type="warning" link size="small" @click="handleStockOutRow(row)">出库</el-button>
			</template>
		</sTable>
	</sPageSplit>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import { useTable } from '@/hooks/useTable'
import businessApi from '@/api/business'
import { formatStock } from '@/utils/formatStock'
import sPageSplit from '@/components/sPageSplit/index.vue'

const searchForm = ref({
	keyword: '',
	product_id: null,
	warehouse_id: null,
})

const { tableRef, data, total, loading, paginationProps, refresh, search, handlePageChange, handlePageSizeChange } = useTable({
	apiObj: { get: (params) => businessApi.stock.list.get(params) },
	searchForm: searchForm.value,
})

const columns = [
	{ prop: 'id', title: 'ID', width: 70, align: 'center' },
	{ prop: 'product.name', title: '产品名称', width: 150, showOverflowTooltip: true },
	{ prop: 'product.code', title: '产品编码', width: 100 },
	{ prop: 'warehouse.name', title: '仓库', width: 100 },
	{ prop: 'quantity', title: '库存数量', width: 100, align: 'center', slots: { default: 'quantity_default' } },
	{ prop: 'frozen_qty', title: '冻结库存', width: 110, align: 'center', slots: { default: 'frozen_qty_default' } },
	{ prop: 'cost_price', title: '成本价', width: 100, align: 'right' },
	{ prop: 'total_amount', title: '库存金额', width: 120, align: 'right', slots: { default: 'total_amount_default' } },
	{ prop: 'action_col', title: '操作', width: 120, align: 'center', fixed: 'right', slots: { default: 'action_default' } },
]

const formatMoney = (val) => val ? '¥' + Number(val).toFixed(2) : '-'

onMounted(() => {
	refresh()
})
</script>

<style scoped>
.toolbar { margin-bottom: 12px; display: flex; justify-content: flex-end; }
.text-danger { color: var(--el-color-danger); }
</style>
