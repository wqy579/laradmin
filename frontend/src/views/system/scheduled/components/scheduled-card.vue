<template>
	<el-card shadow="hover" class="scheduled-card" :class="`scheduled-card--${item.status}`">
		<div class="card-header">
			<div class="card-header__left">
				<span class="status-dot" :class="`status-dot--${item.status}`" />
				<span class="card-title" :title="item.name">{{ item.name }}</span>
			</div>
			<el-tag :type="statusTagType" size="small" effect="dark" round>{{ statusLabel }}</el-tag>
		</div>

		<div class="card-body">
			<div class="card-info">
				<div class="info-row">
					<span class="info-label">命令</span>
					<code class="info-value info-value--mono" :title="item.command">{{ item.command }}</code>
				</div>
				<div class="info-row">
					<span class="info-label">类型</span>
					<el-tag size="small" effect="plain">{{ typeLabel }}</el-tag>
				</div>
				<div class="info-row">
					<span class="info-label">调度</span>
					<code v-if="item.expression" class="info-value info-value--mono">{{ item.expression }}</code>
					<el-tag v-else size="small" effect="plain" type="warning">每{{ formatInterval(item.interval) }}</el-tag>
				</div>
				<div class="info-row">
					<span class="info-label">最后运行</span>
					<span class="info-value">{{ formatTime(item.last_run_at) }}</span>
				</div>
				<div class="info-row">
					<span class="info-label">下次运行</span>
					<span class="info-value info-value--next">{{ formatTime(item.next_run_at) }}</span>
				</div>
			</div>

			<div class="card-stats">
				<div class="stat-item">
					<span class="stat-num">{{ item.run_count || 0 }}</span>
					<span class="stat-text">运行次数</span>
				</div>
				<div class="stat-item stat-item--error">
					<span class="stat-num">{{ item.error_count || 0 }}</span>
					<span class="stat-text">异常次数</span>
				</div>
			</div>
		</div>

		<div class="card-footer">
			<div class="footer-actions">
				<el-button v-if="canStart" size="small" type="primary" plain :loading="loadingAction === 'start'" @click="emitAction('start')">启动</el-button>
				<el-button v-if="canRun" size="small" type="success" plain :loading="loadingAction === 'run'" @click="emitAction('run')">立即执行</el-button>
				<el-button v-if="canPause" size="small" type="warning" plain :loading="loadingAction === 'pause'" @click="emitAction('pause')">暂停</el-button>
				<el-button v-if="canResume" size="small" type="primary" plain :loading="loadingAction === 'resume'" @click="emitAction('resume')">恢复</el-button>
				<el-button v-if="canStop" size="small" type="danger" plain :loading="loadingAction === 'stop'" @click="emitAction('stop')">禁用</el-button>
			</div>
			<el-dropdown trigger="click" @command="onCommand">
				<el-button size="small" text>
					<el-icon><ElIconMoreFilled /></el-icon>
				</el-button>
				<template #dropdown>
					<el-dropdown-menu>
						<el-dropdown-item command="edit">编辑</el-dropdown-item>
						<el-dropdown-item command="logs">查看日志</el-dropdown-item>
						<el-dropdown-item command="delete" divided style="color: var(--el-color-danger)">删除</el-dropdown-item>
					</el-dropdown-menu>
				</template>
			</el-dropdown>
		</div>
	</el-card>
</template>

<script setup>
import { computed, ref } from 'vue'

const props = defineProps({
	item: { type: Object, required: true },
})
const emit = defineEmits(['start', 'run', 'pause', 'resume', 'stop', 'edit', 'logs', 'delete'])

const loadingAction = ref('')

const statusMap = {
	idle: { label: '空闲', color: '#409EFF', tagType: 'info' },
	running: { label: '运行中', color: '#67C23A', tagType: 'success' },
	paused: { label: '已暂停', color: '#E6A23C', tagType: 'warning' },
	stopped: { label: '已禁用', color: '#909399', tagType: 'info' },
	error: { label: '异常', color: '#F56C6C', tagType: 'danger' },
}

const statusLabel = computed(() => statusMap[props.item.status]?.label || props.item.status)
const statusTagType = computed(() => statusMap[props.item.status]?.tagType || 'info')

const typeMap = { artisan: 'Artisan', job: '队列', shell: 'Shell' }
const typeLabel = computed(() => typeMap[props.item.type] || props.item.type)

const status = computed(() => props.item.status)
const canStart = computed(() => ['stopped', 'error'].includes(status.value))
const canRun = computed(() => ['idle'].includes(status.value))
const canPause = computed(() => status.value === 'running')
const canResume = computed(() => status.value === 'paused')
const canStop = computed(() => ['running', 'paused', 'idle'].includes(status.value))

const formatTime = (time) => {
	if (!time) return '—'
	return time.replace('T', ' ').substring(0, 19)
}

const formatInterval = (seconds) => {
	if (!seconds) return '—'
	if (seconds < 60) return seconds + '秒'
	if (seconds < 3600) return Math.floor(seconds / 60) + '分钟'
	return Math.floor(seconds / 3600) + '小时'
}

const emitAction = (action) => {
	loadingAction.value = action
	emit(action, props.item)
	setTimeout(() => (loadingAction.value = ''), 2000)
}

const onCommand = (cmd) => emit(cmd, props.item)
</script>

<style scoped>
.scheduled-card {
	border-radius: 8px;
	overflow: hidden;
	transition:
		transform 0.2s,
		box-shadow 0.2s;
	border: 1px solid var(--el-border-color-lighter);
	min-width: 0;
}
.scheduled-card:hover {
	transform: translateY(-2px);
	box-shadow: 0 6px 16px rgba(0, 0, 0, 0.08);
}
.scheduled-card :deep(.el-card__body) {
	padding: 0;
	display: flex;
	flex-direction: column;
}

/* ---- Header ---- */
.card-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 14px 16px;
	gap: 8px;
}
.card-header__left {
	display: flex;
	align-items: center;
	gap: 10px;
	flex: 1;
	overflow: hidden;
}
.status-dot {
	width: 8px;
	height: 8px;
	border-radius: 50%;
	flex-shrink: 0;
}
.status-dot--idle {
	background: #409eff;
}
.status-dot--running {
	background: #67c23a;
	animation: pulse 2s ease-in-out infinite;
}
.status-dot--paused {
	background: #e6a23c;
}
.status-dot--stopped {
	background: #909399;
}
.status-dot--error {
	background: #f56c6c;
	animation: pulse-error 1.5s ease-in-out infinite;
}

@keyframes pulse {
	0%,
	100% {
		box-shadow: 0 0 0 0 rgba(103, 194, 58, 0.5);
	}
	50% {
		box-shadow: 0 0 0 6px rgba(103, 194, 58, 0);
	}
}
@keyframes pulse-error {
	0%,
	100% {
		box-shadow: 0 0 0 0 rgba(245, 108, 108, 0.5);
	}
	50% {
		box-shadow: 0 0 0 6px rgba(245, 108, 108, 0);
	}
}
.card-title {
	font-weight: 600;
	font-size: 14px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	color: var(--el-text-color-primary);
}

/* ---- Body ---- */
.card-body {
	padding: 0 16px 14px;
	flex: 1;
}
.card-info {
	display: flex;
	flex-direction: column;
	gap: 8px;
}
.info-row {
	display: flex;
	align-items: center;
	gap: 8px;
	font-size: 12px;
}
.info-label {
	color: var(--el-text-color-secondary);
	flex-shrink: 0;
	width: 56px;
}
.info-value {
	color: var(--el-text-color-primary);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
.info-value--mono {
	font-family: 'SFMono-Regular', 'Consolas', 'Courier New', monospace;
	font-size: 11px;
	background: var(--el-fill-color-lighter);
	padding: 1px 6px;
	border-radius: 3px;
}
.info-value--next {
	color: var(--el-color-primary);
	font-weight: 500;
}

/* ---- Stats ---- */
.card-stats {
	display: flex;
	gap: 24px;
	margin-top: 14px;
	padding-top: 12px;
	border-top: 1px dashed var(--el-border-color-lighter);
}
.stat-item {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 2px;
}
.stat-num {
	font-size: 20px;
	font-weight: 700;
	color: var(--el-text-color-primary);
	line-height: 1;
}
.stat-item--error .stat-num {
	color: var(--el-color-danger);
}
.stat-text {
	font-size: 11px;
	color: var(--el-text-color-secondary);
}

/* ---- Footer ---- */
.card-footer {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 10px 16px;
	border-top: 1px solid var(--el-border-color-lighter);
	background: var(--el-fill-color-lighter);
	gap: 8px;
}
.footer-actions {
	display: flex;
	align-items: center;
	gap: 6px;
	flex-wrap: wrap;
}
</style>
