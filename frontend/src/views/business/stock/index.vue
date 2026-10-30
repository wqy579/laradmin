<template>
	<sPageSplit side-title="库存管理">
		<div class="toolbar">
			<div class="left-panel">
				<el-select v-model="searchForm.brand_id" clearable filterable placeholder="品牌" style="width:160px" @change="search">
					<el-option v-for="b in brandOptions" :key="b.id" :label="`${b.name}（${b.product_count || 0}）`" :value="b.id" />
				</el-select>
			</div>
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
	brand_id: null,
})

const { tableRef, data, total, loading, paginationProps, refresh, search, handlePageChange, handlePageSizeChange } = useTable({
	apiObj: { get: (params) => businessApi.stock.list.get(params) },
	searchForm: searchForm.value,
})

// 品牌筛选：复用品牌 options 接口（启用品牌 + 商品数）
const brandOptions = ref([])
const loadBrandOptions = async () => {
	const res = await businessApi.brand.options.get()
	brandOptions.value = res.data?.list || []
}

const columns = [
	{ prop: 'id', title: 'ID', width: 70, align: 'center' },
	{ prop: 'product.name', title: '产品名称', width: 150, showOverflowTooltip: true },
	{ prop: 'product.code', title: '产品编码', width: 100 },
	{ prop: 'warehouse.name', title: '仓库', width: 100 },
	{ prop: 'quantity', title: '库存数量', width: 100, align: 'right', slots: { default: 'quantity_default' } },
	{ prop: 'frozen_qty', title: '冻结库存', width: 110, align: 'right', slots: { default: 'frozen_qty_default' } },
	{ prop: 'cost_price', title: '成本价', width: 100, align: 'right' },
	{ prop: 'total_amount', title: '库存金额', width: 120, align: 'right', slots: { default: 'total_amount_default' } },
	{ prop: 'action_col', title: '操作', width: 120, align: 'center', fixed: 'right', slots: { default: 'action_default' } },
]

const formatMoney = (val) => val ? '¥' + Number(val).toFixed(2) : '-'

onMounted(() => {
	loadBrandOptions()
	refresh()
})
</script>

<style scoped>
.toolbar { margin-bottom: 12px; display: flex; justify-content: space-between; align-items: center; }
.text-danger { color: var(--el-color-danger); }
:deep(.vxe-body--column .vxe-cell) { font-variant-numeric: tabular-nums; }
:deep(.vxe-header--row) { height: 44px !important; }
:deep(.vxe-header--column .vxe-cell) { font-size: var(--font-base); font-weight: 600; }
:deep(.vxe-body--row) { height: 48px !important; }
:deep(.vxe-body--row:nth-child(even)) { background-color: #FAFAFA; }
</style>
