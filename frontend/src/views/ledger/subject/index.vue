<template>
	<div class="page">
		<div class="page-header">
			<span class="title">会计科目</span>
		</div>
		<el-card shadow="never" class="card">
			<!-- 查询区 -->
			<div class="filter">
				<el-input v-model="filters.code" placeholder="请输入科目编码" style="width:160px" clearable @keyup.enter="handleSearch" />
				<el-input v-model="filters.name" placeholder="请输入科目名称" style="width:180px" clearable @keyup.enter="handleSearch" />
				<el-select v-model="filters.category" placeholder="科目类别" style="width:140px" clearable>
					<el-option label="全部" value="all" />
					<el-option label="资产类" value="asset" />
					<el-option label="负债类" value="liability" />
					<el-option label="共同类" value="common" />
					<el-option label="所有者权益类" value="equity" />
					<el-option label="成本类" value="cost" />
					<el-option label="损益类" value="profit_loss" />
				</el-select>
				<el-select v-model="filters.status" placeholder="状态" style="width:100px" clearable>
					<el-option label="全部" value="all" />
					<el-option label="启用" value="enabled" />
					<el-option label="停用" value="disabled" />
				</el-select>
				<el-button type="primary" style="width:80px" @click="handleSearch">查询</el-button>
				<el-button style="width:80px" @click="handleReset">重置</el-button>
			</div>
			<!-- 按钮区 -->
			<div class="btn-bar">
				<el-button type="primary" style="width:110px" @click="openForm(null)">新增科目</el-button>
				<el-button style="width:110px" @click="toggleExpandAll">{{ allExpanded ? '折叠全部' : '展开全部' }}</el-button>
				<el-button style="width:90px" @click="handleExport">导出</el-button>
			</div>
			<!-- 树形表格 -->
			<sTable ref="tableRef" :columns="columns" :data="treeData" :loading="loading" height="100%" stripe :tree-config="{ transform: true, rowField: 'id', parentField: 'parent_id' }" highlight-current-row>
				<template #checkbox="{ row }">
					<el-checkbox v-model="row._checked" />
				</template>
				<template #code="{ row }">
					<span class="code-cell" :style="{ paddingLeft: (row.level - 1) * 16 + 'px' }">
						<vxe-icon v-if="row.children && row.children.length" :name="row._expanded ? 'square-minus-fill' : 'square-plus-fill'" class="toggle-icon" @click="toggleRow(row)" />
						<span class="code-text">{{ row.code }}</span>
					</span>
				</template>
				<template #name="{ row }">
					<el-icon class="cat-icon" :style="{ color: categoryColor(row.category) }">
						<component :is="categoryIcon(row.category)" />
					</el-icon>
					<span>{{ row.name }}</span>
				</template>
				<template #category="{ row }">
					<el-tag :style="{ background: categoryBg(row.category), color: categoryColor(row.category), border: 'none' }" size="small">{{ categoryLabel(row.category) }}</el-tag>
				</template>
				<template #balance_direction="{ row }">
					<span :style="{ color: row.balance_direction === 'debit' ? '#409EFF' : '#B8823A' }">{{ row.balance_direction === 'debit' ? '借' : '贷' }}</span>
				</template>
				<template #aux_types="{ row }">
					<template v-if="row.aux_types && row.aux_types.length">
						<el-tag v-for="a in row.aux_types" :key="a" type="info" size="small" style="margin-right:4px">{{ auxLabel(a) }}</el-tag>
					</template>
					<span v-else class="dash">—</span>
				</template>
				<template #status="{ row }">
					<el-tag :type="row.status === 'enabled' ? 'success' : 'info'" size="small">{{ row.status === 'enabled' ? '启用' : '停用' }}</el-tag>
				</template>
				<template #is_leaf="{ row }">
					<el-tag v-if="row.is_leaf" type="warning" size="small">末级</el-tag>
					<span v-else class="dash">—</span>
				</template>
				<template #action="{ row }">
					<el-button link type="primary" @click="openForm(row)">编辑</el-button>
					<el-button link type="primary" @click="openForm(null, row)">新增下级</el-button>
					<el-button link type="info" @click="toggleStatus(row)">{{ row.status === 'enabled' ? '停用' : '启用' }}</el-button>
					<el-button link type="danger" @click="handleDelete(row)">删除</el-button>
				</template>
			</sTable>
		</el-card>
		<SubjectForm ref="formRef" @saved="load" />
	</div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import ledgerApi from '@/api/ledger'
import sTable from '@/components/sTable/index.vue'
import SubjectForm from '../components/SubjectForm.vue'

const treeData = ref([])
const loading = ref(false)
const allExpanded = ref(false)
const tableRef = ref(null)
const formRef = ref(null)
const filters = reactive({ code: '', name: '', category: '', status: '' })

const columns = [
	{ type: 'checkbox', width: 45, align: 'center', slots: { default: 'checkbox' } },
	{ field: 'code', title: '科目编码', width: 200, slots: { default: 'code' } },
	{ field: 'name', title: '科目名称', minWidth: 260, slots: { default: 'name' } },
	{ field: 'category', title: '科目类别', width: 130, align: 'center', slots: { default: 'category' } },
	{ field: 'balance_direction', title: '余额方向', width: 90, align: 'center', slots: { default: 'balance_direction' } },
	{ field: 'aux_types', title: '辅助核算', width: 160, align: 'center', slots: { default: 'aux_types' } },
	{ field: 'level', title: '级次', width: 70, align: 'center' },
	{ field: 'status', title: '状态', width: 90, align: 'center', slots: { default: 'status' } },
	{ field: 'is_leaf', title: '末级标记', width: 90, align: 'center', slots: { default: 'is_leaf' } },
	{ title: '操作', width: 230, align: 'center', fixed: 'right', slots: { default: 'action' } },
]

const categoryLabel = (c) => ({ asset: '资产类', liability: '负债类', common: '共同类', equity: '所有者权益类', cost: '成本类', profit_loss: '损益类' }[c] || c)
const categoryColor = (c) => ({ asset: '#409EFF', liability: '#E6A23C', common: '#909399', equity: '#9B59B6', cost: '#1ABC9C', profit_loss: '#67C23A' }[c] || '#606266')
const categoryBg = (c) => ({ asset: '#F0F9EB', liability: '#FDF6EC', common: '#F4F4F5', equity: '#FBF0FF', cost: '#E8FAF7', profit_loss: '#F0F9EB' }[c] || '#F4F4F5')
const categoryIcon = (c) => ({ asset: 'ElIconCoin', liability: 'ElIconWallet', common: 'ElIconSetting', equity: 'ElIconTrophyBase', cost: 'ElIconHistogram', profit_loss: 'ElIconTrendCharts' }[c] || 'ElIconDocument')
const auxLabel = (a) => ({ customer: '客户', supplier: '供应商', employee: '员工', department: '部门', project: '项目' }[a] || a)

const load = async () => {
	loading.value = true
	try {
		const res = await ledgerApi.subject.list.get({ ...filters })
		treeData.value = res.data?.tree || []
	} catch (e) { ElMessage.error('加载失败') }
	finally { loading.value = false }
}
const handleSearch = () => load()
const handleReset = () => { Object.assign(filters, { code: '', name: '', category: '', status: '' }); load() }
const toggleExpandAll = () => { allExpanded.value = !allExpanded.value; tableRef.value?.$refs?.xGrid?.setAllTreeExpand(allExpanded.value) }
const toggleRow = (row) => { tableRef.value?.$refs?.xGrid?.toggleTreeExpand(row) }
const openForm = (row, parent) => formRef.value?.open(row, parent)
const toggleStatus = async (row) => {
	try { await ledgerApi.subject.toggleStatus.put(row.id); ElMessage.success(row.status === 'enabled' ? '已停用' : '已启用'); load() }
	catch { ElMessage.error('操作失败') }
}
const handleDelete = (row) => {
	ElMessageBox.confirm('确认删除该科目？删除后不可恢复。', '操作确认', { type: 'warning', confirmButtonText: '确定删除', cancelButtonText: '取消' })
		.then(async () => { await ledgerApi.subject.delete.delete(row.id); ElMessage.success('删除成功'); load() })
		.catch(() => {})
}
const handleExport = async () => {
	try {
		const blob = await ledgerApi.subject.export(filters)
		const url = URL.createObjectURL(blob); const a = document.createElement('a'); a.href = url; a.download = 'ledger_subjects.csv'; a.click(); setTimeout(() => URL.revokeObjectURL(url), 1000)
	} catch { ElMessage.error('导出失败') }
}
onMounted(() => load())
</script>

<style scoped>
.page { background: #f0f2f5; min-height: 100vh; padding: 0; }
.page-header { height: 50px; background: #fff; border-bottom: 1px solid #e8e8e8; padding: 0 20px; display: flex; align-items: center; }
.title { font-size: 18px; font-weight: 700; color: #303133; }
.card { margin: 16px 20px; }
.filter { display: flex; gap: 12px; flex-wrap: wrap; padding: 0 0 16px 0; }
.btn-bar { height: 50px; display: flex; gap: 8px; align-items: center; border-top: 1px solid #ebeef5; padding-top: 12px; margin-bottom: 12px; }
.code-cell { display: inline-flex; align-items: center; gap: 4px; }
.code-text { font-family: 'Courier New', monospace; color: #909399; }
.toggle-icon { cursor: pointer; color: #409eff; }
.cat-icon { margin-right: 4px; vertical-align: middle; }
.dash { color: #909399; }
:deep(.vxe-header--row) { height: 44px !important; }
:deep(.vxe-body--row.row--current > .vxe-body--column) { background-color: #ECF5FF; color: #409EFF; }
</style>
