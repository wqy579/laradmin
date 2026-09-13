<template>
	<el-dialog v-model="visible" :title="title" :width="width" destroy-on-close append-to-body class="s-import-dialog" @closed="handleClosed">
		<div class="s-import-content">
			<div v-if="showTemplate && (templateUrl || templateApi)" class="s-import-template">
				<el-button link type="primary" @click="handleDownloadTemplate">
					<el-icon><ElIconDownload /></el-icon>
					{{ templateText }}
				</el-button>
			</div>

			<el-upload ref="uploadRef" v-model:file-list="fileList" class="s-import-upload" :action="''" :auto-upload="false" :accept="accept" :disabled="disabled || uploading" :limit="1" :on-change="handleFileChange" :on-remove="handleFileRemove" drag>
				<div class="s-import-upload__content">
					<el-icon class="s-import-upload__icon"><ElIconUpload /></el-icon>
					<div class="s-import-upload__text">{{ uploadText }}</div>
					<div class="s-import-upload__tip">支持拖拽上传，文件大小不超过 {{ maxSize }}MB</div>
				</div>
			</el-upload>

			<el-progress v-if="uploading && uploadPercent > 0" :percentage="uploadPercent" :stroke-width="8" class="s-import-progress" />

			<slot name="form" :form-data="customFormData" />
		</div>

		<template #footer>
			<el-button @click="close">取消</el-button>
			<el-button type="primary" :loading="uploading" :disabled="!selectedFile" @click="handleImport">
				{{ importing ? '导入中...' : importText }}
			</el-button>
		</template>
	</el-dialog>
</template>

<script setup>
import { ref, computed } from 'vue'
import { ElMessage } from 'element-plus'
import { downloadFromUrl, downloadApiResponse } from '@/utils/download'

defineOptions({ inheritAttrs: false })

const props = defineProps({
	title: { type: String, default: '数据导入' },
	width: { type: [String, Number], default: '520px' },
	apiMethod: { type: Function, required: true },
	templateUrl: { type: String, default: '' },
	templateApi: { type: Function, default: null },
	formData: { type: Object, default: () => ({}) },
	maxSize: { type: Number, default: 10 },
	accept: { type: String, default: '.xlsx,.xls,.csv' },
	uploadText: { type: String, default: '上传文件' },
	importText: { type: String, default: '开始导入' },
	showTemplate: { type: Boolean, default: true },
	templateText: { type: String, default: '下载模板' },
	disabled: { type: Boolean, default: false },
})

const emit = defineEmits(['success', 'error', 'progress', 'closed'])

const visible = ref(false)
const uploadRef = ref(null)
const fileList = ref([])
const selectedFile = ref(null)
const uploading = ref(false)
const importing = ref(false)
const uploadPercent = ref(0)
const customFormData = ref({})

function open() {
	visible.value = true
	resetState()
}

function close() {
	visible.value = false
}

function resetState() {
	fileList.value = []
	selectedFile.value = null
	uploading.value = false
	importing.value = false
	uploadPercent.value = 0
	customFormData.value = {}
}

function handleFileChange(file) {
	const rawFile = file.raw
	if (!rawFile) return

	const maxSizeBytes = props.maxSize * 1024 * 1024
	if (rawFile.size > maxSizeBytes) {
		ElMessage.error(`文件大小不能超过 ${props.maxSize}MB`)
		fileList.value = []
		return
	}

	selectedFile.value = rawFile
}

function handleFileRemove() {
	selectedFile.value = null
	uploadPercent.value = 0
}

async function handleDownloadTemplate() {
	try {
		if (props.templateApi) {
			const response = await props.templateApi()
			downloadApiResponse(response, 'template.xlsx')
		} else if (props.templateUrl) {
			downloadFromUrl(props.templateUrl, 'template.xlsx')
		}
	} catch (error) {
		console.error('下载模板失败:', error)
		ElMessage.error('下载模板失败')
	}
}

async function handleImport() {
	if (!selectedFile.value) {
		ElMessage.warning('请先选择要导入的文件')
		return
	}

	uploading.value = true
	importing.value = true
	uploadPercent.value = 0

	try {
		const formData = new FormData()
		formData.append('file', selectedFile.value)

		Object.keys(props.formData).forEach((key) => {
			formData.append(key, props.formData[key])
		})

		Object.keys(customFormData.value).forEach((key) => {
			formData.append(key, customFormData.value[key])
		})

		const result = await props.apiMethod(formData, (progress) => {
			uploadPercent.value = progress.percent || 0
			emit('progress', progress)
		})

		if (result?.code === 200) {
			ElMessage.success(result.message || '导入成功')
			emit('success', result.data)
			close()
		}
	} catch (error) {
		console.error('导入失败:', error)
		ElMessage.error(error.message || '导入失败')
		emit('error', error)
	} finally {
		uploading.value = false
		importing.value = false
	}
}

function handleClosed() {
	emit('closed')
}

defineExpose({ open, close })
</script>

<style scoped>
.s-import-content {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.s-import-template {
	display: flex;
	justify-content: flex-end;
}

.s-import-upload {
	width: 100%;
}

.s-import-upload :deep(.el-upload) {
	width: 100%;
}

.s-import-upload :deep(.el-upload-dragger) {
	width: 100%;
	padding: 32px;
	display: flex;
	flex-direction: column;
	align-items: center;
}

.s-import-upload__content {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 8px;
}

.s-import-upload__icon {
	font-size: 48px;
	color: var(--el-color-primary);
}

.s-import-upload__text {
	font-size: 14px;
	color: var(--el-text-color-regular);
}

.s-import-upload__tip {
	font-size: 12px;
	color: var(--el-text-color-secondary);
}

.s-import-progress {
	margin-top: 8px;
}

@media (max-width: 768px) {
	.s-import-upload :deep(.el-upload-dragger) {
		padding: 24px;
	}

	.s-import-upload__icon {
		font-size: 36px;
	}
}
</style>
