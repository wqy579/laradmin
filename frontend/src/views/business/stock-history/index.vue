<template>
	<div class="biz-list">
		<div class="toolbar">
			<div class="right-panel">
				<el-select v-model="searchForm.warehouse_id" placeholder="仓库" clearable size="small" style="width:130px" @change="search">
					<el-option v-for="w in warehouses" :key="w.id" :label="w.name" :value="w.id" />
				</el-select>
				<el-select v-model="searchForm.change_type" placeholder="变化类型" clearable size="small" style="width:130px" @change="search">
					<el-option v-for="t in changeTypes" :key="t.value" :label="t.label" :value="t.value" />
				</el-select>
				<el-input v-model="searchForm.keyword" placeholder="搜索商品名/编码" clearable size="small" style="width:180px"
					@keyup.enter="search" @clear="search" />
				<el-button size="small" @click="refresh"><i class="el-icon-refresh"></i> 刷新</el-button>
			</div>
		</div>
		<sTable ref="tableRef" tableName="stock_history" :data="data" :columns="columns" :loading="loading" :total="total"
			:currentPage="currentPage" :pageSize="pageSize" :pageSizes="pageSizes" height="100%" stripe
			@pageChange="currentPage = $event; fetchData()" @pageSizeChange="pageSize = $event; currentPage = 1; fetchData()">
			<template #change_type="{ row }">
				<el-tag size="small" :type="row.change_qty > 0 ? 'success' : 'danger'">{{ changeTypeLabel(row.change_type) }}</el-tag>
			</template>
			<template #change_qty="{ row }">
				<span :class="row.change_qty > 0 ? 'text-success' : 'text-danger'">{{ row.change_qty > 0 ? '+' : '' }}{{ formatStock(Math.abs(row.change_qty), row.unit_conversion, row.unit_conversion_medium, row.price_unit, row.barcode_medium_unit, row.price_unit_small) }}</span>
			</template>
			<template #before_qty="{ row }">
				<span>{{ formatStock(row.before_qty, row.unit_conversion, row.unit_conversion_medium, row.price_unit, row.barcode_medium_unit, row.price_unit_small) }}</span>
			</template>
			<template #after_qty="{ row }">
				<span>{{ formatStock(row.after_qty, row.unit_conversion, row.unit_conversion_medium, row.price_unit, row.barcode_medium_unit, row.price_unit_small) }}</span>
			</template>
		</sTable>
	</div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import businessApi from '@/api/business'
import { formatStock } from '@/utils/formatStock'
import sTable from '@/components/sTable/index.vue'

const searchForm = reactive({ warehouse_id: '', change_type: '', keyword: '' })
const data = ref([])
const total = ref(0)
const loading = ref(false)
const tableRef = ref(null)
const currentPage = ref(1)
const pageSize = ref(30)
const pageSizes = [30, 50, 100, 200]
const warehouses = ref([])

// 变化类型中文映射（与 StockService::recordHistory 写入的 change_type 一致）
const changeTypes = [
	{ value: 'sale_freeze', label: '销售冻结' },
	{ value: 'sale_unfreeze', label: '销售解冻' },
	{ value: 'stock_in', label: '入库' },
	{ value: 'stock_out', label: '出库' },
]
const changeTypeLabel = (t) => changeTypes.find(c => c.value === t)?.label || t

const columns = [
	{ prop: 'id', title: 'ID', width: 70, align: 'center' },
	{ prop: 'product_name', title: '商品', width: 180, showOverflowTooltip: true },
	{ prop: 'warehouse_name', title: '仓库', width: 120 },
	{ prop: 'change_type', title: '变化类型', width: 100, align: 'center', slots: { default: 'change_type' } },
	{ prop: 'change_qty', title: '变动量', width: 90, align: 'right', slots: { default: 'change_qty' } },
	{ prop: 'before_qty', title: '变化前', width: 110, align: 'right', slots: { default: 'before_qty' } },
	{ prop: 'after_qty', title: '变化后', width: 110, align: 'right', slots: { default: 'after_qty' } },
	{ prop: 'related_type', title: '关联类型', width: 110 },
	{ prop: 'related_id', title: '关联ID', width: 80, align: 'center' },
	{ prop: 'remark', title: '备注', width: 140, showOverflowTooltip: true },
	{ prop: 'created_at', title: '时间', width: 160 },
]

async function fetchData() {
	loading.value = true
	try {
		const params = { page: currentPage.value, page_size: pageSize.value }
		Object.keys(searchForm).forEach(k => { if (searchForm[k] !== '' && searchForm[k] !== null) params[k] = searchForm[k] })
		const res = await businessApi.stockHistory.list.get(params)
		// 后端返回 {code, data: paginator}，paginator 含 data(items)/total
		const d = res.data || {}
		data.value = (d.data || d.list || []).map(item => ({ ...item }))
		total.value = d.total || 0
	} catch (e) {
		ElMessage.error('加载失败')
	} finally {
		loading.value = false
	}
}
function search() { currentPage.value = 1; fetchData() }
function refresh() { fetchData() }

onMounted(() => {
	businessApi.warehouse.list.get({type:"normal",  page_size: 9999 }).then(r => { if (r.code === 200) warehouses.value = r.data?.list || [] })
	fetchData()
})
</script>

<style scoped>
.biz-list { height: 100%; display: flex; flex-direction: column; }
.toolbar { display: flex; justify-content: flex-end; margin-bottom: 8px; }
.right-panel { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.text-success { color: #67c23a; font-weight: 600; }
.text-danger { color: #f56c6c; font-weight: 600; }
</style>
