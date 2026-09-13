<template>
	<el-dialog v-model="visible" :title="record ? '编辑仓库' : '新增仓库'" width="500px" destroy-on-close @close="formRef?.resetFields()">
		<el-form ref="formRef" :model="form" :rules="rules" label-width="90px">
			<el-form-item label="仓库名称" prop="name"><el-input v-model="form.name" placeholder="请输入仓库名称" maxlength="100" /></el-form-item>
			<el-form-item label="仓库编码" prop="code"><el-input v-model="form.code" placeholder="留空自动生成" maxlength="20" /></el-form-item>
			<el-form-item label="地址" prop="address"><el-input v-model="form.address" placeholder="请输入地址" maxlength="500" /></el-form-item>
			<el-form-item label="负责人" prop="contact"><el-input v-model="form.contact" placeholder="请输入负责人" maxlength="100" /></el-form-item>
			<el-form-item label="联系电话" prop="phone"><el-input v-model="form.phone" placeholder="请输入联系电话" maxlength="50" /></el-form-item>
			<el-form-item label="状态" prop="is_active">
				<el-radio-group v-model="form.is_active"><el-radio :value="1">启用</el-radio><el-radio :value="0">禁用</el-radio></el-radio-group>
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
const form = ref({ name: '', code: '', address: '', contact: '', phone: '', is_active: 1 })
const rules = { name: [{ required: true, message: '请输入仓库名称', trigger: 'blur' }] }
const visible = computed({ get: () => props.visible, set: (v) => emit('update:visible', v) })

watch(() => props.record, (val) => { form.value = val ? { ...val } : { name: '', code: '', address: '', contact: '', phone: '', is_active: 1 } }, { immediate: true })

const handleSubmit = async () => {
	await formRef.value.validate()
	submitting.value = true
	try {
		const payload = {
			name: form.value.name,
			code: form.value.code || undefined,
			address: form.value.address || null,
			contact: form.value.contact || null,
			phone: form.value.phone || null,
			is_active: !!form.value.is_active,
		}
		const res = props.record
			? await businessApi.warehouse.edit.put(props.record.id, payload)
			: await businessApi.warehouse.add.post(payload)
		if (res.code === 200) { ElMessage.success(res.message || '操作成功'); emit('success'); visible.value = false }
	} catch (err) {
		const msg = err?.response?.data?.message || err?.message
		if (msg) ElMessage.error(msg)
	} finally { submitting.value = false }
}
</script>
