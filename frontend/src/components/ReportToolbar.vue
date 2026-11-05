<template>
	<div class="rt-group">
		<el-button size="small" @click="onPreview">预览</el-button>
		<el-button size="small" :loading="printing" @click="onPrint">打印</el-button>
		<el-button size="small" :loading="exporting" @click="$emit('xls')">导出XLS</el-button>
		<el-button size="small" :loading="pdfing" @click="onPdf">导出PDF</el-button>
		<el-button size="small" :loading="jpging" @click="onJpg">导出JPG</el-button>
		<el-dropdown trigger="click" @command="onCommand">
			<el-button size="small">
				报表打印设置<el-icon style="margin-left: 4px"><ArrowDown /></el-icon>
			</el-button>
			<template #dropdown>
				<el-dropdown-menu>
					<el-dropdown-item command="all">报表设置</el-dropdown-item>
					<el-dropdown-item command="print">打印设置</el-dropdown-item>
					<el-dropdown-item command="paper">纸张设置</el-dropdown-item>
				</el-dropdown-menu>
			</template>
		</el-dropdown>

		<PrintPreview
			v-model="previewVisible"
			:title="`${title} - 打印预览`"
			:doc-html="previewHtml"
			:rebuild="rebuildPreview"
		/>
		<PrintSettingsDialog v-model="settingsVisible" :mode="settingsMode" @saved="onSettingsSaved" />
	</div>
</template>

<script setup>
import { ref } from 'vue'
import { ElMessage } from 'element-plus'
import { ArrowDown } from '@element-plus/icons-vue'
import PrintPreview from './print-preview/index.vue'
import PrintSettingsDialog from './print-preview/PrintSettingsDialog.vue'
import { loadPrintSettings } from './print-preview/printSettings'
import { buildReportDocHtml, exportReportJpg, exportReportPdf, printReportDoc } from './print-preview/reportPrintDoc'

const props = defineProps({
	/** 报表标题，落到打印抬头与导出文件名 */
	title: { type: String, default: '报表' },
	/** 列定义（与 ReportTable 用的同一份：{ prop, label, type }） */
	columns: { type: Array, default: () => [] },
	/** 当前表格数据 */
	rows: { type: Array, default: () => [] },
	/** 后端 XLS 导出进行中标记（由页面持有） */
	exporting: { type: Boolean, default: false },
})
const emit = defineEmits(['xls'])

const printing = ref(false)
const pdfing = ref(false)
const jpging = ref(false)
const previewVisible = ref(false)
const previewHtml = ref('')
const settingsVisible = ref(false)
const settingsMode = ref('all')

// 预览用当前生效的设置快照；改设置后由 rebuild 重新生成
const previewSettings = ref(loadPrintSettings())

function ensureData() {
	if (!props.columns.length) {
		ElMessage.warning('当前没有可打印的数据')
		return false
	}
	return true
}

function docOptions(settings = previewSettings.value) {
	return { title: props.title, columns: props.columns, rows: props.rows, settings }
}

function onPreview() {
	if (!ensureData()) return
	previewSettings.value = loadPrintSettings()
	previewHtml.value = buildReportDocHtml(docOptions())
	previewVisible.value = true
}

/** 预览弹窗里改设置后重渲染（PrintPreview 传公司名 + 完整设置） */
function rebuildPreview(companyName, settings) {
	if (settings) previewSettings.value = settings
	previewHtml.value = buildReportDocHtml(docOptions(previewSettings.value))
}

function onPrint() {
	if (!ensureData()) return
	printing.value = true
	try {
		printReportDoc(docOptions(loadPrintSettings()))
	} finally {
		printing.value = false
	}
}

async function onPdf() {
	if (!ensureData()) return
	pdfing.value = true
	try {
		await exportReportPdf(docOptions(loadPrintSettings()))
	} catch (e) {
		ElMessage.error(e?.message || '导出 PDF 失败')
	} finally {
		pdfing.value = false
	}
}

async function onJpg() {
	if (!ensureData()) return
	jpging.value = true
	try {
		await exportReportJpg(docOptions(loadPrintSettings()))
	} catch (e) {
		ElMessage.error(e?.message || '导出 JPG 失败')
	} finally {
		jpging.value = false
	}
}

function onCommand(command) {
	settingsMode.value = command
	settingsVisible.value = true
}

/** 改完设置：预览若开着就即时按新纸张/模板重画 */
function onSettingsSaved(settings) {
	previewSettings.value = settings
	if (previewVisible.value) previewHtml.value = buildReportDocHtml(docOptions(settings))
}
</script>

<style scoped>
.rt-group {
	display: inline-flex;
	align-items: center;
	gap: 8px;
}
.rt-group .el-button {
	height: 24px;
	padding: 0 8px;
	font-size: 12px;
}
</style>
