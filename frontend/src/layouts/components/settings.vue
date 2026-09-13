<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAppStore } from '../../stores/app'
import { useResponsive } from '../../hooks/useResponsive'

const { t } = useI18n()
const appStore = useAppStore()
const { isMobile } = useResponsive()
const drawerSize = computed(() => (isMobile.value ? '85vw' : '360px'))
</script>

<template>
	<el-drawer :model-value="appStore.showSettings" :title="t('settings.title')" :size="drawerSize" @close="appStore.showSettings = false">
		<div class="settings-body">
			<!-- 布局模式 -->
			<div class="settings-section">
				<div class="settings-label">{{ t('settings.layoutMode') }}</div>
				<div class="settings-layouts">
					<div v-for="item in appStore.layoutList" :key="item.value" class="settings-layout-item" :class="{ active: appStore.layout === item.value }" @click="appStore.setLayout(item.value)">
						<div class="settings-layout-thumb" :class="'thumb--' + item.value">
							<span class="thumb-sidebar" />
							<span class="thumb-sub" />
							<span class="thumb-main">
								<span class="thumb-header" />
								<span class="thumb-content" />
							</span>
						</div>
						<span class="settings-layout-name">{{ item.label }}</span>
					</div>
				</div>
			</div>

			<!-- 主题色 -->
			<div class="settings-section">
				<div class="settings-label">{{ t('settings.themeColor') }}</div>
				<div class="settings-colors">
					<span v-for="c in appStore.themeColors" :key="c.color" class="settings-color-dot" :class="{ active: appStore.themeColor === c.color }" :style="{ background: c.color }" @click="appStore.setThemeColor(c.color)">
						<svg v-if="appStore.themeColor === c.color" viewBox="0 0 1024 1024" width="12" height="12">
							<path fill="#fff" d="M912 190h-69.9c-9.8 0-19.1 4.5-25.1 12.2L404.7 724.5 207 474a32 32 0 0 0-25.1-12.2H112c-6.7 0-10.4 7.7-6.3 12.9l281.9 348.5c12.8 15.8 35.5 15.8 48.3 0l481.4-598.8c4.1-5.2.4-12.9-6.3-12.9z" />
						</svg>
					</span>
				</div>
			</div>

			<!-- 界面设置 -->
			<div class="settings-section">
				<div class="settings-label">{{ t('settings.darkMode') }}</div>
				<el-switch :model-value="appStore.isDark" :active-text="t('settings.darkModeOn')" :inactive-text="t('settings.darkModeOff')" @change="appStore.toggleDark()" />
			</div>

			<div class="settings-section">
				<div class="settings-label">{{ t('settings.tagsBar') }}</div>
				<el-switch v-model="appStore.showTagsBar" :active-text="t('settings.tagsBarOn')" :inactive-text="t('settings.tagsBarOff')" />
			</div>
		</div>
	</el-drawer>
</template>

<style scoped>
.settings-body {
	display: flex;
	flex-direction: column;
	gap: 24px;
}
.settings-section {
	display: flex;
	flex-direction: column;
	gap: 10px;
}
.settings-label {
	font-size: 13px;
	font-weight: 500;
	color: var(--layout-text-secondary);
}
.settings-layouts {
	display: flex;
	gap: 16px;
}
.settings-layout-item {
	cursor: pointer;
	text-align: center;
	transition: transform 0.15s;
}
.settings-layout-item:hover {
	transform: translateY(-1px);
}
.settings-layout-name {
	font-size: 12px;
	color: var(--layout-text-secondary);
	margin-top: 6px;
	display: block;
	transition: color 0.2s;
}
.settings-layout-item.active .settings-layout-name {
	color: var(--el-color-primary);
	font-weight: 500;
}

/* ===== Layout thumbnails ===== */
.settings-layout-thumb {
	width: 60px;
	height: 42px;
	border: 2px solid var(--layout-border);
	border-radius: 6px;
	display: flex;
	overflow: hidden;
	transition: border-color 0.2s;
	background: #f0f2f5;
}
.settings-layout-item.active .settings-layout-thumb {
	border-color: var(--el-color-primary);
}
.thumb-sidebar {
	background: #001529;
	flex-shrink: 0;
}
.thumb-sub {
	background: #fff;
	border-right: 1px solid #e5e6eb;
	flex-shrink: 0;
}
.thumb-main {
	flex: 1;
	display: flex;
	flex-direction: column;
	min-width: 0;
}
.thumb-header {
	height: 8px;
	flex-shrink: 0;
	background: #fff;
	border-bottom: 1px solid #e5e6eb;
}
.thumb-content {
	flex: 1;
	background: #f0f2f5;
}

/* basic: sidebar + main */
.thumb--basic .thumb-sidebar {
	width: 14px;
}
.thumb--basic .thumb-sub {
	display: none;
}

/* top: no sidebar, header full width */
.thumb--top .thumb-sidebar,
.thumb--top .thumb-sub {
	display: none;
}
.thumb--top .thumb-header {
	height: 10px;
	background: #001529;
	border-bottom: none;
}

/* default: sidebar + sub + main */
.thumb--default .thumb-sidebar {
	width: 8px;
}
.thumb--default .thumb-sub {
	width: 10px;
}

.settings-colors {
	display: flex;
	gap: 10px;
	flex-wrap: wrap;
}
.settings-color-dot {
	width: 24px;
	height: 24px;
	border-radius: 50%;
	cursor: pointer;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	transition:
		transform 0.15s,
		box-shadow 0.15s;
}
.settings-color-dot:hover {
	transform: scale(1.15);
}
.settings-color-dot.active {
	box-shadow:
		0 0 0 2px var(--layout-surface),
		0 0 0 4px currentColor;
}
</style>
