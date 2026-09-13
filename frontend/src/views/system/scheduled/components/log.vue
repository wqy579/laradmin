<template>
	<el-drawer v-model="dialogVisible" :title="`执行日志 — ${taskName}`" :size="drawerSize" destroy-on-close>
		<div class="log-toolbar">
			<el-select v-model="logSearch.status" placeholder="状态筛选" clearable style="width: 140px" @change="search">
				<el-option label="成功" value="success" />
				<el-option label="失败" value="failed" />
				<el-option label="超时" value="timeout" />
			</el-select>
			<el-popconfirm title="确定清空所有日志吗？" @confirm="handleClearLogs">
				<template #reference>
					<el-button type="danger" size="small" plain>清空日志</el-button>
				</template>
			</el-popconfirm>
		</div>
		<sTable
			ref="tableRef"
			tableName="scheduled_log"
			:data="data"
			:columns="columns"
			:loading="loading"
			:total="total"
			:currentPage="paginationProps.currentPage"
			:pageSize="paginationProps.pageSize"
			:pageSizes="[20, 50, 100]"
			rowKey="id"
			height="100%"
			stripe
			@refresh="refresh"
			@pageChange="handlePageChange"
			@pageSizeChange="handlePageSizeChange"
		>
			<template #status_default="{ row }">
				<el-tag :type="logStatusType(row.status)" size="small" effect="dark" round>{{ logStatusLabel(row.status) }}</el-tag>
			</template>
			<template #execution_time_default="{ row }">
				<span :class="{ 'text-danger': row.execution_time > 10000 }">{{ row.execution_time ? row.execution_time + 'ms' : '—' }}</span>
			</template>
			<template #output_default="{ row }">
				<el-button v-if="row.output || row.error_message" type="primary" link size="small" @click="showOutput(row)">查看</el-button>
				<span v-else style="color: var(--el-text-color-placeholder)">—</span>
			</template>
		</sTable>

		<el-dialog v-model="outputVisible" title="执行输出" :width="outputDialogWidth" append-to-body>
			<div v-if="outputData.error_message" class="output-block output-block--error">
				<div class="output-block__title">错误信息</div>
				<pre class="output-pre">{{ outputData.error_message }}</pre>
			</div>
			<div v-if="outputData.output" class="output-block">
				<div class="output-block__title">输出内容</div>
				<pre class="output-pre">{{ outputData.output }}</pre>
			</div>
			<el-empty v-if="!outputData.output && !outputData.error_message" description="无输出内容" />
		</el-dialog>
	</el-drawer>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { ElMessage } from 'element-plus'
import systemApi from '@/api/system'
import { useTable } from '@/hooks/useTable'
import { useResponsive } from '@/hooks/useResponsive'

const props = defineProps({
	visible: Boolean,
	record: Object,
})
const emit = defineEmits(['update:visible'])

const { isMobile } = useResponsive()
const drawerSize = computed(() => (isMobile.value ? '100%' : '740px'))
const outputDialogWidth = computed(() => (isMobile.value ? '95%' : '620px'))

const dialogVisible = computed({
	get: () => props.visible,
	set: (v) => emit('update:visible', v),
})
const taskName = computed(() => props.record?.name || '')

const logSearch = ref({ status: '' })
const taskId = computed(() => props.record?.id)

const {
	tableRef,
	data,
	total,
	loading,
	paginationProps,
	refresh,
	search: doSearch,
	handlePageChange,
	handlePageSizeChange,
} = useTable({
	apiObj: {
		get: (params) => {
			if (!taskId.value) return Promise.resolve({ code: 200, data: { list: [], total: 0 } })
			return systemApi.scheduled.logs.get(taskId.value, { ...params, ...logSearch.value })
		},
	},
	searchForm: { status: '' },
	pageSize: 20,
	autoLoad: false,
})

const search = () => doSearch()

const columns = [
	{ prop: 'id', title: 'ID', width: 70 },
	{ prop: 'status', title: '状态', width: 90, align: 'center', slots: { default: 'status_default' } },
	{ prop: 'execution_time', title: '耗时', width: 100, align: 'center', slots: { default: 'execution_time_default' } },
	{ prop: 'started_at', title: '开始时间', width: 170 },
	{ prop: 'finished_at', title: '结束时间', width: 170 },
	{ prop: 'output', title: '输出', align: 'center', slots: { default: 'output_default' } },
]

const logStatusType = (s) => ({ success: 'success', failed: 'danger', timeout: 'warning', running: 'info' })[s] || 'info'
const logStatusLabel = (s) => ({ success: '成功', failed: '失败', timeout: '超时', running: '执行中' })[s] || s

const outputVisible = ref(false)
const outputData = ref({})
const showOutput = (row) => {
	outputData.value = row
	outputVisible.value = true
}

const handleClearLogs = async () => {
	try {
		await systemApi.scheduled.clearLogs.delete(taskId.value)
		ElMessage.success('日志已清空')
		refresh()
	} catch (e) {
		console.error(e)
	}
}

watch(
	() => props.visible,
	(v) => {
		if (v && taskId.value) {
			setTimeout(() => refresh(), 100)
		}
	},
)
</script>

<style scoped>
.log-toolbar {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 12px;
}
.text-danger {
	color: var(--el-color-danger);
	font-weight: 600;
}
.output-block {
	margin-bottom: 16px;
}
.output-block--error .output-block__title {
	color: var(--el-color-danger);
}
.output-block__title {
	font-weight: 600;
	font-size: 13px;
	margin-bottom: 8px;
	color: var(--el-text-color-primary);
}
.output-pre {
	max-height: 300px;
	overflow: auto;
	padding: 12px;
	background: var(--el-fill-color-lighter);
	border-radius: 6px;
	border: 1px solid var(--el-border-color-lighter);
	font-size: 12px;
	line-height: 1.6;
	white-space: pre-wrap;
	word-break: break-all;
	margin: 0;
}

@media (max-width: 768px) {
	.log-toolbar {
		flex-wrap: wrap;
		gap: 8px;
	}
	.log-toolbar .el-select {
		width: 100% !important;
	}
}
</style>
