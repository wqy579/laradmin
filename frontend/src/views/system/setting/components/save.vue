<template>
	<el-dialog v-model="dialogVisible" :title="isEdit ? '编辑配置' : '添加配置'" width="600px" :close-on-click-modal="false" @closed="handleCancel">
		<el-form ref="formRef" :model="formData" :rules="formRules" label-width="100px">
			<el-form-item label="所属分组" prop="parent_id">
				<el-tree-select v-model="formData.parent_id" :data="groupTree" :props="{ label: 'name', children: 'children', value: 'id' }" placeholder="请选择所属分组" check-strictly filterable style="width: 100%" />
			</el-form-item>

			<el-form-item label="配置名称" prop="name">
				<el-input v-model="formData.name" placeholder="请输入配置名称" />
			</el-form-item>

			<el-form-item label="配置键" prop="key">
				<el-input v-model="formData.key" placeholder="请输入配置键，如: site_name" />
				<div class="form-tip">建议使用小写字母、下划线命名</div>
			</el-form-item>

			<el-form-item label="配置类型" prop="type">
				<el-select v-model="formData.type" placeholder="请选择配置类型" @change="handleTypeChange">
					<el-option label="字符串" value="string" />
					<el-option label="文本" value="text" />
					<el-option label="数字" value="number" />
					<el-option label="布尔值" value="boolean" />
					<el-option label="下拉选择" value="select" />
					<el-option label="单选" value="radio" />
					<el-option label="多选" value="checkbox" />
					<el-option label="文件" value="file" />
					<el-option label="JSON" value="json" />
				</el-select>
			</el-form-item>

			<el-form-item v-if="['select', 'radio', 'checkbox'].includes(formData.type)" label="选项配置" prop="options">
				<div class="options-editor">
					<div v-for="(option, index) in formData.options" :key="index" class="option-item">
						<el-input v-model="option.label" placeholder="选项标签" style="flex: 1" />
						<el-input v-model="option.value" placeholder="选项值" style="flex: 1; margin: 0 8px" />
						<el-button type="danger" link :disabled="formData.options.length <= 1" @click="removeOption(index)">
							<el-icon><ElIconDelete /></el-icon>
						</el-button>
					</div>
					<el-button type="primary" link @click="addOption">
						<el-icon><ElIconPlus /></el-icon>
						添加选项
					</el-button>
				</div>
			</el-form-item>

			<el-form-item label="配置值" prop="value">
				<s-upload v-if="formData.type === 'file'" v-model="formData._fileValue" :max-count="1" list-type="picture-card" @success="(data) => handleFileSuccess(data, 'value')" @remove="() => handleFileRemove('value')" />
				<el-input v-else-if="['string', 'text', 'json'].includes(formData.type)" v-model="formData.value" type="textarea" :placeholder="getValuePlaceholder(formData.type)" :rows="formData.type === 'json' ? 6 : 3" />
				<el-input-number v-else-if="formData.type === 'number'" v-model="formData.value" placeholder="请输入数字" style="width: 100%" controls-position="right" />
				<el-switch v-else-if="formData.type === 'boolean'" v-model="formData.value" active-text="是" inactive-text="否" />
				<el-select v-else-if="['select', 'radio'].includes(formData.type)" v-model="formData.value" placeholder="请选择">
					<el-option v-for="opt in formData.options" :key="opt.value" :label="opt.label" :value="opt.value" />
				</el-select>
				<el-checkbox-group v-else-if="formData.type === 'checkbox'" v-model="formData.value">
					<el-checkbox v-for="opt in formData.options" :key="opt.value" :label="opt.label" :value="opt.value" />
				</el-checkbox-group>
				<el-input v-else v-model="formData.value" placeholder="请输入配置值" />
			</el-form-item>

			<el-form-item label="默认值" prop="default_value">
				<s-upload v-if="formData.type === 'file'" v-model="formData._fileDefault" :max-count="1" list-type="picture-card" @success="(data) => handleFileSuccess(data, 'default_value')" @remove="() => handleFileRemove('default_value')" />
				<el-input v-else-if="['string', 'text', 'json'].includes(formData.type)" v-model="formData.default_value" type="textarea" :placeholder="getValuePlaceholder(formData.type)" :rows="formData.type === 'json' ? 6 : 3" />
				<el-input-number v-else-if="formData.type === 'number'" v-model="formData.default_value" placeholder="请输入默认值" style="width: 100%" controls-position="right" />
				<el-switch v-else-if="formData.type === 'boolean'" v-model="formData.default_value" active-text="是" inactive-text="否" />
				<el-input v-else v-model="formData.default_value" placeholder="请输入默认值" />
			</el-form-item>

			<el-form-item label="描述" prop="description">
				<el-input v-model="formData.description" type="textarea" placeholder="请输入配置描述" :rows="2" />
			</el-form-item>

			<el-form-item label="排序" prop="sort">
				<el-input-number v-model="formData.sort" :min="0" :max="9999" style="width: 100%" controls-position="right" />
				<div class="form-tip">数字越小排序越靠前</div>
			</el-form-item>

			<el-form-item label="状态" prop="status">
				<el-switch v-model="formData.status" active-text="启用" inactive-text="禁用" :active-value="1" :inactive-value="0" />
			</el-form-item>
		</el-form>

		<template #footer>
			<el-button @click="handleCancel">取消</el-button>
			<el-button type="primary" :loading="submitLoading" @click="handleOk">确定</el-button>
		</template>
	</el-dialog>
</template>

<script setup>
import { ref, reactive, computed, watch } from 'vue'
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
	parentId: {
		type: [Number, String],
		default: null,
	},
	groupTree: {
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

const isEdit = computed(() => !!props.record)

const formData = reactive({
	parent_id: null,
	name: '',
	key: '',
	type: 'string',
	options: [],
	value: '',
	default_value: '',
	description: '',
	sort: 0,
	status: 1,
	item_type: 'config',
	// 图片上传组件绑定的文件数组
	_fileValue: [],
	_fileDefault: [],
})

const getValuePlaceholder = (type) => {
	if (type === 'json') return '{"key": "value"}'
	return '请输入配置值'
}

// 文件上传成功：将 URL 存入对应字段
const handleFileSuccess = (data, field) => {
	formData[field] = data.url
}

// 文件移除：清空对应字段
const handleFileRemove = (field) => {
	formData[field] = ''
}

const formRules = computed(() => ({
	parent_id: [{ required: true, message: '请选择所属分组', trigger: 'change' }],
	name: [{ required: true, message: '请输入配置名称', trigger: 'blur' }],
	key: [
		{ required: !isEdit.value, message: '请输入配置键', trigger: 'blur' },
		{ pattern: /^[a-z][a-z0-9_]*$/i, message: '只能包含字母、数字、下划线，且以字母开头', trigger: 'blur' },
	],
	type: [{ required: true, message: '请选择配置类型', trigger: 'change' }],
}))

// 从记录中解析文件类型值，生成 sUpload 可用的数组格式
function parseFileValue(url) {
	if (!url) return []
	return [{ uid: -1, name: url.split('/').pop() || 'file', url, status: 'success', response: { url } }]
}

watch(
	() => props.visible,
	(val) => {
		if (val) {
			if (isEdit.value && props.record) {
				const record = props.record
				formData.parent_id = record.parent_id || null
				formData.name = record.name || ''
				formData.key = record.key || ''
				formData.type = record.type || 'string'
				formData.description = record.description || ''
				formData.default_value = record.default_value ?? ''
				formData.sort = record.sort ?? 0
				formData.status = typeof record.status === 'boolean' ? (record.status ? 1 : 0) : (record.status ?? 1)

				// options 回填
				if (typeof record.options === 'string' && record.options) {
					try {
						formData.options = JSON.parse(record.options)
					} catch {
						formData.options = []
					}
				} else if (Array.isArray(record.options)) {
					formData.options = [...record.options]
				} else {
					formData.options = []
				}
				if (['select', 'radio', 'checkbox'].includes(formData.type) && formData.options.length === 0) {
					formData.options = [{ label: '选项1', value: 'option1' }]
				}

				// value 回填
				const value = record.value ?? record.default_value ?? ''
				if (formData.type === 'checkbox') {
					if (typeof value === 'string') {
						try {
							formData.value = JSON.parse(value)
						} catch {
							formData.value = []
						}
					} else if (Array.isArray(value)) {
						formData.value = [...value]
					} else {
						formData.value = []
					}
				} else if (formData.type === 'boolean') {
					formData.value = value === true || value === '1' || value === 1
				} else if (formData.type === 'file') {
					formData.value = value
					formData._fileValue = parseFileValue(value)
				} else {
					formData.value = value
				}

				// file 类型的默认值回填
				if (formData.type === 'file') {
					const dv = record.default_value ?? ''
					formData.default_value = dv
					formData._fileDefault = parseFileValue(dv)
				}
			} else {
				formData.parent_id = props.parentId || null
				formData.name = ''
				formData.key = ''
				formData.type = 'string'
				formData.options = []
				formData.value = ''
				formData.default_value = ''
				formData.description = ''
				formData.sort = 0
				formData.status = 1
				formData.item_type = 'config'
				formData._fileValue = []
				formData._fileDefault = []
			}
		}
	},
	{ immediate: true },
)

const handleTypeChange = (type) => {
	formData.value = ''
	formData.default_value = ''
	formData._fileValue = []
	formData._fileDefault = []
	if (['select', 'radio', 'checkbox'].includes(type)) {
		if (!formData.options || formData.options.length === 0) {
			formData.options = [{ label: '选项1', value: 'option1' }]
		}
		if (type === 'checkbox') {
			formData.value = []
		}
	} else {
		formData.options = []
	}
}

const addOption = () => {
	const index = formData.options.length + 1
	formData.options.push({ label: `选项${index}`, value: `option${index}` })
}

const removeOption = (index) => {
	formData.options.splice(index, 1)
}

const handleOk = async () => {
	try {
		await formRef.value.validate()

		if (['select', 'radio', 'checkbox'].includes(formData.type) && formData.options.length === 0) {
			ElMessage.error('请至少添加一个选项')
			return
		}

		const submitData = { ...formData }
		// 清理内部字段，不提交给后端
		delete submitData._fileValue
		delete submitData._fileDefault

		if (['select', 'radio', 'checkbox'].includes(formData.type)) {
			submitData.options = JSON.stringify(formData.options)
		} else {
			submitData.options = null
		}

		if (formData.type === 'checkbox') {
			if (Array.isArray(submitData.value)) {
				submitData.value = JSON.stringify(submitData.value)
			}
			if (Array.isArray(submitData.default_value)) {
				submitData.default_value = JSON.stringify(submitData.default_value)
			}
		}

		submitLoading.value = true

		let res
		if (isEdit.value) {
			res = await systemApi.config.edit.put(props.record.id, submitData)
		} else {
			res = await systemApi.config.add.post(submitData)
		}

		if (res.code === 200) {
			ElMessage.success(isEdit.value ? '编辑成功' : '新增成功')
			emit('success')
		} else {
			ElMessage.error(res.message || (isEdit.value ? '编辑失败' : '新增失败'))
		}
	} catch (error) {
		if (error !== false) {
			console.error(isEdit.value ? '编辑配置失败:' : '新增配置失败:', error)
			ElMessage.error(isEdit.value ? '编辑失败' : '新增失败')
		}
	} finally {
		submitLoading.value = false
	}
}

const handleCancel = () => {
	emit('update:visible', false)
}
</script>

<style scoped>
.options-editor {
	width: 100%;
}

.option-item {
	display: flex;
	align-items: center;
	margin-bottom: 8px;
}

.form-tip {
	font-size: 12px;
	color: var(--el-text-color-placeholder);
	margin-top: 4px;
}

@media (max-width: 768px) {
	:deep(.el-dialog__body) {
		padding: 12px;
	}
	:deep(.el-form-item__label) {
		float: none;
		width: 100% !important;
		text-align: left;
		padding-bottom: 4px;
	}
	:deep(.el-form-item__content) {
		margin-left: 0 !important;
	}
	.option-item {
		flex-wrap: wrap;
		gap: 8px;
	}
	.option-item .el-input {
		flex: 1 1 calc(50% - 4px) !important;
		min-width: 0 !important;
	}
}
</style>
