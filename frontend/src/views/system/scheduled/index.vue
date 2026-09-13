<template>
	<div class="scheduled-page">
		<!-- 统计卡片 -->
		<div class="statistics-cards">
			<div v-for="stat in statCards" :key="stat.key" class="stat-card" :class="{ 'stat-card--active': statusFilter === stat.key }" @click="filterByStatus(stat.key === 'total' ? '' : stat.key)">
				<div class="stat-icon" :style="{ color: stat.color }">
					<component :is="stat.icon" />
				</div>
				<div class="stat-info">
					<div class="stat-value">{{ statistics[stat.key] || 0 }}</div>
					<div class="stat-label">{{ stat.label }}</div>
				</div>
			</div>
		</div>

		<!-- 工具栏 -->
		<div class="scheduled-toolbar">
			<div class="left-panel">
				<el-input v-model="searchKeyword" placeholder="搜索名称/命令" clearable style="width: 240px" @clear="loadData" @keyup.enter="loadData">
					<template #prefix
						><el-icon><ElIconSearch /></el-icon
					></template>
				</el-input>
				<el-button type="primary" plain @click="loadData">搜索</el-button>
				<el-tag v-if="statusFilter" closable effect="dark" round @close="filterByStatus('')">
					{{ activeStatusLabel }}
				</el-tag>
			</div>
			<div class="right-panel">
				<el-button type="primary" @click="openSave(null)">新增调度</el-button>
				<el-tooltip content="刷新" placement="top">
					<el-button icon="ElIconRefresh" circle @click="loadData" />
				</el-tooltip>
			</div>
		</div>

		<!-- 卡片列表 -->
		<div v-loading="loading" class="card-grid">
			<template v-if="list.length > 0">
				<el-row :gutter="16">
					<el-col v-for="item in list" :key="item.id" :xs="24" :sm="12" :md="8" :lg="6">
						<scheduled-card :item="item" @start="handleStart" @run="handleRun" @pause="handlePause" @resume="handleResume" @stop="handleStop" @edit="openSave" @logs="openLogs" @delete="handleDelete" />
					</el-col>
				</el-row>
			</template>
			<el-empty v-else-if="!loading" description="暂无调度任务" />
		</div>

		<!-- 分页 -->
		<div v-if="total > 0" class="pagination-bar">
			<el-pagination v-model:current-page="page" v-model:page-size="pageSize" :total="total" :page-sizes="[12, 24, 48, 96]" layout="total, sizes, prev, pager, next" background @current-change="loadData" @size-change="loadData" />
		</div>
	</div>

	<SaveDialog v-model:visible="saveVisible" :record="saveRecord" @success="onRefresh" />
	<LogDrawer v-model:visible="logVisible" :record="logRecord" />
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { List, Clock, VideoPlay, VideoPause, SwitchButton, WarningFilled } from '@element-plus/icons-vue'
import systemApi from '@/api/system'
import ScheduledCard from './components/scheduled-card.vue'
import SaveDialog from './components/save.vue'
import LogDrawer from './components/log.vue'

const list = ref([])
const total = ref(0)
const page = ref(1)
const pageSize = ref(12)
const searchKeyword = ref('')
const statusFilter = ref('')
const loading = ref(false)

const statistics = ref({ total: 0, idle: 0, running: 0, paused: 0, stopped: 0, error: 0 })

const saveVisible = ref(false)
const saveRecord = ref(null)
const logVisible = ref(false)
const logRecord = ref(null)

const statCards = [
	{ key: 'total', label: '全部', icon: List, color: 'var(--el-color-primary)' },
	{ key: 'idle', label: '空闲', icon: Clock, color: '#409EFF' },
	{ key: 'running', label: '运行中', icon: VideoPlay, color: '#67C23A' },
	{ key: 'paused', label: '已暂停', icon: VideoPause, color: '#E6A23C' },
	{ key: 'stopped', label: '已禁用', icon: SwitchButton, color: '#909399' },
	{ key: 'error', label: '异常', icon: WarningFilled, color: '#F56C6C' },
]

const statusLabels = { idle: '空闲', running: '运行中', paused: '已暂停', stopped: '已禁用', error: '异常' }
const activeStatusLabel = computed(() => statusLabels[statusFilter.value] || '')

const loadData = async () => {
	loading.value = true
	try {
		const params = { page: page.value, page_size: pageSize.value }
		if (searchKeyword.value) params.keyword = searchKeyword.value
		if (statusFilter.value) params.status = statusFilter.value
		const res = await systemApi.scheduled.list.get(params)
		if (res.code === 200) {
			list.value = res.data.list || []
			total.value = res.data.total || 0
		}
	} catch (e) {
		console.error(e)
	} finally {
		loading.value = false
	}
}

const loadStatistics = async () => {
	try {
		const res = await systemApi.scheduled.statistics.get()
		if (res.code === 200) statistics.value = res.data
	} catch (e) {
		console.error(e)
	}
}

const onRefresh = () => {
	loadData()
	loadStatistics()
}

const filterByStatus = (status) => {
	statusFilter.value = status
	page.value = 1
	loadData()
}

const openSave = (record) => {
	saveRecord.value = record
	saveVisible.value = true
}

const openLogs = (record) => {
	logRecord.value = record
	logVisible.value = true
}

const handleLifecycle = async (action, item, label) => {
	try {
		await systemApi.scheduled[action].post(item.id)
		ElMessage.success(`${label}成功`)
		onRefresh()
	} catch (e) {
		ElMessage.error(e?.response?.data?.message || `${label}失败`)
	}
}

const handleStart = (item) => handleLifecycle('start', item, '启动')
const handlePause = (item) => handleLifecycle('pause', item, '暂停')
const handleResume = (item) => handleLifecycle('resume', item, '恢复')
const handleStop = (item) => handleLifecycle('stop', item, '禁用')

const handleRun = async (item) => {
	try {
		ElMessage.info('开始执行...')
		const res = await systemApi.scheduled.run.post(item.id)
		if (res.code === 200) {
			ElMessage.success('执行完成')
			onRefresh()
		}
	} catch (e) {
		ElMessage.error(e?.response?.data?.message || '执行失败')
	}
}

const handleDelete = (item) => {
	ElMessageBox.confirm(`确定删除调度「${item.name}」吗？`, '删除确认', { type: 'warning' })
		.then(async () => {
			await systemApi.scheduled.delete.delete(item.id)
			ElMessage.success('删除成功')
			onRefresh()
		})
		.catch(() => {})
}

onMounted(() => {
	loadData()
	loadStatistics()
})
</script>

<style scoped>
.scheduled-page {
	display: flex;
	flex-direction: column;
	height: 100%;
	padding: 20px;
	background: var(--el-bg-color);
}

/* ---- Statistics ---- */
.statistics-cards {
	display: grid;
	grid-template-columns: repeat(6, 1fr);
	gap: 12px;
}
.stat-card {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 14px 16px;
	background: var(--el-fill-color-lighter);
	border: 2px solid transparent;
	border-radius: 10px;
	cursor: pointer;
	transition: all 0.2s;
}
.stat-card:hover {
	background: var(--el-fill-color);
	transform: translateY(-1px);
}
.stat-card--active {
	border-color: var(--el-color-primary);
	background: var(--el-color-primary-light-9);
}
.stat-icon {
	font-size: 28px;
	display: flex;
	align-items: center;
}
.stat-info {
	flex: 1;
}
.stat-value {
	font-size: 22px;
	font-weight: 700;
	color: var(--el-text-color-primary);
	line-height: 1.2;
}
.stat-label {
	font-size: 12px;
	color: var(--el-text-color-secondary);
	margin-top: 2px;
}

/* ---- Toolbar ---- */
.scheduled-toolbar {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	flex-wrap: wrap;
	margin-top: 20px;
	margin-bottom: 16px;
}
.scheduled-toolbar .left-panel,
.scheduled-toolbar .right-panel {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
}

/* ---- Card Grid ---- */
.card-grid {
	flex: 1;
	overflow-y: auto;
	overflow-x: hidden;
	padding-bottom: 8px;
}
.card-grid :deep(.el-col) {
	margin-bottom: 16px;
}
.card-grid :deep(.el-row) {
	flex-wrap: wrap;
}

/* ---- Pagination ---- */
.pagination-bar {
	display: flex;
	justify-content: flex-end;
	padding-top: 16px;
	border-top: 1px solid var(--el-border-color-lighter);
}

@media (max-width: 768px) {
	.scheduled-page {
		padding: 12px;
	}
	.statistics-cards {
		grid-template-columns: repeat(2, 1fr);
		gap: 8px;
	}
	.stat-card {
		padding: 10px 12px;
	}
	.stat-icon {
		font-size: 22px;
	}
	.stat-value {
		font-size: 18px;
	}
	.scheduled-toolbar {
		margin-top: 12px;
		margin-bottom: 12px;
	}
	.scheduled-toolbar .left-panel .el-input {
		width: 100% !important;
	}
	.pagination-bar {
		justify-content: center;
	}
	.pagination-bar :deep(.el-pagination) {
		flex-wrap: wrap;
		justify-content: center;
	}
}
</style>
