<template>
	<el-dialog v-model="visible" :title="record?.is_main !== false ? '新增主分类' : '新增副分类'" width="420px" destroy-on-close @close="handleClose">
		<el-form ref="formRef" :model="form" :rules="rules" label-width="100px">
			<el-form-item label="分类名称" prop="name">
				<el-input v-model="form.name" placeholder="请输入分类名称" maxlength="100" />
			</el-form-item>
			<el-form-item v-if="!record?.parent_id" label="类型" prop="is_main">
				<el-radio-group v-model="form.is_main">
					<el-radio :value="true">主分类</el-radio>
					<el-radio :value="false">副分类</el-radio>
				</el-radio-group>
			</el-form-item>
			<el-form-item v-if="record?.parent_id" label="所属主分类">
				<el-input :value="record.main_cat_name" disabled />
			</el-form-item>
			<el-form-item label="排序" prop="sort_order">
				<el-input-number v-model="form.sort_order" :min="0" :max="999" style="width:100%" />
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

const props = defineProps({ visible: Boolean, record: Object, parentMains: { type: Array, default: () => [] } })
const emit = defineEmits(['update:visible', 'success'])

const formRef = ref(null)
const submitting = ref(false)

const form = ref({ name: '', is_main: true, parent_id: null, sort_order: 0, is_active: 1 })

const rules = { name: [{ required: true, message: '请输入分类名称', trigger: 'blur' }] }

const visible = computed({ get: () => props.visible, set: (v) => emit('update:visible', v) })

watch(() => props.record, (val) => {
	if (val) {
		form.value = { name: val.name || '', is_main: val.is_main ?? true, parent_id: val.parent_id || null, sort_order: val.sort_order ?? 0, is_active: val.is_active !== undefined ? (val.is_active ? 1 : 0) : 1 }
	} else {
		form.value = { name: '', is_main: true, parent_id: null, sort_order: 0, is_active: 1 }
	}
}, { immediate: true })

const handleSubmit = async () => {
	await formRef.value.validate()
	submitting.value = true
	try {
		const payload = { ...form.value }
		let res
		if (props.record?.id) {
			res = await businessApi.product.category.edit.put(props.record.id, payload)
		} else {
			res = await businessApi.product.category.add.post(payload)
		}
		if (res.code === 200) {
			ElMessage.success(res.message || '操作成功')
			emit('success')
			visible.value = false
		}
	} finally {
		submitting.value = false
	}
}

const handleClose = () => { formRef.value?.resetFields() }
</script>
