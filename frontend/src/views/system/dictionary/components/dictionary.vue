<template>
	<el-dialog :title="isEdit ? '编辑字典类型' : '新增字典类型'" v-model="dialogVisible" width="600px" :close-on-click-modal="false">
		<el-form ref="formRef" :model="formData" :rules="formRules" label-width="100px">
			<el-form-item label="字典名称" prop="name">
				<el-input v-model="formData.name" placeholder="如：用户状态" clearable maxlength="50" show-word-limit />
			</el-form-item>
			<el-form-item label="字典编码" prop="code">
				<el-input v-model="formData.code" placeholder="如：user_status" clearable :disabled="isEdit" />
				<div class="form-tip">系统唯一标识，只能包含字母、数字、下划线，且必须以字母开头</div>
			</el-form-item>
			<el-form-item label="值类型" prop="value_type">
				<el-select v-model="formData.value_type" placeholder="请选择值类型" clearable>
					<el-option label="字符串" value="string" />
					<el-option label="数字" value="number" />
					<el-option label="布尔值" value="boolean" />
					<el-option label="JSON" value="json" />
				</el-select>
				<div class="form-tip">指定字典项值的类型，系统会根据类型自动格式化返回数据</div>
			</el-form-item>
			<el-form-item label="排序" prop="sort">
				<el-input-number v-model="formData.sort" :min="0" :max="10000" style="width: 100%" controls-position="right" />
				<div class="form-tip">数值越小越靠前</div>
			</el-form-item>
			<el-form-item label="状态" prop="status">
				<el-select v-model="formData.status" placeholder="请选择状态" clearable>
					<el-option label="启用" :value="1" />
					<el-option label="禁用" :value="0" />
				</el-select>
			</el-form-item>
			<el-form-item label="描述" prop="description">
				<el-input v-model="formData.description" type="textarea" placeholder="请输入字典描述" :rows="3" maxlength="200" show-word-limit />
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
	dictionaryList: {
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
	name: '',
	code: '',
	value_type: 'string',
	description: '',
	status: null,
	sort: 0,
})

// 编码唯一性验证
const validateCodeUnique = async (rule, value) => {
	if (!value) return Promise.resolve()
	const exists = props.dictionaryList.some((item) => item.code === value && item.id !== props.record?.id)
	if (exists) return Promise.reject('该编码已存在，请使用其他编码')
	return Promise.resolve()
}

const formRules = {
	name: [
		{ required: true, message: '请输入字典名称', trigger: 'blur' },
		{ min: 2, max: 50, message: '字典名称长度在 2 到 50 个字符', trigger: 'blur' },
	],
	code: [
		{ required: true, message: '请输入字典编码', trigger: 'blur' },
		{ pattern: /^[a-zA-Z][a-zA-Z0-9_]*$/, message: '编码格式不正确，只能包含字母、数字、下划线，且必须以字母开头', trigger: 'blur' },
		{ validator: validateCodeUnique, trigger: 'blur' },
	],
	value_type: [{ required: true, message: '请选择值类型', trigger: 'change' }],
}

const resetForm = () => {
	formData.value = {
		id: '',
		name: '',
		code: '',
		value_type: 'string',
		description: '',
		status: null,
		sort: 0,
	}
	formRef.value?.clearValidate()
}

const setData = (data) => {
	if (data) {
		formData.value = {
			id: data.id || '',
			name: data.name || '',
			code: data.code || '',
			value_type: data.value_type || 'string',
			description: data.description || '',
			status: data.status !== undefined ? Number(data.status) : null,
			sort: data.sort !== undefined ? Number(data.sort) : 0,
		}
	}
}

const handleSubmit = async () => {
	try {
		await formRef.value.validate()
		submitLoading.value = true

		const submitData = {
			name: formData.value.name,
			code: formData.value.code,
			value_type: formData.value.value_type,
			description: formData.value.description,
			status: formData.value.status !== null ? Number(formData.value.status) : null,
			sort: Number(formData.value.sort || 0),
		}

		let res
		if (isEdit.value) {
			res = await systemApi.dictionary.edit.put(formData.value.id, submitData)
		} else {
			res = await systemApi.dictionary.add.post(submitData)
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
