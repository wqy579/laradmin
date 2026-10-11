<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { useFullscreen } from '@vueuse/core'
import { useAppStore } from '../../stores/app'
import { useUserStore } from '../../stores/modules/user'
import businessApi from '../../api/business'
import { useWebSocket } from '../../hooks/useWebSocket'
import SearchModal from './search.vue'
import MessageDrawer from './message.vue'
import MessagePanel from './message-panel.vue'

const props = defineProps({
	mobile: { type: Boolean, default: false },
})

const { locale, t } = useI18n()
const router = useRouter()
const appStore = useAppStore()
const userStore = useUserStore()

const searchVisible = ref(false)
const messageVisible = ref(false)
const messagePanelVisible = ref(false)
const { isFullscreen, toggle: toggleFullscreen } = useFullscreen()
const { status: wsStatus } = useWebSocket()

// 未读消息数：来自新的 messages 系统（businessApi.messages），30s 轮询刷新。
const unreadMsgCount = ref(0)

const messageCount = computed(() => {
	if (messageVisible.value || messagePanelVisible.value) return 0
	return unreadMsgCount.value
})

async function fetchMessageCount() {
	try {
		const res = await businessApi.messages.unreadCount.get()
		if (res.code === 200) unreadMsgCount.value = res.data?.count ?? 0
	} catch (e) {
		console.error('获取未读消息数失败:', e)
	}
}

function handlePanelCountChange(count) {
	unreadMsgCount.value = count || 0
}

let messagePollTimer = null
function startMessagePolling() {
	fetchMessageCount()
	messagePollTimer = setInterval(fetchMessageCount, 30000)
}

const userName = computed(() => {
	const info = userStore.userInfo || {}
	return info.nickname || info.username || 'Admin'
})
const userNameFirst = computed(() => userName.value.charAt(0).toUpperCase())

function switchLocale(lang) {
	locale.value = lang
	appStore.setLocale(lang)
}

function handleCommand(cmd) {
	switch (cmd) {
		case 'search':
			searchVisible.value = true
			break
		case 'message':
			messageVisible.value = true
			break
		case 'profile':
			router.push('/ucenter')
			break
		case 'settings':
			router.push('/system/setting')
			break
		case 'clearCache':
			handleClearCache()
			break
		case 'logout':
			handleLogout()
			break
	}
}

function handleClearCache() {
	ElMessageBox.confirm(t('header.clearCacheConfirm'), t('header.clearCache'), {
		confirmButtonText: t('common.confirm'),
		cancelButtonText: t('common.cancel'),
		type: 'warning',
	})
		.then(() => {
			localStorage.clear()
			sessionStorage.clear()
			ElMessage.success(t('header.cacheCleared'))
			setTimeout(() => window.location.reload(), 800)
		})
		.catch(() => {})
}

function handleLogout() {
	ElMessageBox.confirm(t('header.logoutConfirm'), t('header.logout'), {
		confirmButtonText: t('common.confirm'),
		cancelButtonText: t('common.cancel'),
		type: 'warning',
	})
		.then(() => {
			userStore.logout()
			router.push('/login')
		})
		.catch(() => {})
}

onMounted(() => {
	startMessagePolling()
})

onBeforeUnmount(() => {
	if (messagePollTimer) {
		clearInterval(messagePollTimer)
		messagePollTimer = null
	}
})
</script>

<template>
	<div class="user-bar" :class="{ 'user-bar--mobile': mobile }">
		<template v-if="!mobile">
			<div class="panel-item" @click="searchVisible = true">
				<el-tooltip :content="t('header.search')" placement="bottom" :show-after="300">
					<el-icon :size="16"><ElIconSearch /></el-icon>
				</el-tooltip>
			</div>

			<el-popover
				v-model:visible="messagePanelVisible"
				:width="360"
				placement="bottom-end"
				trigger="click"
				popper-class="message-popover"
			>
				<template #reference>
					<div class="panel-item">
						<el-badge :value="messageCount" :max="99" :hidden="messageCount === 0">
							<el-icon :size="16"><ElIconBell /></el-icon>
						</el-badge>
					</div>
				</template>
				<MessagePanel
					:visible="messagePanelVisible"
					@navigate="messagePanelVisible = false"
					@count-change="handlePanelCountChange"
				/>
			</el-popover>

			<el-dropdown trigger="click" @command="switchLocale">
				<div class="panel-item panel-item--lang">
					<span class="lang-switch">{{ locale === 'zh-CN' ? 'EN' : '中' }}</span>
				</div>
				<template #dropdown>
					<el-dropdown-menu>
						<el-dropdown-item command="zh-CN" :disabled="locale === 'zh-CN'">中文</el-dropdown-item>
						<el-dropdown-item command="en" :disabled="locale === 'en'">English</el-dropdown-item>
					</el-dropdown-menu>
				</template>
			</el-dropdown>

			<div class="panel-item" @click="toggleFullscreen">
				<el-tooltip :content="isFullscreen ? t('header.exitFullscreen') : t('header.fullscreen')" placement="bottom" :show-after="300">
					<el-icon :size="16">
						<ElIconFullScreen v-if="!isFullscreen" />
						<ElIconAim v-else />
					</el-icon>
				</el-tooltip>
			</div>
		</template>

		<el-dropdown class="panel-item panel-item--user" trigger="click" @command="handleCommand">
			<div class="user-info">
				<span class="avatar-wrap">
					<el-avatar :size="30">{{ userNameFirst }}</el-avatar>
					<span class="ws-dot" :class="wsStatus" />
				</span>
				<label v-if="!mobile" class="user-info__name">{{ userName }}</label>
				<el-icon v-if="!mobile" class="user-info__arrow"><ElIconArrowDown /></el-icon>
			</div>
			<template #dropdown>
				<el-dropdown-menu>
					<el-dropdown-item v-if="mobile" command="search" icon="ElIconSearch">
						{{ t('header.search') }}
					</el-dropdown-item>
					<el-dropdown-item v-if="mobile" command="message" icon="ElIconBell">
						{{ t('header.message') }}
					</el-dropdown-item>
					<el-dropdown-item command="profile" icon="ElIconUser">
						{{ t('header.profile') }}
					</el-dropdown-item>
					<el-dropdown-item command="settings" icon="ElIconSetting">
						{{ t('header.systemSettings') }}
					</el-dropdown-item>
					<el-dropdown-item command="clearCache" icon="ElIconDelete">
						{{ t('header.clearCache') }}
					</el-dropdown-item>
					<el-dropdown-item divided command="logout" icon="ElIconSwitchButton">
						{{ t('header.logout') }}
					</el-dropdown-item>
				</el-dropdown-menu>
			</template>
		</el-dropdown>
	</div>

	<SearchModal v-model:visible="searchVisible" />
	<MessageDrawer v-model:visible="messageVisible" />
</template>

<style scoped>
.user-bar {
	display: flex;
	align-items: center;
	height: 100%;
}

.panel-item {
	position: relative;
	padding: 0 14px;
	cursor: pointer;
	height: 100%;
	display: flex;
	align-items: center;
	justify-content: center;
	transition: background-color 0.2s;
	color: var(--layout-text-secondary);
}
.panel-item:hover {
	background: rgba(0, 0, 0, 0.08);
	color: var(--layout-text);
}
.panel-item:active {
	background: rgba(0, 0, 0, 0.12);
}
.panel-item__inner {
	display: flex;
	align-items: center;
	justify-content: center;
	position: relative;
}
.panel-item :deep(.el-tooltip__trigger) {
	display: flex !important;
	align-items: center;
	justify-content: center;
}

.panel-item--lang {
	padding: 0 10px;
}
.lang-switch {
	font-size: 12px;
	font-weight: 700;
	letter-spacing: 0.5px;
	cursor: pointer;
	user-select: none;
}

.user-bar :deep(.el-dropdown) {
	height: 100%;
	display: flex;
	align-items: center;
}

.panel-item :deep(.el-badge__content) {
	top: -5px;
	right: -5px;
	min-width: 18px;
	height: 18px;
	padding: 0 5px;
	line-height: 18px;
	border-radius: 10px;
	background-color: #F56C6C;
	color: #fff;
	font-size: 12px;
	border: 1px solid #fff;
}

.user-info {
	display: flex;
	align-items: center;
	gap: 6px;
	height: 100%;
	cursor: pointer;
}
.user-info__name {
	display: inline-block;
	margin-left: 2px;
	font-size: 13px;
	color: var(--layout-text);
	cursor: pointer;
	white-space: nowrap;
}
.user-info__arrow {
	font-size: 12px;
	color: var(--layout-text-secondary);
}

.avatar-wrap {
	position: relative;
	display: inline-flex;
}
.ws-dot {
	position: absolute;
	top: 0;
	right: 0;
	width: 9px;
	height: 9px;
	border-radius: 50%;
	border: 2px solid var(--layout-header-bg, #fff);
	background: var(--el-color-danger);
	transition: background-color 0.3s;
}
.ws-dot.OPEN {
	background: var(--el-color-success);
	animation: ws-breathe 2s ease-in-out infinite;
}
.ws-dot.CONNECTING {
	background: var(--el-color-warning);
	animation: ws-breathe-warning 1.5s ease-in-out infinite;
}
@keyframes ws-breathe {
	0%,
	100% {
		box-shadow: 0 0 0 0 rgba(103, 194, 58, 0.6);
	}
	50% {
		box-shadow: 0 0 0 5px rgba(103, 194, 58, 0);
	}
}
@keyframes ws-breathe-warning {
	0%,
	100% {
		box-shadow: 0 0 0 0 rgba(230, 162, 60, 0.6);
	}
	50% {
		box-shadow: 0 0 0 5px rgba(230, 162, 60, 0);
	}
}
</style>

<style scoped>
.user-bar--mobile .panel-item {
	padding: 0 8px;
}
</style>

<style>
/* 铃铛下拉面板：去掉 el-popover 默认的 padding/圆角/阴影，让 message-panel 自己的样式接管 */
.el-popover.message-popover {
	padding: 0;
	border-radius: 4px;
	box-shadow: 0 2px 12px rgba(0, 0, 0, 0.1);
}
.el-popover.message-popover .el-popper__arrow::before,
.el-popover.message-popover .el-popper__arrow::after {
	display: none;
}
</style>
