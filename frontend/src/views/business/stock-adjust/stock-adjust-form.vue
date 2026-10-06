<template>
	<el-dialog
		v-model="visible"
		:title="editRow ? '编辑库存调整单' : '新增库存调整单'"
		width="1200px"
		top="5vh"
		@close="$emit('close')"
		destroy-on-close
	>
		<!-- 基本信息 -->
		<el-form :inline="true" class="base-form" @submit.prevent>
			<el-form-item label="调整单号">
				<el-input v-model="form.adjust_no" disabled style="width:180px" />
			</el-form-item>
			<el-form-item label="仓库" required>
				<el-select v-model="form.warehouse_id" placeholder="请选择仓库" style="width:200px" :disabled="!!editRow" @change="onWarehouseChange">
					<el-option v-for="w in warehouses" :key="w.id" :label="w.name" :value="w.id" />
				</el-select>
			</el-form-item>
			<el-form-item label="调整日期">
				<el-date-picker v-model="form.adjust_date" type="date" value-format="YYYY-MM-DD" style="width:160px" />
			</el-form-item>
			<el-form-item label="调整类型" required>
				<el-select v-model="form.adjust_type" style="width:140px">
					<el-option label="库存损耗" value="stock_loss" />
					<el-option label="库存溢余" value="stock_gain" />
					<el-option label="其他" value="other" />
				</el-select>
			</el-form-item>
		</el-form>
		<el-form class="reason-form" @submit.prevent>
			<el-form-item label="调整原因" required>
				<el-input v-model="form.reason" type="textarea" :rows="2" placeholder="请输入调整原因，例如：商品破损、过期、系统误差修正等" style="width:100%" />
			</el-form-item>
		</el-form>

		<!-- 明细工具栏 -->
		<div class="toolbar">
			<span class="toolbar-title">商品明细</span>
			<el-button type="primary" size="small" @click="openProductSelector">
				<i class="el-icon-plus"></i>添加商品
			</el-button>
			<div style="margin-left:auto;display:flex;align-items:center;gap:6px">
				<el-input v-model="keyword" placeholder="搜索商品名称/编码" clearable style="width:240px" :prefix-icon="Search" />
			</div>
		</div>

		<!-- 明细表格 -->
		<div class="items-wrap" v-loading="loadingItems">
			<el-table :data="items" height="400" size="small" border>
				<el-table-column type="index" label="序号" width="55" align="center" />
				<el-table-column prop="product_code" label="商品编码" width="110" />
				<el-table-column prop="product_name" label="商品名称" min-width="180" show-overflow-tooltip />
				<el-table-column prop="spec" label="规格" width="100" />
				<el-table-column prop="unit" label="单位" width="60" align="center" />
				<el-table-column prop="before_qty" label="调整前库存" width="100" align="right">
					<template #default="{ row }">{{ row.before_qty ?? 0 }}</template>
				</el-table-column>
				<el-table-column label="调整数量" width="120" align="right">
					<template #default="{ row }">
						<el-input-number v-model="row.adjust_qty" :precision="2" :controls="false" size="small" style="width:100px" @change="recalc(row)" />
					</template>
				</el-table-column>
				<el-table-column prop="after_qty" label="调整后库存" width="100" align="right">
					<template #default="{ row }">{{ row.after_qty ?? 0 }}</template>
				</el-table-column>
				<el-table-column label="单位成本" width="100" align="right">
					<template #default="{ row }">
						<el-input-number v-model="row.unit_cost" :precision="4" :controls="false" size="small" style="width:90px" @change="recalc(row)" />
					</template>
				</el-table-column>
				<el-table-column label="总成本" width="100" align="right">
					<template #default="{ row }">¥{{ fmtMoney(Math.abs(row.total_cost || 0)) }}</template>
				</el-table-column>
				<el-table-column prop="remark" label="备注" width="150">
					<template #default="{ row }">
						<el-input v-model="row.remark" size="small" />
					</template>
				</el-table-column>
				<el-table-column label="操作" width="80" align="center">
					<template #default="{ $index }">
						<el-button type="danger" link size="small" @click="removeItem($index)">删除</el-button>
					</template>
				</el-table-column>
			</el-table>
		</div>

		<!-- 底部汇总 -->
		<div class="footer">
			<div class="summary">
				共 <b>{{ items.length }}</b> 种商品
				<span class="num-up" style="margin-left:16px">增加 {{ increaseCount }} 种，¥{{ fmtMoney(increaseAmount) }}</span>
				<span class="num-down" style="margin-left:16px">减少 {{ decreaseCount }} 种，¥{{ fmtMoney(decreaseAmount) }}</span>
			</div>
			<div class="actions">
				<el-button @click="$emit('close')">取消</el-button>
				<el-button @click="save('draft')">保存草稿</el-button>
				<el-button type="primary" @click="save('submit')">提交审核</el-button>
			</div>
		</div>

		<!-- 商品选择弹窗 -->
		<el-dialog v-model="productSelectorVisible" title="选择商品" width="900px" top="5vh">
			<div class="product-search">
				<el-input v-model="productKeyword" placeholder="搜索商品名称/编码" clearable style="width:300px" :prefix-icon="Search" />
				<el-button type="primary" @click="searchProducts" style="margin-left:10px">搜索</el-button>
			</div>
			<el-table :data="productList" height="400" @selection-change="handleSelectionChange" border>
				<el-table-column type="selection" width="50" />
				<el-table-column prop="code" label="编码" width="100" />
				<el-table-column prop="name" label="名称" min-width="180" show-overflow-tooltip />
				<el-table-column prop="spec" label="规格" width="100" />
				<el-table-column prop="price_unit_small" label="单位" width="60" align="center" />
				<el-table-column prop="stock_qty" label="当前库存" width="100" align="right" />
				<el-table-column prop="cost_price" label="成本价" width="100" align="right">
					<template #default="{ row }">¥{{ fmtMoney(row.cost_price) }}</template>
				</el-table-column>
			</el-table>
			<template #footer>
				<el-button @click="productSelectorVisible = false">取消</el-button>
				<el-button type="primary" @click="confirmProducts">确定</el-button>
			</template>
		</el-dialog>
	</el-dialog>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import { Search } from '@element-plus/icons-vue'
import businessApi from '@/api/business'

const props = defineProps({
	visible: Boolean,
	editRow: Object,
	warehouses: { type: Array, default: () => [] },
})
const emit = defineEmits(['close', 'saved'])

const form = reactive({
	adjust_no: '',
	warehouse_id: '',
	adjust_date: '',
	adjust_type: 'other',
	reason: '',
})
const items = ref([])
const keyword = ref('')
const loadingItems = ref(false)

// 商品选择器
const productSelectorVisible = ref(false)
const productKeyword = ref('')
const productList = ref([])
const selectedProducts = ref([])

onMounted(async () => {
	form.adjust_date = new Date().toISOString().slice(0, 10)
	if (props.editRow) await loadEdit(props.editRow)
})

watch(() => props.visible, (val) => {
	if (val && !props.editRow) {
		form.adjust_no = 'TZ' + new Date().toISOString().slice(0, 10).replace(/-/g, '') + '000001'
	}
})

async function loadEdit(row) {
	try {
		const res = await businessApi.stockAdjust.detail.get(row.id)
		const d = res.data || row
		Object.assign(form, {
			adjust_no: d.adjust_no,
			warehouse_id: d.warehouse_id,
			adjust_date: (d.adjust_date || '').slice(0, 10),
			adjust_type: d.adjust_type || 'other',
			reason: d.reason || '',
		})
		items.value = (d.items || []).map((it) => ({ ...it, adjust_qty: Number(it.adjust_qty), unit_cost: Number(it.unit_cost), total_cost: Number(it.total_cost) }))
	} catch (e) {
		ElMessage.error('加载调整单失败')
	}
}

async function onWarehouseChange(warehouseId) {
	if (!warehouseId) return
	// 重新加载商品列表（简化版：不自动填充，由用户手动选择）
}

function openProductSelector() {
	productSelectorVisible.value = true
	productKeyword.value = ''
	productList.value = []
	selectedProducts.value = []
	searchProducts()
}

async function searchProducts() {
	try {
		const res = await businessApi.product.list.get({ keyword: productKeyword.value, page_size: 100 })
		productList.value = res.data?.list || []
	} catch (e) {
		ElMessage.error('加载商品失败')
	}
}

function handleSelectionChange(selection) {
	selectedProducts.value = selection
}

function confirmProducts() {
	if (selectedProducts.value.length === 0) {
		ElMessage.warning('请选择商品')
		return
	}
	const warehouseId = form.warehouse_id
	if (!warehouseId) {
		ElMessage.warning('请先选择仓库')
		return
	}

	selectedProducts.value.forEach((p) => {
		const existing = items.value.find((item) => item.product_id === p.id)
		if (existing) {
			return
		}
		items.value.push({
			product_id: p.id,
			product_code: p.code,
			product_name: p.name,
			spec: p.spec,
			unit: p.price_unit_small,
			before_qty: p.stock_qty || 0,
			adjust_qty: 0,
			after_qty: p.stock_qty || 0,
			unit_cost: p.cost_price || 0,
			total_cost: 0,
			remark: '',
		})
	})
	productSelectorVisible.value = false
	selectedProducts.value = []
}

function removeItem(index) {
	items.value.splice(index, 1)
}

function recalc(row) {
	row.after_qty = (row.before_qty || 0) + (row.adjust_qty || 0)
	row.total_cost = Math.abs(row.adjust_qty || 0) * (row.unit_cost || 0)
}

const increaseCount = computed(() => items.value.filter((r) => r.adjust_qty > 0).length)
const decreaseCount = computed(() => items.value.filter((r) => r.adjust_qty < 0).length)
const increaseAmount = computed(() => items.value.filter((r) => r.adjust_qty > 0).reduce((s, r) => s + Number(r.total_cost), 0))
const decreaseAmount = computed(() => items.value.filter((r) => r.adjust_qty < 0).reduce((s, r) => s + Number(r.total_cost), 0))

function fmtMoney(v) {
	return Number(v ?? 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

async function save(action) {
	if (!form.warehouse_id) { ElMessage.warning('请选择仓库'); return }
	if (!form.adjust_date) { ElMessage.warning('请选择调整日期'); return }
	if (!form.adjust_type) { ElMessage.warning('请选择调整类型'); return }
	if (!form.reason) { ElMessage.warning('请填写调整原因'); return }
	if (!items.value.length) { ElMessage.warning('请至少添加一个商品'); return }

	const payload = {
		warehouse_id: form.warehouse_id,
		adjust_date: form.adjust_date,
		adjust_type: form.adjust_type,
		reason: form.reason,
		action,
		items: items.value.map((r) => ({
			product_id: r.product_id,
			adjust_qty: r.adjust_qty,
			unit_cost: r.unit_cost,
			remark: r.remark,
		})),
	}

	try {
		if (props.editRow) {
			await businessApi.stockAdjust.update.put(props.editRow.id, payload)
			if (action === 'submit') await businessApi.stockAdjust.submit.post(props.editRow.id)
		} else {
			const res = await businessApi.stockAdjust.create.post(payload)
			if (action === 'submit') {
				await businessApi.stockAdjust.submit.post(res.data?.id)
			}
		}
		ElMessage.success(action === 'submit' ? '已提交审核' : '草稿已保存')
		emit('saved')
	} catch (e) {
		ElMessage.error(e?.response?.data?.message || '保存失败')
	}
}
</script>

<style scoped>
.base-form { padding: 16px; border-bottom: 1px solid #ebeef5; }
.reason-form { padding: 0 16px 16px; }
.toolbar { display: flex; align-items: center; padding: 12px 16px; border-bottom: 1px solid #ebeef5; }
.toolbar-title { font-size: 14px; font-weight: 500; color: #303133; margin-right: 12px; }
.items-wrap { padding: 16px; }
.footer { display: flex; align-items: center; justify-content: space-between; padding: 16px; border-top: 1px solid #ebeef5; background: #fafafa; }
.summary { font-size: 14px; color: #606266; }
.actions { display: flex; gap: 8px; }
.num-up { color: #52c41a; font-weight: 500; }
.num-down { color: #f5222d; font-weight: 500; }
.product-search { display: flex; align-items: center; padding: 16px; border-bottom: 1px solid #ebeef5; }
</style>
