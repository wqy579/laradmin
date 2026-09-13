<script setup>
import { ref, watch, nextTick, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useUserStore } from '../../stores/modules/user'
import { useResponsive } from '../../hooks/useResponsive'

const { isMobile } = useResponsive()

const { t } = useI18n()
const router = useRouter()
const userStore = useUserStore()

const menuData = computed(() => userStore.getMenu() || [])

const visible = defineModel('visible', { type: Boolean, default: false })
const keyword = ref('')
const results = ref([])
const selectedIdx = ref(0)
const inputRef = ref(null)

function flattenMenus(items, parents = []) {
	const out = []
	for (const item of items) {
		const title = item.meta?.title || item.title
		const rawIcon = item.meta?.icon || item.icon
		const icon = rawIcon || null
		const path = [...parents, title]
		if (item.path && !item.children) {
			out.push({ title, path: item.path, icon, breadcrumbs: path.join(' / ') })
		}
		if (item.children) {
			out.push(...flattenMenus(item.children, path))
		}
	}
	return out
}

const allMenus = computed(() => flattenMenus(menuData.value))

function handleSearch() {
	if (!keyword.value.trim()) {
		results.value = []
		selectedIdx.value = 0
		return
	}
	const kw = keyword.value.toLowerCase().trim()
	results.value = allMenus.value.filter((m) => m.title.toLowerCase().includes(kw) || m.breadcrumbs.toLowerCase().includes(kw))
	selectedIdx.value = 0
}

function handleSelect(item) {
	visible.value = false
	router.push(item.path)
}

function handleKeydown(e) {
	if (!results.value.length) {
		if (e.key === 'Escape') {
			visible.value = false
		}
		return
	}
	switch (e.key) {
		case 'ArrowUp':
			e.preventDefault()
			selectedIdx.value = selectedIdx.value > 0 ? selectedIdx.value - 1 : results.value.length - 1
			break
		case 'ArrowDown':
			e.preventDefault()
			selectedIdx.value = selectedIdx.value < results.value.length - 1 ? selectedIdx.value + 1 : 0
			break
		case 'Enter':
			e.preventDefault()
			if (results.value[selectedIdx.value]) handleSelect(results.value[selectedIdx.value])
			break
		case 'Escape':
			visible.value = false
			break
	}
}

function handleClose() {
	keyword.value = ''
	results.value = []
	selectedIdx.value = 0
}

watch(visible, (val) => {
	if (val) {
		nextTick(() => inputRef.value?.focus())
	} else {
		handleClose()
	}
})
</script>

<template>
	<el-dialog v-model="visible" :title="t('header.search')" :width="isMobile ? '95vw' : '520px'" :show-close="true" @close="handleClose" class="search-modal" append-to-body destroy-on-close>
		<el-input ref="inputRef" v-model="keyword" :placeholder="t('header.searchPlaceholder')" prefix-icon="ElIconSearch" size="large" clearable @input="handleSearch" @keydown="handleKeydown" />

		<div v-if="results.length" class="search-results">
			<div v-for="(item, idx) in results" :key="item.path" class="result-item" :class="{ active: selectedIdx === idx }" @click="handleSelect(item)" @mouseenter="selectedIdx = idx">
				<el-icon class="result-item__icon" :size="16"><component :is="item.icon" /></el-icon>
				<div class="result-item__content">
					<div class="result-item__title">{{ item.title }}</div>
					<div class="result-item__path">{{ item.breadcrumbs }}</div>
				</div>
				<kbd v-if="selectedIdx === idx" class="result-item__enter">Enter</kbd>
			</div>
		</div>

		<div v-else-if="keyword" class="search-empty">
			<el-empty :description="t('common.noData')" :image-size="56" />
		</div>

		<div v-else class="search-tips">
			<div class="search-tips__list">
				<div class="search-tips__item">
					<kbd>↑↓</kbd><span>{{ t('header.navigateResults') }}</span>
				</div>
				<div class="search-tips__item">
					<kbd>Enter</kbd><span>{{ t('header.selectResult') }}</span>
				</div>
				<div class="search-tips__item">
					<kbd>Esc</kbd><span>{{ t('header.closeSearch') }}</span>
				</div>
			</div>
		</div>
	</el-dialog>
</template>

<style scoped>
.search-modal :deep(.el-dialog__body) {
	padding-top: 16px;
}
.search-results {
	max-height: 340px;
	overflow-y: auto;
	margin-top: 12px;
	border: 1px solid var(--el-border-color-lighter);
	border-radius: 8px;
}
.result-item {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 10px 14px;
	cursor: pointer;
	transition: background-color 0.15s;
	border-bottom: 1px solid var(--el-border-color-extra-light);
}
.result-item:last-child {
	border-bottom: none;
	border-radius: 0 0 8px 8px;
}
.result-item:first-child {
	border-radius: 8px 8px 0 0;
}
.result-item:hover,
.result-item.active {
	background: var(--el-color-primary-light-9);
}
.result-item__icon {
	color: var(--el-color-primary);
	flex-shrink: 0;
	width: 16px;
	height: 16px;
}
.result-item__content {
	flex: 1;
	min-width: 0;
}
.result-item__title {
	font-size: 14px;
	color: var(--layout-text);
	font-weight: 500;
	line-height: 1.4;
}
.result-item__path {
	font-size: 12px;
	color: var(--layout-text-muted);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	margin-top: 2px;
}
.result-item__enter {
	display: inline-block;
	padding: 1px 6px;
	font-size: 10px;
	font-family: inherit;
	line-height: 1.4;
	color: var(--layout-text-muted);
	background: var(--el-fill-color-lighter);
	border: 1px solid var(--el-border-color-lighter);
	border-radius: 4px;
	flex-shrink: 0;
}
.search-empty {
	margin-top: 24px;
}
.search-tips {
	margin-top: 20px;
	display: flex;
	justify-content: center;
}
.search-tips__list {
	display: flex;
	gap: 20px;
}
.search-tips__item {
	display: flex;
	align-items: center;
	gap: 6px;
	font-size: 12px;
	color: var(--layout-text-muted);
}
kbd {
	display: inline-block;
	padding: 2px 7px;
	font-size: 11px;
	font-family: inherit;
	line-height: 1.4;
	color: var(--layout-text-secondary);
	background: var(--el-fill-color-light);
	border: 1px solid var(--el-border-color-lighter);
	border-radius: 4px;
	box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
}
</style>
