<template>
	<sPageSplit side-title="日志筛选" :side-width="'220px'">
		<template #side>
			<div class="filter-side">
				<div class="filter-side__body">
					<el-tree v-if="filterTreeData.length > 0" v-model:currentKey="currentTreeKey" :data="filterTreeData" :props="{ label: 'title', children: 'children' }" node-key="key" highlight-current default-expand-all @node-click="onTreeSelect">
						<template #default="{ data }">
							<el-icon v-if="data.type === 'method'" style="margin-right: 4px"><ElIconConnection /></el-icon>
							<el-icon v-else-if="data.type === 'status' && data.key === 'success'" style="margin-right: 4px; color: var(--el-color-success)"><ElIconCircleCheck /></el-icon>
							<el-icon v-else-if="data.type === 'status' && data.key === 'error'" style="margin-right: 4px; color: var(--el-color-danger)"><ElIconCircleClose /></el-icon>
							<span>{{ data.title }}</span>
						</template>
					</el-tree>
				</div>
			</div>
		</template>

		<!-- 统计卡片 -->
		<div class="statistics-cards">
			<div class="stat-card">
				<el-icon style="color: var(--el-color-primary); font-size: 24px"><ElIconDocument /></el-icon>
				<div class="stat-info">
					<div class="stat-value">{{ statistics.total || 0 }}</div>
					<div class="stat-label">总日志数</div>
				</div>
			</div>
			<div class="stat-card">
				<el-icon style="color: var(--el-color-success); font-size: 24px"><ElIconCircleCheck /></el-icon>
				<div class="stat-info">
					<div class="stat-value">{{ statistics.success || 0 }}</div>
					<div class="stat-label">成功数</div>
				</div>
			</div>
			<div class="stat-card">
				<el-icon style="color: var(--el-color-danger); font-size: 24px"><ElIconCircleClose /></el-icon>
				<div class="stat-info">
					<div class="stat-value">{{ statistics.error || 0 }}</div>
					<div class="stat-label">失败数</div>
				</div>
			</div>
			<div class="stat-card">
				<el-icon style="color: var(--el-color-warning); font-size: 24px"><ElIconClock /></el-icon>
				<div class="stat-info">
					<div class="stat-value">{{ statistics.avg_time || 0 }}ms</div>
					<div class="stat-label">平均响应时间</div>
				</div>
			</div>
		</div>

		<!-- 工具栏 -->
		<div class="tool-bar">
			<div class="left-panel">
				<el-date-picker v-model="dateRange" type="daterange" range-separator="至" start-placeholder="开始时间" end-placeholder="结束时间" value-format="YYYY-MM-DD HH:mm:ss" style="width: 280px" @change="handleDateChange" />
				<el-button type="primary" @click="search">
					<el-icon><ElIconSearch /></el-icon>
					搜索
				</el-button>
				<el-button @click="handleLogReset">
					<el-icon><ElIconRefreshRight /></el-icon>
					清除
				</el-button>
			</div>
			<div class="right-panel">
				<el-dropdown>
					<el-button>
						更多
						<el-icon><ElIconArrowDown /></el-icon>
					</el-button>
					<template #dropdown>
						<el-dropdown-menu>
							<el-dropdown-item @click="handleClearLogs">
								<el-icon><ElIconDelete /></el-icon>
								清理日志
							</el-dropdown-item>
							<el-dropdown-item @click="handleRefreshStats">
								<el-icon><ElIconRefresh /></el-icon>
								刷新统计
							</el-dropdown-item>
						</el-dropdown-menu>
					</template>
				</el-dropdown>
			</div>
		</div>
		<div class="table-content">
			<sTable
				ref="tableRef"
				tableName="system_log"
				:data="data"
				:columns="columns"
				:loading="loading"
				:total="total"
				:currentPage="paginationProps.currentPage"
				:pageSize="paginationProps.pageSize"
				:pageSizes="paginationProps.pageSizes"
				rowKey="id"
				height="100%"
				stripe
				@refresh="refresh"
				@pageChange="handlePageChange"
				@pageSizeChange="handlePageSizeChange"
			>
				<template #status_default="{ row }">
					<el-tag :type="row.status === 'success' ? 'success' : 'danger'" size="small">
						{{ row.status === 'success' ? '成功' : '失败' }}
					</el-tag>
				</template>
				<template #method_default="{ row }">
					<el-tag :type="getMethodType(row.method)" size="small">
						{{ row.method }}
					</el-tag>
				</template>
				<template #execution_time_default="{ row }">
					<span :style="{ color: getExecTimeColor(row.execution_time) }"> {{ row.execution_time }}ms </span>
				</template>
				<template #action_default="{ row }">
					<el-button type="primary" link size="small" @click="handleView(row)">查看详情</el-button>
					<el-popconfirm title="确定删除该日志吗？" @confirm="handleDelete(row)">
						<template #reference>
							<el-button type="danger" link size="small">删除</el-button>
						</template>
					</el-popconfirm>
				</template>
			</sTable>
		</div>
	</sPageSplit>

	<!-- 日志详情弹窗 -->
	<DetailDialog ref="detailDialogRef" />

	<!-- 清理日志确认弹窗 -->
	<el-dialog v-model="dialog.clear" title="清理日志" width="460px">
		<el-form label-position="top">
			<el-form-item label="清理天数">
				<el-input-number v-model="clearDays" :min="1" :max="365" style="width: 100%" placeholder="请输入天数" controls-position="right" />
			</el-form-item>
			<el-alert title="删除指定天数之前的日志记录，此操作不可恢复" type="warning" show-icon :closable="false" />
		</el-form>
		<template #footer>
			<el-button @click="dialog.clear = false">取消</el-button>
			<el-button type="primary" :loading="clearLoading" @click="handleConfirmClear">确定</el-button>
		</template>
	</el-dialog>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import systemApi from '@/api/system'
import { useTable } from '@/hooks/useTable'
import sPageSplit from '@/components/sPageSplit/index.vue'
import DetailDialog from './components/detail.vue'

// 统计数据
const statistics = ref({
	total: 0,
	success: 0,
	error: 0,
	avg_time: 0,
})

const { tableRef, data, total, loading, searchForm, paginationProps, refresh, search, handlePageChange, handlePageSizeChange } = useTable({
	apiObj: { get: (params) => systemApi.log.list.get(params) },
	searchForm: {
		method: '',
		status: '',
		start_date: '',
		end_date: '',
	},
})

const dialog = reactive({ clear: false })
const detailDialogRef = ref(null)
const clearDays = ref(30)
const clearLoading = ref(false)
const dateRange = ref([])
const currentTreeKey = ref(null)

// 树形筛选数据
const filterTreeData = ref([
	{
		title: '请求方式',
		key: 'method-root',
		children: [
			{ title: 'GET', key: 'GET', type: 'method' },
			{ title: 'POST', key: 'POST', type: 'method' },
			{ title: 'PUT', key: 'PUT', type: 'method' },
			{ title: 'DELETE', key: 'DELETE', type: 'method' },
			{ title: 'PATCH', key: 'PATCH', type: 'method' },
		],
	},
	{
		title: '返回状态',
		key: 'status-root',
		children: [
			{ title: '成功', key: 'success', type: 'status' },
			{ title: '失败', key: 'error', type: 'status' },
		],
	},
])

const getMethodType = (method) => {
	const types = { GET: '', POST: 'success', PUT: 'warning', DELETE: 'danger', PATCH: 'info' }
	return types[method] || 'info'
}

const getExecTimeColor = (time) => {
	if (time > 1000) return 'var(--el-color-danger)'
	if (time > 500) return 'var(--el-color-warning)'
	return 'var(--el-color-success)'
}

const columns = [
	{ prop: 'id', title: 'ID', width: 80 },
	{ prop: 'username', title: '用户名', width: 120 },
	{ prop: 'module', title: '模块', width: 120 },
	{ prop: 'action', title: '操作', width: 150 },
	{ prop: 'method', title: '请求方式', width: 100, align: 'center', slots: { default: 'method_default' } },
	{ prop: 'url', title: 'URL', showOverflowTooltip: true },
	{ prop: 'ip', title: 'IP地址', width: 140 },
	{ prop: 'status_code', title: '状态码', width: 100, align: 'center' },
	{ prop: 'status', title: '状态', width: 100, align: 'center', slots: { default: 'status_default' } },
	{ prop: 'execution_time', title: '执行时间', width: 120, align: 'center', slots: { default: 'execution_time_default' } },
	{ prop: 'created_at', title: '创建时间', width: 180 },
	{ prop: 'action_col', title: '操作', width: 150, align: 'center', fixed: 'right', slots: { default: 'action_default' } },
]

// 加载统计数据
const loadStatistics = async () => {
	try {
		const res = await systemApi.log.statistics.get(searchForm)
		if (res.code === 200) {
			statistics.value = res.data
		}
	} catch (error) {
		console.error('加载统计数据失败:', error)
	}
}

// 树形选择事件
const onTreeSelect = (nodeData) => {
	searchForm.method = ''
	searchForm.status = ''

	if (nodeData.type === 'method') {
		searchForm.method = nodeData.key
	} else if (nodeData.type === 'status') {
		searchForm.status = nodeData.key
	}

	search()
	loadStatistics()
}

// 日期变化
const handleDateChange = (dates) => {
	if (dates && dates.length === 2) {
		searchForm.start_date = dates[0]
		searchForm.end_date = dates[1]
	} else {
		searchForm.start_date = ''
		searchForm.end_date = ''
	}
	search()
	loadStatistics()
}

// 重置
const handleLogReset = () => {
	dateRange.value = []
	searchForm.start_date = ''
	searchForm.end_date = ''
	searchForm.method = ''
	searchForm.status = ''
	currentTreeKey.value = null
	search()
	loadStatistics()
}

// 查看详情
const handleView = (row) => {
	detailDialogRef.value?.open(row)
}

// 删除日志
const handleDelete = async (row) => {
	try {
		await systemApi.log.delete.delete(row.id)
		ElMessage.success('删除成功')
		refresh()
		loadStatistics()
	} catch (error) {
		console.error('删除日志失败:', error)
	}
}

// 清理日志
const handleClearLogs = () => {
	dialog.clear = true
}

const handleConfirmClear = async () => {
	if (!clearDays.value || clearDays.value < 1) {
		ElMessage.warning('请输入有效的天数')
		return
	}
	clearLoading.value = true
	try {
		const res = await systemApi.log.clear.post({ days: clearDays.value })
		if (res.code === 200) {
			ElMessage.success('清理成功')
			dialog.clear = false
			clearDays.value = 30
			refresh()
			loadStatistics()
		} else {
			ElMessage.error(res.message || '清理失败')
		}
	} catch (error) {
		console.error('清理日志失败:', error)
		ElMessage.error('清理失败')
	} finally {
		clearLoading.value = false
	}
}

const handleRefreshStats = () => {
	loadStatistics()
}

onMounted(() => {
	loadStatistics()
})
</script>

<style scoped>
/* 侧栏内部布局（容器本身由 sPageSplit 提供） */
.filter-side {
	display: flex;
	flex-direction: column;
	height: 100%;
	overflow: hidden;
}
.filter-side__body {
	flex: 1;
	overflow-y: auto;
	padding: 12px;
}

.statistics-cards {
	display: flex;
	gap: 16px;
	padding: 16px;
	background: var(--el-bg-color);
	border-bottom: 1px solid var(--el-border-color-lighter);
}

.stat-card {
	display: flex;
	align-items: center;
	gap: 12px;
	flex: 1;
	padding: 12px 16px;
	background: var(--el-fill-color-lighter);
	border-radius: 8px;
}

.stat-info {
	flex: 1;
}

.stat-value {
	font-size: 20px;
	font-weight: 600;
	color: var(--el-text-color-primary);
}

.stat-label {
	font-size: 12px;
	color: var(--el-text-color-secondary);
	margin-top: 2px;
}

/* .tool-bar/.left-panel/.right-panel/.table-content 已抽取到全局 components.css */

@media (max-width: 768px) {
	.statistics-cards {
		flex-wrap: wrap;
		padding: 12px;
		gap: 8px;
	}
	.stat-card {
		flex: 1 1 calc(50% - 8px);
		min-width: calc(50% - 8px);
		padding: 10px 12px;
	}
	.stat-value {
		font-size: 18px;
	}
	.stat-icon {
		font-size: 20px !important;
	}
}
</style>
