<script setup>
import { ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import businessApi from '@/api/business'

const props = defineProps({
	visible: { type: Boolean, default: false },
})

const emit = defineEmits(['navigate', 'count-change'])

const router = useRouter()

const list = ref([])
const loading = ref(false)
const totalCount = ref(0)

/**
 * 相对时间格式化：5分钟前 / 2小时前 / 3天前，超过 7 天回退到 YYYY-MM-DD HH:mm。
 */
function formatTime(value) {
	if (!value) return ''
	const date = new Date(value)
	if (isNaN(date.getTime())) return String(value)
	const diff = Date.now() - date.getTime()
	const sec = Math.floor(diff / 1000)
	if (sec < 60) return '刚刚'
	const min = Math.floor(sec / 60)
	if (min < 60) return `${min}分钟前`
	const hr = Math.floor(min / 60)
	if (hr < 24) return `${hr}小时前`
	const day = Math.floor(hr / 24)
	if (day < 7) return `${day}天前`
	const pad = (n) => String(n).padStart(2, '0')
	return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}`
}

async function fetchList() {
	loading.value = true
	try {
		const res = await businessApi.messages.list.get({ page: 1, page_size: 10 })
		// 拦截器 reject 时 res 为 undefined，直接 res.code 会二次抛错
		if (res && typeof res === 'object' && res.code === 200) {
			list.value = res.data?.list || []
			totalCount.value = res.data?.total || 0
		}
		// 角标用独立的 unread-count 接口取真值：列表接口不分未读/已读，
		// 且只取第 1 页 10 条，无法据此推算未读数。
		await fetchUnreadCount()
	} catch (e) {
		// 静默：401 已由拦截器统一处理，这里 console.error 会被 Vue errorHandler
		// 捕获进渲染链，在 appContext 过渡期触发 Object.create(undefined) 崩溃
	} finally {
		loading.value = false
	}
}

/** 拉取未读总数并同步给角标（user-bar 监听 count-change） */
async function fetchUnreadCount() {
	try {
		const res = await businessApi.messages.unreadCount.get()
		if (res && typeof res === 'object' && res.code === 200) {
			emit('count-change', res.data?.count ?? 0)
		}
	} catch (e) {
		// 同 fetchList：静默降级，不外抛
	}
}

/**
 * 从 payload 中解析跳转路径；payload 形如 { entity_type, entity_id, path, extra }。
 * 只有当 path 是形如 "/xxx" 的字符串时才跳转。
 */
function resolvePath(item) {
	const payload = item?.payload
	if (!payload || typeof payload !== 'object') return ''
	const path = payload.path || payload.route || payload.url
	return typeof path === 'string' && path.startsWith('/') ? path : ''
}

async function handleItemClick(item) {
	if (item && !item.is_read) {
		try {
			// 后端回传 unread_count，用它更新角标，避免多打一次请求
			const res = await businessApi.messages.read.post(item.id)
			item.is_read = true
			emit('count-change', typeof res?.data?.unread_count === 'number' ? res.data.unread_count : 0)
			if (!Number.isInteger(res?.data?.unread_count)) await fetchUnreadCount()
		} catch (e) {
			// 静默：401 已由拦截器处理
		}
	}
	const path = resolvePath(item)
	if (path) {
		emit('navigate')
		try {
			await router.push(path)
		} catch (e) {
			// 路由跳转失败不影响已读标记
		}
	}
}

async function handleMarkAllRead() {
	try {
		await businessApi.messages.readAll.post()
		list.value.forEach((it) => (it.is_read = true))
		ElMessage.success('已全部标记为已读')
		emit('count-change', 0)
	} catch (e) {
		ElMessage.error('操作失败')
	}
}

function handleViewAll() {
	emit('navigate')
	router.push('/ucenter?menu=my-messages')
}

// 面板每次打开时刷新列表，保证数据是最新的
watch(
	() => props.visible,
	(val) => {
		if (val) fetchList()
	}
)
</script>

<template>
	<div class="message-panel">
		<div class="message-panel__header">消息通知</div>

		<div v-loading="loading" class="message-panel__list">
			<div v-if="!loading && list.length === 0" class="message-panel__empty">暂无消息</div>

			<div
				v-for="item in list"
				:key="item.id"
				class="message-panel__item"
				:class="{ 'is-unread': !item.is_read }"
				@click="handleItemClick(item)"
			>
				<div class="message-panel__item-body">
					<div class="message-panel__title">{{ item.title }}</div>
					<div class="message-panel__summary">{{ item.content || '' }}</div>
				</div>
				<div class="message-panel__time">{{ formatTime(item.created_at) }}</div>
			</div>
		</div>

		<div class="message-panel__footer">
			<el-button link type="primary" :disabled="loading" @click="handleMarkAllRead">全部已读</el-button>
			<el-button link type="primary" @click="handleViewAll">查看全部</el-button>
		</div>
	</div>
</template>

<style scoped>
.message-panel {
	width: 100%;
	background: #ffffff;
	border-radius: 4px;
	box-shadow: 0 2px 12px rgba(0, 0, 0, 0.1);
	overflow: hidden;
}

.message-panel__header {
	height: 44px;
	line-height: 44px;
	border-bottom: 1px solid #EBEEF5;
	padding-left: 16px;
	font-size: 14px;
	font-weight: 700;
	color: #303133;
}

.message-panel__list {
	max-height: 400px;
	overflow-y: auto;
}

.message-panel__empty {
	text-align: center;
	padding: 40px;
	font-size: 14px;
	color: #909399;
}

.message-panel__item {
	display: flex;
	align-items: center;
	gap: 8px;
	height: 60px;
	padding: 10px 16px;
	border-bottom: 1px solid #EBEEF5;
	border-left: 3px solid transparent;
	cursor: pointer;
	transition: background-color 0.2s;
}
.message-panel__item:hover {
	background: #F5F7FA;
}
.message-panel__item.is-unread {
	border-left: 3px solid #409EFF;
	background: #F0F7FF;
}

.message-panel__item-body {
	flex: 1;
	min-width: 0;
	overflow: hidden;
}

.message-panel__title {
	font-size: 14px;
	color: #303133;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
.message-panel__item.is-unread .message-panel__title {
	font-weight: 500;
}

.message-panel__summary {
	font-size: 12px;
	color: #909399;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	margin-top: 4px;
}

.message-panel__time {
	flex-shrink: 0;
	font-size: 12px;
	color: #C0C4CC;
	text-align: right;
}

.message-panel__footer {
	display: flex;
	align-items: center;
	justify-content: space-between;
	height: 44px;
	padding: 0 16px;
	border-top: 1px solid #EBEEF5;
}
.message-panel__footer .el-button {
	font-size: 14px;
	padding: 0;
}
</style>
