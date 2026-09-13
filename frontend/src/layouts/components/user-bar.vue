<script setup>
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { useFullscreen } from '@vueuse/core'
import { useAppStore } from '../../stores/app'
import { useUserStore } from '../../stores/modules/user'
import { useNotificationStore } from '../../stores/modules/notification'
import { useWebSocket } from '../../hooks/useWebSocket'
import SearchModal from './search.vue'
import MessageDrawer from './message.vue'

const props = defineProps({
	mobile: { type: Boolean, default: false },
})

const { locale, t } = useI18n()
const router = useRouter()
const appStore = useAppStore()
const userStore = useUserStore()

const searchVisible = ref(false)
const messageVisible = ref(false)
const { isFullscreen, toggle: toggleFullscreen } = useFullscreen()
const notificationStore = useNotificationStore()
const { status: wsStatus } = useWebSocket()

const messageCount = computed(() => (messageVisible.value ? 0 : notificationStore.unreadCount))

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
</script>

<template>
	<div class="user-bar" :class="{ 'user-bar--mobile': mobile }">
		<template v-if="!mobile">
			<div class="panel-item" @click="searchVisible = true">
				<el-tooltip :content="t('header.search')" placement="bottom" :show-after="300">
					<el-icon :size="16"><ElIconSearch /></el-icon>
				</el-tooltip>
			</div>

			<div class="panel-item" @click="messageVisible = true">
				<el-tooltip :content="t('header.message')" placement="bottom" :show-after="300">
					<el-badge :value="messageCount" :max="99" :hidden="messageCount === 0">
						<el-icon :size="16"><ElIconBell /></el-icon>
					</el-badge>
				</el-tooltip>
			</div>

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
	top: 2px;
	right: calc(1px + var(--el-badge-size) / 2);
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
