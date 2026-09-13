<template>
	<div class="s-upload-image" @paste.capture="handlePaste">
		<!-- 单图模式 -->
		<template v-if="maxCount === 1">
			<div v-if="singleFile" class="s-upload-single" @click="triggerReplace">
				<img :src="singleFile.url" class="s-upload-single__img" />
				<div class="s-upload-single__actions">
					<el-icon :size="20" @click.stop="handlePreview(singleFile)"><ElIconZoomIn /></el-icon>
					<el-icon :size="20" @click.stop="handleSingleRemove"><ElIconDelete /></el-icon>
				</div>
			</div>
			<div v-else class="s-upload-single s-upload-single--empty" @click="triggerSelect">
				<el-icon :size="28"><ElIconPlus /></el-icon>
				<span class="s-upload-single__text">上传图片</span>
			</div>
			<input ref="singleInputRef" type="file" class="s-upload-image__hidden-input" :accept="computedAccept" @change="handleSingleChange" />
		</template>

		<!-- 多图模式 -->
		<template v-else>
			<el-upload v-model:file-list="internalFileList" action="" :http-request="handleHttpRequest" :before-upload="beforeUpload" :on-exceed="handleExceed" :accept="computedAccept" :limit="maxCount" :disabled="disabled" :list-type="listType" :multiple="multiple">
				<template #default>
					<slot name="trigger">
						<el-icon v-if="listType === 'picture-card'"><ElIconPlus /></el-icon>
						<div v-else class="s-upload-image__drag-text">
							<el-icon><ElIconUpload /></el-icon>
							<span>点击或拖拽上传</span>
						</div>
					</slot>
				</template>
				<template v-if="tip" #tip>
					<div class="el-upload__tip">{{ tip }}</div>
				</template>
			</el-upload>
		</template>

		<el-dialog v-model="previewVisible" title="图片预览" append-to-body destroy-on-close>
			<img :src="previewUrl" alt="预览" style="width: 100%" />
		</el-dialog>
	</div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { ElMessage } from 'element-plus'
import systemApi from '@/api/system'
import { IMAGE_EXTENSIONS, MAX_IMAGE_SIZE, validateExtension, validateSize } from '@/config/upload'

defineOptions({ inheritAttrs: false })

const props = defineProps({
	modelValue: { type: Array, default: () => [] },
	accept: { type: String, default: '' },
	maxSize: { type: Number, default: MAX_IMAGE_SIZE },
	maxCount: { type: Number, default: 9 },
	multiple: { type: Boolean, default: false },
	disabled: { type: Boolean, default: false },
	listType: { type: String, default: 'picture-card' },
	paste: { type: Boolean, default: true },
	tip: { type: String, default: '' },
	customUpload: { type: Function, default: null },
})

const emit = defineEmits(['update:modelValue', 'success', 'error', 'progress', 'remove', 'exceed', 'preview'])

const computedAccept = computed(() => props.accept || 'image/*')

const internalFileList = ref([])
const previewVisible = ref(false)
const previewUrl = ref('')

// 单图模式
const singleInputRef = ref(null)
const singleFile = ref(null)

// 从 modelValue 初始化
watch(
	() => props.modelValue,
	(val) => {
		if (props.maxCount === 1) {
			// 单图模式
			if (val.length > 0 && val[0]?.url) {
				if (singleFile.value?.url !== val[0].url) {
					singleFile.value = { uid: val[0].uid || -1, url: val[0].url, name: val[0].name || '', response: val[0] }
				}
			} else {
				singleFile.value = null
			}
		} else {
			// 多图模式
			const currentUrls = internalFileList.value
				.filter((f) => f.status === 'success' && f.response)
				.map((f) => f.response?.url)
				.join(',')
			const newUrls = val.map((f) => f.url).join(',')
			if (currentUrls !== newUrls) {
				internalFileList.value = val.map((item, i) => ({
					uid: item.uid || -(i + 1),
					name: item.name || item.file_name || '',
					url: item.url || '',
					status: 'success',
					response: item,
				}))
			}
		}
	},
	{ immediate: true, deep: true },
)

// 多图模式：文件列表变化时同步到 modelValue
watch(
	internalFileList,
	(list) => {
		const files = list.filter((f) => f.status === 'success' && f.response).map((f) => f.response)
		emit('update:modelValue', files)
	},
	{ deep: true },
)

function beforeUpload(file) {
	if (!validateExtension(file, IMAGE_EXTENSIONS)) {
		ElMessage.error(`不支持的图片格式，仅支持: ${IMAGE_EXTENSIONS.join(', ')}`)
		return false
	}
	if (!validateSize(file, props.maxSize)) {
		ElMessage.error(`图片大小不能超过 ${props.maxSize}MB`)
		return false
	}
	return true
}

async function uploadSingleFile(file) {
	try {
		let result
		if (props.customUpload) {
			result = await props.customUpload(file, (percent) => {
				emit('progress', { percent }, file)
			})
		} else {
			const formData = new FormData()
			formData.append('file', file)
			result = await systemApi.upload.post(formData, (e) => {
				const percent = Math.round((e.loaded / e.total) * 100)
				emit('progress', { percent }, file)
			})
		}
		const data = result.data
		singleFile.value = { uid: Date.now(), url: data.url, name: data.file_name || file.name, response: data }
		emit('update:modelValue', [data])
		emit('success', data)
	} catch (error) {
		emit('error', error, file)
		ElMessage.error('上传失败')
	}
}

function triggerSelect() {
	singleInputRef.value?.click()
}

function triggerReplace() {
	singleInputRef.value?.click()
}

function handleSingleChange(e) {
	const file = e.target.files?.[0]
	if (!file) return
	if (!beforeUpload(file)) return
	uploadSingleFile(file)
	e.target.value = ''
}

function handleSingleRemove() {
	singleFile.value = null
	emit('update:modelValue', [])
	emit('remove')
}

async function handleHttpRequest({ file, onProgress, onSuccess, onError }) {
	try {
		let result
		if (props.customUpload) {
			result = await props.customUpload(file, (percent) => {
				onProgress({ percent })
				emit('progress', { percent }, file)
			})
		} else {
			const formData = new FormData()
			formData.append('file', file)
			result = await systemApi.upload.post(formData, (e) => {
				const percent = Math.round((e.loaded / e.total) * 100)
				onProgress({ percent })
				emit('progress', { percent }, file)
			})
		}
		onSuccess(result.data)
		emit('success', result.data, internalFileList.value)
	} catch (error) {
		onError(error)
		emit('error', error, file)
	}
}

function handleExceed(files) {
	ElMessage.warning(`最多只能上传 ${props.maxCount} 张图片`)
	emit('exceed', files, internalFileList.value)
}

function handlePreview(file) {
	const url = file?.url || file?.response?.url
	if (url) {
		previewUrl.value = url
		previewVisible.value = true
	}
	emit('preview', file)
}

function handleRemove(file) {
	emit('remove', file, internalFileList.value)
}

// 粘贴上传
function handlePaste(e) {
	if (!props.paste || props.disabled) return
	const items = e.clipboardData?.items
	if (!items) return

	for (const item of items) {
		if (item.type.startsWith('image/')) {
			e.preventDefault()
			const file = item.getAsFile()
			if (file && beforeUpload(file)) {
				if (props.maxCount === 1) {
					uploadSingleFile(file)
				} else {
					uploadPastedFile(file)
				}
			}
			break
		}
	}
}

async function uploadPastedFile(file) {
	if (internalFileList.value.length >= props.maxCount) {
		handleExceed([file])
		return
	}

	const fileItem = {
		uid: Date.now() + Math.random(),
		name: file.name || 'pasted-image.png',
		status: 'uploading',
		percentage: 0,
		raw: file,
	}
	internalFileList.value.push(fileItem)

	try {
		let result
		if (props.customUpload) {
			result = await props.customUpload(file, (percent) => {
				fileItem.percentage = percent
				emit('progress', { percent }, file)
			})
		} else {
			const formData = new FormData()
			formData.append('file', file)
			result = await systemApi.upload.post(formData, (e) => {
				const percent = Math.round((e.loaded / e.total) * 100)
				fileItem.percentage = percent
				emit('progress', { percent }, file)
			})
		}
		fileItem.status = 'success'
		fileItem.percentage = 100
		fileItem.response = result.data
		fileItem.url = result.data.url
		emit('success', result.data, internalFileList.value)
	} catch (error) {
		fileItem.status = 'fail'
		emit('error', error, file)
	}
}

onMounted(() => {
	if (props.paste) {
		document.addEventListener('paste', handlePaste)
	}
})

onUnmounted(() => {
	if (props.paste) {
		document.removeEventListener('paste', handlePaste)
	}
})
</script>

<style scoped>
.s-upload-image {
	width: 100%;
}

.s-upload-image__hidden-input {
	display: none;
}

.s-upload-image__drag-text {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	gap: 8px;
	color: var(--el-text-color-secondary);
	font-size: 14px;
}

/* 单图上传 */
.s-upload-single {
	position: relative;
	width: 120px;
	height: 120px;
	border-radius: 8px;
	overflow: hidden;
	border: 1px dashed var(--el-border-color-darker);
	cursor: pointer;
	transition: border-color 0.2s;
}

.s-upload-single:hover {
	border-color: var(--el-color-primary);
}

.s-upload-single__img {
	width: 100%;
	height: 100%;
	object-fit: cover;
}

.s-upload-single__actions {
	position: absolute;
	inset: 0;
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 16px;
	background: rgba(0, 0, 0, 0.5);
	opacity: 0;
	transition: opacity 0.2s;
	color: #fff;
}

.s-upload-single:hover .s-upload-single__actions {
	opacity: 1;
}

.s-upload-single--empty {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	gap: 6px;
	background: var(--el-fill-color-lighter);
	color: var(--el-text-color-secondary);
}

.s-upload-single--empty:hover {
	color: var(--el-color-primary);
}

.s-upload-single__text {
	font-size: 12px;
}

@media (max-width: 768px) {
	.s-upload-image :deep(.el-upload--picture-card) {
		width: min(100px, 22vw);
		height: min(100px, 22vw);
	}
	.s-upload-image :deep(.el-upload-list__item) {
		width: min(100px, 22vw);
		height: min(100px, 22vw);
	}
	.s-upload-single {
		width: 100px;
		height: 100px;
	}
}
</style>
