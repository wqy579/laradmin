<script setup>
import { ref, computed, watch, nextTick, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useEventListener, onClickOutside } from '@vueuse/core'
import { useAppStore } from '../../stores/app'

const props = defineProps({
	compact: Boolean,
})

const route = useRoute()
const router = useRouter()
const appStore = useAppStore()

const tagsRef = ref(null)
const contextMenuRef = ref(null)
const contextVisible = ref(false)
const contextLeft = ref(0)
const contextTop = ref(0)
const contextTag = ref(null)
const dragIndex = ref(null)
const dragOverIndex = ref(null)

const tags = computed(() => appStore.tagsList)
const affixTags = computed(() => {
	return tags.value
		.filter((t) => t.closable === false)
		.sort((a, b) => {
			const sortA = a.sort ?? 0
			const sortB = b.sort ?? 0
			if (sortA !== sortB) return sortA - sortB
			return (a.id ?? 0) - (b.id ?? 0)
		})
})
const normalTags = computed(() => tags.value.filter((t) => t.closable !== false))

function isActive(tag) {
	return tag.path === route.path
}

function isAffix(tag) {
	return tag.closable === false
}

function handleClick(tag) {
	router.push(tag.path)
}

function handleClose(tag) {
	if (isAffix(tag)) return
	appStore.removeTag(tag.path)
	if (isActive(tag)) {
		const remain = appStore.tagsList
		const next = remain[remain.length - 1]
		router.push(next?.path || '/')
	}
}

function openContextMenu(e, tag) {
	e.preventDefault()
	contextTag.value = tag
	contextVisible.value = true
	contextLeft.value = e.clientX + 1
	contextTop.value = e.clientY + 1
	nextTick(() => {
		const menu = document.querySelector('.tags-contextmenu')
		if (menu) {
			if (document.body.offsetWidth - e.clientX < menu.offsetWidth) {
				contextLeft.value = document.body.offsetWidth - menu.offsetWidth + 1
			}
			if (document.body.offsetHeight - e.clientY < menu.offsetHeight) {
				contextTop.value = e.clientY - menu.offsetHeight + 1
			}
		}
	})
}

function closeContextMenu() {
	contextVisible.value = false
	contextTag.value = null
}

function refreshTab() {
	closeContextMenu()
	router.replace({ path: '/redirect' + route.path })
}

function closeTab() {
	const tag = contextTag.value
	if (!tag || isAffix(tag)) return
	closeContextMenu()
	appStore.removeTag(tag.path)
	if (isActive(tag)) {
		const remain = appStore.tagsList
		const next = remain[remain.length - 1]
		router.push(next?.path || '/')
	}
}

function closeOtherTabs() {
	const tag = contextTag.value
	if (!tag) return
	closeContextMenu()
	appStore.removeOtherTags(tag.path)
	if (!appStore.tagsList.find((t) => t.path === route.path)) {
		router.push(tag.path)
	}
}

function closeLeftTabs() {
	const tag = contextTag.value
	if (!tag) return
	closeContextMenu()
	appStore.removeLeftTags(tag.path)
	if (!appStore.tagsList.find((t) => t.path === route.path)) {
		router.push(tag.path)
	}
}

function closeRightTabs() {
	const tag = contextTag.value
	if (!tag) return
	closeContextMenu()
	appStore.removeRightTags(tag.path)
	if (!appStore.tagsList.find((t) => t.path === route.path)) {
		router.push(tag.path)
	}
}

function closeAllTabs() {
	closeContextMenu()
	appStore.clearTags()
	const first = appStore.tagsList[0]
	router.push(first?.path || '/')
}

/* 拖拽排序 */
function onDragStart(e, index) {
	dragIndex.value = index
	e.dataTransfer.effectAllowed = 'move'
	e.target.classList.add('dragging')
}

function onDragEnd(e) {
	dragIndex.value = null
	dragOverIndex.value = null
	e.target.classList.remove('dragging')
	document.querySelectorAll('.tags-item.drag-over').forEach((el) => el.classList.remove('drag-over'))
}

function onDragOver(e, index) {
	e.preventDefault()
	e.dataTransfer.dropEffect = 'move'
	if (dragOverIndex.value !== index) {
		dragOverIndex.value = index
	}
}

function onDragLeave(e) {
	e.currentTarget.classList.remove('drag-over')
}

function onDrop(e, toIndex) {
	e.preventDefault()
	const from = dragIndex.value
	if (from === null || from === toIndex) return
	appStore.reorderTags(from, toIndex)
	dragIndex.value = null
	dragOverIndex.value = null
}

onClickOutside(contextMenuRef, closeContextMenu)

useEventListener(tagsRef, 'wheel', (event) => {
	const delta = event.wheelDelta || event.detail
	tagsRef.value.scrollLeft += delta > 0 ? -50 : 50
})

function scrollToActive() {
	nextTick(() => {
		if (!tagsRef.value) return
		const active = tagsRef.value.querySelector('.tags-item.active')
		if (active) active.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'nearest' })
	})
}

watch(
	() => route.path,
	() => {
		scrollToActive()
	},
)

onMounted(() => {
	scrollToActive()
})
</script>

<template>
	<div class="tags-bar" :class="{ 'tags-bar--compact': compact }">
		<div ref="tagsRef" class="tags-bar__inner">
			<!-- 固定标签 -->
			<div v-for="tag in affixTags" :key="'affix-' + tag.path" class="tags-item tags-item--affix" :class="{ active: isActive(tag) }" @click="handleClick(tag)" @contextmenu="openContextMenu($event, tag)">
				<el-icon v-if="tag.icon" :size="12" class="tags-item__icon">
					<component :is="tag.icon" />
				</el-icon>
				<span class="tags-item__text">{{ tag.title }}</span>
			</div>

			<!-- 分隔线 -->
			<div v-if="affixTags.length && normalTags.length" class="tags-divider" />

			<!-- 可拖动标签 -->
			<div
				v-for="(tag, index) in normalTags"
				:key="'tag-' + tag.path"
				class="tags-item"
				:class="{
					active: isActive(tag),
					dragging: dragIndex === index,
					'drag-over': dragOverIndex === index && dragIndex !== index,
				}"
				draggable="true"
				@dragstart="onDragStart($event, index)"
				@dragend="onDragEnd"
				@dragover="onDragOver($event, index)"
				@dragleave="onDragLeave"
				@drop="onDrop($event, index)"
				@click="handleClick(tag)"
				@contextmenu="openContextMenu($event, tag)"
			>
				<el-icon v-if="tag.icon" :size="12" class="tags-item__icon">
					<component :is="tag.icon" />
				</el-icon>
				<span class="tags-item__text">{{ tag.title }}</span>
				<span class="tags-item__close" @click.stop="handleClose(tag)">
					<el-icon :size="12"><ElIconClose /></el-icon>
				</span>
			</div>
		</div>
	</div>

	<teleport to="body">
		<transition name="ctx-fade">
			<ul v-if="contextVisible" ref="contextMenuRef" class="tags-contextmenu" :style="{ left: contextLeft + 'px', top: contextTop + 'px' }">
				<li @click="refreshTab">
					<el-icon><ElIconRefreshRight /></el-icon>
					{{ $t('header.refresh') }}
				</li>
				<hr />
				<li :class="{ disabled: contextTag && isAffix(contextTag) }" @click="closeTab">
					<el-icon><ElIconClose /></el-icon>
					{{ $t('header.close') }}
				</li>
				<li @click="closeOtherTabs">
					<el-icon><ElIconSort /></el-icon>
					{{ $t('header.closeOther') }}
				</li>
				<li @click="closeLeftTabs">
					<el-icon><ElIconBack /></el-icon>
					{{ $t('header.closeLeft') }}
				</li>
				<li @click="closeRightTabs">
					<el-icon><ElIconRight /></el-icon>
					{{ $t('header.closeRight') }}
				</li>
				<hr />
				<li @click="closeAllTabs">
					<el-icon><ElIconCloseBold /></el-icon>
					{{ $t('header.closeAll') }}
				</li>
			</ul>
		</transition>
	</teleport>
</template>

<style scoped>
.tags-bar {
	background: var(--layout-tags-bg);
	border-bottom: 1px solid var(--layout-border);
	flex-shrink: 0;
}
.tags-bar__inner {
	display: flex;
	align-items: center;
	gap: 6px;
	padding: 6px 12px;
	margin: 0 8px;
	overflow-x: auto;
	white-space: nowrap;
	scrollbar-width: none;
}
.tags-bar__inner::-webkit-scrollbar {
	height: 0;
}
.tags-divider {
	width: 1px;
	height: 16px;
	background: var(--layout-border);
	flex-shrink: 0;
	margin: 0 2px;
}
.tags-item {
	display: inline-flex;
	align-items: center;
	gap: 5px;
	padding: 4px 10px;
	height: 28px;
	box-sizing: border-box;
	font-size: 12px;
	line-height: 1;
	border: 1px solid var(--layout-border);
	border-radius: 4px;
	cursor: pointer;
	background: var(--layout-surface);
	color: var(--layout-text-secondary);
	transition:
		color 0.2s,
		border-color 0.2s,
		background-color 0.2s,
		transform 0.15s,
		opacity 0.15s;
	flex-shrink: 0;
	user-select: none;
}
.tags-item:hover {
	color: var(--el-color-primary);
	border-color: var(--el-color-primary);
}
.tags-item:active {
	transform: scale(0.96);
}
.tags-item__icon {
	flex-shrink: 0;
}
.tags-item.active {
	color: #fff;
	background: var(--el-color-primary);
	border-color: var(--el-color-primary);
	box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

/* 固定标签样式 */
.tags-item--affix:not(.active) {
	background: var(--el-color-warning-light-9);
	border-color: var(--el-color-warning-light-5);
	color: var(--el-color-warning);
}
.tags-item--affix:not(.active):hover {
	border-color: var(--el-color-warning);
}
.tags-item--affix.active {
	background: var(--el-color-warning);
	border-color: var(--el-color-warning);
	color: #fff;
	box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

/* 拖拽样式 */
.tags-item.dragging {
	opacity: 0.4;
}
.tags-item.drag-over {
	border-color: var(--el-color-success);
	box-shadow: 0 0 0 1px var(--el-color-success-light-5);
}

.tags-item__close {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 16px;
	height: 16px;
	border-radius: 50%;
	transition: background-color 0.15s;
}
.tags-item__close:hover {
	background: rgba(0, 0, 0, 0.08);
}
.tags-item.active .tags-item__close:hover,
.tags-item--affix.active .tags-item__close:hover {
	background: rgba(255, 255, 255, 0.25);
}

/* compact 模式 */
.tags-bar--compact .tags-bar__inner {
	padding: 4px 8px;
	margin: 0 8px;
	gap: 4px;
}
.tags-bar--compact .tags-item {
	height: 26px;
	padding: 2px 8px;
	font-size: 11px;
}
</style>

<style>
.tags-contextmenu {
	position: fixed;
	width: 160px;
	margin: 0;
	border-radius: 8px;
	background: var(--el-bg-color-overlay);
	border: 1px solid var(--el-border-color-light);
	box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
	z-index: 3000;
	list-style: none;
	padding: 4px 0;
}
.tags-contextmenu hr {
	margin: 4px 8px;
	border: none;
	height: 1px;
	background: var(--el-border-color-lighter);
}
.tags-contextmenu li {
	display: flex;
	align-items: center;
	gap: 8px;
	margin: 0;
	cursor: pointer;
	line-height: 30px;
	padding: 0 12px;
	font-size: 13px;
	color: var(--el-text-color-regular);
	border-radius: 4px;
	transition:
		background-color 0.15s,
		color 0.15s;
}
.tags-contextmenu li .el-icon {
	font-size: 14px;
}
.tags-contextmenu li:hover {
	background: var(--el-color-primary-light-9);
	color: var(--el-color-primary);
}
.tags-contextmenu li.disabled {
	cursor: not-allowed;
	color: var(--el-text-color-placeholder);
}
.tags-contextmenu li.disabled:hover {
	background: transparent;
	color: var(--el-text-color-placeholder);
}
.ctx-fade-enter-active,
.ctx-fade-leave-active {
	transition: opacity 0.15s ease;
}
.ctx-fade-enter-from,
.ctx-fade-leave-to {
	opacity: 0;
}
</style>
