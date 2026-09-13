<template>
	<el-dialog v-model="visible" :title="record ? '编辑车辆' : '新增车辆'" width="500px" destroy-on-close @close="formRef?.resetFields()">
		<el-form ref="formRef" :model="form" :rules="rules" label-width="90px">
			<el-form-item label="车牌号" prop="plate_no"><el-input v-model="form.plate_no" placeholder="请输入车牌号" maxlength="20" /></el-form-item>
			<el-form-item label="司机姓名" prop="driver_name"><el-input v-model="form.driver_name" placeholder="请输入司机姓名" maxlength="100" /></el-form-item>
			<el-form-item label="司机电话" prop="driver_phone"><el-input v-model="form.driver_phone" placeholder="请输入司机电话" maxlength="50" /></el-form-item>
			<el-form-item label="载重(吨)" prop="load_capacity"><el-input-number v-model="form.load_capacity" :precision="2" :min="0" :step="0.1" style="width:100%" /></el-form-item>
			<el-form-item label="状态" prop="is_active">
				<el-radio-group v-model="form.is_active"><el-radio :value="1">启用</el-radio><el-radio :value="0">禁用</el-radio></el-radio-group>
			</el-form-item>
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

const props = defineProps({ visible: Boolean, record: Object })
const emit = defineEmits(['update:visible', 'success'])
const formRef = ref(null)
const submitting = ref(false)
const form = ref({ plate_no: '', driver_name: '', driver_phone: '', vehicle_type: '', load_capacity: null, remark: '', is_active: 1 })
const rules = { plate_no: [{ required: true, message: '请输入车牌号', trigger: 'blur' }] }
const visible = computed({ get: () => props.visible, set: (v) => emit('update:visible', v) })

watch(() => props.record, (val) => { form.value = val ? { ...val, load_capacity: val.load_capacity || null } : { plate_no: '', driver_name: '', driver_phone: '', vehicle_type: '', load_capacity: null, remark: '', is_active: 1 } }, { immediate: true })

const handleSubmit = async () => {
	await formRef.value.validate()
	submitting.value = true
	try {
		const res = props.record ? await businessApi.vehicle.edit.put(props.record.id, form.value) : await businessApi.vehicle.add.post(form.value)
		if (res.code === 200) { ElMessage.success(res.message || '操作成功'); emit('success'); visible.value = false }
	} finally { submitting.value = false }
}
</script>
