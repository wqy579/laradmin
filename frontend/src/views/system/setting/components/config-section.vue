<template>
	<div class="config-section" :class="`config-section--depth-${depth}`">
		<div class="config-section__title" :class="`config-section__title--depth-${depth}`">{{ group.name }}</div>
		<el-form v-if="directConfigs.length > 0" :model="formData" label-width="160px" class="config-form">
			<el-form-item v-for="config in directConfigs" :key="config.id" :label="config.name">
				<div class="config-input-wrapper">
					<el-input v-if="config.type === 'string'" v-model="formData[config.key]" :placeholder="config.description" />
					<el-input v-else-if="config.type === 'text'" v-model="formData[config.key]" type="textarea" :rows="3" :placeholder="config.description" />
					<el-input-number v-else-if="config.type === 'number'" v-model="formData[config.key]" :placeholder="config.description" controls-position="right" />
					<el-switch v-else-if="config.type === 'boolean'" v-model="formData[config.key]" />
					<el-select v-else-if="config.type === 'select'" v-model="formData[config.key]" :placeholder="config.description" clearable>
						<el-option v-for="opt in config.options" :key="opt.value" :label="opt.label" :value="opt.value" />
					</el-select>
					<el-radio-group v-else-if="config.type === 'radio'" v-model="formData[config.key]">
						<el-radio v-for="opt in config.options" :key="opt.value" :value="opt.value">{{ opt.label }}</el-radio>
					</el-radio-group>
					<el-checkbox-group v-else-if="config.type === 'checkbox'" v-model="formData[config.key]">
						<el-checkbox v-for="opt in config.options" :key="opt.value" :label="opt.value" :value="opt.value">{{ opt.label }}</el-checkbox>
					</el-checkbox-group>
					<div v-else-if="config.type === 'file'" class="file-input-wrapper">
						<s-upload v-model="fileLists[config.key]" :max-count="1" list-type="picture-card" @success="(data) => emit('fileSuccess', config.key, data)" @remove="() => emit('fileRemove', config.key)" />
					</div>
					<div v-else-if="config.type === 'json'" class="json-editor-wrapper">
						<el-input v-model="jsonStrings[config.key]" type="textarea" :rows="4" placeholder='{"key": "value"}' @blur="emit('validateJson', config.key)" />
						<div v-if="jsonErrors[config.key]" class="json-error">{{ jsonErrors[config.key] }}</div>
					</div>
					<el-button type="primary" link size="small" class="edit-btn" @click="emit('edit', config)">
						<el-icon><ElIconEdit /></el-icon>
						编辑
					</el-button>
				</div>
			</el-form-item>
		</el-form>
		<config-section v-for="child in group.children || []" :key="child.id" :group="child" :depth="depth + 1" />
	</div>
</template>

<script setup>
import { inject, computed } from 'vue'

const props = defineProps({
	group: { type: Object, required: true },
	depth: { type: Number, default: 0 },
})

const emit = defineEmits(['edit', 'fileSuccess', 'fileRemove', 'validateJson'])

const formData = inject('configFormData')
const jsonStrings = inject('configJsonStrings')
const jsonErrors = inject('configJsonErrors')
const fileLists = inject('configFileLists')
const allConfigs = inject('configAllConfigs')

const directConfigs = computed(() => allConfigs.value.filter((c) => c.parent_id == props.group.id).sort((a, b) => (a.sort || 0) - (b.sort || 0)))
</script>

<style scoped>
.config-section {
	margin-bottom: 20px;
}

.config-section--depth-1 {
	margin-left: 12px;
	padding: 16px;
	background: var(--el-fill-color-lighter);
	border-radius: 6px;
	margin-bottom: 16px;
}

.config-section--depth-2,
.config-section--depth-3 {
	margin-left: 8px;
	padding: 12px;
	background: var(--el-fill-color-extra-light);
	border-radius: 4px;
	margin-bottom: 12px;
}

.config-section__title {
	font-weight: 600;
	color: var(--el-text-color-primary);
	padding-left: 12px;
	border-left: 3px solid var(--el-color-primary);
	margin-bottom: 16px;
}

.config-section__title--depth-0 {
	font-size: 14px;
}

.config-section__title--depth-1 {
	font-size: 13px;
	border-left-color: var(--el-color-success);
}

.config-section__title--depth-2,
.config-section__title--depth-3 {
	font-size: 13px;
	border-left-color: var(--el-color-info);
}

.config-form {
	width: 100%;
}

.config-input-wrapper {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	width: 100%;
}

.config-input-wrapper > :deep(.el-input),
.config-input-wrapper > :deep(.el-select) {
	flex: 1;
	max-width: 400px;
}

.config-input-wrapper > :deep(.el-input-number) {
	flex: none;
	max-width: 200px;
}

.json-editor-wrapper {
	flex: 1;
}

.json-error {
	font-size: 12px;
	color: var(--el-color-danger);
	margin-top: 4px;
}

.config-input-wrapper .edit-btn {
	flex-shrink: 0;
	opacity: 0;
	transition: opacity 0.2s;
}

.config-input-wrapper:hover .edit-btn {
	opacity: 1;
}

.file-input-wrapper {
	flex: 1;
}
</style>
