<template>
	<el-dialog v-model="visible" :title="record ? '编辑路线' : '新增路线'" width="560px" destroy-on-close @close="formRef?.resetFields()">
		<el-form ref="formRef" :model="form" :rules="rules" label-width="90px">
			<el-form-item label="路线名称" prop="name"><el-input v-model="form.name" placeholder="请输入路线名称" maxlength="200" /></el-form-item>
			<el-form-item label="路线编码" prop="code"><el-input v-model="form.code" placeholder="请输入路线编码" maxlength="50" /></el-form-item>
			<el-form-item label="关联客户" prop="customer_ids">
				<el-select v-model="form.customer_ids" multiple placeholder="请选择客户" style="width:100%">
					<el-option v-for="c in customers" :key="c.id" :label="c.name" :value="c.id" />
				</el-select>
			</el-form-item>
			<el-form-item label="排序" prop="sort_order"><el-input-number v-model="form.sort_order" :min="0" :max="999" style="width:100%" /></el-form-item>
			<el-form-item label="备注" prop="remark"><el-input v-model="form.remark" type="textarea" :rows="2" placeholder="请输入备注" /></el-form-item>
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

const props = defineProps({ visible: Boolean, record: Object, customers: { type: Array, default: () => [] } })
const emit = defineEmits(['update:visible', 'success'])
const formRef = ref(null)
const submitting = ref(false)
const form = ref({ name: '', code: '', customer_ids: [], sort_order: 0, remark: '' })
const rules = { name: [{ required: true, message: '请输入路线名称', trigger: 'blur' }] }
const visible = computed({ get: () => props.visible, set: (v) => emit('update:visible', v) })

watch(() => props.record, async (val) => {
	form.value = val ? { ...val, customer_ids: val.customer_ids || [] } : { name: '', code: '', customer_ids: [], sort_order: 0, remark: '' }
}, { immediate: true })

const handleSubmit = async () => {
	await formRef.value.validate()
	submitting.value = true
	try {
		const payload = { ...form.value }
		const res = props.record ? await businessApi.route.edit.put(props.record.id, payload) : await businessApi.route.add.post(payload)
		if (res.code === 200) { ElMessage.success(res.message || '操作成功'); emit('success'); visible.value = false }
	} finally { submitting.value = false }
}
</script>
