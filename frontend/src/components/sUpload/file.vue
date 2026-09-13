<template>
	<div class="s-upload-file">
		<!-- 单文件模式 -->
		<template v-if="maxCount === 1">
			<div v-if="singleFile" class="s-upload-single-file">
				<div class="s-upload-single-file__info">
					<el-icon class="s-upload-single-file__icon"><ElIconDocument /></el-icon>
					<span class="s-upload-single-file__name" :title="singleFile.name">{{ singleFile.name }}</span>
				</div>
				<div class="s-upload-single-file__actions">
					<el-button type="primary" link size="small" @click="triggerReplace">替换</el-button>
					<el-button type="danger" link size="small" @click="handleSingleRemove">删除</el-button>
				</div>
			</div>
			<div v-else>
				<div v-if="draggable" class="s-upload-file__dropzone" @click="triggerSelect" @dragover.prevent="isDragging = true" @dragleave.prevent="isDragging = false" @drop.prevent="handleSingleDrop">
					<el-icon class="s-upload-file__dropzone-icon"><ElIconUpload /></el-icon>
					<p class="s-upload-file__dropzone-text">将文件拖到此处，或<em>点击上传</em></p>
					<p v-if="tip" class="s-upload-file__dropzone-tip">{{ tip }}</p>
				</div>
				<div v-else class="s-upload-file__button">
					<el-button type="primary" @click="triggerSelect">
						<el-icon><ElIconUpload /></el-icon>
						选择文件
					</el-button>
					<span v-if="tip" class="s-upload-file__button-tip">{{ tip }}</span>
				</div>
			</div>
			<input ref="singleInputRef" type="file" class="s-upload-file__input" :accept="accept" @change="handleSingleChange" />
		</template>

		<!-- 多文件模式 -->
		<template v-else>
			<div v-if="draggable" class="s-upload-file__dropzone" :class="{ 's-upload-file__dropzone--active': isDragging, 's-upload-file__dropzone--disabled': disabled }" @click="triggerSelect" @dragover.prevent="isDragging = true" @dragleave.prevent="isDragging = false" @drop.prevent="handleDrop">
				<el-icon class="s-upload-file__dropzone-icon"><ElIconUpload /></el-icon>
				<p class="s-upload-file__dropzone-text">将文件拖到此处，或<em>点击上传</em></p>
				<p v-if="tip" class="s-upload-file__dropzone-tip">{{ tip }}</p>
			</div>
			<div v-else class="s-upload-file__button">
				<el-button type="primary" :disabled="disabled" @click="triggerSelect">
					<el-icon><ElIconUpload /></el-icon>
					选择文件
				</el-button>
				<span v-if="tip" class="s-upload-file__button-tip">{{ tip }}</span>
			</div>
			<input ref="inputRef" type="file" class="s-upload-file__input" :accept="accept" :multiple="multiple" :disabled="disabled" @change="handleInputChange" />
		</template>

		<!-- 多文件列表 -->
		<div v-if="maxCount !== 1 && fileStates.length" class="s-upload-file__list">
			<div v-for="item in fileStates" :key="item.uid" class="s-upload-file__item">
				<div class="s-upload-file__item-info">
					<el-icon class="s-upload-file__item-icon"><ElIconDocument /></el-icon>
					<span class="s-upload-file__item-name" :title="item.file.name">{{ item.file.name }}</span>
					<span class="s-upload-file__item-size">{{ formatFileSize(item.file.size) }}</span>
				</div>
				<div v-if="item.status === 'hashing'" class="s-upload-file__progress">
					<el-progress :percentage="item.hashProgress" :format="() => '校验中'" :stroke-width="4" />
				</div>
				<div v-else-if="item.status === 'uploading' || item.status === 'merging'" class="s-upload-file__progress">
					<el-progress :percentage="item.uploadProgress" :status="item.status === 'merging' ? '' : undefined" :stroke-width="4" :format="() => (item.status === 'merging' ? '合并中' : `${item.uploadProgress}%`)" />
					<span v-if="item.chunkTotal" class="s-upload-file__chunk-info">{{ item.chunkUploaded }} / {{ item.chunkTotal }} 分片</span>
				</div>
				<div class="s-upload-file__item-actions">
					<el-tag v-if="item.status === 'success'" type="success" size="small">已上传</el-tag>
					<el-tag v-else-if="item.status === 'error'" type="danger" size="small">失败</el-tag>
					<el-button v-if="item.status === 'error'" type="primary" link size="small" @click="retryUpload(item)">重试</el-button>
					<el-button v-if="item.status === 'uploading'" type="warning" link size="small" @click="cancelUpload(item)">取消</el-button>
					<el-button v-if="item.status === 'success' || item.status === 'error'" type="danger" link size="small" @click="removeFile(item)">删除</el-button>
				</div>
			</div>
		</div>
	</div>
</template>

<script setup>
import { ref, watch, onMounted, onUnmounted } from 'vue'
import { ElMessage } from 'element-plus'
import systemApi from '@/api/system'
import { CHUNK_SIZE, CHUNK_MAX_CONCURRENT, CHUNK_THRESHOLD, MAX_FILE_SIZE, MAX_CHUNK_FILE_SIZE, ALLOWED_EXTENSIONS, validateExtension, validateSize, formatFileSize, computeFileMD5 } from '@/config/upload'

defineOptions({ inheritAttrs: false })

const props = defineProps({
	modelValue: { type: Array, default: () => [] },
	accept: { type: String, default: '' },
	maxSize: { type: Number, default: MAX_FILE_SIZE },
	maxCount: { type: Number, default: 5 },
	multiple: { type: Boolean, default: false },
	disabled: { type: Boolean, default: false },
	draggable: { type: Boolean, default: true },
	paste: { type: Boolean, default: true },
	chunkSize: { type: Number, default: CHUNK_SIZE },
	chunkThreshold: { type: Number, default: CHUNK_THRESHOLD },
	concurrentChunks: { type: Number, default: CHUNK_MAX_CONCURRENT },
	tip: { type: String, default: '' },
	customUpload: { type: Function, default: null },
})

const emit = defineEmits(['update:modelValue', 'success', 'error', 'progress', 'remove', 'exceed', 'chunkProgress'])

const inputRef = ref(null)
const singleInputRef = ref(null)
const isDragging = ref(false)
const fileStates = ref([])
const abortControllers = new Map()

// 单文件模式
const singleFile = ref(null)

// 同步 modelValue（多文件模式）
watch(
	() => props.modelValue,
	(val) => {
		if (props.maxCount === 1) {
			// 单文件模式
			if (val.length > 0 && val[0]) {
				const item = val[0]
				if (singleFile.value?.response?.id !== item.id) {
					singleFile.value = {
						uid: item.uid || -1,
						name: item.name || item.file_name || '文件',
						response: item,
					}
				}
			} else {
				singleFile.value = null
			}
		} else {
			const currentIds = fileStates.value
				.filter((f) => f.status === 'success' && f.response)
				.map((f) => f.response?.id)
				.join(',')
			const newIds = val.map((f) => f.id).join(',')
			if (currentIds !== newIds) {
				fileStates.value = val.map((item, i) => ({
					uid: item.uid || -(i + 1),
					file: { name: item.name || item.file_name, size: item.size },
					status: 'success',
					uploadProgress: 100,
					response: item,
				}))
			}
		}
	},
	{ immediate: true, deep: true },
)

function emitUpdate() {
	const files = fileStates.value.filter((f) => f.status === 'success' && f.response).map((f) => f.response)
	emit('update:modelValue', files)
}

function triggerSelect() {
	if (props.maxCount === 1) {
		singleInputRef.value?.click()
	} else {
		inputRef.value?.click()
	}
}

function triggerReplace() {
	singleInputRef.value?.click()
}

function validateFile(file) {
	if (!validateExtension(file, ALLOWED_EXTENSIONS)) {
		ElMessage.error(`不支持的文件格式: ${file.name}`)
		emit('error', new Error('不支持的文件格式'), file)
		return false
	}
	const isLargeFile = file.size > props.chunkThreshold
	const maxSize = isLargeFile ? MAX_CHUNK_FILE_SIZE : props.maxSize
	if (!validateSize(file, maxSize)) {
		ElMessage.error(`文件大小超出限制: ${file.name}`)
		emit('error', new Error('文件大小超出限制'), file)
		return false
	}
	return true
}

// 单文件上传
async function uploadSingleFile(file) {
	if (!validateFile(file)) return

	const item = {
		uid: Date.now() + Math.random(),
		file,
		status: 'uploading',
		uploadProgress: 0,
		response: null,
	}

	// 临时显示上传状态
	singleFile.value = { uid: item.uid, name: file.name, uploading: true }

	try {
		let data
		if (props.customUpload) {
			const result = await props.customUpload(file, (percent) => {
				emit('progress', { percent }, file)
			})
			data = result.data || result
		} else if (file.size > props.chunkThreshold) {
			data = await uploadSingleWithChunks(file)
		} else {
			const formData = new FormData()
			formData.append('file', file)
			const result = await systemApi.upload.post(formData, (e) => {
				emit('progress', { percent: Math.round((e.loaded / e.total) * 100) }, file)
			})
			data = result.data
		}
		singleFile.value = { uid: item.uid, name: file.name, response: data }
		emit('update:modelValue', [data])
		emit('success', data)
	} catch (error) {
		singleFile.value = null
		emit('error', error, file)
		ElMessage.error('上传失败')
	}
}

async function uploadSingleWithChunks(file) {
	const hash = await computeFileMD5(file)
	const initResult = await systemApi.upload.initChunk({
		file_name: file.name,
		file_size: file.size,
		file_hash: hash,
		chunk_size: props.chunkSize,
	})
	if (initResult.data.uploaded) return initResult.data.data

	const { upload_id, total_chunks, uploaded_chunks } = initResult.data
	const pending = []
	for (let i = 0; i < total_chunks; i++) {
		if (!uploaded_chunks.includes(i)) pending.push(i)
	}

	const controller = new AbortController()
	abortControllers.set('single', controller)

	// 简单串行上传分片
	for (const idx of pending) {
		if (controller.signal.aborted) throw new Error('aborted')
		const start = idx * props.chunkSize
		const end = Math.min(start + props.chunkSize, file.size)
		const chunk = file.slice(start, end)
		const formData = new FormData()
		formData.append('upload_id', upload_id)
		formData.append('chunk_index', idx)
		formData.append('chunk', chunk)
		await systemApi.upload.uploadChunk(formData)
	}

	abortControllers.delete('single')
	const mergeResult = await systemApi.upload.mergeChunks({ upload_id })
	return mergeResult.data
}

function handleSingleChange(e) {
	const file = e.target.files?.[0]
	if (!file) return
	uploadSingleFile(file)
	e.target.value = ''
}

function handleSingleDrop(e) {
	isDragging.value = false
	if (props.disabled) return
	const file = e.dataTransfer.files?.[0]
	if (file) uploadSingleFile(file)
}

function handleSingleRemove() {
	singleFile.value = null
	emit('update:modelValue', [])
	emit('remove')
}

// 多文件模式
function handleInputChange(e) {
	const files = Array.from(e.target.files || [])
	if (files.length) processFiles(files)
	inputRef.value.value = ''
}

function handleDrop(e) {
	isDragging.value = false
	if (props.disabled) return
	const files = Array.from(e.dataTransfer.files || [])
	if (files.length) processFiles(files)
}

function handlePaste(e) {
	if (!props.paste || props.disabled) return
	const items = e.clipboardData?.items
	if (!items) return

	const files = []
	for (const item of items) {
		if (item.kind === 'file') {
			const file = item.getAsFile()
			if (file) files.push(file)
		}
	}
	if (files.length) {
		e.preventDefault()
		processFiles(files)
	}
}

function processFiles(files) {
	const currentCount = fileStates.value.filter((f) => f.status !== 'error').length
	const remaining = props.maxCount - currentCount
	if (remaining <= 0) {
		ElMessage.warning(`最多只能上传 ${props.maxCount} 个文件`)
		emit('exceed', files, fileStates.value)
		return
	}

	const toUpload = files.slice(0, remaining)
	if (toUpload.length < files.length) {
		ElMessage.warning(`已超出数量限制，仅上传前 ${toUpload.length} 个文件`)
		emit('exceed', files, fileStates.value)
	}

	for (const file of toUpload) {
		if (!validateFile(file)) continue
		addAndUpload(file)
	}
}

function addAndUpload(file) {
	const item = {
		uid: Date.now() + Math.random(),
		file,
		status: 'pending',
		uploadProgress: 0,
		hashProgress: 0,
		chunkTotal: 0,
		chunkUploaded: 0,
		response: null,
	}
	fileStates.value.push(item)
	uploadFile(item)
}

async function uploadFile(item) {
	if (props.customUpload) {
		await uploadWithCustom(item)
	} else if (item.file.size > props.chunkThreshold) {
		await uploadWithChunks(item)
	} else {
		await uploadDirect(item)
	}
}

async function uploadDirect(item) {
	item.status = 'uploading'
	const controller = new AbortController()
	abortControllers.set(item.uid, controller)

	try {
		const formData = new FormData()
		formData.append('file', item.file)
		const result = await systemApi.upload.post(formData, (e) => {
			item.uploadProgress = Math.round((e.loaded / e.total) * 100)
			emit('progress', { percent: item.uploadProgress }, item.file)
		})
		onUploadSuccess(item, result.data)
	} catch (error) {
		if (error.name !== 'CanceledError' && error.name !== 'AbortError') {
			onUploadError(item, error)
		}
	} finally {
		abortControllers.delete(item.uid)
	}
}

async function uploadWithCustom(item) {
	item.status = 'uploading'
	try {
		const result = await props.customUpload(item.file, (percent) => {
			item.uploadProgress = percent
			emit('progress', { percent }, item.file)
		})
		onUploadSuccess(item, result.data || result)
	} catch (error) {
		onUploadError(item, error)
	}
}

async function uploadWithChunks(item) {
	try {
		item.status = 'hashing'
		item.hashProgress = 0
		const hash = await computeFileMD5(item.file, (percent) => {
			item.hashProgress = percent
		})

		item.status = 'uploading'
		item.uploadProgress = 0
		const initResult = await systemApi.upload.initChunk({
			file_name: item.file.name,
			file_size: item.file.size,
			file_hash: hash,
			chunk_size: props.chunkSize,
		})

		if (initResult.data.uploaded) {
			onUploadSuccess(item, initResult.data.data)
			return
		}

		const { upload_id, total_chunks, uploaded_chunks } = initResult.data
		item.chunkTotal = total_chunks
		item.chunkUploaded = uploaded_chunks.length
		item.uploadProgress = Math.round((item.chunkUploaded / total_chunks) * 100)

		const pendingIndices = []
		for (let i = 0; i < total_chunks; i++) {
			if (!uploaded_chunks.includes(i)) pendingIndices.push(i)
		}

		const controller = new AbortController()
		abortControllers.set(item.uid, controller)
		await uploadChunksConcurrently(item, upload_id, pendingIndices, total_chunks, controller)
		abortControllers.delete(item.uid)

		if (controller.signal.aborted) return

		item.status = 'merging'
		item.uploadProgress = 100
		const mergeResult = await systemApi.upload.mergeChunks({ upload_id })
		onUploadSuccess(item, mergeResult.data)
	} catch (error) {
		if (error.name !== 'CanceledError' && error.name !== 'AbortError') {
			onUploadError(item, error)
		}
	}
}

async function uploadChunksConcurrently(item, uploadId, pendingIndices, totalChunks, controller) {
	let index = 0
	let activeCount = 0
	let resolve, reject
	let done = false

	const next = () => {
		while (index < pendingIndices.length && activeCount < props.concurrentChunks) {
			if (controller.signal.aborted) return
			const chunkIndex = pendingIndices[index++]
			activeCount++
			uploadSingleChunk(item, uploadId, chunkIndex, totalChunks, controller)
				.then(() => {
					activeCount--
					resolve()
				})
				.catch((err) => {
					activeCount--
					reject(err)
				})
		}
	}

	const promise = new Promise((res, rej) => {
		resolve = () => {
			if (done) return
			if (index >= pendingIndices.length && activeCount === 0) {
				done = true
				res()
			} else next()
		}
		reject = (err) => {
			if (!done) {
				done = true
				rej(err)
			}
		}
	})

	next()
	await promise
}

async function uploadSingleChunk(item, uploadId, chunkIndex, totalChunks, controller) {
	const start = chunkIndex * props.chunkSize
	const end = Math.min(start + props.chunkSize, item.file.size)
	const chunk = item.file.slice(start, end)

	const formData = new FormData()
	formData.append('upload_id', uploadId)
	formData.append('chunk_index', chunkIndex)
	formData.append('chunk', chunk)

	await systemApi.upload.uploadChunk(formData)
	item.chunkUploaded++
	item.uploadProgress = Math.round((item.chunkUploaded / totalChunks) * 100)
}

function onUploadSuccess(item, data) {
	item.status = 'success'
	item.uploadProgress = 100
	item.response = data
	emit('success', data, fileStates.value)
	emitUpdate()
}

function onUploadError(item, error) {
	item.status = 'error'
	emit('error', error, item.file)
}

function cancelUpload(item) {
	const controller = abortControllers.get(item.uid)
	if (controller) {
		controller.abort()
		abortControllers.delete(item.uid)
	}
	if (item.upload_id) {
		systemApi.upload.cancelChunk({ upload_id: item.upload_id }).catch(() => {})
	}
	item.status = 'error'
}

function retryUpload(item) {
	item.status = 'pending'
	item.uploadProgress = 0
	item.hashProgress = 0
	item.chunkTotal = 0
	item.chunkUploaded = 0
	uploadFile(item)
}

function removeFile(item) {
	const index = fileStates.value.findIndex((f) => f.uid === item.uid)
	if (index !== -1) {
		fileStates.value.splice(index, 1)
		emit('remove', item, fileStates.value)
		emitUpdate()
	}
}

onMounted(() => {
	if (props.paste) document.addEventListener('paste', handlePaste)
})

onUnmounted(() => {
	if (props.paste) document.removeEventListener('paste', handlePaste)
	for (const [uid, controller] of abortControllers) controller.abort()
	abortControllers.clear()
})
</script>

<style scoped>
.s-upload-file {
	width: 100%;
}

.s-upload-file__input {
	display: none;
}

.s-upload-file__dropzone {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	padding: 24px;
	border: 1px dashed var(--el-border-color-darker);
	border-radius: var(--el-border-radius-base);
	cursor: pointer;
	transition: border-color 0.2s;
	background-color: var(--el-fill-color-lighter);
}

.s-upload-file__dropzone:hover {
	border-color: var(--el-color-primary);
}

.s-upload-file__dropzone--active {
	border-color: var(--el-color-primary);
	background-color: var(--el-color-primary-light-9);
}

.s-upload-file__dropzone--disabled {
	cursor: not-allowed;
	opacity: 0.6;
}

.s-upload-file__dropzone-icon {
	font-size: 40px;
	color: var(--el-text-color-placeholder);
	margin-bottom: 8px;
}

.s-upload-file__dropzone-text {
	color: var(--el-text-color-regular);
	font-size: 14px;
	margin: 0;
}

.s-upload-file__dropzone-text em {
	color: var(--el-color-primary);
	font-style: normal;
}

.s-upload-file__dropzone-tip {
	color: var(--el-text-color-placeholder);
	font-size: 12px;
	margin: 4px 0 0;
}

.s-upload-file__button {
	display: flex;
	align-items: center;
	gap: 8px;
}

.s-upload-file__button-tip {
	color: var(--el-text-color-placeholder);
	font-size: 12px;
}

.s-upload-file__list {
	margin-top: 12px;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.s-upload-file__item {
	padding: 10px 12px;
	border: 1px solid var(--el-border-color-lighter);
	border-radius: var(--el-border-radius-base);
	background-color: var(--el-bg-color);
	transition: border-color 0.2s;
}

.s-upload-file__item:hover {
	border-color: var(--el-border-color);
}

.s-upload-file__item-info {
	display: flex;
	align-items: center;
	gap: 8px;
}

.s-upload-file__item-icon {
	color: var(--el-text-color-secondary);
	flex-shrink: 0;
}

.s-upload-file__item-name {
	flex: 1;
	font-size: 14px;
	color: var(--el-text-color-primary);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.s-upload-file__item-size {
	font-size: 12px;
	color: var(--el-text-color-secondary);
	flex-shrink: 0;
}

.s-upload-file__progress {
	margin-top: 8px;
}

.s-upload-file__chunk-info {
	font-size: 12px;
	color: var(--el-text-color-secondary);
	margin-left: 8px;
}

.s-upload-file__item-actions {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-top: 6px;
	justify-content: flex-end;
}

/* 单文件模式 */
.s-upload-single-file {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 8px 12px;
	border: 1px solid var(--el-border-color);
	border-radius: var(--el-border-radius-base);
	background: var(--el-bg-color);
}

.s-upload-single-file__info {
	display: flex;
	align-items: center;
	gap: 8px;
	flex: 1;
	min-width: 0;
}

.s-upload-single-file__icon {
	color: var(--el-color-primary);
	flex-shrink: 0;
}

.s-upload-single-file__name {
	font-size: 14px;
	color: var(--el-text-color-primary);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.s-upload-single-file__actions {
	display: flex;
	align-items: center;
	gap: 4px;
	flex-shrink: 0;
}

@media (max-width: 768px) {
	.s-upload-file__dropzone {
		padding: 16px;
	}
	.s-upload-file__item-info {
		flex-direction: column;
		align-items: flex-start;
	}
	.s-upload-single-file {
		flex-wrap: wrap;
		gap: 8px;
	}
	.s-upload-single-file__actions {
		width: 100%;
		justify-content: flex-end;
	}
}
</style>
