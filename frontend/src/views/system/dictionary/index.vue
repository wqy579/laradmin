<template>
	<sPageSplit side-title="字典列表" :side-width="'280px'">
		<template #side>
			<div class="dict-side">
				<div class="dict-side__header">
					<el-input v-model="dictionaryKeyword" placeholder="搜索字典..." clearable @input="handleDictionarySearch">
						<template #prefix>
							<el-icon><ElIconSearch /></el-icon>
						</template>
					</el-input>
					<el-button type="primary" size="small" style="margin-top: 12px; width: 100%" @click="handleAddDictionary">
						<el-icon><ElIconPlus /></el-icon>
						新增字典
					</el-button>
				</div>
				<div class="dict-side__body">
					<div v-if="filteredDictionaries.length > 0" class="dictionary-list">
						<div v-for="item in filteredDictionaries" :key="item.id" :class="['dictionary-item', { active: selectedDictionaryId === item.id }]" @click="handleSelectDictionary(item)">
							<div class="item-main">
								<div class="item-name">{{ item.name }}</div>
								<div class="item-code">{{ item.code }}</div>
							</div>
							<div class="item-meta">
								<el-tag :type="item.status ? 'success' : 'info'" size="small">
									{{ item.status ? '启用' : '禁用' }}
								</el-tag>
								<span class="item-count">{{ item.items_count || 0 }} 项</span>
							</div>
							<div class="item-actions" @click.stop>
								<el-dropdown trigger="click">
									<el-button type="primary" link size="small">
										<el-icon><ElIconMoreFilled /></el-icon>
									</el-button>
									<template #dropdown>
										<el-dropdown-menu>
											<el-dropdown-item @click="handleEditDictionary(item)">
												<el-icon><ElIconEdit /></el-icon>
												编辑
											</el-dropdown-item>
											<el-dropdown-item @click="handleDeleteDictionary(item)" style="color: var(--el-color-danger)">
												<el-icon><ElIconDelete /></el-icon>
												删除
											</el-dropdown-item>
										</el-dropdown-menu>
									</template>
								</el-dropdown>
							</div>
						</div>
					</div>
					<el-empty v-else-if="!dictionaryLoading" description="暂无字典类型" :image-size="60">
						<el-button type="primary" size="small" @click="handleAddDictionary">创建第一个字典</el-button>
					</el-empty>
				</div>
			</div>
		</template>

		<div class="tool-bar">
			<div class="right-panel">
				<el-dropdown :disabled="!selectedRows.length">
					<el-button :disabled="!selectedRows.length">
						批量操作
						<el-icon><ElIconArrowDown /></el-icon>
					</el-button>
					<template #dropdown>
						<el-dropdown-menu>
							<el-dropdown-item @click="handleBatchStatus">批量启用/禁用</el-dropdown-item>
							<el-dropdown-item divided style="color: var(--el-color-danger)" @click="handleBatchDelete">批量删除</el-dropdown-item>
						</el-dropdown-menu>
					</template>
				</el-dropdown>
				<el-button type="primary" :disabled="!selectedDictionaryId" @click="handleAddItem">
					<el-icon><ElIconPlus /></el-icon>
					新增
				</el-button>
			</div>
		</div>
		<div class="table-content">
			<!-- 未选择字典 -->
			<div v-if="!selectedDictionaryId" class="empty-state">
				<el-empty description="请选择左侧字典类型后操作" :image-size="120" />
			</div>
			<!-- 字典项表格 -->
			<sTable
				v-else
				ref="tableRef"
				tableName="dictionary_item"
				:data="data"
				:columns="columns"
				:searchForm="searchForm"
				:loading="loading"
				:total="total"
				:currentPage="paginationProps.currentPage"
				:pageSize="paginationProps.pageSize"
				:pageSizes="paginationProps.pageSizes"
				rowKey="id"
				height="100%"
				stripe
				@refresh="refresh"
				@search="search"
				@pageChange="handlePageChange"
				@pageSizeChange="handlePageSizeChange"
				@selectionChange="onSelectionChange"
			>
				<template #color_default="{ row }">
					<span v-if="row.color" class="color-cell" :style="{ backgroundColor: row.color }"></span>
					<span v-else>-</span>
				</template>
				<template #is_default_default="{ row }">
					<el-tag v-if="row.is_default" type="warning" size="small">默认</el-tag>
					<span v-else>-</span>
				</template>
				<template #status_default="{ row }">
					<el-tag :type="row.status ? 'success' : 'danger'" size="small">
						{{ row.status ? '启用' : '禁用' }}
					</el-tag>
				</template>
				<template #action_default="{ row }">
					<el-button type="primary" link size="small" @click="handleEditItem(row)">编辑</el-button>
					<el-popconfirm title="确定删除该字典项吗？" @confirm="handleDeleteItem(row)">
						<template #reference>
							<el-button type="danger" link size="small">删除</el-button>
						</template>
					</el-popconfirm>
				</template>
			</sTable>
		</div>
	</sPageSplit>

	<!-- 字典类型弹窗 -->
	<DictionaryDialog v-if="dialog.dictionary" v-model:visible="dialog.dictionary" :record="currentDictionary" :dictionary-list="dictionaryList" @success="handleDictionarySuccess" />

	<!-- 字典项弹窗 -->
	<ItemDialog v-if="dialog.item" v-model:visible="dialog.item" :record="currentItem" :dictionary-id="selectedDictionaryId" :item-list="data" @success="handleItemSuccess" />
</template>

<script setup>
import { ref, reactive, onMounted, h } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { useTable } from '@/hooks/useTable'
import systemApi from '@/api/system'
import sPageSplit from '@/components/sPageSplit/index.vue'
import DictionaryDialog from './components/dictionary.vue'
import ItemDialog from './components/item.vue'

// 字典列表相关
const dictionaryList = ref([])
const filteredDictionaries = ref([])
const selectedDictionaryId = ref(null)
const dictionaryKeyword = ref('')
const dictionaryLoading = ref(false)

// 字典项表格
const { tableRef, data, total, loading, selectedRows, searchForm, paginationProps, refresh, search, resetSearch, handlePageChange, handlePageSizeChange, onSelectionChange, clearSelection } = useTable({
	apiObj: { get: (params) => systemApi.dictionaryItem.list.get(params) },
	searchForm: {
		dictionary_id: null,
		label: '',
		value: '',
		status: null,
	},
	autoLoad: false,
})

const columns = [
	{ type: 'checkbox', width: 50 },
	{ prop: 'id', title: 'ID', width: 80, align: 'center' },
	{ prop: 'label', title: '标签名称', width: 150, showOverflowTooltip: true, filter: true },
	{ prop: 'value', title: '数据值', width: 120, showOverflowTooltip: true, filter: true },
	{ prop: 'color', title: '颜色', width: 100, align: 'center', slots: { default: 'color_default' } },
	{ prop: 'is_default', title: '默认项', width: 100, align: 'center', slots: { default: 'is_default_default' } },
	{ prop: 'sort', title: '排序', width: 80, align: 'center' },
	{
		prop: 'status',
		title: '状态',
		width: 100,
		align: 'center',
		filter: {
			type: 'select',
			options: [
				{ label: '启用', value: 1 },
				{ label: '禁用', value: 0 },
			],
		},
		slots: { default: 'status_default' },
	},
	{ prop: 'description', title: '描述', showOverflowTooltip: true },
	{ prop: 'action_col', title: '操作', width: 150, align: 'center', fixed: 'right', slots: { default: 'action_default' } },
]

// 弹窗状态
const dialog = reactive({
	dictionary: false,
	item: false,
})

const currentDictionary = ref(null)
const currentItem = ref(null)

// 加载字典列表
const loadDictionaryList = async () => {
	try {
		dictionaryLoading.value = true
		const res = await systemApi.dictionary.all.get()
		if (res.code === 200) {
			dictionaryList.value = res.data || []
			filteredDictionaries.value = res.data || []
		} else {
			ElMessage.error(res.message || '加载字典列表失败')
		}
	} catch (error) {
		console.error('加载字典列表失败:', error)
		ElMessage.error('加载字典列表失败')
	} finally {
		dictionaryLoading.value = false
	}
}

// 搜索字典
const handleDictionarySearch = (val) => {
	const keyword = val || ''
	if (!keyword) {
		filteredDictionaries.value = dictionaryList.value
		return
	}
	filteredDictionaries.value = dictionaryList.value.filter((dict) => {
		return dict.name.toLowerCase().includes(keyword.toLowerCase()) || dict.code.toLowerCase().includes(keyword.toLowerCase())
	})
}

// 选择字典
const handleSelectDictionary = (dictionary) => {
	selectedDictionaryId.value = dictionary.id
	searchForm.label = ''
	searchForm.value = ''
	searchForm.status = null
	searchForm.dictionary_id = dictionary.id
	search()
}

// 字典项搜索：浮动筛选由 sTable 自动渲染并触发 @search
// 字典项重置：浮动筛选各自 clearable，无需统一重置

// 新增字典
const handleAddDictionary = () => {
	currentDictionary.value = null
	dialog.dictionary = true
}

// 编辑字典
const handleEditDictionary = (dictionary) => {
	currentDictionary.value = { ...dictionary }
	dialog.dictionary = true
}

// 删除字典
const handleDeleteDictionary = (dictionary) => {
	const itemCount = dictionary.items_count || 0
	ElMessageBox.confirm(itemCount > 0 ? `确定删除字典类型"${dictionary.name}"吗？删除后该字典下的 ${itemCount} 个字典项也会被删除！` : `确定删除字典类型"${dictionary.name}"吗？`, '确认删除', { confirmButtonText: '删除', cancelButtonText: '取消', type: 'warning' })
		.then(async () => {
			try {
				await systemApi.dictionary.delete.delete(dictionary.id)
				ElMessage.success('删除成功')
				if (selectedDictionaryId.value === dictionary.id) {
					selectedDictionaryId.value = null
					data.value = []
				}
				loadDictionaryList()
			} catch (error) {
				console.error('删除字典失败:', error)
			}
		})
		.catch(() => {})
}

// 新增字典项
const handleAddItem = () => {
	if (!selectedDictionaryId.value) {
		ElMessage.warning('请先选择字典类型')
		return
	}
	currentItem.value = null
	dialog.item = true
}

// 编辑字典项
const handleEditItem = (record) => {
	currentItem.value = { ...record }
	dialog.item = true
}

// 删除字典项
const handleDeleteItem = async (record) => {
	try {
		await systemApi.dictionaryItem.delete.delete(record.id)
		ElMessage.success('删除成功')
		refresh()
		loadDictionaryList()
	} catch (error) {
		console.error('删除字典项失败:', error)
	}
}

// 批量删除字典项
const handleBatchDelete = () => {
	if (!selectedRows.value.length) {
		ElMessage.warning('请选择要删除的字典项')
		return
	}
	ElMessageBox.confirm(`确定删除选中的 ${selectedRows.value.length} 个字典项吗？`, '确认删除', {
		confirmButtonText: '删除',
		cancelButtonText: '取消',
		type: 'warning',
	})
		.then(async () => {
			try {
				const ids = selectedRows.value.map((item) => item.id)
				await systemApi.dictionaryItem.batchDelete.post({ ids })
				ElMessage.success('删除成功')
				clearSelection()
				refresh()
				loadDictionaryList()
			} catch (error) {
				console.error('批量删除失败:', error)
			}
		})
		.catch(() => {})
}

// 批量启用/禁用
const handleBatchStatus = () => {
	if (!selectedRows.value.length) {
		ElMessage.warning('请选择要操作的字典项')
		return
	}
	const newStatus = selectedRows.value[0].status ? 0 : 1
	const statusText = newStatus ? '启用' : '禁用'
	ElMessageBox.confirm(`确定要${statusText}选中的 ${selectedRows.value.length} 个字典项吗？`, `确认${statusText}`, {
		confirmButtonText: '确定',
		cancelButtonText: '取消',
	})
		.then(async () => {
			try {
				const ids = selectedRows.value.map((item) => item.id)
				await systemApi.dictionaryItem.batchStatus.post({ ids, status: newStatus })
				ElMessage.success(`${statusText}成功`)
				clearSelection()
				refresh()
			} catch (error) {
				console.error('批量操作失败:', error)
			}
		})
		.catch(() => {})
}

// 成功回调
const handleDictionarySuccess = () => {
	dialog.dictionary = false
	loadDictionaryList()
}

const handleItemSuccess = () => {
	dialog.item = false
	refresh()
	loadDictionaryList()
}

onMounted(() => {
	loadDictionaryList()
})
</script>

<style scoped>
/* 侧栏内部布局（容器本身由 sPageSplit 提供） */
.dict-side {
	display: flex;
	flex-direction: column;
	height: 100%;
	overflow: hidden;
}
.dict-side__header {
	padding: 12px 16px;
	border-bottom: 1px solid var(--layout-border-light, var(--el-border-color-lighter));
	flex-shrink: 0;
}
.dict-side__body {
	flex: 1;
	overflow-y: auto;
	padding: 12px;
}

.dictionary-item {
	display: flex;
	align-items: center;
	padding: 12px;
	margin-bottom: 8px;
	border-radius: 6px;
	cursor: pointer;
	transition: all 0.2s;
	border: 1px solid var(--el-border-color-lighter);
	background: var(--el-bg-color);
}

.dictionary-item:hover {
	background-color: var(--el-fill-color-light);
	border-color: var(--el-border-color);
}

.dictionary-item:hover .item-actions {
	opacity: 1;
}

.dictionary-item.active {
	background-color: var(--el-color-primary-light-9);
	border-color: var(--el-color-primary);
}

.item-main {
	flex: 1;
	min-width: 0;
}

.item-name {
	font-size: 14px;
	font-weight: 500;
	color: var(--el-text-color-primary);
	margin-bottom: 4px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.item-code {
	font-size: 12px;
	color: var(--el-text-color-secondary);
	font-family: Consolas, Monaco, monospace;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.item-meta {
	display: flex;
	align-items: center;
	gap: 8px;
	font-size: 12px;
	color: var(--el-text-color-secondary);
	margin-right: 8px;
}

.item-actions {
	opacity: 0;
	transition: opacity 0.2s;
}

/* .tool-bar/.left-panel/.right-panel/.table-content 已抽取到全局 components.css */

.tool-bar {
	justify-content: flex-end;
}

.empty-state {
	display: flex;
	align-items: center;
	justify-content: center;
	height: 100%;
}

.color-cell {
	display: inline-block;
	width: 20px;
	height: 20px;
	border-radius: 2px;
	border: 1px solid var(--el-border-color);
	vertical-align: middle;
}

@media (max-width: 768px) {
	.dictionary-item {
		padding: 10px;
	}
	.item-meta {
		margin-right: 0;
	}
	.item-actions {
		opacity: 1;
	}
	.empty-state {
		padding: 24px 16px;
	}
}
</style>
