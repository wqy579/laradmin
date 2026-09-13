<script setup>
import { getWidgetList } from '../widgets'

defineProps({
	modelValue: Boolean,
})

const emit = defineEmits(['update:modelValue', 'add'])

const widgets = getWidgetList()
</script>

<template>
	<el-drawer :model-value="modelValue" title="添加小部件" size="360px" direction="rtl" @update:model-value="$emit('update:modelValue', $event)">
		<div class="widget-panel">
			<div v-for="widget in widgets" :key="widget.id" class="widget-panel__item" @click="$emit('add', widget.id)">
				<div class="widget-panel__icon-wrapper">
					<el-icon :size="24" class="widget-panel__icon">
						<component :is="widget.icon" />
					</el-icon>
				</div>
				<div class="widget-panel__info">
					<div class="widget-panel__title">{{ widget.title }}</div>
					<div class="widget-panel__size">{{ widget.defaultW }} × {{ widget.defaultH }}</div>
				</div>
				<el-icon :size="20" class="widget-panel__add-icon">
					<ElIconPlus />
				</el-icon>
			</div>
		</div>
	</el-drawer>
</template>

<style scoped>
.widget-panel {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.widget-panel__item {
	display: flex;
	align-items: center;
	gap: 16px;
	padding: 16px;
	border: 1px solid var(--el-border-color-lighter);
	border-radius: 12px;
	cursor: pointer;
	transition: all 0.2s ease;
}

.widget-panel__item:hover {
	border-color: var(--el-color-primary);
	background: var(--el-color-primary-light-9);
	transform: translateY(-2px);
	box-shadow: 0 4px 12px rgba(64, 158, 255, 0.1);
}

.widget-panel__icon-wrapper {
	width: 48px;
	height: 48px;
	border-radius: 12px;
	background: linear-gradient(135deg, var(--el-color-primary-light-7), var(--el-color-primary-light-9));
	display: flex;
	align-items: center;
	justify-content: center;
	flex-shrink: 0;
}

.widget-panel__icon {
	color: var(--el-color-primary);
}

.widget-panel__info {
	flex: 1;
}

.widget-panel__title {
	font-size: 14px;
	font-weight: 600;
	color: var(--el-text-color-primary);
	margin-bottom: 4px;
}

.widget-panel__size {
	font-size: 12px;
	color: var(--el-text-color-secondary);
}

.widget-panel__add-icon {
	color: var(--el-text-color-secondary);
	flex-shrink: 0;
	transition:
		color 0.2s ease,
		transform 0.2s ease;
}

.widget-panel__item:hover .widget-panel__add-icon {
	color: var(--el-color-primary);
	transform: scale(1.1);
}
</style>
