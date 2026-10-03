<template>
	<el-dialog model-value="modelValue" title="库存台账" width="960px" top="6vh" @close="$emit('update:modelValue', false)" destroy-on-close>
		<div class="filters">
			<el-select
				v-model="productId"
				filterable
				remote
				clearable
				placeholder="搜索商品名称/编码"
				:remote-method="searchProducts"
				style="width: 240px"
			>
				<el-option v-for="p in productOptions" :key="p.id" :label="`${p.name}${p.spec ? '（'+p.spec+'）' : ''}`" :value="p.id" />
			</el-select>
			<el-select v-model="warehouseId" placeholder="全部仓库" clearable style="width:150px">
				<el-option v-for="w in warehouses" :key="w.id" :label="w.name" :value="w.id" />
			</el-select>
			<el-date-picker v-model="dateRange" type="daterange" range-separator="至" start-placeholder="开始" end-placeholder="结束" value-format="YYYY-MM-DD" style="width:260px" />
			<el-button type="primary" @click="query">查询</el-button>
		</div>

		<el-table :data="rows" v-loading="loading" size="small" border height="420" show-summary :summary-method="summary">
			<el-table-column prop="date" label="日期" width="110">
				<template #default="{ row }">{{ (row.date || '').slice(0, 10) }}</template>
			</el-table-column>
			<el-table-column prop="type_label" label="单据类型" width="110" />
			<el-table-column prop="summary" label="摘要" min-width="160" show-overflow-tooltip />
			<el-table-column prop="in_qty" label="入库数量" width="90" align="right">
				<template #default="{ row }"><span class="up">{{ row.in_qty ? '+'+row.in_qty : '' }}</span></template>
			</el-table-column>
			<el-table-column prop="out_qty" label="出库数量" width="90" align="right">
				<template #default="{ row }"><span class="down">{{ row.out_qty ? '-'+row.out_qty : '' }}</span></template>
			</el-table-column>
			<el-table-column prop="balance_qty" label="结存数量" width="90" align="right" />
			<el-table-column label="成本单价" width="90" align="right">
				<template #default="{ row }">¥{{ fmtMoney(row.cost_price) }}</template>
			</el-table-column>
			<el-table-column label="结存金额" width="110" align="right">
				<template #default="{ row }">¥{{ fmtMoney(row.balance_amount) }}</template>
			</el-table-column>
		</el-table>
	</el-dialog>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import businessApi from '@/api/business'

const props = defineProps({ modelValue: Boolean })
const emit = defineEmits(['update:modelValue'])

const productId = ref(null)
const warehouseId = ref(null)
const dateRange = ref([])
const productOptions = ref([])
const warehouses = ref([])
const rows = ref([])
const opening = ref({ qty: 0, amount: 0 })
const loading = ref(false)

async function searchProducts(k) {
	if (!k) return
	try {
		const res = await businessApi.product.list.get({ keyword: k, page_size: 20 })
		productOptions.value = res.data?.list || res.data?.data || []
	} catch (e) { /* ignore */ }
}

async function loadWarehouses() {
	try {
		const res = await businessApi.warehouse.list.get({})
		warehouses.value = res.data?.list || res.data || []
	} catch (e) { /* ignore */ }
}

async function query() {
	if (!productId.value) { ElMessage.warning('请先选择商品'); return }
	loading.value = true
	try {
		const params = { product_id: productId.value }
		if (warehouseId.value) params.warehouse_id = warehouseId.value
		if (dateRange.value?.length === 2) { params.start_date = dateRange.value[0]; params.end_date = dateRange.value[1] }
		const res = await businessApi.stocktaking.ledger.get(params)
		const d = res.data || {}
		opening.value = d.opening || { qty: 0, amount: 0 }
		rows.value = d.list || []
	} catch (e) {
		ElMessage.error(e?.response?.data?.message || '查询失败')
	} finally {
		loading.value = false
	}
}

function summary({ columns }) {
	const sums = []
	columns.forEach((col, i) => {
		if (i === 0) sums[i] = `期初结存 ${opening.value.qty}　期末结存 ${rows.value.length ? rows.value[rows.value.length - 1].balance_qty : opening.value.qty}`
		else sums[i] = ''
	})
	return sums
}

function fmtMoney(v) { return Number(v ?? 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }

onMounted(loadWarehouses)
</script>

<style scoped>
.filters { display: flex; align-items: center; gap: 10px; padding-bottom: 12px; flex-wrap: wrap; }
.up { color: #52c41a; }
.down { color: #f5222d; }
</style>
