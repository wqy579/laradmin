<template>
	<el-drawer v-model="visible" title="配置列表" :size="'80%'" destroy-on-close @close="handleClose">
		<template #header>
			<span>配置列表</span>
			<el-button type="primary" size="small" style="margin-left: 16px" @click="handleAdd">
				<el-icon><ElIconPlus /></el-icon>
				添加配置
			</el-button>
		</template>
		<sTable ref="tableRef" tableName="system_setting_list" :data="treeData" :columns="columns" :loading="loading" rowKey="id" height="100%" hidePagination :tree-config="{ childrenField: 'children', accordion: false, showLine: true, expandAll: true }">
			<template #type_default="{ row }">
				<el-tag v-if="row.item_type === 'group'" type="info" size="small">分组</el-tag>
				<el-tag v-else size="small">{{ typeLabels[row.type] || row.type }}</el-tag>
			</template>
			<template #value_default="{ row }">
				<template v-if="row.item_type === 'group'">-</template>
				<template v-else-if="row.type === 'boolean'">
					<el-tag :type="row.value === '1' || row.value === true ? 'success' : 'danger'" size="small">
						{{ row.value === '1' || row.value === true ? '是' : '否' }}
					</el-tag>
				</template>
				<template v-else-if="row.type === 'file'">
					<el-image v-if="row.value" :src="row.value" style="width: 32px; height: 32px" fit="cover" :preview-src-list="[row.value]" preview-teleported />
					<span v-else class="text-muted">未设置</span>
				</template>
				<template v-else>
					<span :title="row.value">{{ row.value ? (String(row.value).length > 40 ? String(row.value).slice(0, 40) + '...' : row.value) : '-' }}</span>
				</template>
			</template>
			<template #status_default="{ row }">
				<el-tag :type="row.status ? 'success' : 'danger'" size="small">{{ row.status ? '启用' : '禁用' }}</el-tag>
			</template>
			<template #action_default="{ row }">
				<el-button type="primary" link size="small" @click="handleEdit(row)">编辑</el-button>
				<el-button type="danger" link size="small" @click="handleDelete(row)">删除</el-button>
			</template>
		</sTable>

		<SaveConfigDialog v-if="configDialogVisible" v-model:visible="configDialogVisible" :record="currentRecord" :parent-id="null" :group-tree="groupTree" @success="handleSaveSuccess" />

		<SaveGroupDialog v-if="groupDialogVisible" v-model:visible="groupDialogVisible" :record="currentRecord" :group-tree="groupTree" @success="handleSaveSuccess" />
	</el-drawer>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import systemApi from '@/api/system'
import SaveConfigDialog from './save.vue'
import SaveGroupDialog from './save-group.vue'

const props = defineProps({
	modelValue: Boolean,
	groupTree: { type: Array, default: () => [] },
})

const emit = defineEmits(['update:modelValue', 'refresh'])

const visible = computed({
	get: () => props.modelValue,
	set: (val) => emit('update:modelValue', val),
})

const tableRef = ref(null)
const loading = ref(false)
const treeData = ref([])
const configDialogVisible = ref(false)
const groupDialogVisible = ref(false)
const currentRecord = ref(null)

const typeLabels = {
	string: '字符串',
	text: '文本',
	number: '数字',
	boolean: '布尔值',
	select: '下拉选择',
	radio: '单选',
	checkbox: '多选',
	file: '文件',
	json: 'JSON',
}

const columns = [
	{ prop: 'name', title: '名称', minWidth: 200, treeNode: true },
	{ prop: 'key', title: '键', minWidth: 160 },
	{ prop: 'type', title: '类型', width: 100, slots: { default: 'type_default' } },
	{ prop: 'value', title: '值', minWidth: 160, slots: { default: 'value_default' } },
	{ prop: 'sort', title: '排序', width: 70 },
	{ prop: 'status', title: '状态', width: 80, slots: { default: 'status_default' } },
	{ prop: 'action', title: '操作', width: 130, fixed: 'right', slots: { default: 'action_default' } },
]

const loadData = async () => {
	loading.value = true
	try {
		const [treeRes, configRes] = await Promise.all([systemApi.config.tree.get(), systemApi.config.all.get({ item_type: 'config' })])

		if (treeRes.code === 200 && configRes.code === 200) {
			const tree = treeRes.data || []
			const configs = configRes.data || []
			const configByParent = {}
			configs.forEach((c) => {
				const pid = c.parent_id
				if (!configByParent[pid]) configByParent[pid] = []
				configByParent[pid].push(c)
			})
			treeData.value = mergeTreeConfigs(tree, configByParent)
		}
	} catch (error) {
		console.error('加载配置列表失败:', error)
	} finally {
		loading.value = false
	}
}

function mergeTreeConfigs(nodes, configByParent) {
	if (!nodes) return []
	return nodes.map((node) => {
		const directConfigs = (configByParent[node.id] || []).sort((a, b) => (a.sort || 0) - (b.sort || 0))
		const childGroups = mergeTreeConfigs(node.children, configByParent)
		const children = [...directConfigs, ...childGroups]
		const result = { ...node }
		delete result.children
		if (children.length > 0) {
			result.children = children
		}
		return result
	})
}

watch(visible, (val) => {
	if (val) loadData()
})

const handleAdd = () => {
	currentRecord.value = null
	configDialogVisible.value = true
}

const handleEdit = (row) => {
	currentRecord.value = row
	if (row.item_type === 'group') {
		groupDialogVisible.value = true
	} else {
		configDialogVisible.value = true
	}
}

const handleDelete = (row) => {
	const isGroup = row.item_type === 'group'
	const hint = isGroup ? '（该分组下的子分组和配置项也将被删除）' : ''
	ElMessageBox.confirm(`确定要删除「${row.name}」吗？${hint}`, '确认删除', {
		confirmButtonText: '确定',
		cancelButtonText: '取消',
		type: 'warning',
	}).then(async () => {
		try {
			await systemApi.config.delete.delete(row.id)
			ElMessage.success('删除成功')
			loadData()
			emit('refresh')
		} catch (error) {
			ElMessage.error(error.response?.data?.message || '删除失败')
		}
	})
}

const handleSaveSuccess = () => {
	configDialogVisible.value = false
	groupDialogVisible.value = false
	loadData()
	emit('refresh')
}

const handleClose = () => {
	visible.value = false
}
</script>

<style scoped>
.text-muted {
	color: var(--el-text-color-placeholder);
	font-size: 12px;
}
</style>
