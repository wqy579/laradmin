<template>
	<el-dialog v-model="visible" :title="record ? '编辑供应商' : '新增供应商'" width="520px" destroy-on-close @close="formRef?.resetFields()">
		<el-form ref="formRef" :model="form" :rules="rules" label-width="90px">
			<el-form-item label="供应商名称" prop="name">
				<el-input v-model="form.name" placeholder="请输入供应商名称" maxlength="200" />
			</el-form-item>
			<el-form-item label="供应商编码" prop="code">
				<el-input v-model="form.code" placeholder="请输入供应商编码" maxlength="50" />
			</el-form-item>
			<el-form-item label="联系人" prop="contact">
				<el-input v-model="form.contact" placeholder="请输入联系人" maxlength="100" />
			</el-form-item>
			<el-form-item label="联系电话" prop="phone">
				<el-input v-model="form.phone" placeholder="请输入联系电话" maxlength="50" />
			</el-form-item>
			<el-form-item label="地址" prop="address">
				<el-input v-model="form.address" placeholder="请输入地址" maxlength="500" />
			</el-form-item>
			<el-form-item label="备注" prop="remark">
				<el-input v-model="form.remark" type="textarea" :rows="2" placeholder="请输入备注" />
			</el-form-item>
			<el-form-item label="状态" prop="is_active">
				<el-radio-group v-model="form.is_active">
					<el-radio :value="1">启用</el-radio>
					<el-radio :value="0">禁用</el-radio>
				</el-radio-group>
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

const props = defineProps({ visible: Boolean, record: Object })
const emit = defineEmits(['update:visible', 'success'])

const formRef = ref(null)
const submitting = ref(false)
const form = ref({ name: '', code: '', contact: '', phone: '', address: '', remark: '', is_active: 1 })
const rules = { name: [{ required: true, message: '请输入供应商名称', trigger: 'blur' }] }

const visible = computed({ get: () => props.visible, set: (v) => emit('update:visible', v) })

watch(() => props.record, (val) => {
	form.value = val ? { ...val } : { name: '', code: '', contact: '', phone: '', address: '', remark: '', is_active: 1 }
}, { immediate: true })

const handleSubmit = async () => {
	await formRef.value.validate()
	submitting.value = true
	try {
		const res = props.record
			? await businessApi.supplier.edit.put(props.record.id, form.value)
			: await businessApi.supplier.add.post(form.value)
		if (res.code === 200) { ElMessage.success(res.message || '操作成功'); emit('success'); visible.value = false }
	} finally { submitting.value = false }
}
</script>
