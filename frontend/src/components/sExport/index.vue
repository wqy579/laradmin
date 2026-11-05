<template>
	<el-dialog v-model="visible" :title="title" :width="width" destroy-on-close append-to-body class="s-export-dialog" @closed="handleClosed">
		<div class="s-export-content">
			<div v-if="exportFields.length > 0" class="s-export-section">
				<div class="s-export-section__header">
					<span>选择导出字段</span>
					<el-checkbox v-model="selectAll" :indeterminate="isIndeterminate" @change="handleSelectAll">全选</el-checkbox>
				</div>
				<el-checkbox-group v-model="selectedFields" class="s-export-fields">
					<el-checkbox v-for="field in exportFields" :key="field.value" :value="field.value" :disabled="field.required">
						{{ field.label }}
					</el-checkbox>
				</el-checkbox-group>
				<div v-if="maxFields > 0 && selectedFields.length > maxFields" class="s-export-tip">
					<el-icon><ElIconWarning /></el-icon>
					最多只能选择 {{ maxFields }} 个字段
				</div>
			</div>
			<div v-if="localExport" class="s-export-section">
				<div class="s-export-section__header"><span>导出格式</span></div>
				<el-radio-group v-model="format">
					<el-radio v-for="f in FORMATS" :key="f.value" :value="f.value">{{ f.label }}</el-radio>
				</el-radio-group>
			</div>
		</div>

		<template #footer>
			<el-button @click="close">取消</el-button>
			<el-button type="primary" :loading="exporting" :disabled="disabled || !hasValidFields" @click="handleExport">
				{{ submitText }}
			</el-button>
		</template>
	</el-dialog>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { ElMessage } from 'element-plus'
import { exportReportJpg, exportReportPdf, exportReportXls } from '@/components/print-preview/reportPrintDoc'

defineOptions({ inheritAttrs: false })

const FORMATS = [
	{ value: 'xls', label: 'XLS（Excel）' },
	{ value: 'pdf', label: 'PDF' },
	{ value: 'jpg', label: 'JPG（图片）' },
]

const props = defineProps({
	title: { type: String, default: '数据导出' },
	width: { type: [String, Number], default: '480px' },
	apiMethod: { type: Function, required: true },
	exportFields: { type: Array, default: () => [] },
	defaultFields: { type: Array, default: () => [] },
	filters: { type: Object, default: () => ({}) },
	exportText: { type: String, default: '提交导出' },
	disabled: { type: Boolean, default: false },
	maxFields: { type: Number, default: 0 },
	/**
	 * 本地导出（XLS/PDF/JPG）三件套：三者都传才启用「导出格式」选择。
	 * 不传则保持原有行为——勾选字段后提交后端异步导出任务，
	 * 已有的用户/分析等页面调用不受影响。
	 */
	docTitle: { type: String, default: '' },
	columns: { type: Array, default: () => [] },
	rows: { type: Array, default: () => [] },
})

const emit = defineEmits(['success', 'error', 'closed'])

const visible = ref(false)
const selectedFields = ref([])
const exporting = ref(false)
const selectAll = ref(false)
const isIndeterminate = ref(false)
const format = ref('xls')

/** 传齐 docTitle/columns/rows 才走本地导出，否则维持后端异步任务 */
const localExport = computed(() => !!props.docTitle && props.columns.length > 0)

const submitText = computed(() => {
	if (exporting.value) return localExport.value ? '导出中...' : '提交中...'
	return localExport.value ? '立即导出' : props.exportText
})

const requiredFields = computed(() => {
	return props.exportFields.filter((f) => f.required).map((f) => f.value)
})

const hasValidFields = computed(() => {
	if (props.exportFields.length > 0) {
		if (props.maxFields > 0) {
			return selectedFields.value.length > 0 && selectedFields.value.length <= props.maxFields
		}
		return selectedFields.value.length > 0
	}
	return true
})

watch(
	() => selectedFields.value.length,
	(len) => {
		const totalLen = props.exportFields.length
		selectAll.value = len === totalLen
		isIndeterminate.value = len > 0 && len < totalLen
	},
)

function open() {
	visible.value = true
	resetState()
}

function close() {
	visible.value = false
}

function resetState() {
	selectedFields.value = [...props.defaultFields, ...requiredFields.value]
	exporting.value = false
	updateSelectAllState()
}

function updateSelectAllState() {
	const len = selectedFields.value.length
	const totalLen = props.exportFields.length
	selectAll.value = len === totalLen && totalLen > 0
	isIndeterminate.value = len > 0 && len < totalLen
}

function handleSelectAll(checked) {
	if (checked) {
		selectedFields.value = props.exportFields.map((f) => f.value)
	} else {
		selectedFields.value = [...requiredFields.value]
	}
}

/** 勾选的字段决定导出哪几列；没提供字段勾选时全列 */
const pickedColumns = computed(() => {
	if (!props.exportFields.length) return props.columns
	const set = new Set(selectedFields.value)
	const picked = props.columns.filter((c) => set.has(c.prop))
	return picked.length ? picked : props.columns
})

function docOptions() {
	return {
		title: props.docTitle || props.title,
		columns: props.columns,
		rows: props.rows,
		picked: pickedColumns.value,
	}
}

async function handleLocalExport() {
	if (!props.rows.length) {
		ElMessage.warning('当前没有可导出的数据')
		return
	}
	exporting.value = true
	try {
		const options = docOptions()
		if (format.value === 'pdf') await exportReportPdf(options)
		else if (format.value === 'jpg') await exportReportJpg(options)
		else exportReportXls(options)
		ElMessage.success('导出完成')
		emit('success', { format: format.value })
		close()
	} catch (error) {
		console.error('导出失败:', error)
		ElMessage.error(error.message || '导出失败')
		emit('error', error)
	} finally {
		exporting.value = false
	}
}

async function handleExport() {
	if (props.exportFields.length > 0 && selectedFields.value.length === 0) {
		ElMessage.warning('请至少选择一个导出字段')
		return
	}

	if (props.maxFields > 0 && selectedFields.value.length > props.maxFields) {
		ElMessage.warning(`最多只能选择 ${props.maxFields} 个字段`)
		return
	}

	if (localExport.value) return handleLocalExport()

	exporting.value = true

	try {
		const params = {
			fields: selectedFields.value,
			filters: props.filters,
		}

		const result = await props.apiMethod(params)

		if (result?.code === 200) {
			ElMessage.success({
				message: '导出任务已提交，完成后将通过系统通知发送下载链接',
				duration: 3000,
			})
			emit('success', result.data)
			close()
		}
	} catch (error) {
		console.error('导出失败:', error)
		ElMessage.error(error.message || '导出失败')
		emit('error', error)
	} finally {
		exporting.value = false
	}
}

function handleClosed() {
	emit('closed')
}

defineExpose({ open, close })
</script>

<style scoped>
.s-export-content {
	display: flex;
	flex-direction: column;
	gap: 20px;
}

.s-export-section {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.s-export-section__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	font-weight: 500;
	color: var(--el-text-color-primary);
}

.s-export-fields {
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
	padding: 12px;
	background: var(--el-fill-color-lighter);
	border-radius: 4px;
}

.s-export-tip {
	display: flex;
	align-items: center;
	gap: 6px;
	font-size: 12px;
	color: var(--el-color-warning);
}

@media (max-width: 768px) {
	.s-export-fields {
		padding: 8px;
	}
}
</style>
