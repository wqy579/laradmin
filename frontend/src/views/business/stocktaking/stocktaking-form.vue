<template>
	<el-dialog
		model-value="visible"
		:title="editRow ? '继续盘点' : '新增盘点单'"
		width="1100px"
		top="5vh"
		@close="$emit('close')"
		destroy-on-close
	>
		<!-- 基本信息 -->
		<el-form :inline="true" class="base-form" @submit.prevent>
			<el-form-item label="仓库" required>
				<el-select v-model="form.warehouse_id" placeholder="请选择仓库" style="width:200px" :disabled="!!editRow" @change="onWarehouseChange">
					<el-option v-for="w in warehouses" :key="w.id" :label="w.name" :value="w.id" />
				</el-select>
			</el-form-item>
			<el-form-item label="盘点日期">
				<el-date-picker v-model="form.check_date" type="date" value-format="YYYY-MM-DD" style="width:160px" />
			</el-form-item>
			<el-form-item label="盘点类型">
				<el-select v-model="form.check_type" style="width:130px">
					<el-option label="全面盘点" value="full" />
					<el-option label="抽盘" value="sample" />
					<el-option label="异动盘点" value="adjust" />
				</el-select>
			</el-form-item>
			<el-form-item label="备注">
				<el-input v-model="form.remark" placeholder="可选填" style="width:220px" />
			</el-form-item>
		</el-form>

		<!-- 明细工具栏 -->
		<div class="toolbar">
			<el-input v-model="keyword" placeholder="搜索商品名称/编码/条码" clearable style="width:240px" :prefix-icon="Search" />
			<el-button style="margin-left:10px" @click="flattenAll">全部盘平</el-button>
			<div style="margin-left:auto;display:flex;align-items:center;gap:6px">
				<el-switch v-model="onlyDiff" />
				<span style="font-size:13px;color:#666">只看差异</span>
			</div>
			<el-button style="margin-left:10px;background:#52c41a;color:#fff;border:none" @click="exportCsv">导出盘点表</el-button>
		</div>

		<!-- 明细表格 -->
		<div class="items-wrap" v-loading="loadingItems">
			<el-table :data="filteredItems" height="380" size="small" :row-class-name="rowClass" border>
				<el-table-column type="index" label="序号" width="55" align="center" />
				<el-table-column prop="product_code" label="商品编码" width="110" />
				<el-table-column prop="product_name" label="商品名称" min-width="180" show-overflow-tooltip />
				<el-table-column prop="spec" label="规格" width="90" />
				<el-table-column prop="unit" label="单位" width="60" align="center" />
				<el-table-column prop="book_qty" label="账面数量" width="90" align="right" />
				<el-table-column label="实盘数量" width="120" align="center">
					<template #default="{ row }">
						<el-input-number v-model="row.actual_qty" :min="0" :controls="false" size="small" style="width:90px" @change="recalc(row)" />
					</template>
				</el-table-column>
				<el-table-column label="差异数量" width="90" align="right">
					<template #default="{ row }">
						<span :class="diffClass(row.diff_qty)">{{ formatDiff(row.diff_qty) }}</span>
					</template>
				</el-table-column>
				<el-table-column label="成本单价" width="90" align="right">
					<template #default="{ row }">¥{{ fmtMoney(row.cost_price) }}</template>
				</el-table-column>
				<el-table-column label="差异金额" width="110" align="right">
					<template #default="{ row }">
						<span :class="diffClass(row.diff_qty)">{{ fmtSigned(row.diff_amount) }}</span>
					</template>
				</el-table-column>
				<el-table-column prop="checker" label="盘点人" width="90">
					<template #default="{ row }">
						<el-input v-model="row.checker" size="small" />
					</template>
				</el-table-column>
			</el-table>
		</div>

		<!-- 底部 -->
		<div class="footer">
			<div class="summary">
				共 <b>{{ items.length }}</b> 种商品
				<span class="num-up" style="margin-left:16px">盘盈 {{ profitCount }} 种，¥{{ fmtMoney(profitAmount) }}</span>
				<span class="num-down" style="margin-left:16px">盘亏 {{ lossCount }} 种，¥{{ fmtMoney(lossAmount) }}</span>
			</div>
			<div>
				<el-button @click="$emit('close')">取消</el-button>
				<el-button @click="save('draft')">保存草稿</el-button>
				<el-button type="warning" @click="save('submit')">提交审核</el-button>
			</div>
		</div>
	</el-dialog>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Search } from '@element-plus/icons-vue'
import businessApi from '@/api/business'

const props = defineProps({
	visible: Boolean,
	editRow: Object,
	warehouses: { type: Array, default: () => [] },
})
const emit = defineEmits(['close', 'saved'])

const form = reactive({ warehouse_id: '', check_date: '', check_type: 'full', remark: '' })
const items = ref([])
const keyword = ref('')
const onlyDiff = ref(false)
const loadingItems = ref(false)

onMounted(async () => {
	form.check_date = new Date().toISOString().slice(0, 10)
	if (props.editRow) await loadEdit(props.editRow)
})

async function loadEdit(row) {
	try {
		const res = await businessApi.stocktaking.detail.get(row.id)
		const d = res.data || row
		form.warehouse_id = d.warehouse_id
		form.check_date = (d.check_date || '').slice(0, 10)
		form.check_type = d.check_type || 'full'
		form.remark = d.remark || ''
		items.value = (d.items || []).map((it) => ({ ...it }))
	} catch (e) {
		ElMessage.error('加载盘点单失败')
	}
}

async function onWarehouseChange(warehouseId) {
	if (!warehouseId) return
	loadingItems.value = true
	try {
		const res = await businessApi.stocktaking.warehouseProducts.get({ warehouse_id: warehouseId })
		items.value = (res.data?.list || []).map((p) => ({
			product_id: p.product_id,
			product_code: p.product_code,
			product_name: p.product_name,
			spec: p.spec,
			unit: p.unit,
			book_qty: p.book_qty,
			actual_qty: p.actual_qty,
			diff_qty: p.diff_qty,
			cost_price: p.cost_price,
			diff_amount: p.diff_amount,
			checker: '',
		}))
	} catch (e) {
		ElMessage.error(e?.response?.data?.message || '加载商品库存失败')
	} finally {
		loadingItems.value = false
	}
}

function recalc(row) {
	row.diff_qty = (row.actual_qty || 0) - (row.book_qty || 0)
	row.diff_amount = Math.round(row.diff_qty * (row.cost_price || 0) * 100) / 100
}

function flattenAll() {
	items.value.forEach((r) => { r.actual_qty = r.book_qty; recalc(r) })
}

const filteredItems = computed(() => {
	let list = items.value
	if (keyword.value) {
		const k = keyword.value.toLowerCase()
		list = list.filter((r) =>
			(r.product_name || '').toLowerCase().includes(k) ||
			(r.product_code || '').toLowerCase().includes(k) ||
			(r.unit || '').toLowerCase().includes(k))
	}
	if (onlyDiff.value) list = list.filter((r) => r.diff_qty !== 0)
	return list
})

const profitCount = computed(() => items.value.filter((r) => r.diff_qty > 0).length)
const lossCount = computed(() => items.value.filter((r) => r.diff_qty < 0).length)
const profitAmount = computed(() => items.value.filter((r) => r.diff_qty > 0).reduce((s, r) => s + Number(r.diff_amount), 0))
const lossAmount = computed(() => items.value.filter((r) => r.diff_qty < 0).reduce((s, r) => s + Math.abs(Number(r.diff_amount)), 0))

function rowClass({ row }) {
	if (row.diff_qty > 0) return 'row-profit'
	if (row.diff_qty < 0) return 'row-loss'
	return ''
}
function diffClass(v) { return v > 0 ? 'num-up' : v < 0 ? 'num-down' : 'num-zero' }
function formatDiff(v) { return v > 0 ? `+${v}` : `${v}` }
function fmtMoney(v) { return Number(v ?? 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }
function fmtSigned(v) { const n = Number(v || 0); return (n >= 0 ? '+' : '-') + '¥' + Math.abs(n).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }

async function save(action) {
	if (!form.warehouse_id) { ElMessage.warning('请先选择仓库'); return }
	if (!items.value.length) { ElMessage.warning('该仓库没有可盘点的商品'); return }
	if (action === 'submit') {
		try { await ElMessageBox.confirm('确认提交盘点单？提交后将不能修改盘点数量', '提示', { type: 'warning' }) }
		catch { return }
	}
	const payload = {
		warehouse_id: form.warehouse_id,
		check_date: form.check_date,
		check_type: form.check_type,
		remark: form.remark,
		action,
		items: items.value.map((r) => ({ product_id: r.product_id, actual_qty: r.actual_qty, checker: r.checker })),
	}
	try {
		if (props.editRow) {
			await businessApi.stocktaking.update.put(props.editRow.id, payload)
			if (action === 'submit') await businessApi.stocktaking.submit.post(props.editRow.id)
		} else {
			await businessApi.stocktaking.create.post(payload)
		}
		ElMessage.success(action === 'submit' ? '已提交审核' : '草稿已保存')
		emit('saved')
	} catch (e) {
		ElMessage.error(e?.response?.data?.message || '保存失败')
	}
}

function exportCsv() {
	const header = ['商品编码', '商品名称', '规格', '单位', '账面数量', '实盘数量', '差异数量', '成本单价', '差异金额']
	const rows = filteredItems.value.map((r) => [r.product_code, r.product_name, r.spec, r.unit, r.book_qty, r.actual_qty, r.diff_qty, r.cost_price, r.diff_amount])
	const csv = '\uFEFF' + [header, ...rows].map((row) => row.join(',')).join('\n')
	const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' })
	const a = document.createElement('a')
	a.href = URL.createObjectURL(blob)
	a.download = `盘点表_${form.check_date}.csv`
	a.click()
	URL.revokeObjectURL(a.href)
}
</script>

<style scoped>
.base-form { padding: 8px 16px 0; }
.toolbar { display: flex; align-items: center; padding: 10px 16px; }
.items-wrap { padding: 0 16px; }
.footer { display: flex; align-items: center; justify-content: space-between; padding: 12px 16px 0; border-top: 1px solid #f0f0f0; margin-top: 10px; }
.summary { font-size: 14px; color: #666; }
.num-up { color: #52c41a; font-weight: 500; }
.num-down { color: #f5222d; font-weight: 500; }
.num-zero { color: #999; }
:deep(.row-profit) { background: #f6ffed !important; }
:deep(.row-loss) { background: #fff2f0 !important; }
</style>
