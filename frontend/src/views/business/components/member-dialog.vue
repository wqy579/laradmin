<template>
	<el-dialog v-model="visible" :title="record ? '编辑会员' : '新增会员'" width="640px" destroy-on-close @close="resetForm">
		<el-form ref="formRef" :model="form" :rules="rules" label-width="100px">
			<el-row :gutter="16">
				<el-col :span="12">
					<el-form-item label="会员名称" prop="name">
						<el-input v-model="form.name" maxlength="100" placeholder="请输入会员名称" />
					</el-form-item>
				</el-col>
				<el-col :span="12">
					<el-form-item label="关联客户" prop="customer_id">
						<el-select v-model="form.customer_id" clearable filterable remote :remote-method="searchCustomers"
							:loading="customerLoading" placeholder="搜索客户名称/编码/手机" style="width:100%">
							<el-option v-for="c in customers" :key="c.id" :label="`${c.name}${c.phone ? '（' + c.phone + '）' : ''}`" :value="c.id" />
						</el-select>
					</el-form-item>
				</el-col>
				<el-col :span="12">
					<el-form-item label="联系人">
						<el-input v-model="form.contact" maxlength="50" />
					</el-form-item>
				</el-col>
				<el-col :span="12">
					<el-form-item label="手机">
						<el-input v-model="form.phone" maxlength="20" />
					</el-form-item>
				</el-col>
				<el-col :span="12">
					<el-form-item label="性别">
						<el-radio-group v-model="form.gender">
							<el-radio :value="0">未知</el-radio>
							<el-radio :value="1">男</el-radio>
							<el-radio :value="2">女</el-radio>
						</el-radio-group>
					</el-form-item>
				</el-col>
				<el-col :span="12">
					<el-form-item label="生日">
						<el-date-picker v-model="form.birthday" type="date" value-format="YYYY-MM-DD" style="width:100%" />
					</el-form-item>
				</el-col>
				<el-col :span="12">
					<el-form-item label="会员等级">
						<el-select v-model="form.level_id" clearable placeholder="默认普通会员" style="width:100%">
							<el-option v-for="l in levels" :key="l.id" :label="l.name" :value="l.id" />
						</el-select>
					</el-form-item>
				</el-col>
				<el-col :span="12">
					<el-form-item label="邮箱">
						<el-input v-model="form.email" maxlength="100" />
					</el-form-item>
				</el-col>
				<el-col :span="12">
					<el-form-item label="状态">
						<el-radio-group v-model="form.status">
							<el-radio :value="1">正常</el-radio>
							<el-radio :value="0">冻结</el-radio>
						</el-radio-group>
					</el-form-item>
				</el-col>
				<el-col :span="24">
					<el-form-item label="地址">
						<el-input v-model="form.address" maxlength="255" type="textarea" :rows="2" />
					</el-form-item>
				</el-col>
				<el-col :span="24">
					<el-form-item label="备注">
						<el-input v-model="form.remark" maxlength="1000" type="textarea" :rows="2" />
					</el-form-item>
				</el-col>
			</el-row>
		</el-form>
		<template #footer>
			<el-button @click="visible = false">取消</el-button>
			<el-button type="primary" :loading="submitting" @click="handleSubmit">确定</el-button>
		</template>
	</el-dialog>
</template>

<script setup>
import { ref, watch, computed, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import businessApi from '@/api/business'

const props = defineProps({
	visible: Boolean,
	record: Object,
	levels: { type: Array, default: () => [] },
})
const emit = defineEmits(['update:visible', 'success'])

const formRef = ref(null)
const submitting = ref(false)
const customers = ref([])
const customerLoading = ref(false)

const empty = { name: '', customer_id: null, contact: '', phone: '', gender: 0, birthday: null, email: '', address: '', level_id: null, status: 1, remark: '' }
const form = ref({ ...empty })

const rules = { name: [{ required: true, message: '请输入会员名称', trigger: 'blur' }] }

const visible = computed({ get: () => props.visible, set: (v) => emit('update:visible', v) })

const normalize = (val) => {
	if (!val) return { ...empty }
	return {
		...empty,
		...val,
		birthday: val.birthday ? String(val.birthday).slice(0, 10) : null,
		gender: val.gender ?? 0,
		status: val.status ?? 1,
	}
}

watch(() => props.record, (val) => { form.value = normalize(val) }, { immediate: true })

const resetForm = () => { form.value = { ...empty }; customers.value = [] }

const searchCustomers = async (kw) => {
	customerLoading.value = true
	try {
		const res = await businessApi.customer.list.get({ keyword: kw, page: 1, page_size: 20 })
		customers.value = res.data?.list || res.data || []
	} finally {
		customerLoading.value = false
	}
}

onMounted(searchCustomers)

const handleSubmit = async () => {
	await formRef.value.validate()
	submitting.value = true
	try {
		const res = props.record
			? await businessApi.member.edit.put(props.record.id, form.value)
			: await businessApi.member.add.post(form.value)
		if (res.code === 200) { ElMessage.success(res.message || '已保存'); emit('success'); visible.value = false }
	} finally { submitting.value = false }
}
</script>
