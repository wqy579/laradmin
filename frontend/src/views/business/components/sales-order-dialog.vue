<template>
	<el-dialog v-model="visible" :title="record ? '编辑销售订单' : '新增销售订单'" width="720px" destroy-on-close @close="resetForm">
		<el-form ref="formRef" :model="form" :rules="rules" label-width="90px">
			<el-row :gutter="16">
				<el-col :span="12">
					<el-form-item label="客户" prop="customer_id">
						<el-select v-model="form.customer_id" placeholder="请选择客户" filterable style="width:100%">
							<el-option v-for="c in customers" :key="c.id" :label="c.name" :value="c.id" />
						</el-select>
					</el-form-item>
				</el-col>
				<el-col :span="12">
					<el-form-item label="仓库" prop="warehouse_id">
						<el-select v-model="form.warehouse_id" placeholder="请选择仓库" style="width:100%">
							<el-option v-for="w in warehouses" :key="w.id" :label="w.name" :value="w.id" />
						</el-select>
					</el-form-item>
				</el-col>
			</el-row>
			<el-form-item label="日期" prop="order_date">
				<el-date-picker v-model="form.order_date" type="date" placeholder="请选择日期" style="width:100%" />
			</el-form-item>
			<el-form-item label="订单明细">
				<div class="items-table">
					<div class="items-header">
						<span class="col-product">产品</span>
						<span class="col-qty">数量</span>
						<span class="col-price">单价</span>
						<span class="col-amount">金额</span>
						<span class="col-action"></span>
					</div>
					<div v-for="(item, idx) in form.items" :key="idx" class="items-row">
						<el-select v-model="item.product_id" placeholder="选择产品" filterable style="width:40%" @change="onProductChange(item)">
							<el-option v-for="p in products" :key="p.id" :label="p.name + (p.code ? ' (' + p.code + ')' : '')" :value="p.id" />
						</el-select>
						<el-input-number v-model="item.quantity" :min="1" :precision="0" style="width:20%" />
						<el-input-number v-model="item.price" :min="0" :precision="2" style="width:20%" @change="calcItemAmount(item)" />
						<span class="col-amount">{{ (item.amount || 0).toFixed(2) }}</span>
						<el-button type="danger" link size="small" @click="form.items.splice(idx, 1)">删除</el-button>
					</div>
					<el-button type="primary" link size="small" @click="addItem">+ 添加产品</el-button>
				</div>
			</el-form-item>
			<el-form-item label="总金额"><span class="total-amount">¥{{ totalAmount.toFixed(2) }}</span></el-form-item>
			<el-form-item label="备注" prop="remark">
				<el-input v-model="form.remark" type="textarea" :rows="2" placeholder="请输入备注" />
			</el-form-item>
		</el-form>
		<template #footer>
			<el-button @click="visible = false">取消</el-button>
			<el-button type="primary" :loading="submitting" @click="handleSubmit">确定</el-button>
		</template>
	</el-dialog>
</template>

<script setup>
import { ref, watch, computed } from 'vue'
import { ElMessage } from 'element-plus'
import businessApi from '@/api/business'

const props = defineProps({ visible: Boolean, record: Object, customers: { type: Array, default: () => [] }, warehouses: { type: Array, default: () => [] } })
const emit = defineEmits(['update:visible', 'success'])
const formRef = ref(null)
const submitting = ref(false)
const products = ref([])

const form = ref({ customer_id: null, warehouse_id: null, order_date: null, items: [], remark: '' })
const rules = { customer_id: [{ required: true, message: '请选择客户', trigger: 'change' }], warehouse_id: [{ required: true, message: '请选择仓库', trigger: 'change' }], order_date: [{ required: true, message: '请选择日期', trigger: 'change' }] }
const visible = computed({ get: () => props.visible, set: (v) => emit('update:visible', v) })

const totalAmount = computed(() => form.value.items.reduce((sum, i) => sum + (i.amount || 0), 0))

const loadProducts = async () => {
	const res = await businessApi.product.list.get({ per_page: 9999 })
	if (res.code === 200) products.value = res.data?.list || []
}

watch(() => props.record, async (val) => {
	await loadProducts()
	if (val) {
		form.value = {
			customer_id: val.customer_id,
			warehouse_id: val.warehouse_id,
			order_date: val.order_date,
			items: (val.items || []).map(i => ({ ...i, amount: i.quantity * i.price })),
			remark: val.remark || '',
		}
	} else {
		form.value = { customer_id: null, warehouse_id: null, order_date: null, items: [], remark: '' }
	}
}, { immediate: true })

const addItem = () => { form.value.items.push({ product_id: null, quantity: 1, price: 0, amount: 0 }) }
const onProductChange = (item) => { const p = products.value.find(x => x.id === item.product_id); if (p) { item.price = Number(p.price_large) || 0; calcItemAmount(item) } }
const calcItemAmount = (item) => { item.amount = (item.quantity || 0) * (item.price || 0) }
const resetForm = () => { formRef.value?.resetFields() }

const handleSubmit = async () => {
	await formRef.value.validate()
	if (form.value.items.length === 0) { ElMessage.warning('请至少添加一个产品'); return }
	submitting.value = true
	try {
		const payload = { ...form.value, items: form.value.items.map(i => ({ product_id: i.product_id, quantity: i.quantity, price: i.price })) }
		const res = props.record ? await businessApi.salesOrder.edit.put(props.record.id, payload) : await businessApi.salesOrder.add.post(payload)
		if (res.code === 200) { ElMessage.success(res.message || '操作成功'); emit('success'); visible.value = false }
	} finally { submitting.value = false }
}
</script>

<style scoped>
.items-table { width: 100%; }
.items-header, .items-row { display: flex; align-items: center; gap: 8px; padding: 4px 0; }
.items-header { font-weight: bold; font-size: 12px; color: var(--el-text-color-secondary); border-bottom: 1px solid var(--el-border-color); padding-bottom: 8px; }
.col-product { flex: 2; }
.col-qty { width: 80px; }
.col-price { width: 100px; }
.col-amount { width: 90px; text-align: right; }
.col-action { width: 50px; }
.total-amount { font-weight: bold; font-size: 16px; color: var(--el-color-primary); }
</style>
