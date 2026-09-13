<template>
	<div class="sPageSplit" :class="{ 'is-tablet': isTablet, 'is-mobile': isMobile }">
		<!-- 桌面/平板：侧栏常驻 -->
		<aside v-if="!isMobile" class="sPageSplit__side" :style="{ width: effectiveSideWidth }">
			<slot name="side" />
		</aside>

		<!-- 移动端：Drawer 承载侧栏 -->
		<el-drawer v-else v-model="drawerVisible" :title="sideTitle || '筛选'" direction="ltr" size="80%" :destroy-on-close="false">
			<slot name="side" />
		</el-drawer>

		<main class="sPageSplit__main">
			<!-- 移动端顶部唤起按钮 -->
			<div v-if="isMobile && $slots.side" class="sPageSplit__trigger">
				<el-button size="small" @click="drawerVisible = true">
					<el-icon><ElIconMenu /></el-icon>
					<span>{{ sideTitle || '筛选' }}</span>
				</el-button>
			</div>
			<slot />
		</main>
	</div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useResponsive } from '../../hooks/useResponsive'

const props = defineProps({
	// 桌面端侧栏宽度
	sideWidth: { type: String, default: '240px' },
	// 平板端侧栏宽度，默认收窄
	tabletSideWidth: { type: String, default: '180px' },
	// 侧栏标题（移动端 Drawer 标题 + 唤起按钮文案）
	sideTitle: { type: String, default: '' },
})

const { isMobile, isTablet } = useResponsive()

const drawerVisible = ref(false)

const effectiveSideWidth = computed(() => (isTablet.value ? props.tabletSideWidth : props.sideWidth))
</script>

<style scoped>
.sPageSplit {
	display: flex;
	height: 100%;
	overflow: hidden;
}

.sPageSplit__side {
	flex-shrink: 0;
	border-right: 1px solid var(--layout-border-light, var(--el-border-color-lighter));
	background: var(--layout-surface, var(--el-bg-color));
	display: flex;
	flex-direction: column;
	overflow: hidden;
	transition: width 0.25s;
}

.sPageSplit__main {
	flex: 1;
	min-width: 0;
	display: flex;
	flex-direction: column;
	overflow: hidden;
}

.sPageSplit__trigger {
	padding: 8px 12px;
	border-bottom: 1px solid var(--el-border-color-lighter);
	background: var(--el-bg-color);
	flex-shrink: 0;
}

/* 移动端：主区不再左右排列（aside 已移入 Drawer） */
.sPageSplit.is-mobile {
	flex-direction: column;
}

/* Drawer 内边距交由侧栏内容自行控制 */
:deep(.el-drawer) {
	width: 70vw !important;
}
:deep(.el-drawer__header) {
	margin-bottom: 0;
	padding: 12px 16px 0;
}
:deep(.el-drawer__body) {
	padding: 0;
}
</style>
