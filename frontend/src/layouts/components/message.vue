<script setup>
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { ElMessage } from 'element-plus'
import { useResponsive } from '../../hooks/useResponsive'
import { useNotificationStore } from '../../stores/modules/notification'
import { useDictionaryStore } from '../../stores/modules/dictionary'
import notificationApi from '../../api/notification'
import service from '../../utils/request'

const { isMobile } = useResponsive()
const { t } = useI18n()
const notificationStore = useNotificationStore()
const dictStore = useDictionaryStore()

const visible = defineModel('visible', { type: Boolean, default: false })

const activeTab = ref('unread')
const loading = ref(false)
const loadingMore = ref(false)
const allList = ref([])
const allTotal = ref(0)
const allPage = ref(1)
const allHasMore = computed(() => allList.value.length < allTotal.value)

// 从字典动态获取分类 Tab
const categories = computed(() => {
	const items = dictStore.getDictionary('notification_category') || []
	return items.map((i) => ({ label: i.label, value: i.value, color: i.color }))
})

// Tab 列表：固定未读 + 全部 + 字典分类
const tabs = computed(() => {
	const unreadLabel = notificationStore.unreadCount > 0 ? `${t('header.unread')}(${notificationStore.unreadCount})` : t('header.unread')
	return [{ label: unreadLabel, value: 'unread' }, { label: t('header.allMessages'), value: 'all' }, ...categories.value]
})

// 当前显示的列表
const displayList = computed(() => {
	if (activeTab.value === 'unread') return notificationStore.unreadList
	if (activeTab.value === 'all') return allList.value
	return allList.value
})

const displayTotal = computed(() => {
	if (activeTab.value === 'unread') return notificationStore.unreadTotal
	return allTotal.value
})

const displayHasMore = computed(() => {
	if (activeTab.value === 'unread') return notificationStore.hasMore
	return allHasMore.value
})

// 获取指示器颜色
function getIndicatorColor(msg) {
	const cat = categories.value.find((c) => c.value === msg.category)
	return cat?.color || 'var(--el-color-info)'
}

// 格式化时间
function formatTime(time) {
	if (!time) return ''
	const date = new Date(time)
	const now = new Date()
	const diff = now - date
	const minutes = Math.floor(diff / 60000)
	if (minutes < 1) return t('common.loading')
	if (minutes < 60) return `${minutes}分钟前`
	const hours = Math.floor(minutes / 60)
	if (hours < 24) return `${hours}小时前`
	const days = Math.floor(hours / 24)
	if (days < 30) return `${days}天前`
	return date.toLocaleDateString()
}

// 加载数据
async function fetchData(append = false) {
	if (activeTab.value === 'unread') {
		if (append) {
			await notificationStore.loadMore()
		} else {
			await notificationStore.fetchUnreadList()
		}
		return
	}

	if (loading.value || (append && loadingMore.value)) return
	if (!append) {
		loading.value = true
		allPage.value = 1
	} else {
		loadingMore.value = true
		allPage.value++
	}

	try {
		const params = { page: allPage.value, page_size: 20 }
		if (activeTab.value !== 'all') {
			params.category = activeTab.value
		}
		const res = await notificationApi.notification.list.get(params)
		if (res.code === 200) {
			if (append) {
				allList.value.push(...res.data.list)
			} else {
				allList.value = res.data.list
			}
			allTotal.value = res.data.total ?? 0
		}
	} catch (e) {
		console.error('获取通知列表失败:', e)
	} finally {
		loading.value = false
		loadingMore.value = false
	}
}

// 加载更多
async function loadMore() {
	await fetchData(true)
}

// 点击消息主体
async function handleClick(msg) {
	if (!msg.is_read) {
		await notificationStore.markAsRead(msg.id)
	}
	// 如果没有操作按钮，则处理旧的 action_type 逻辑
	if (!msg.action_data || msg.action_data.length === 0) {
		if (msg.action_type === 'link' && msg.action_data?.url) {
			window.location.hash = msg.action_data.url
			visible.value = false
		}
	}
}

// 处理操作按钮点击
async function handleAction(msg, action) {
	if (!msg.is_read) {
		await notificationStore.markAsRead(msg.id)
	}

	switch (action.type) {
		case 'link':
			// 跳转链接
			if (action.url) {
				if (action.url.startsWith('/')) {
					window.location.hash = action.url
				} else {
					window.location.href = action.url
				}
				visible.value = false
			}
			break

		case 'download':
			// 下载文件：用 axios blob 方式，携带 JWT token，并识别后端的 JSON 错误响应
			if (action.url) {
				try {
					const blob = await service.get(action.url, { responseType: 'blob' })
					// 后端错误（如文件已过期）会返回 JSON，被包装成 Blob，需解析后提示
					if (blob.type && blob.type.includes('application/json')) {
						const err = JSON.parse(await blob.text())
						ElMessage.error(err.message || '文件下载失败或已过期')
						break
					}
					const filename = decodeURIComponent(action.url.split('/').pop() || 'download')
					const url = URL.createObjectURL(blob)
					const link = document.createElement('a')
					link.href = url
					link.download = filename
					document.body.appendChild(link)
					link.click()
					document.body.removeChild(link)
					URL.revokeObjectURL(url)
				} catch (e) {
					ElMessage.error('文件下载失败或已过期')
				}
			}
			break

		case 'modal':
			// 打开弹窗
			if (action.modal) {
				// TODO: 实现弹窗逻辑
				console.log('打开弹窗:', action.modal)
			}
			break

		default:
			console.log('未知操作类型:', action.type)
	}
}

// 全部已读
async function handleMarkAllRead() {
	await notificationStore.markAllAsRead()
}

// 删除
async function handleDelete(id) {
	await notificationStore.deleteNotification(id)
}

// 清空已读
async function handleClearRead() {
	await notificationStore.clearRead()
	await fetchData()
}

// 监听 Tab 切换重新加载数据
watch(activeTab, () => {
	if (activeTab.value !== 'unread') {
		allList.value = []
		allPage.value = 1
		fetchData()
	}
})

// 打开抽屉时刷新
watch(visible, (val) => {
	if (val) {
		if (activeTab.value === 'unread') {
			notificationStore.fetchUnreadList()
		} else {
			fetchData()
		}
	}
})
</script>

<template>
	<el-drawer v-model="visible" :title="t('header.message')" direction="rtl" :size="isMobile ? '100vw' : '400px'" append-to-body>
		<div class="msg-drawer">
			<div class="msg-header">
				<span v-if="activeTab === 'unread' && notificationStore.unreadCount > 0" class="msg-header__count"> {{ notificationStore.unreadCount }} 条未读 </span>
				<span v-else />
				<div class="msg-header__actions">
					<el-button v-if="notificationStore.unreadCount > 0" type="primary" link size="small" @click="handleMarkAllRead">
						{{ t('header.markAllRead') }}
					</el-button>
					<el-button type="danger" link size="small" @click="handleClearRead">
						{{ t('header.clearRead') }}
					</el-button>
				</div>
			</div>

			<el-tabs v-model="activeTab" class="msg-tabs">
				<el-tab-pane v-for="tab in tabs" :key="tab.value" :label="tab.label" :name="tab.value" />
			</el-tabs>

			<div class="msg-list">
				<template v-if="loading">
					<div v-for="i in 5" :key="i" class="msg-item msg-item--skeleton">
						<el-skeleton :rows="2" animated />
					</div>
				</template>

				<template v-else>
					<div v-for="msg in displayList" :key="msg.id" class="msg-item" :class="{ unread: !msg.is_read }">
						<div class="msg-item__indicator" :style="{ background: getIndicatorColor(msg) }" />
						<div class="msg-item__body" @click="handleClick(msg)">
							<div class="msg-item__title">{{ msg.title }}</div>
							<div class="msg-item__text">{{ msg.content }}</div>
							<div class="msg-item__time">{{ formatTime(msg.created_at) }}</div>
							<!-- 操作按钮组 -->
							<div v-if="msg.action_data && msg.action_data.length > 0" class="msg-item__actions">
								<button v-for="(action, index) in msg.action_data" :key="index" class="msg-item__action-btn" :class="'msg-item__action-btn--' + (action.type || 'default')" @click.stop="handleAction(msg, action)">
									<el-icon v-if="action.type === 'download'" :size="14"><ElIconDownload /></el-icon>
									<el-icon v-else-if="action.type === 'link'"><ElIconLink /></el-icon>
									{{ action.label || '操作' }}
								</button>
							</div>
						</div>
						<button class="msg-item__del" :aria-label="t('common.delete')" @click.stop="handleDelete(msg.id)">
							<el-icon :size="14"><ElIconDelete /></el-icon>
						</button>
					</div>

					<el-empty v-if="!displayList.length" :description="t('common.noData')" :image-size="56" />
				</template>

				<div v-if="!loading && displayHasMore" class="msg-loadmore">
					<el-button link :loading="loadingMore" @click="loadMore">
						{{ loadingMore ? t('common.loading') : t('header.loadMore') }}
					</el-button>
				</div>
				<div v-if="!loading && !displayHasMore && displayList.length > 10" class="msg-nomore">
					{{ t('header.noMoreData') }}
				</div>
			</div>
		</div>
	</el-drawer>
</template>

<style scoped>
.msg-drawer {
	display: flex;
	flex-direction: column;
	height: 100%;
}
.msg-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 4px;
}
.msg-header__count {
	font-size: 12px;
	color: var(--layout-text-muted);
}
.msg-header__actions {
	display: flex;
	gap: 8px;
}
.msg-tabs {
	flex-shrink: 0;
}
.msg-tabs :deep(.el-tabs__header) {
	margin-bottom: 0;
}
.msg-tabs :deep(.el-tabs__item) {
	font-size: 13px;
	padding: 0 12px;
	height: 36px;
	line-height: 36px;
}
.msg-list {
	flex: 1;
	overflow-y: auto;
	padding: 8px 0;
}
.msg-item {
	display: flex;
	align-items: flex-start;
	gap: 10px;
	padding: 12px 14px;
	border-radius: 8px;
	cursor: pointer;
	transition: background-color 0.2s;
	position: relative;
}
.msg-item:hover {
	background: var(--el-fill-color-light);
}
.msg-item.unread {
	background: var(--el-color-primary-light-9);
}
.msg-item--skeleton {
	cursor: default;
}
.msg-item--skeleton:hover {
	background: transparent;
}
.msg-item__indicator {
	width: 8px;
	height: 8px;
	border-radius: 50%;
	flex-shrink: 0;
	margin-top: 6px;
}
.msg-item.unread .msg-item__title {
	color: var(--el-color-primary);
	font-weight: 600;
}
.msg-item__body {
	flex: 1;
	min-width: 0;
}
.msg-item__title {
	font-size: 14px;
	color: var(--layout-text);
	font-weight: 500;
	margin-bottom: 4px;
	line-height: 1.4;
}
.msg-item__text {
	font-size: 13px;
	color: var(--layout-text-secondary);
	line-height: 1.5;
	display: -webkit-box;
	-webkit-line-clamp: 2;
	-webkit-box-orient: vertical;
	overflow: hidden;
}
.msg-item__time {
	font-size: 12px;
	color: var(--layout-text-muted);
	margin-top: 6px;
}
.msg-item__actions {
	display: flex;
	gap: 8px;
	margin-top: 8px;
}
.msg-item__action-btn {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	padding: 4px 10px;
	border: none;
	border-radius: 4px;
	background: var(--el-color-primary);
	color: white;
	font-size: 12px;
	cursor: pointer;
	transition: all 0.2s;
	white-space: nowrap;
}
.msg-item__action-btn--download {
	background: var(--el-color-success);
}
.msg-item__action-btn--link {
	background: var(--el-color-info);
}
.msg-item__action-btn--modal {
	background: var(--el-color-warning);
}
.msg-item__action-btn:hover {
	opacity: 0.9;
	transform: translateY(-1px);
}
.msg-item__del {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 28px;
	height: 28px;
	border: none;
	border-radius: 6px;
	background: transparent;
	cursor: pointer;
	color: var(--layout-text-muted);
	opacity: 0;
	transition:
		opacity 0.15s,
		color 0.15s,
		background-color 0.15s;
	flex-shrink: 0;
	padding: 0;
}
.msg-item:hover .msg-item__del {
	opacity: 1;
}
.msg-item__del:hover {
	color: var(--el-color-danger);
	background: var(--el-color-danger-light-9);
}
.msg-loadmore {
	text-align: center;
	padding: 12px 0 4px;
}
.msg-nomore {
	text-align: center;
	padding: 12px 0 4px;
	font-size: 12px;
	color: var(--layout-text-muted);
}

@media (max-width: 768px) {
	.msg-list {
		padding: 8px 0;
	}
}
</style>
