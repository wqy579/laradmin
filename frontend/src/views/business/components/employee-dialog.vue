<template>
	<el-dialog v-model="visible" :title="record ? '编辑员工' : '新增员工'" width="560px" destroy-on-close @close="formRef?.resetFields()">
		<el-form ref="formRef" :model="form" :rules="rules" label-width="90px">
			<el-form-item label="工号" prop="code">
				<el-input v-model="form.code" placeholder="请输入工号" maxlength="30" />
			</el-form-item>
			<el-form-item label="姓名" prop="name">
				<el-input v-model="form.name" placeholder="请输入姓名" maxlength="100" />
			</el-form-item>
			<el-form-item label="性别" prop="gender">
				<el-radio-group v-model="form.gender">
					<el-radio value="男">男</el-radio>
					<el-radio value="女">女</el-radio>
				</el-radio-group>
			</el-form-item>
			<el-form-item label="职位" prop="position">
				<el-input v-model="form.position" placeholder="请输入职位" maxlength="50" />
			</el-form-item>
			<el-form-item label="角色" prop="role">
				<el-select v-model="form.role" placeholder="请选择角色" clearable style="width:100%">
					<el-option label="员工" value="staff" />
					<el-option label="销售" value="salesman" />
					<el-option label="主管" value="manager" />
					<el-option label="管理员" value="admin" />
				</el-select>
			</el-form-item>
			<el-form-item label="电话" prop="phone">
				<el-input v-model="form.phone" placeholder="请输入电话" maxlength="50" />
			</el-form-item>
			<el-form-item label="身份证号" prop="id_card">
				<el-input v-model="form.id_card" placeholder="请输入身份证号" maxlength="20" />
			</el-form-item>
			<el-form-item label="入职日期" prop="hire_date">
				<el-date-picker v-model="form.hire_date" type="date" placeholder="请选择入职日期" style="width:100%" />
			</el-form-item>
			<el-form-item label="基本工资" prop="base_salary">
				<el-input-number v-model="form.base_salary" :precision="2" :min="0" style="width:100%" />
			</el-form-item>
			<el-form-item label="提成比例" prop="commission_rate">
				<el-input-number v-model="form.commission_rate" :precision="2" :min="0" :max="100" style="width:100%" />
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
const form = ref({ code: '', name: '', gender: '男', position: '', role: '', phone: '', id_card: '', hire_date: '', base_salary: null, commission_rate: null, remark: '', is_active: 1 })
const rules = { code: [{ required: true, message: '请输入工号', trigger: 'blur' }], name: [{ required: true, message: '请输入姓名', trigger: 'blur' }] }

const visible = computed({ get: () => props.visible, set: (v) => emit('update:visible', v) })

watch(() => props.record, (val) => {
	form.value = val ? {
		code: val.code || '',
		name: val.name || '',
		gender: val.gender || '男',
		position: val.position || '',
		role: val.role || '',
		phone: val.phone || '',
		id_card: val.id_card || '',
		hire_date: val.hire_date || '',
		base_salary: val.base_salary || null,
		commission_rate: val.commission_rate || null,
		remark: val.remark || '',
		is_active: val.is_active ? 1 : 0,
	} : { code: '', name: '', gender: '男', position: '', role: '', phone: '', id_card: '', hire_date: '', base_salary: null, commission_rate: null, remark: '', is_active: 1 }
}, { immediate: true })

const handleSubmit = async () => {
	await formRef.value.validate()
	submitting.value = true
	try {
		const payload = { ...form.value }
		const res = props.record
			? await businessApi.employee.edit.put(props.record.id, payload)
			: await businessApi.employee.add.post(payload)
		if (res.code === 200) { ElMessage.success(res.message || '操作成功'); emit('success'); visible.value = false }
	} finally { submitting.value = false }
}
</script>
