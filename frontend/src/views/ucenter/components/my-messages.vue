<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import businessApi from '@/api/business'

const router = useRouter()

const activeTab = ref('unread') // unread | read | all
const list = ref([])
const loading = ref(false)
const page = ref(1)
const pageSize = ref(10)
const total = ref(0)
const unreadTotal = ref(0)
const selectedIds = ref([])

/**
 * type → Element Plus 图标组件名（全局注册，模板里用 <component :is> 渲染）。
 * type 取值：approval / stock_warning / system / business；category 作为兜底。
 */
function typeIcon(item) {
	const key = String(item?.type || item?.category || '')
	if (key === 'approval' || key === 'task') return 'ElIconDocument'
	if (key === 'stock_warning' || key === 'reminder') return 'ElIconWarning'
	if (key === 'system' || key === 'announcement') return 'ElIconNotification'
	return 'ElIconBell'
}

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

const unreadCount = computed(() => unreadTotal.value)
const isAllSelected = computed(() => list.value.length > 0 && selectedIds.value.length === list.value.length)

async function fetchUnreadTotal() {
	try {
		const res = await businessApi.messages.unreadCount.get()
		// 拦截器 reject 时 res 为 undefined，直接 res.code 会二次抛错
		if (res && typeof res === 'object' && res.code === 200) {
			unreadTotal.value = res.data?.count || 0
		}
	} catch (e) {
		// 静默：401 已由拦截器统一处理（logout + 跳登录），这里再 console.error
		// 会被 Vue errorHandler 捕获进渲染链，在 appContext 过渡期触发崩溃
	}
}

async function fetchList() {
	loading.value = true
	selectedIds.value = []
	try {
		const params = { page: page.value, page_size: pageSize.value, status: activeTab.value }
		const res = await businessApi.messages.list.get(params)
		if (res && typeof res === 'object' && res.code === 200) {
			list.value = res.data?.list || []
			total.value = res.data?.total || 0
		}
	} catch (e) {
		// ElMessage.error 走 element-plus 的 message 组件，传纯字符串不会触发
		// Object.create(undefined)；保留用户可见反馈，但不再 console.error
		ElMessage.error('加载消息失败')
	} finally {
		loading.value = false
	}
}

function switchTab(tab) {
	if (activeTab.value === tab) return
	activeTab.value = tab
	page.value = 1
	fetchList()
}

function onPageChange(p) {
	page.value = p
	fetchList()
}

function toggleSelect(item) {
	const idx = selectedIds.value.indexOf(item.id)
	if (idx > -1) selectedIds.value.splice(idx, 1)
	else selectedIds.value.push(item.id)
}

function toggleSelectAll() {
	selectedIds.value = isAllSelected.value ? [] : list.value.map((it) => it.id)
}

/**
 * 从 payload 中解析跳转路径；payload 形如 { entity_type, entity_id, path, extra }。
 */
function resolvePath(item) {
	const payload = item?.payload
	if (!payload || typeof payload !== 'object') return ''
	const path = payload.path || payload.route || payload.url
	return typeof path === 'string' && path.startsWith('/') ? path : ''
}

async function handleViewDetail(item) {
	if (item && !item.is_read) {
		try {
			await businessApi.messages.read.post(item.id)
			item.is_read = true
			unreadTotal.value = Math.max(0, unreadTotal.value - 1)
		} catch (e) {
			console.error('标记已读失败:', e)
		}
	}
	const path = resolvePath(item)
	if (path) {
		try {
			await router.push(path)
		} catch (e) {
			console.error('跳转失败:', e)
		}
		return
	}
	if (item && !item.is_read) ElMessage.success('已标记为已读')
}

async function handleMarkAllRead() {
	try {
		const res = await businessApi.messages.readAll.post()
		if (res.code === 200) {
			ElMessage.success('已全部标记为已读')
			unreadTotal.value = 0
			fetchList()
		}
	} catch (e) {
		ElMessage.error('操作失败')
	}
}

async function handleDeleteRow(item) {
	try {
		await ElMessageBox.confirm('确定删除该消息？删除后不可恢复', '删除消息', { type: 'warning' })
	} catch {
		return
	}
	await doDelete([item.id], true)
}

async function handleDeleteSelected() {
	if (selectedIds.value.length === 0) {
		ElMessage.warning('请先选择要删除的消息')
		return
	}
	try {
		await ElMessageBox.confirm(
			`确定删除选中的 ${selectedIds.value.length} 条消息？删除后不可恢复`,
			'批量删除',
			{ type: 'warning' }
		)
	} catch {
		return
	}
	await doDelete(selectedIds.value.slice(), false)
}

/**
 * 删除一组消息。后端提供单条删除接口；逐条调用并在全部完成后刷新本地状态。
 * 若后端后续支持批量删除，可在此替换为一次请求。
 */
async function doDelete(ids, isSingle) {
	loading.value = true
	const removed = []
	for (const id of ids) {
		try {
			const res = await businessApi.messages.delete.delete(id)
			if (res.code === 200) removed.push(id)
		} catch (e) {
			console.error(`删除消息 ${id} 失败:`, e)
		}
	}
	loading.value = false
	const removedSet = new Set(removed)
	const removedUnread = list.value.filter((it) => removedSet.has(it.id) && !it.is_read).length
	if (removed.length > 0) {
		ElMessage.success(`已删除 ${removed.length} 条消息`)
		unreadTotal.value = Math.max(0, unreadTotal.value - removedUnread)
		list.value = list.value.filter((it) => !removedSet.has(it.id))
		total.value = Math.max(0, total.value - removed.length)
		selectedIds.value = selectedIds.value.filter((id) => !removedSet.has(id))
		if (list.value.length === 0 && page.value > 1) {
			page.value -= 1
			fetchList()
		}
	} else if (!isSingle) {
		ElMessage.error('删除失败')
	}
}

onMounted(() => {
	fetchList()
	fetchUnreadTotal()
})
</script>

<template>
	<div class="my-messages">
		<h2 class="page-title">我的消息</h2>

		<!-- 顶部 Tab + 操作按钮 -->
		<div class="messages-toolbar">
			<div class="messages-tabs">
				<div class="messages-tab" :class="{ active: activeTab === 'unread' }" @click="switchTab('unread')">
					<span>未读</span>
					<span v-if="unreadCount > 0" class="unread-badge">{{ unreadCount }}</span>
				</div>
				<div class="messages-tab" :class="{ active: activeTab === 'read' }" @click="switchTab('read')">已读</div>
				<div class="messages-tab" :class="{ active: activeTab === 'all' }" @click="switchTab('all')">全部</div>
			</div>

			<div class="messages-actions">
				<button class="btn-outline" :disabled="loading" @click="handleMarkAllRead">全部已读</button>
				<button class="btn-danger-outline" :disabled="loading || selectedIds.length === 0" @click="handleDeleteSelected">
					删除
				</button>
			</div>
		</div>

		<!-- 消息列表 -->
		<div v-loading="loading" class="messages-list">
			<el-empty v-if="!loading && list.length === 0" description="暂无消息" :image-size="80" />

			<!-- 全选行 -->
			<div v-if="list.length > 0" class="message-row message-row--header">
				<el-checkbox :model-value="isAllSelected" @change="toggleSelectAll">全选</el-checkbox>
			</div>

			<div v-for="item in list" :key="item.id" class="message-row" :class="{ 'is-unread': !item.is_read }">
				<div v-if="!item.is_read" class="unread-dot"></div>

				<el-checkbox
					class="message-row__check"
					:model-value="selectedIds.includes(item.id)"
					@change="toggleSelect(item)"
				/>

				<div class="message-row__icon">
					<el-icon :size="20"><component :is="typeIcon(item)" /></el-icon>
				</div>

				<div class="message-row__body">
					<div class="message-row__title" :class="{ bold: !item.is_read }">{{ item.title }}</div>
					<div class="message-row__content">{{ item.content || '' }}</div>
					<div class="message-row__time">{{ formatTime(item.created_at) }}</div>
				</div>

				<div class="message-row__actions">
					<el-button link type="primary" @click="handleViewDetail(item)">查看详情</el-button>
					<el-button link type="danger" @click="handleDeleteRow(item)">删除</el-button>
				</div>
			</div>
		</div>

		<!-- 分页 -->
		<div v-if="total > 0" class="messages-pagination">
			<el-pagination
				v-model:current-page="page"
				:page-size="pageSize"
				:total="total"
				layout="prev, pager, next"
				@current-change="onPageChange"
			/>
		</div>
	</div>
</template>

<style scoped>
.my-messages {
	width: 100%;
}

.page-title {
	margin: 0 0 20px;
	font-size: 18px;
	font-weight: 600;
	color: #303133;
}

.messages-toolbar {
	display: flex;
	align-items: center;
	justify-content: space-between;
	height: 50px;
	border-bottom: 1px solid #EBEEF5;
}

.messages-tabs {
	display: flex;
	align-items: center;
	gap: 30px;
	height: 100%;
}

.messages-tab {
	position: relative;
	display: inline-flex;
	align-items: center;
	gap: 6px;
	font-size: 14px;
	color: #606266;
	cursor: pointer;
	padding-bottom: 8px;
	transition: color 0.2s;
	user-select: none;
}
.messages-tab:hover {
	color: #409EFF;
}
.messages-tab.active {
	color: #409EFF;
	font-weight: 500;
}
.messages-tab.active::after {
	content: '';
	position: absolute;
	left: 0;
	right: 0;
	bottom: -1px;
	height: 2px;
	background: #409EFF;
}

.unread-badge {
	display: inline-flex;
	align-items: center;
	background: #FEEFEC;
	color: #F56C6C;
	border-radius: 10px;
	padding: 0 6px;
	height: 18px;
	font-size: 12px;
	line-height: 18px;
}

.messages-actions {
	display: flex;
	align-items: center;
}

.btn-outline {
	height: 32px;
	width: 100px;
	padding: 0;
	background: #ffffff;
	border: 1px solid #DCDFE6;
	border-radius: 4px;
	color: #606266;
	font-size: 14px;
	cursor: pointer;
	transition: all 0.2s;
}
.btn-outline:hover:not(:disabled) {
	color: #409EFF;
	border-color: #409EFF;
}
.btn-outline:disabled {
	opacity: 0.6;
	cursor: not-allowed;
}

.btn-danger-outline {
	height: 32px;
	width: 80px;
	margin-left: 10px;
	padding: 0;
	background: #ffffff;
	border: 1px solid #F56C6C;
	border-radius: 4px;
	color: #F56C6C;
	font-size: 14px;
	cursor: pointer;
	transition: all 0.2s;
}
.btn-danger-outline:hover:not(:disabled) {
	background: #FEEFEC;
}
.btn-danger-outline:disabled {
	opacity: 0.6;
	cursor: not-allowed;
}

.messages-list {
	background: #ffffff;
}

.message-row {
	position: relative;
	display: flex;
	align-items: center;
	gap: 16px;
	height: 80px;
	padding: 16px 20px;
	border-bottom: 1px solid #EBEEF5;
	transition: background-color 0.2s;
}
.message-row:hover {
	background: #FAFAFA;
}
.message-row.is-unread {
	background: #F0F7FF;
}
.message-row.is-unread:hover {
	background: #E6F1FF;
}
.message-row--header {
	height: 44px;
	padding: 12px 20px;
}

.unread-dot {
	position: absolute;
	top: 16px;
	right: 16px;
	width: 8px;
	height: 8px;
	border-radius: 50%;
	background: #409EFF;
}

.message-row__check {
	flex-shrink: 0;
}

.message-row__icon {
	flex-shrink: 0;
	width: 32px;
	height: 32px;
	border-radius: 4px;
	background: #ECF5FF;
	color: #409EFF;
	display: flex;
	align-items: center;
	justify-content: center;
}

.message-row__body {
	flex: 1;
	min-width: 0;
	overflow: hidden;
}

.message-row__title {
	font-size: 14px;
	color: #303133;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
.message-row__title.bold {
	font-weight: 500;
}

.message-row__content {
	font-size: 13px;
	color: #606266;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	margin-top: 4px;
}

.message-row__time {
	font-size: 12px;
	color: #C0C4CC;
	margin-top: 4px;
}

.message-row__actions {
	flex-shrink: 0;
	display: flex;
	align-items: center;
	gap: 8px;
}
.message-row__actions .el-button {
	font-size: 14px;
	padding: 0;
}

.messages-pagination {
	display: flex;
	justify-content: center;
	padding: 16px 0;
}
</style>
