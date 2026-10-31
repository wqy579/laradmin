<template>
	<sPageSplit side-title="库存管理">
		<div class="toolbar">
			<div class="left-panel">
				<el-input v-model="searchForm.keyword" placeholder="商品名称/编码" clearable style="width:180px" @keyup.enter="search" @clear="search" />
				<el-select v-model="searchForm.warehouse_id" clearable filterable placeholder="仓库" style="width:150px" @change="search">
					<el-option v-for="w in warehouseOptions" :key="w.id" :label="w.name" :value="w.id" />
				</el-select>
				<el-select v-model="searchForm.brand_id" clearable filterable placeholder="品牌" style="width:160px" @change="search">
					<el-option v-for="b in brandOptions" :key="b.id" :label="`${b.name}（${b.product_count || 0}）`" :value="b.id" />
				</el-select>
				<el-button type="primary" @click="search">查询</el-button>
				<el-button @click="resetSearch">重置</el-button>
			</div>
			<div class="right-panel">
				<el-button :loading="exporting" @click="onExport">导出</el-button>
				<el-button @click="onPrint">打印</el-button>
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
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import { useTable } from '@/hooks/useTable'
import businessApi from '@/api/business'
import { formatStock } from '@/utils/formatStock'
import { saveBlob, printTable } from '@/utils/fileDownload'
import sPageSplit from '@/components/sPageSplit/index.vue'

const router = useRouter()

const searchForm = ref({
	keyword: '',
	product_id: null,
	warehouse_id: null,
	brand_id: null,
})

// autoLoad:false —— useTable 挂载时已自动查一次，页面再 refresh() 就是重复请求（QA-13）
const { tableRef, data, total, loading, paginationProps, refresh, search, resetSearch, handlePageChange, handlePageSizeChange } = useTable({
	apiObj: { get: (params) => businessApi.stock.list.get(params) },
	searchForm: searchForm.value,
	autoLoad: false,
})

// 仓库下拉复用列表接口内嵌的 warehouses（后端只回启用的普通仓），省一次请求
const warehouseOptions = ref([])
const loadWarehouses = async () => {
	const res = await businessApi.stock.list.get({ page: 1, page_size: 1 }).catch(() => null)
	warehouseOptions.value = res?.data?.warehouses || []
}

// 品牌筛选：复用品牌 options 接口（启用品牌 + 商品数）
const brandOptions = ref([])
const loadBrandOptions = async () => {
	const res = await businessApi.brand.options.get().catch(() => null)
	brandOptions.value = res?.data?.list || []
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

const exporting = ref(false)
async function onExport() {
	exporting.value = true
	try {
		const blob = await businessApi.stock.export({ ...searchForm.value })
		saveBlob(blob, '库存查询.csv')
	} catch {
		ElMessage.error('导出失败')
	} finally {
		exporting.value = false
	}
}

function onPrint() {
	if (!data.value.length) return ElMessage.warning('当前没有可打印的数据')
	const cols = [
		{ prop: 'id', label: 'ID' },
		{ prop: 'product.code', label: '产品编码' },
		{ prop: 'product.name', label: '产品名称' },
		{ prop: 'warehouse.name', label: '仓库' },
		{ prop: 'quantity', label: '库存数量' },
		{ prop: 'frozen_qty', label: '冻结库存' },
		{ prop: 'cost_price', label: '成本价' },
		{ prop: 'total_amount', label: '库存金额' },
	]
	printTable(cols, data.value, '库存查询')
}

// 入库/出库按钮跳转目标页（带商品/仓库预填）；此前模板绑定了未定义的函数，点击必抛 ReferenceError
function goStockDoc(path, row) {
	router.push({ path, query: row ? { product_id: row.product_id, warehouse_id: row.warehouse_id } : {} })
}
const handleStockIn = () => goStockDoc('/business/stock-in')
const handleStockOut = () => goStockDoc('/business/stock-out')
const handleStockInRow = (row) => goStockDoc('/business/stock-in', row)
const handleStockOutRow = (row) => goStockDoc('/business/stock-out', row)

onMounted(() => {
	loadBrandOptions()
	loadWarehouses()
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
