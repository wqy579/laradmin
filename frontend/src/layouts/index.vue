<script setup>
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAppStore } from '../stores/app'
import { useUserStore } from '../stores/modules/user'
import { useResponsive } from '../hooks/useResponsive'
import Breadcrumb from './components/breadcrumb.vue'
import UserBar from './components/user-bar.vue'
import TagsBar from './components/tags-bar.vue'
import SettingsDrawer from './components/settings.vue'
import MenuItem from './components/menu-item.vue'
import { menuIcons } from '../config/menuIcons'

const route = useRoute()
const router = useRouter()
const appStore = useAppStore()
const userStore = useUserStore()

const { isMobile } = useResponsive()

// 过滤掉被标记为隐藏的菜单节点（如无启用模型时的"内容管理"），
// 同时剔除隐藏后变空的分组，递归保持树结构
function filterHiddenMenus(items) {
	return items
		.filter((i) => !i.meta?.hidden)
		.map((i) => (i.children ? { ...i, children: filterHiddenMenus(i.children) } : i))
		.filter((i) => !i.children || i.children.length > 0)
}
const menuData = computed(() => filterHiddenMenus(userStore.getMenu() || []))

const mobileSidebarVisible = ref(false)

const currentPageTitle = computed(() => findMenuTitle(route.path))

const isBasic = computed(() => appStore.layout === 'basic')
const isTop = computed(() => appStore.layout === 'top')
const isDefault = computed(() => appStore.layout === 'default')

const sidebarWidth = computed(() => (appStore.sidebarCollapsed ? '64px' : '220px'))

const sidebarBg = computed(() => (appStore.isDark ? 'var(--layout-sidebar-bg)' : '#001529'))

const sidebarTextColor = computed(() => (appStore.isDark ? 'rgba(255,255,255,0.65)' : 'rgba(255,255,255,0.7)'))

const sidebarActiveTextColor = '#fff'

const topHeaderBg = computed(() => (appStore.isDark ? 'var(--layout-sidebar-bg)' : '#001529'))

const activeSubMenu = computed(() => {
	const contains = (items, path) => {
		for (const item of items) {
			if (item.path === path || path.startsWith(item.path + '/')) return true
			if (item.children && contains(item.children, path)) return true
		}
		return false
	}
	return menuData.value.find((m) => m.path === route.path || route.path.startsWith(m.path + '/') || (m.children && contains(m.children, route.path))) || null
})
const activePrimary = computed(() => activeSubMenu.value?.path || route.path)

const activeTopItem = computed(() => activeSubMenu.value)

const defaultHasSub = computed(() => !!activeSubMenu.value?.children?.length)

function findMenuItem(path) {
	const find = (items) => {
		for (const item of items) {
			if (item.path === path) return item
			if (item.children) {
				const found = find(item.children)
				if (found) return found
			}
		}
	}
	// 精确匹配
	const exact = find(menuData.value)
	if (exact) return exact
	// 前缀匹配：找到路径最长的菜单项
	let best = null
	const search = (items) => {
		for (const item of items) {
			if (path.startsWith(item.path + '/') || path === item.path) {
				if (!best || item.path.length > best.path.length) best = item
			}
			if (item.children) search(item.children)
		}
	}
	search(menuData.value)
	return best
}

function findMenuTitle(path) {
	const item = findMenuItem(path)
	return getTitle(item) || path
}

function findMenuIcon(path) {
	return getIcon(findMenuItem(path))
}

function getClosable(item) {
	return !item?.meta?.affix
}

function getIcon(item) {
	// 优先使用后端返回的图标
	if (item?.meta?.icon || item?.icon) {
		return item?.meta?.icon || item?.icon
	}
	// 回退到前端配置的图标映射
	return menuIcons[item?.name] || null
}

function getTitle(item) {
	return item?.meta?.title || item?.title
}

function handleMenuSelect(index) {
	const item = findMenuItem(index)
	appStore.addTag({ path: index, title: getTitle(item) || index, icon: getIcon(item), closable: getClosable(item), sort: item.sort ?? item.meta?.sort ?? 0, id: item.id ?? 0 })
	router.push(index)
	mobileSidebarVisible.value = false
}

function handlePrimarySelect(item) {
	// 递归找到第一个叶子节点进行导航
	const firstLeaf = (node) => {
		if (node.children?.length) return firstLeaf(node.children[0])
		return node
	}
	const target = firstLeaf(item)
	appStore.addTag({ path: target.path, title: getTitle(target) || target.path, icon: getIcon(target), closable: getClosable(target), sort: target.sort ?? target.meta?.sort ?? 0, id: target.id ?? 0 })
	router.push(target.path)
	mobileSidebarVisible.value = false
}

function handleSubMenuSelect(index) {
	const item = findMenuItem(index)
	appStore.addTag({ path: index, title: getTitle(item) || index, icon: getIcon(item), closable: getClosable(item), sort: item?.sort ?? item?.meta?.sort ?? 0, id: item?.id ?? 0 })
	router.push(index)
	mobileSidebarVisible.value = false
}

function syncCurrentTag() {
	if (route.path === '/' || route.path.startsWith('/redirect')) return
	const item = findMenuItem(route.path)
	if (!item) return
	const exists = appStore.tagsList.find((t) => t.path === route.path)
	if (!exists) {
		appStore.addTag({ path: route.path, title: getTitle(item), icon: getIcon(item), closable: getClosable(item), sort: item.sort ?? item.meta?.sort ?? 0, id: item.id ?? 0 })
	}
}

watch(() => route.path, syncCurrentTag, { immediate: true })

function getTopActiveMenu() {
	return activeSubMenu.value?.path || route.path
}
</script>

<template>
	<!-- ==================== 移动端布局 ==================== -->
	<el-container v-if="isMobile" class="layout-mobile">
		<el-header class="mobile-header">
			<button class="mobile-header__hamburger" @click="mobileSidebarVisible = true">
				<el-icon :size="20"><ElIconMenu /></el-icon>
			</button>
			<span class="mobile-header__title">{{ currentPageTitle }}</span>
			<UserBar :mobile="true" />
		</el-header>
		<TagsBar v-if="appStore.showTagsBar" compact />
		<el-main class="layout-content">
			<RouterView :key="route.fullPath" />
		</el-main>

		<!-- 遮罩层 -->
		<Transition name="mobile-overlay">
			<div v-if="mobileSidebarVisible" class="mobile-overlay" @click="mobileSidebarVisible = false" />
		</Transition>

		<!-- 移动端侧边栏 -->
		<Transition name="mobile-sidebar">
			<aside v-if="mobileSidebarVisible" class="mobile-sidebar">
				<div class="mobile-sidebar__logo">
					<svg viewBox="0 0 28 28" width="24" height="24">
						<rect fill="#409eff" rx="6" width="28" height="28" />
						<text x="14" y="20" fill="#fff" font-size="18" font-weight="bold" text-anchor="middle">T</text>
					</svg>
					<span class="mobile-sidebar__logo-text">Tensent</span>
				</div>
				<el-scrollbar class="mobile-sidebar__scroll">
					<el-menu :default-active="route.path" :background-color="sidebarBg" :text-color="sidebarTextColor" :active-text-color="sidebarActiveTextColor" @select="handleMenuSelect">
						<MenuItem v-for="item in menuData" :key="item.path" :item="item" @select="handleMenuSelect" />
					</el-menu>
				</el-scrollbar>
				<div class="mobile-sidebar__footer">
					<button
						class="mobile-sidebar__footer-btn"
					@click="appStore.showSettings = true; mobileSidebarVisible = false"
					>
						<el-icon :size="18"><ElIconSetting /></el-icon>
						<span>布局设置</span>
					</button>
				</div>
			</aside>
		</Transition>

		<SettingsDrawer />
	</el-container>

	<!-- ==================== 经典布局 basic ==================== -->
	<el-container v-else-if="isBasic" class="layout-basic">
		<el-aside :width="sidebarWidth" class="sidebar">
			<div class="sidebar-logo">
				<svg class="sidebar-logo__icon" viewBox="0 0 28 28" width="24" height="24">
					<rect fill="#409eff" rx="6" width="28" height="28" />
					<text x="14" y="20" fill="#fff" font-size="18" font-weight="bold" text-anchor="middle">T</text>
				</svg>
				<transition name="fade">
					<span v-show="!appStore.sidebarCollapsed" class="sidebar-logo__text">Tensent</span>
				</transition>
			</div>
			<el-scrollbar class="sidebar__scroll">
				<el-menu :default-active="route.path" :collapse="appStore.sidebarCollapsed" :collapse-transition="false" :background-color="sidebarBg" :text-color="sidebarTextColor" :active-text-color="sidebarActiveTextColor" @select="handleMenuSelect">
					<MenuItem v-for="item in menuData" :key="item.path" :item="item" @select="handleMenuSelect" />
				</el-menu>
			</el-scrollbar>
			<div class="sidebar-footer">
				<button class="sidebar-footer__btn" @click="appStore.toggleSidebar()">
					<el-icon :size="18">
						<ElIconFold v-if="!appStore.sidebarCollapsed" />
						<ElIconExpand v-else />
					</el-icon>
				</button>
			</div>
		</el-aside>

		<el-container class="layout-main">
			<el-header class="layout-header">
				<div class="layout-header__left"><Breadcrumb /></div>
				<div class="layout-header__right"><UserBar /></div>
			</el-header>
			<TagsBar v-if="appStore.showTagsBar" />
			<el-main class="layout-content">
				<RouterView :key="route.fullPath" />
			</el-main>
		</el-container>
	</el-container>

	<!-- ==================== 顶部菜单 top ==================== -->
	<el-container v-else-if="isTop" class="layout-top">
		<el-header class="layout-top__header">
			<div class="layout-top__logo">
				<svg viewBox="0 0 28 28" width="24" height="24" style="flex-shrink: 0">
					<rect fill="#409eff" rx="6" width="28" height="28" />
					<text x="14" y="20" fill="#fff" font-size="18" font-weight="bold" text-anchor="middle">T</text>
				</svg>
				<span>Tensent Admin</span>
			</div>
			<div class="layout-top__menu-wrap">
				<el-menu :default-active="getTopActiveMenu()" mode="horizontal" :ellipsis="false" :background-color="topHeaderBg" :text-color="sidebarTextColor" :active-text-color="sidebarActiveTextColor" class="layout-top__menu" @select="handleMenuSelect">
					<MenuItem v-for="item in menuData" :key="item.path" :item="item" @select="handleMenuSelect" />
				</el-menu>
			</div>
			<div class="layout-top__right">
				<UserBar />
			</div>
		</el-header>

		<TagsBar v-if="appStore.showTagsBar" />

		<el-main class="layout-content">
			<RouterView :key="route.fullPath" />
		</el-main>
	</el-container>

	<!-- ==================== 双栏布局 default ==================== -->
	<el-container v-else-if="isDefault" class="layout-default">
		<div class="default-primary">
			<div class="default-primary__logo">
				<svg viewBox="0 0 28 28" width="28" height="28">
					<rect fill="#409eff" rx="6" width="28" height="28" />
					<text x="14" y="20" fill="#fff" font-size="18" font-weight="bold" text-anchor="middle">T</text>
				</svg>
			</div>
			<el-scrollbar class="default-primary__scroll">
				<div class="default-primary__menu">
					<el-tooltip v-for="item in menuData" :key="item.path" :content="getTitle(item)" placement="right" :show-after="300">
						<div
							class="default-primary__item"
							:class="{
								active: item === activeSubMenu,
							}"
							@click="handlePrimarySelect(item)"
						>
							<el-icon :size="20"><component :is="getIcon(item)" /></el-icon>
						</div>
					</el-tooltip>
				</div>
			</el-scrollbar>
		</div>

		<div v-if="defaultHasSub" class="default-sub" :class="{ 'default-sub--collapsed': appStore.sidebarCollapsed }">
			<template v-if="activeSubMenu">
				<div class="default-sub__title">
					<el-icon class="default-sub__title-icon"><component :is="getIcon(activeSubMenu)" /></el-icon>
					<span v-show="!appStore.sidebarCollapsed" class="default-sub__title-text">{{ activeSubMenu.title }}</span>
				</div>
				<el-scrollbar class="default-sub__scroll">
					<el-menu :default-active="route.path" :collapse="appStore.sidebarCollapsed" :collapse-transition="false" class="default-sub__menu" @select="handleSubMenuSelect">
						<MenuItem v-for="child in activeSubMenu.children" :key="child.path" :item="child" @select="handleSubMenuSelect" />
					</el-menu>
				</el-scrollbar>
			</template>
			<div class="default-sub__footer">
				<button class="sidebar-footer__btn" @click="appStore.toggleSidebar()">
					<el-icon :size="18">
						<ElIconFold v-if="!appStore.sidebarCollapsed" />
						<ElIconExpand v-else />
					</el-icon>
				</button>
			</div>
		</div>

		<el-container class="layout-main">
			<el-header class="layout-header">
				<div class="layout-header__left"><Breadcrumb /></div>
				<div class="layout-header__right"><UserBar /></div>
			</el-header>
			<TagsBar v-if="appStore.showTagsBar" />
			<el-main class="layout-content">
				<RouterView :key="route.fullPath" />
			</el-main>
		</el-container>
	</el-container>

	<!-- ==================== 浮动设置按钮（桌面端） ==================== -->
	<template v-if="!isMobile">
		<button class="settings-btn" @click="appStore.showSettings = true">
			<el-icon :size="18"><ElIconSetting /></el-icon>
		</button>
		<SettingsDrawer />
	</template>
</template>

<style scoped>
/* ===== 公共 ===== */
.layout-main {
	flex-direction: column;
	overflow: hidden;
}
.layout-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	height: 56px !important;
	padding: 0 16px;
	background: var(--layout-header-bg);
	border-bottom: 1px solid var(--layout-border);
	flex-shrink: 0;
}
.layout-header__left {
	display: flex;
	align-items: center;
	overflow: hidden;
	min-width: 0;
}
.layout-header__right {
	display: flex;
	align-items: center;
	flex-shrink: 0;
	height: 100%;
}
.layout-content {
	background: var(--layout-content-bg);
	overflow-y: auto;
	padding: 0;
}

/* ===== sidebar (basic + shared footer) ===== */
.sidebar {
	background: var(--layout-sidebar-bg);
	overflow: hidden;
	transition: width 0.28s;
	display: flex;
	flex-direction: column;
}
.sidebar__scroll {
	flex: 1;
	overflow: hidden;
}
.sidebar-logo {
	height: 56px;
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 10px;
	border-bottom: 1px solid var(--layout-sidebar-border);
	overflow: hidden;
	flex-shrink: 0;
}
.sidebar-logo__text {
	color: var(--layout-sidebar-active-text);
	font-size: 16px;
	font-weight: 700;
	letter-spacing: 0.5px;
	white-space: nowrap;
}
.sidebar-footer {
	height: 56px;
	display: flex;
	align-items: center;
	justify-content: center;
	border-top: 1px solid var(--layout-sidebar-border);
	flex-shrink: 0;
}
.sidebar-footer__btn {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 36px;
	height: 36px;
	border: none;
	border-radius: 6px;
	background: transparent;
	cursor: pointer;
	color: var(--layout-sidebar-text);
	transition:
		color 0.2s,
		background-color 0.2s;
	padding: 0;
}
.sidebar-footer__btn:hover {
	color: var(--layout-sidebar-active-text);
	background: var(--layout-sidebar-hover);
}
.fade-enter-active,
.fade-leave-active {
	transition: opacity 0.2s;
}
.fade-enter-from,
.fade-leave-to {
	opacity: 0;
}

/* ===== basic ===== */
.layout-basic {
	height: 100vh;
	overflow: hidden;
}

/* ===== top ===== */
.layout-top {
	flex-direction: column;
	height: 100vh;
	overflow: hidden;
}
.layout-top__header {
	display: flex;
	align-items: center;
	height: 56px;
	background: var(--layout-sidebar-bg);
	padding: 0 16px;
	overflow: hidden;
}
.layout-top__logo {
	color: var(--layout-sidebar-active-text);
	font-size: 15px;
	font-weight: 700;
	margin-right: 20px;
	white-space: nowrap;
	display: flex;
	align-items: center;
	gap: 8px;
	flex-shrink: 0;
}
.layout-top__menu-wrap {
	flex: 1;
	min-width: 0;
	overflow: hidden;
}
.layout-top__menu {
	border-bottom: none !important;
	height: 56px;
}
.layout-top__menu :deep(.el-menu-item),
.layout-top__menu :deep(.el-sub-menu__title) {
	height: 56px;
	line-height: 56px;
	border-bottom: none !important;
}
.layout-top__right {
	flex-shrink: 0;
	margin-left: 8px;
}
.layout-top__right :deep(.panel-item) {
	color: var(--layout-sidebar-text);
}
.layout-top__right :deep(.panel-item:hover) {
	color: var(--layout-sidebar-active-text);
	background: var(--layout-sidebar-hover);
}
.layout-top__right :deep(.user-info__name) {
	color: var(--layout-sidebar-text);
}
.layout-top__right :deep(.user-info__arrow) {
	color: var(--layout-sidebar-text);
}

/* ===== dual ===== */
.layout-default {
	height: 100vh;
	overflow: hidden;
}
.default-primary {
	width: 68px;
	background: var(--layout-sidebar-bg);
	display: flex;
	flex-direction: column;
	flex-shrink: 0;
}
.default-primary__logo {
	height: 56px;
	display: flex;
	align-items: center;
	justify-content: center;
	border-bottom: 1px solid var(--layout-sidebar-border);
	flex-shrink: 0;
}
.default-primary__scroll {
	flex: 1;
	overflow: hidden;
}
.default-primary__menu {
	padding: 8px 0;
	display: flex;
	flex-direction: column;
	align-items: center;
}
.default-primary__item {
	width: 44px;
	height: 44px;
	border-radius: 10px;
	display: flex;
	align-items: center;
	justify-content: center;
	color: var(--layout-sidebar-text);
	cursor: pointer;
	transition:
		color 0.2s,
		background-color 0.2s;
	margin-bottom: 4px;
}
.default-primary__item:hover {
	color: var(--layout-sidebar-active-text);
	background: var(--layout-sidebar-hover);
}
.default-primary__item.active {
	color: var(--layout-sidebar-active-text);
	background: var(--el-color-primary);
	box-shadow: var(--layout-shadow);
}
.default-sub {
	width: 220px;
	background: var(--layout-sub-sidebar-bg);
	border-right: 1px solid var(--layout-border);
	overflow: hidden;
	display: flex;
	flex-direction: column;
	flex-shrink: 0;
	transition: width 0.28s;
}
.default-sub--collapsed {
	width: 64px;
}
.default-sub__title {
	height: 56px;
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 0;
	font-size: 14px;
	font-weight: 600;
	color: var(--layout-text);
	border-bottom: 1px solid var(--layout-border);
	flex-shrink: 0;
	gap: 8px;
	overflow: hidden;
}
.default-sub__title-text {
	white-space: nowrap;
}
.default-sub__title-icon {
	color: var(--el-color-primary);
	flex-shrink: 0;
}
.default-sub__scroll {
	flex: 1;
	overflow: hidden;
}
.default-sub__menu {
	border-right: none;
	padding-top: 4px;
}
.default-sub__footer {
	height: 56px;
	display: flex;
	align-items: center;
	justify-content: center;
	border-top: 1px solid var(--layout-border);
	flex-shrink: 0;
}
.default-sub__footer .sidebar-footer__btn {
	color: var(--layout-text-secondary);
}
.default-sub__footer .sidebar-footer__btn:hover {
	color: var(--el-color-primary);
	background: var(--el-fill-color-light);
}

/* ===== 浮动设置按钮 ===== */
.settings-btn {
	position: fixed;
	right: 24px;
	bottom: 100px;
	width: 42px;
	height: 42px;
	border: none;
	border-radius: 50%;
	background: var(--el-color-primary);
	display: flex;
	align-items: center;
	justify-content: center;
	cursor: pointer;
	box-shadow: 0 2px 12px rgba(0, 0, 0, 0.15);
	transition:
		transform 0.2s,
		box-shadow 0.2s;
	z-index: 999;
	color: #fff;
	padding: 0;
}
.settings-btn:hover {
	transform: scale(1.08);
	box-shadow: 0 4px 16px rgba(0, 0, 0, 0.2);
}
.settings-btn:active {
	transform: scale(0.95);
}

/* ===== 移动端布局 ===== */
.layout-mobile {
	height: 100vh;
	overflow: hidden;
	flex-direction: column;
}
.mobile-header {
	display: flex;
	align-items: center;
	height: 52px !important;
	padding: 0 12px;
	background: var(--layout-header-bg);
	border-bottom: 1px solid var(--layout-border);
	flex-shrink: 0;
	gap: 8px;
}
.mobile-header__hamburger {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 40px;
	height: 40px;
	border: none;
	border-radius: 6px;
	background: transparent;
	cursor: pointer;
	color: var(--layout-text);
	flex-shrink: 0;
	padding: 0;
}
.mobile-header__hamburger:active {
	background: var(--el-fill-color-light);
}
.mobile-header__title {
	flex: 1;
	font-size: 15px;
	font-weight: 600;
	color: var(--layout-text);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	min-width: 0;
}

/* 遮罩层 */
.mobile-overlay {
	position: fixed;
	inset: 0;
	background: rgba(0, 0, 0, 0.45);
	z-index: 1000;
}
.mobile-overlay-enter-active,
.mobile-overlay-leave-active {
	transition: opacity 0.25s;
}
.mobile-overlay-enter-from,
.mobile-overlay-leave-to {
	opacity: 0;
}

/* 侧边栏 */
.mobile-sidebar {
	position: fixed;
	top: 0;
	left: 0;
	bottom: 0;
	width: 260px;
	background: var(--layout-sidebar-bg);
	z-index: 1001;
	display: flex;
	flex-direction: column;
	overflow: hidden;
	box-shadow: 4px 0 16px rgba(0, 0, 0, 0.15);
}
.mobile-sidebar-enter-active,
.mobile-sidebar-leave-active {
	transition: transform 0.25s ease;
}
.mobile-sidebar-enter-from,
.mobile-sidebar-leave-to {
	transform: translateX(-100%);
}
.mobile-sidebar__logo {
	height: 52px;
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 0 16px;
	border-bottom: 1px solid var(--layout-sidebar-border);
	flex-shrink: 0;
}
.mobile-sidebar__logo-text {
	color: var(--layout-sidebar-active-text);
	font-size: 16px;
	font-weight: 700;
}
.mobile-sidebar__scroll {
	flex: 1;
	overflow: hidden;
}
.mobile-sidebar__footer {
	height: 52px;
	display: flex;
	align-items: center;
	justify-content: center;
	border-top: 1px solid var(--layout-sidebar-border);
	flex-shrink: 0;
}
.mobile-sidebar__footer-btn {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	gap: 6px;
	height: 40px;
	padding: 0 16px;
	border: none;
	border-radius: 6px;
	background: transparent;
	cursor: pointer;
	color: var(--layout-sidebar-text);
	font-size: 13px;
}
.mobile-sidebar__footer-btn:active {
	background: var(--layout-sidebar-hover);
}
</style>
