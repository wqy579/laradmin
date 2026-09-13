<template>
	<div class="setting-page">
		<div class="content-wrapper">
			<el-tabs v-model="activeTab" class="config-tabs">
				<el-tab-pane v-for="group in topGroups" :key="group.id" :label="group.name" :name="String(group.id)">
					<config-section v-for="sub in group.children || []" :key="sub.id" :group="sub" :depth="0" @edit="handleEditConfig" @file-success="handleFileSuccess" @file-remove="handleFileRemove" @validate-json="validateJson" />
					<config-section v-if="getDirectConfigs(group.id).length > 0" :group="{ id: group.id, name: '通用配置', children: [] }" :depth="0" @edit="handleEditConfig" @file-success="handleFileSuccess" @file-remove="handleFileRemove" @validate-json="validateJson" />
				</el-tab-pane>
			</el-tabs>
		</div>

		<div class="footer-bar">
			<el-button @click="configListVisible = true">
				<el-icon>
					<ElIconList />
				</el-icon>
				配置列表
			</el-button>
			<el-button @click="handleReset">重置</el-button>
			<el-button type="primary" :loading="saving" @click="handleSave">
				<el-icon>
					<ElIconCheck />
				</el-icon>
				保存配置
			</el-button>
		</div>

		<SaveConfigDialog v-if="dialog.save" v-model:visible="dialog.save" :record="currentConfig" :parent-id="activeTab" :group-tree="groupTree" @success="handleSaveSuccess" />

		<ConfigListDrawer v-model="configListVisible" :group-tree="groupTree" @refresh="handleRefresh" />
	</div>
</template>

<script setup>
import { ref, reactive, provide, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import systemApi from '@/api/system'
import SaveConfigDialog from './components/save.vue'
import ConfigSection from './components/config-section.vue'
import ConfigListDrawer from './components/config-list.vue'

const saving = ref(false)
const activeTab = ref('')
const topGroups = ref([])
const groupTree = ref([])
const allConfigs = ref([])
const formData = reactive({})
const jsonStrings = reactive({})
const jsonErrors = reactive({})
const fileLists = reactive({})

const dialog = reactive({ save: false })
const currentConfig = ref(null)
const configListVisible = ref(false)

// 通过 provide 向递归组件注入共享状态
provide('configFormData', formData)
provide('configJsonStrings', jsonStrings)
provide('configJsonErrors', jsonErrors)
provide('configFileLists', fileLists)
provide('configAllConfigs', allConfigs)

// --- 数据加载 ---

const loadGroups = async () => {
	try {
		const res = await systemApi.config.tree.get()
		if (res.code === 200) {
			groupTree.value = res.data || []
			topGroups.value = res.data || []
			if (topGroups.value.length > 0 && !activeTab.value) {
				activeTab.value = String(topGroups.value[0].id)
			}
		}
	} catch (error) {
		console.error('加载分组失败:', error)
	}
}

const loadConfigs = async () => {
	try {
		const res = await systemApi.config.all.get({ item_type: 'config' })
		if (res.code === 200) {
			allConfigs.value = res.data || []
			initFormData()
		}
	} catch (error) {
		console.error('加载配置项失败:', error)
		ElMessage.error('加载配置项失败')
	}
}

const initFormData = () => {
	allConfigs.value.forEach((config) => {
		const value = config.value ?? config.default_value ?? ''
		if (config.type === 'boolean') {
			formData[config.key] = value === true || value === '1'
		} else if (config.type === 'number') {
			formData[config.key] = value !== '' && value !== null ? Number(value) : null
		} else if (config.type === 'checkbox') {
			try {
				formData[config.key] = typeof value === 'string' ? JSON.parse(value) : Array.isArray(value) ? value : []
			} catch {
				formData[config.key] = []
			}
		} else if (config.type === 'json') {
			jsonStrings[config.key] = typeof value === 'string' ? value : JSON.stringify(value, null, 2)
			delete jsonErrors[config.key]
		} else if (config.type === 'file') {
			formData[config.key] = value
			fileLists[config.key] = parseFileList(value)
		} else {
			formData[config.key] = value
		}
	})
}

// --- 数据查询 ---

const getDirectConfigs = (groupId) => {
	return allConfigs.value.filter((c) => c.parent_id == groupId).sort((a, b) => (a.sort || 0) - (b.sort || 0))
}

// --- 文件处理 ---

function parseFileList(url) {
	if (!url) return []
	return [{ uid: -1, name: url.split('/').pop() || 'file', url, status: 'success', response: { url } }]
}

const handleFileSuccess = (key, data) => {
	formData[key] = data.url
}

const handleFileRemove = (key) => {
	formData[key] = ''
}

// --- JSON 验证 ---

const validateJson = (key) => {
	const jsonStr = jsonStrings[key]
	if (!jsonStr || jsonStr.trim() === '') {
		delete jsonErrors[key]
		return true
	}
	try {
		formData[key] = JSON.parse(jsonStr)
		delete jsonErrors[key]
		return true
	} catch (e) {
		jsonErrors[key] = 'JSON 格式错误: ' + e.message
		return false
	}
}

// --- 操作 ---

const handleSave = async () => {
	const hasJsonError = Object.keys(jsonErrors).some((key) => jsonErrors[key])
	if (hasJsonError) {
		ElMessage.error('请先修正 JSON 格式错误')
		return
	}

	Object.keys(jsonStrings).forEach((key) => validateJson(key))

	if (allConfigs.value.length === 0) {
		ElMessage.warning('暂无配置项可保存')
		return
	}
	const updates = []
	Object.keys(formData).forEach((key) => {
		const config = allConfigs.value.find((c) => c.key === key)
		if (config) {
			let value = formData[key]
			if (config.type === 'checkbox') value = JSON.stringify(value)
			updates.push({ id: config.id, value })
		}
	})

	if (updates.length === 0) {
		ElMessage.warning('暂无配置项需要保存')
		return
	}
	try {
		saving.value = true
		await systemApi.config.batchSave.post({ items: updates })
		ElMessage.success('保存成功')
		await loadConfigs()
	} catch (error) {
		console.error('保存配置失败:', error)
		ElMessage.error('保存配置失败')
	} finally {
		saving.value = false
	}
}

const handleReset = () => {
	ElMessageBox.confirm('确定要重置所有配置项吗？', '确认重置', {
		confirmButtonText: '确定',
		cancelButtonText: '取消',
		type: 'warning',
	}).then(async () => {
		await loadConfigs()
		ElMessage.success('已重置')
	})
}

const handleEditConfig = (config) => {
	currentConfig.value = config
	dialog.save = true
}

const handleAddConfig = () => {
	currentConfig.value = null
	dialog.save = true
}

const handleSaveSuccess = () => {
	dialog.save = false
	loadConfigs()
}

const handleRefresh = async () => {
	await loadGroups()
	await loadConfigs()
}

onMounted(async () => {
	await loadGroups()
	await loadConfigs()
})
</script>

<style scoped>
.setting-page {
	display: flex;
	flex-direction: column;
	height: 100%;
	background: var(--el-bg-color-page);
}

.content-wrapper {
	flex: 1;
	overflow: hidden;
	display: flex;
	background: var(--el-bg-color);
}

.config-tabs {
	width: 100%;
	display: flex;
	flex-direction: column;
}

.config-tabs :deep(.el-tabs__header) {
	margin-bottom: 0;
	padding: 0 16px;
	background: var(--el-fill-color-lighter);
}

.config-tabs :deep(.el-tabs__nav-wrap::after) {
	display: none;
}

.config-tabs :deep(.el-tabs__item) {
	padding: 0 20px;
	height: 48px;
	line-height: 48px;
	font-size: 14px;
	font-weight: 500;
}

.config-tabs :deep(.el-tabs__content) {
	flex: 1;
	overflow-y: auto;
	padding: 24px;
}

.footer-bar {
	padding: 16px 24px;
	border-top: 1px solid var(--el-border-color-lighter);
	background: var(--el-bg-color);
	display: flex;
	justify-content: flex-end;
	align-items: center;
	gap: 12px;
	box-shadow: 0 -2px 8px rgba(0, 0, 0, 0.04);
	z-index: 10;
}

@media (max-width: 768px) {
	.config-tabs :deep(.el-tabs__header) {
		padding: 0 8px;
	}

	.config-tabs :deep(.el-tabs__item) {
		padding: 0 12px;
		font-size: 13px;
	}

	.config-tabs :deep(.el-tabs__content) {
		padding: 16px 12px;
	}

	.footer-bar {
		flex-wrap: wrap;
		padding: 12px 16px;
		gap: 8px;
	}

	.footer-bar .el-button {
		flex: 1;
		min-width: 0;
	}
}
</style>
