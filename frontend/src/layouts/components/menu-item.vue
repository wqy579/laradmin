<script setup>
import { menuIcons } from '../../config/menuIcons'

defineProps({
	item: { type: Object, required: true },
})

defineEmits(['select'])

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
</script>

<template>
	<el-sub-menu v-if="item.children?.length" :index="item.path">
		<template #title>
			<el-icon v-if="getIcon(item)"><component :is="getIcon(item)" /></el-icon>
			<span>{{ getTitle(item) }}</span>
		</template>
		<menu-item v-for="child in item.children" :key="child.path" :item="child" @select="(index) => $emit('select', index)" />
	</el-sub-menu>
	<el-menu-item v-else :index="item.path">
		<el-icon v-if="getIcon(item)"><component :is="getIcon(item)" /></el-icon>
		<template #title>{{ getTitle(item) }}</template>
	</el-menu-item>
</template>
