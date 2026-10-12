<template>
	<div class="perf-list">
		<!-- 查询区 -->
		<div class="perf-filter">
			<span class="label">方案名称</span>
			<el-input v-model="search.name" placeholder="方案名称" clearable size="small" style="width: 180px" @keyup.enter="refresh" @clear="refresh" />
			<span class="label">提成方式</span>
			<el-select v-model="search.calc_type" placeholder="全部" clearable size="small" style="width: 140px" @change="refresh">
				<el-option v-for="t in calcTypes" :key="t.value" :label="t.label" :value="t.value" />
			</el-select>
			<span class="label">状态</span>
			<el-select v-model="search.status" placeholder="全部" clearable size="small" style="width: 100px" @change="refresh">
				<el-option label="启用" value="active" /><el-option label="停用" value="inactive" />
			</el-select>
			<el-button type="primary" size="small" @click="refresh">查询</el-button>
			<el-button size="small" @click="resetSearch">重置</el-button>
		</div>

		<div class="perf-toolbar">
			<el-button type="primary" size="default" @click="handleAdd">新增方案</el-button>
		</div>

		<sTable ref="tableRef" tableName="perf_rule" :data="data" :columns="columns" :loading="loading" :total="total"
			:currentPage="paginationProps.currentPage" :pageSize="paginationProps.pageSize" rowKey="id" height="100%" stripe
			@refresh="refresh" @pageChange="handlePageChange" @pageSizeChange="handlePageSizeChange">
			<template #calc_type_default="{ row }">
				<el-tag :type="calcTagType(row.calc_type)" size="small">{{ calcLabel(row.calc_type) }}</el-tag>
			</template>
			<template #status_default="{ row }">
				<el-tag :type="row.status === 'active' ? 'success' : 'info'" size="small">{{ row.status === 'active' ? '启用' : '停用' }}</el-tag>
			</template>
			<template #action_default="{ row }">
				<el-button type="primary" link size="small" @click="handleEdit(row)">编辑</el-button>
				<el-button type="primary" link size="small" @click="handleCopy(row)">复制</el-button>
				<el-button type="warning" link size="small" @click="handleToggle(row)">{{ row.status === 'active' ? '停用' : '启用' }}</el-button>
				<el-popconfirm title="确定删除该方案吗？" @confirm="handleDelete(row)">
					<template #reference><el-button type="danger" link size="small">删除</el-button></template>
				</el-popconfirm>
			</template>
		</sTable>

		<RuleDialog v-if="dialog.visible" v-model:visible="dialog.visible" :record="currentRow" @success="refresh" />
	</div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import { useTable } from '@/hooks/useTable'
import perfApi from '@/api/perf'
import RuleDialog from './rule-dialog.vue'

const calcTypes = [
	{ value: 'sale_rate', label: '按销售额比例' },
	{ value: 'profit_rate', label: '按毛利比例' },
	{ value: 'fixed_per_unit', label: '固定每件/每箱' },
	{ value: 'tiered', label: '阶梯提成' },
	{ value: 'product_specific', label: '单品专属' },
]

const calcLabel = (v) => calcTypes.find((t) => t.value === v)?.label || v
const calcTagType = (v) => ({ sale_rate: '', profit_rate: 'success', fixed_per_unit: 'warning', tiered: 'danger', product_specific: 'info' }[v] || '')

const search = reactive({ name: '', calc_type: '', status: '' })
const { tableRef, data, total, loading, paginationProps, refresh, handlePageChange, handlePageSizeChange } = useTable({
	apiObj: { get: (params) => perfApi.rule.list.get(params) },
	searchForm: search,
})

const columns = [
	{ type: 'checkbox', width: 45 },
	{ prop: 'code', title: '方案编号', width: 130 },
	{ prop: 'name', title: '方案名称', width: 200, slots: { default: 'name_default' } },
	{ prop: 'calc_type', title: '提成方式', width: 150, align: 'center', slots: { default: 'calc_type_default' } },
	{ prop: 'apply_employees_text', title: '适用业务员', width: 180 },
	{ prop: 'product_scope_type', title: '商品范围', width: 160 },
	{ prop: 'date_range', title: '生效起止', width: 200 },
	{ prop: 'status', title: '状态', width: 100, align: 'center', slots: { default: 'status_default' } },
	{ prop: 'action_col', title: '操作', width: 220, align: 'center', fixed: 'right', slots: { default: 'action_default' } },
]

const dialog = reactive({ visible: false })
const currentRow = ref(null)

const handleAdd = () => { currentRow.value = null; dialog.visible = true }
const handleEdit = (row) => { currentRow.value = row; dialog.visible = true }
const handleCopy = async (row) => {
	const res = await perfApi.rule.copy.post(row.id)
	if (res.code === 200) { ElMessage.success('复制成功'); refresh() }
}
const handleToggle = async (row) => {
	const res = await perfApi.rule.edit.put(row.id, { status: row.status === 'active' ? 'inactive' : 'active' })
	if (res.code === 200) { ElMessage.success('保存成功'); refresh() }
}
const handleDelete = async (row) => {
	const res = await perfApi.rule.delete.delete(row.id)
	if (res.code === 200) { ElMessage.success('删除成功'); refresh() }
}
const resetSearch = () => { search.name = ''; search.calc_type = ''; search.status = ''; refresh() }

onMounted(() => refresh())
</script>

<style scoped>
.perf-list { height: 100%; display: flex; flex-direction: column; }
.perf-filter {
	display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
	padding: 16px 20px; background: #fff; border-bottom: 1px solid #ebeef5;
}
.perf-filter .label { font-size: 14px; color: #606266; }
.perf-toolbar { margin: 12px 0; display: flex; }
</style>
