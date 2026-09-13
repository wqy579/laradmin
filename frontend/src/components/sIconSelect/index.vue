<template>
	<div class="sIconSelect" v-bind="$attrs">
		<!-- 输入框触发器 -->
		<el-input :model-value="modelValue" :placeholder="placeholder" readonly :clearable="clearable" @clear="clearIcon" @click="openDialog">
			<template #prefix>
				<el-icon v-if="modelValue" class="sIconSelect-preview">
					<component :is="modelValue" />
				</el-icon>
			</template>
			<template #suffix>
				<el-button link type="primary" @click.stop="openDialog">
					<el-icon><ElIconGrid /></el-icon>
				</el-button>
			</template>
		</el-input>

		<!-- 图标选择弹窗 -->
		<el-dialog v-model="dialogVisible" title="选择图标" :width="isMobile ? '95vw' : 720" destroy-on-close append-to-body class="sIconSelect-dialog">
			<!-- 图标库 Tab -->
			<el-tabs v-model="activeTab" class="sIconSelect-tabs">
				<el-tab-pane label="Element Plus" name="element" />
				<el-tab-pane label="Ant Design" name="ant" />
			</el-tabs>

			<!-- 搜索 -->
			<el-input v-model="searchText" placeholder="搜索图标名称..." clearable class="sIconSelect-search">
				<template #prefix>
					<el-icon><ElIconSearch /></el-icon>
				</template>
			</el-input>

			<!-- 图标网格 -->
			<div class="sIconSelect-grid">
				<div v-for="icon in displayIcons" :key="icon.fullName" class="sIconSelect-item" :class="{ active: modelValue === icon.fullName }" @click="selectIcon(icon.fullName)" :title="icon.fullName">
					<span class="sIconSelect-item-icon">
						<el-icon :size="22"><component :is="icon.fullName" /></el-icon>
					</span>
					<span class="sIconSelect-item-name">{{ icon.name }}</span>
				</div>
				<div v-if="displayIcons.length === 0" class="sIconSelect-empty">
					<el-icon :size="40" color="var(--el-text-color-placeholder)"><ElIconSearch /></el-icon>
					<p>无匹配图标</p>
				</div>
			</div>

			<!-- 底部：已选预览 -->
			<div v-if="modelValue" class="sIconSelect-footer">
				<span class="sIconSelect-footer-label">当前选择：</span>
				<span class="sIconSelect-footer-preview">
					<el-icon :size="18"><component :is="modelValue" /></el-icon>
				</span>
				<code class="sIconSelect-footer-value">{{ modelValue }}</code>
			</div>

			<template #footer>
				<el-button @click="dialogVisible = false">取消</el-button>
				<el-button type="primary" :disabled="!modelValue" @click="confirmSelection">确认</el-button>
			</template>
		</el-dialog>
	</div>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { useResponsive } from '../../hooks/useResponsive'
import { resolveComponent } from 'vue'
import { allIconNames as elementAllNames } from './element-icons'
import { allIconNames as antAllNames } from './ant-icons'

const { isMobile } = useResponsive()

defineOptions({ inheritAttrs: false })

const props = defineProps({
	modelValue: {
		type: String,
		default: '',
	},
	clearable: {
		type: Boolean,
		default: true,
	},
	placeholder: {
		type: String,
		default: '请选择图标',
	},
})

const emit = defineEmits(['update:modelValue', 'change'])

const dialogVisible = ref(false)
const searchText = ref('')
const activeTab = ref('element')

// Element Plus 图标前缀
const ELEMENT_PREFIX = 'ElIcon'
// Ant Design 图标前缀
const ANT_PREFIX = 'AIcon'

// 格式化图标列表（附带 fullName）
const elementIcons = computed(() => elementAllNames.map((name) => ({ name, fullName: ELEMENT_PREFIX + name })))
const antIcons = computed(() => antAllNames.map((name) => ({ name, fullName: ANT_PREFIX + name })))

// 当前 Tab 对应的图标列表
const currentIcons = computed(() => (activeTab.value === 'element' ? elementIcons.value : antIcons.value))

// 搜索过滤后的展示列表
const displayIcons = computed(() => {
	if (!searchText.value) return currentIcons.value
	const kw = searchText.value.toLowerCase()
	return currentIcons.value.filter((icon) => icon.name.toLowerCase().includes(kw) || icon.fullName.toLowerCase().includes(kw))
})

// 打开弹窗时，根据当前值自动切换到对应 Tab
function openDialog() {
	dialogVisible.value = true
	searchText.value = ''
	if (props.modelValue) {
		if (props.modelValue.startsWith(ANT_PREFIX)) {
			activeTab.value = 'ant'
		} else {
			activeTab.value = 'element'
		}
	}
}

function selectIcon(fullName) {
	emit('update:modelValue', fullName)
	emit('change', fullName)
	dialogVisible.value = false
}

function confirmSelection() {
	dialogVisible.value = false
}

function clearIcon() {
	emit('update:modelValue', '')
	emit('change', '')
}

// 切换 Tab 时清空搜索
watch(activeTab, () => {
	searchText.value = ''
})
</script>

<style scoped>
.sIconSelect {
	display: inline-block;
	width: 100%;
}

/* 输入框光标变手型 */
.sIconSelect :deep(.el-input__wrapper) {
	cursor: pointer;
}

.sIconSelect-preview {
	font-size: 16px;
	color: var(--el-text-color-regular);
}

/* Tab 样式 */
.sIconSelect-tabs {
	margin-bottom: 12px;
}

.sIconSelect-tabs :deep(.el-tabs__nav-wrap::after) {
	height: 1px;
}

/* 搜索框 */
.sIconSelect-search {
	margin-bottom: 12px;
}

/* 图标网格 */
.sIconSelect-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(88px, 1fr));
	gap: 8px;
	max-height: 400px;
	overflow-y: auto;
	padding: 4px 0;
}
@media (max-width: 768px) {
	.sIconSelect-grid {
		grid-template-columns: repeat(auto-fill, minmax(64px, 1fr));
	}
}

.sIconSelect-item {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	padding: 12px 4px 8px;
	border-radius: 8px;
	cursor: pointer;
	transition: all 0.2s ease;
	border: 2px solid transparent;
	background: var(--el-fill-color-blank);
}

.sIconSelect-item:hover {
	background: var(--el-color-primary-light-9);
	border-color: var(--el-color-primary-light-7);
	transform: translateY(-1px);
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
}

.sIconSelect-item.active {
	background: var(--el-color-primary-light-8);
	border-color: var(--el-color-primary);
}

.sIconSelect-item-icon {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 28px;
	height: 28px;
	margin-bottom: 6px;
	color: var(--el-text-color-regular);
}

.sIconSelect-item.active .sIconSelect-item-icon {
	color: var(--el-color-primary);
}

.sIconSelect-item-name {
	display: block;
	width: 100%;
	font-size: 10px;
	line-height: 1.4;
	text-align: center;
	color: var(--el-text-color-secondary);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.sIconSelect-item.active .sIconSelect-item-name {
	color: var(--el-color-primary);
	font-weight: 500;
}

/* 空状态 */
.sIconSelect-empty {
	grid-column: 1 / -1;
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	padding: 48px 0;
	color: var(--el-text-color-placeholder);
	font-size: 14px;
	gap: 8px;
}

/* 底部已选预览 */
.sIconSelect-footer {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-top: 16px;
	padding-top: 12px;
	border-top: 1px solid var(--el-border-color-lighter);
}

.sIconSelect-footer-label {
	font-size: 13px;
	color: var(--el-text-color-secondary);
}

.sIconSelect-footer-preview {
	display: inline-flex;
	align-items: center;
	padding: 4px 8px;
	border-radius: 6px;
	background: var(--el-color-primary-light-9);
	color: var(--el-color-primary);
}

.sIconSelect-footer-value {
	font-size: 12px;
	padding: 2px 8px;
	border-radius: 4px;
	background: var(--el-fill-color-light);
	color: var(--el-text-color-regular);
	font-family: 'Menlo', 'Monaco', 'Courier New', monospace;
}
</style>

<style>
/* 弹窗样式（非 scoped） */
.sIconSelect-dialog .el-dialog__body {
	padding-top: 10px;
	padding-bottom: 10px;
}
</style>
