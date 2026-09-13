<template>
	<el-dialog :title="isEdit ? '编辑字典项' : '新增字典项'" v-model="dialogVisible" width="600px" :close-on-click-modal="false">
		<el-form ref="formRef" :model="formData" :rules="formRules" label-width="100px">
			<el-form-item label="标签名称" prop="label">
				<el-input v-model="formData.label" placeholder="如：正常" clearable maxlength="50" show-word-limit />
				<div class="form-tip">用于前端显示的文本</div>
			</el-form-item>
			<el-form-item label="数据值" prop="value">
				<el-input v-model="formData.value" placeholder="如：1" clearable />
				<div class="form-tip">实际使用的值，同一字典内必须唯一</div>
			</el-form-item>
			<el-form-item label="颜色标记" prop="color">
				<div style="display: flex; align-items: center; gap: 10px">
					<input v-model="formData.color" type="color" style="width: 60px; height: 32px; cursor: pointer; border: none; padding: 0" />
					<el-input v-model="formData.color" placeholder="#1890ff" clearable style="flex: 1" />
				</div>
				<div class="form-tip">用于前端展示的颜色标记</div>
			</el-form-item>
			<el-form-item label="默认项" prop="is_default">
				<el-switch v-model="formData.is_default" active-text="是" inactive-text="否" />
				<div v-if="formData.is_default" class="form-tip" style="color: var(--el-color-warning)">设置为默认项后，同一字典内的其他默认项将自动取消</div>
			</el-form-item>
			<el-form-item label="排序" prop="sort">
				<el-input-number v-model="formData.sort" :min="0" :max="10000" style="width: 100%" controls-position="right" />
			</el-form-item>
			<el-form-item label="状态" prop="status">
				<el-switch v-model="formData.status" active-text="启用" inactive-text="禁用" :active-value="1" :inactive-value="0" />
			</el-form-item>
			<el-form-item label="描述" prop="description">
				<el-input v-model="formData.description" type="textarea" placeholder="请输入描述" :rows="3" maxlength="200" show-word-limit />
			</el-form-item>
		</el-form>
		<template #footer>
			<el-button @click="handleCancel">取消</el-button>
			<el-button type="primary" :loading="submitLoading" @click="handleSubmit">保存</el-button>
		</template>
	</el-dialog>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { ElMessage } from 'element-plus'
import systemApi from '@/api/system'

const props = defineProps({
	visible: {
		type: Boolean,
		default: false,
	},
	record: {
		type: Object,
		default: null,
	},
	dictionaryId: {
		type: Number,
		default: null,
	},
	itemList: {
		type: Array,
		default: () => [],
	},
})

const emit = defineEmits(['update:visible', 'success'])

const formRef = ref(null)
const submitLoading = ref(false)

const dialogVisible = computed({
	get: () => props.visible,
	set: (value) => emit('update:visible', value),
})

const isEdit = computed(() => !!props.record?.id)

const formData = ref({
	id: '',
	label: '',
	value: '',
	color: '',
	description: '',
	is_default: false,
	status: 1,
	sort: 0,
	dictionary_id: null,
})

// 数据值唯一性验证
const validateValueUnique = async (rule, value) => {
	if (!value) return Promise.resolve()
	const exists = props.itemList.some((item) => item.value === value && item.id !== props.record?.id)
	if (exists) return Promise.reject('该数据值已存在，请使用其他值')
	return Promise.resolve()
}

const formRules = {
	label: [
		{ required: true, message: '请输入标签名称', trigger: 'blur' },
		{ min: 1, max: 50, message: '标签名称长度在 1 到 50 个字符', trigger: 'blur' },
	],
	value: [
		{ required: true, message: '请输入数据值', trigger: 'blur' },
		{ validator: validateValueUnique, trigger: 'blur' },
	],
}

const resetForm = () => {
	formData.value = {
		id: '',
		label: '',
		value: '',
		color: '',
		description: '',
		is_default: false,
		status: 1,
		sort: 0,
		dictionary_id: null,
	}
	formRef.value?.clearValidate()
}

const setData = (data) => {
	if (data) {
		formData.value = {
			id: data.id || '',
			label: data.label || '',
			value: data.value || '',
			color: data.color || '',
			description: data.description || '',
			is_default: data.is_default || false,
			status: data.status !== undefined ? data.status : 1,
			sort: data.sort !== undefined ? data.sort : 0,
			dictionary_id: data.dictionary_id || props.dictionaryId,
		}
	}
}

const handleSubmit = async () => {
	try {
		await formRef.value.validate()
		submitLoading.value = true

		const submitData = {
			label: formData.value.label,
			value: formData.value.value,
			color: formData.value.color,
			description: formData.value.description,
			is_default: formData.value.is_default,
			status: formData.value.status,
			sort: formData.value.sort,
			dictionary_id: props.dictionaryId,
		}

		let res
		if (isEdit.value) {
			res = await systemApi.dictionaryItem.edit.put(formData.value.id, submitData)
		} else {
			res = await systemApi.dictionaryItem.add.post(submitData)
		}

		if (res.code === 200) {
			ElMessage.success(isEdit.value ? '编辑成功' : '新增成功')
			emit('success')
			handleCancel()
		} else {
			ElMessage.error(res.message || '操作失败')
		}
	} catch (error) {
		if (error !== false) {
			console.error('提交失败:', error)
			ElMessage.error('操作失败')
		}
	} finally {
		submitLoading.value = false
	}
}

const handleCancel = () => {
	resetForm()
	emit('update:visible', false)
}

watch(
	() => props.visible,
	(newVal) => {
		if (newVal) {
			if (props.record) {
				setData(props.record)
			} else {
				resetForm()
				formData.value.dictionary_id = props.dictionaryId
			}
		}
	},
	{ immediate: true },
)
</script>

<style scoped>
.form-tip {
	font-size: 12px;
	color: var(--el-text-color-placeholder);
	margin-top: 4px;
	line-height: 1.5;
}
</style>
