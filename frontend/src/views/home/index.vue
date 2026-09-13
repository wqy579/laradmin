<script setup>
import { ref, computed } from 'vue'
import { GridLayout, GridItem } from 'grid-layout-plus'
import { useResizeObserver, useWindowSize } from '@vueuse/core'
import { useAppStore } from '../../stores/app'
import WidgetPanel from './components/widget-panel.vue'
import { getWidgetConfig } from './widgets'

const store = useAppStore()
const showPanel = ref(false)

// 监听仪表盘容器实际宽度，侧边栏折叠/展开、布局模式切换都能正确触发响应
const gridWrapper = ref(null)
const containerWidth = ref(0)
useResizeObserver(gridWrapper, (entries) => {
	containerWidth.value = entries[0].contentRect.width
})

// 窄屏（<600px）采用单列堆叠布局；桌面保持 12 列网格。
// 关键：colNum 始终为 12，不随宽度切换，避免 widget 已保存的 x/w 与网格列数不匹配
// 导致自定义布局错乱。
const isCompact = computed(() => containerWidth.value > 0 && containerWidth.value < 600)

const responsiveConfig = computed(() => {
	const w = containerWidth.value
	if (w >= 1200) return { rowHeight: 48, margin: [12, 12] }
	if (w >= 900) return { rowHeight: 44, margin: [10, 10] }
	if (w >= 600) return { rowHeight: 40, margin: [8, 8] }
	return { rowHeight: 36, margin: [8, 8] }
})

// 窄屏派生布局：所有小部件单列堆叠，每个占满一行，仅作展示，不写回 store
const effectiveLayout = computed(() => {
	if (!isCompact.value) return store.dashboardLayout
	let y = 0
	return store.dashboardLayout.map((item) => {
		const layout = { ...item, x: 0, y, w: 12, h: item.h }
		y += item.h
		return layout
	})
})

// 窄屏堆叠模式下禁用拖拽/调整，避免污染用户保存的自定义布局
const canEdit = computed(() => store.dashboardEditMode && !isCompact.value)

// 按真实视口宽度判断小屏（与 header 的 @media max-width:600px 一致），
// header 按钮尺寸据此响应，避免依赖网格容器宽度的时序与初始值问题
const { width: windowWidth } = useWindowSize()
const isSmallScreen = computed(() => windowWidth.value > 0 && windowWidth.value < 600)

// 小屏下 header 按钮使用 small 尺寸，桌面使用 default
const headerButtonSize = computed(() => (isSmallScreen.value ? 'small' : 'default'))

const currentDate = computed(() => {
	const now = new Date()
	return now.toLocaleDateString('zh-CN', { year: 'numeric', month: 'long', day: 'numeric', weekday: 'long' })
})

function layoutUpdated(newLayout) {
	// 窄屏堆叠是派生布局，不写回 store
	if (isCompact.value) return
	store.dashboardLayout = newLayout
}

function handleAdd(widgetId) {
	store.addDashboardWidget(widgetId)
	showPanel.value = false
}

function getWidget(item) {
	return getWidgetConfig(item.widgetId)
}
</script>

<template>
	<div class="home-page">
		<div class="home-page__header">
			<div class="home-page__header-left">
				<div class="home-page__icon-wrapper">
					<el-icon :size="22"><ElIconMonitor /></el-icon>
				</div>
				<div class="home-page__text-group">
					<h1 class="home-page__title">数据仪表盘</h1>
					<p class="home-page__subtitle">{{ currentDate }}</p>
				</div>
			</div>

			<div class="home-page__header-right">
				<el-button v-if="!store.dashboardEditMode" icon="ElIconSetting" :size="headerButtonSize" @click="store.dashboardEditMode = true">编辑</el-button>
				<el-button-group v-else>
					<el-button type="primary" icon="ElIconCheck" :size="headerButtonSize" @click="store.dashboardEditMode = false">完成</el-button>
					<el-button icon="ElIconPlus" :size="headerButtonSize" @click="showPanel = true">添加</el-button>
					<el-button icon="ElIconRefreshRight" :size="headerButtonSize" @click="store.resetDashboardLayout()">重置</el-button>
				</el-button-group>
			</div>
		</div>

		<div ref="gridWrapper" class="home-page__grid-wrapper">
			<GridLayout :layout="effectiveLayout" :col-num="12" :row-height="responsiveConfig.rowHeight" :margin="responsiveConfig.margin" :is-draggable="canEdit" :is-resizable="canEdit" :vertical-compact="true" :use-css-transforms="true" @layout-updated="layoutUpdated" class="home-page__grid">
				<GridItem v-for="item in effectiveLayout" :key="item.i" :x="item.x" :y="item.y" :w="item.w" :h="item.h" :i="item.i" :min-w="1" :min-h="1">
					<div class="widget-item">
						<el-button v-if="store.dashboardEditMode" class="widget-item__delete" icon="ElIconClose" circle size="small" text type="danger" @click="store.removeDashboardWidget(item.i)" />
						<component v-if="getWidget(item)" :is="getWidget(item).component" v-bind="getWidget(item).props" />
						<el-empty v-else description="未知小部件" :image-size="60" />
					</div>
				</GridItem>
			</GridLayout>
		</div>

		<WidgetPanel v-model="showPanel" @add="handleAdd" />
	</div>
</template>

<style scoped>
.home-page {
	min-height: 100%;
	display: flex;
	flex-direction: column;
	padding-top: 0;
	background: #fdfdfd;
}

.home-page__header {
	display: flex;
	align-items: center;
	flex-wrap: wrap;
	gap: 10px 12px;
	padding: 12px 20px;
	background: var(--el-bg-color);
	border-bottom: 1px solid var(--el-border-color-lighter);
}

.home-page__header-left {
	display: flex;
	align-items: center;
	gap: 12px;
	flex: 0 1 auto;
	min-width: 0;
}

.home-page__icon-wrapper {
	width: 40px;
	height: 40px;
	border-radius: 10px;
	background: var(--el-color-primary-light-9);
	display: flex;
	align-items: center;
	justify-content: center;
	color: var(--el-color-primary);
	flex-shrink: 0;
}

.home-page__text-group {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
}

.home-page__title {
	font-size: 16px;
	font-weight: 600;
	color: var(--el-text-color-primary);
	margin: 0;
	line-height: 1.3;
}

.home-page__subtitle {
	font-size: 12px;
	color: var(--el-text-color-secondary);
	margin: 0;
}

.home-page__header-right {
	display: flex;
	align-items: center;
	flex-wrap: wrap;
	gap: 8px;
	margin-left: auto;
	justify-content: flex-end;
}

.home-page__grid-wrapper {
	flex: 1;
	padding: 0 20px 20px 20px;
	min-height: 0;
}

.home-page__grid {
	position: relative;
	margin: 0;
	padding: 0;
}

.home-page :deep(.vue-grid-layout) {
	position: relative;
	margin: 0;
	padding: 0;
}

.home-page :deep(.vue-grid-item) {
	transition: none;
}

.home-page :deep(.vue-grid-item.vue-draggable-dragging) {
	transition: none;
	z-index: 10;
	opacity: 0.9;
}

.home-page :deep(.vue-grid-placeholder) {
	background: var(--el-color-primary);
	opacity: 0.15;
	border-radius: 12px;
}

.home-page :deep(.vue-resizable-handle) {
	position: absolute;
	width: 16px;
	height: 16px;
	right: 4px;
	bottom: 4px;
	cursor: se-resize;
}

.widget-item {
	height: 100%;
	background: var(--el-bg-color);
	border: 1px solid var(--el-border-color-lighter);
	border-radius: 12px;
	overflow: hidden;
	position: relative;
	transition: box-shadow 0.2s ease;
}

.widget-item:hover {
	box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08);
}

.widget-item__delete {
	position: absolute;
	top: 8px;
	right: 8px;
	z-index: 10;
	opacity: 0;
	transition: opacity 0.2s ease;
}

.widget-item:hover .widget-item__delete {
	opacity: 1;
}

.home-page :deep(.vue-resizable-handle::after) {
	content: '';
	position: absolute;
	right: 2px;
	bottom: 2px;
	width: 8px;
	height: 8px;
	border-right: 2px solid var(--el-color-primary);
	border-bottom: 2px solid var(--el-color-primary);
	opacity: 0.6;
}

/* 小屏紧凑 header：减小内边距，icon/字号略缩 */
@media (max-width: 600px) {
	.home-page__header {
		padding: 10px 12px;
		gap: 10px;
	}
	.home-page__icon-wrapper {
		width: 34px;
		height: 34px;
		border-radius: 8px;
	}
	.home-page__title {
		font-size: 15px;
	}
	.home-page__subtitle {
		font-size: 11px;
	}
	.home-page__grid-wrapper {
		padding: 0 12px 12px 12px;
	}
}
</style>
