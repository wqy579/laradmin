<template>
	<el-dialog v-model="visible" :title="record ? '编辑入库单' : '新增入库单'" width="500px" destroy-on-close @close="resetForm">
		<el-form ref="formRef" :model="form" :rules="rules" label-width="90px">
			<el-form-item label="产品" prop="product_id">
				<el-select v-model="form.product_id" placeholder="请选择产品" filterable style="width:100%">
					<el-option v-for="p in products" :key="p.id" :label="p.name + (p.code ? ' (' + p.code + ')' : '')" :value="p.id" />
				</el-select>
			</el-form-item>
			<el-form-item label="仓库" prop="warehouse_id">
				<el-select v-model="form.warehouse_id" placeholder="请选择仓库" style="width:100%">
					<el-option v-for="w in warehouses" :key="w.id" :label="w.name" :value="w.id" />
				</el-select>
			</el-form-item>
			<el-form-item label="入库数量" prop="quantity">
				<el-input-number v-model="form.quantity" :min="1" :precision="0" style="width:100%" />
			</el-form-item>
			<el-form-item label="成本价" prop="cost_price">
				<el-input-number v-model="form.cost_price" :min="0" :precision="2" style="width:100%" />
			</el-form-item>
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
import { ref, computed, watch } from 'vue'
import { ElMessage } from 'element-plus'
import businessApi from '@/api/business'

const props = defineProps({ visible: Boolean, record: Object, products: { type: Array, default: () => [] }, warehouses: { type: Array, default: () => [] } })
const emit = defineEmits(['update:visible', 'success'])
const formRef = ref(null)
const submitting = ref(false)

const form = ref({ product_id: null, warehouse_id: null, quantity: 1, cost_price: 0, remark: '' })
const rules = { product_id: [{ required: true, message: '请选择产品', trigger: 'change' }], warehouse_id: [{ required: true, message: '请选择仓库', trigger: 'change' }], quantity: [{ required: true, message: '请输入入库数量', trigger: 'change' }] }
const visible = computed({ get: () => props.visible, set: (v) => emit('update:visible', v) })

watch(() => props.record, (val) => {
	if (val) {
		form.value = { product_id: val.product_id, warehouse_id: val.warehouse_id, quantity: val.quantity, cost_price: val.cost_price || 0, remark: val.remark || '' }
	} else {
		form.value = { product_id: null, warehouse_id: null, quantity: 1, cost_price: 0, remark: '' }
	}
}, { immediate: true })

const resetForm = () => { formRef.value?.resetFields() }

const handleSubmit = async () => {
	await formRef.value.validate()
	submitting.value = true
	try {
		const res = await businessApi.stockIn.add.post(form.value)
		if (res.code === 200) { ElMessage.success(res.message || '入库成功'); emit('success'); visible.value = false }
	} finally { submitting.value = false }
}
</script>
