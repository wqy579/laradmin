<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useUserStore } from '../../stores/modules/user'

const route = useRoute()
const userStore = useUserStore()

const menuData = computed(() => userStore.getMenu() || [])

function getIcon(item) {
	const name = item?.meta?.icon || item?.icon
	return name || null
}

function getTitle(item) {
	return item?.meta?.title || item?.title
}

const breadcrumbs = computed(() => {
	const crumbs = []
	const find = (items, target, parents = []) => {
		for (const item of items) {
			if (item.path === target) {
				crumbs.push(...parents, { path: item.path, title: getTitle(item), icon: getIcon(item) })
				return true
			}
			if (item.children) {
				if (find(item.children, target, [...parents, { path: item.path, title: getTitle(item), icon: getIcon(item) }])) {
					return true
				}
			}
		}
		return false
	}
	find(menuData.value, route.path)
	return crumbs
})
</script>

<template>
	<el-breadcrumb separator="/">
		<el-breadcrumb-item v-for="crumb in breadcrumbs" :key="crumb.path" :to="crumb.path === '/' ? '/' : undefined">
			<span class="crumb">
				<el-icon v-if="crumb.icon" :size="14" class="crumb__icon">
					<component :is="crumb.icon" />
				</el-icon>
				<span>{{ crumb.title }}</span>
			</span>
		</el-breadcrumb-item>
	</el-breadcrumb>
</template>

<style scoped>
.crumb {
	display: inline-flex;
	align-items: center;
	gap: 4px;
}
.crumb__icon {
	flex-shrink: 0;
}
</style>
