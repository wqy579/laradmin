<script setup>
defineProps({
	message: {
		type: String,
		default: '加载失败',
	},
})

defineEmits(['retry'])
</script>

<template>
	<div class="widget-error">
		<el-icon class="widget-error__icon" :size="36">
			<ElIconWarning />
		</el-icon>
		<div class="widget-error__message">{{ message }}</div>
		<el-button size="small" @click="$emit('retry')">重试</el-button>
	</div>
</template>

<style scoped>
.widget-error {
	height: 100%;
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	gap: 12px;
}

.widget-error__icon {
	color: var(--el-color-warning);
}

.widget-error__message {
	font-size: 13px;
	color: var(--el-text-color-secondary);
}
</style>
