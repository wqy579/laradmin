<template>
	<el-dialog v-model="visible" :title="record ? '编辑兑换商品' : '新增兑换商品'" width="560px" destroy-on-close @close="resetForm">
		<el-form ref="formRef" :model="form" :rules="rules" label-width="100px">
			<el-form-item label="商品名称" prop="name">
				<el-input v-model="form.name" maxlength="100" placeholder="请输入商品名称" />
			</el-form-item>
			<el-row :gutter="16">
				<el-col :span="12">
					<el-form-item label="所需积分" prop="points">
						<el-input-number v-model="form.points" :min="1" :step="10" style="width:100%" />
					</el-form-item>
				</el-col>
				<el-col :span="12">
					<el-form-item label="库存" prop="stock">
						<el-input-number v-model="form.stock" :min="0" :step="10" style="width:100%" />
					</el-form-item>
				</el-col>
				<el-col :span="12">
					<el-form-item label="规格">
						<el-input v-model="form.spec" maxlength="100" />
					</el-form-item>
				</el-col>
				<el-col :span="12">
					<el-form-item label="每人限购">
						<el-input-number v-model="form.limit_per_person" :min="0" :step="1" style="width:100%" />
						<span class="hint">0 = 不限</span>
					</el-form-item>
				</el-col>
				<el-col :span="12">
					<el-form-item label="状态">
						<el-radio-group v-model="form.status">
							<el-radio :value="1">上架</el-radio>
							<el-radio :value="0">下架</el-radio>
						</el-radio-group>
					</el-form-item>
				</el-col>
				<el-col :span="12">
					<el-form-item label="排序">
						<el-input-number v-model="form.sort" :min="0" style="width:100%" />
					</el-form-item>
				</el-col>
				<el-col :span="24">
					<el-form-item label="图片URL">
						<el-input v-model="form.image" maxlength="255" placeholder="留空则不显示图片" />
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
import { ref, watch, computed } from 'vue'
import { ElMessage } from 'element-plus'
import businessApi from '@/api/business'

const props = defineProps({ visible: Boolean, record: Object })
const emit = defineEmits(['update:visible', 'success'])

const formRef = ref(null)
const submitting = ref(false)

const empty = { name: '', points: 100, stock: 0, spec: '', limit_per_person: 0, status: 1, sort: 0, image: '', remark: '' }
const form = ref({ ...empty })

const rules = {
	name: [{ required: true, message: '请输入商品名称', trigger: 'blur' }],
	points: [{ required: true, message: '请输入所需积分', trigger: 'blur' }],
}

const visible = computed({ get: () => props.visible, set: (v) => emit('update:visible', v) })

watch(() => props.record, (val) => {
	if (!val) { form.value = { ...empty }; return }
	form.value = { ...empty, ...val }
}, { immediate: true })

const resetForm = () => { form.value = { ...empty } }

const handleSubmit = async () => {
	await formRef.value.validate()
	submitting.value = true
	try {
		const res = props.record
			? await businessApi.pointProduct.edit.put(props.record.id, form.value)
			: await businessApi.pointProduct.add.post(form.value)
		if (res.code === 200) { ElMessage.success(res.message || '已保存'); emit('success'); visible.value = false }
	} finally { submitting.value = false }
}
</script>

<style scoped>
.hint { margin-left: 8px; color: #999; font-size: 12px; }
</style>
