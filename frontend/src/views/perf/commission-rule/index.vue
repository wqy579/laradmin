<template>
	<div class="perf-list">
		<!-- 查询区 -->
		<div class="perf-filter">
			<span class="label">方案名称</span>
			<el-input v-model="search.name" placeholder="方案名称" clearable size="small" style="width: 180px" @keyup.enter="refresh" @clear="refresh" />
			<span class="label">类型</span>
			<el-select v-model="search.type" placeholder="全部" clearable size="small" style="width: 140px" @change="refresh">
				<el-option v-for="t in typeOptions" :key="t.value" :label="t.label" :value="t.value" />
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
			<el-button v-if="hasPerm('perf.export')" size="default" @click="handleExport">导出</el-button>
		</div>

		<sTable ref="tableRef" tableName="perf_rule" :data="data" :columns="columns" :loading="loading" :total="total"
			:currentPage="paginationProps.currentPage" :pageSize="paginationProps.pageSize" :page-sizes="paginationProps.pageSizes"
			pagination-layout="prev, pager, next, jumper, sizes, total" empty-text="当前筛选条件下暂无数据"
			rowKey="id" height="100%" stripe
			@refresh="refresh" @pageChange="handlePageChange" @pageSizeChange="handlePageSizeChange">
			<template #name_default="{ row }">
				<el-button type="primary" link size="small" @click="handleView(row)">{{ row.name }}</el-button>
			</template>
			<template #type_default="{ row }">
				<el-tag :type="typeTagType(rowType(row))" size="small">{{ typeLabel(rowType(row)) }}</el-tag>
			</template>
			<template #levels_default="{ row }">{{ levelText(row) }}</template>
			<template #status_default="{ row }">
				<el-tag :type="row.status === 'active' ? 'success' : 'info'" size="small">{{ row.status === 'active' ? '启用' : '停用' }}</el-tag>
			</template>
			<template #action_default="{ row }">
				<el-button type="primary" link size="small" @click="handleEdit(row)">编辑</el-button>
				<el-button type="primary" link size="small" @click="handleCopy(row)">复制</el-button>
				<el-button type="warning" link size="small" @click="handleToggle(row)">{{ row.status === 'active' ? '停用' : '启用' }}</el-button>
				<el-button type="danger" link size="small" @click="handleDelete(row)">删除</el-button>
			</template>
		</sTable>

		<!-- 方案查看（名称点击） -->
		<el-dialog v-model="detailVisible" title="方案详情" width="560px">
			<div v-if="detail" class="detail-grid">
				<div><span class="k">方案名称</span><span class="v">{{ detail.name }}</span></div>
				<div><span class="k">类型</span><span class="v">{{ typeLabel(rowType(detail)) }}</span></div>
				<div><span class="k">适用业务员</span><span class="v">{{ detail.apply_employees_text || scopeText(detail) }}</span></div>
				<div><span class="k">客户等级</span><span class="v">{{ levelText(detail) }}</span></div>
				<div><span class="k">生效起止</span><span class="v">{{ detail.date_range || `${detail.start_date || '—'} ~ ${detail.end_date || '—'}` }}</span></div>
				<div><span class="k">状态</span><span class="v">{{ detail.status === 'active' ? '启用' : '停用' }}</span></div>
				<div v-if="detail.remark"><span class="k">备注</span><span class="v">{{ detail.remark }}</span></div>
				<div class="rule-json"><span class="k">规则</span><pre class="v">{{ ruleText(detail) }}</pre></div>
			</div>
		</el-dialog>

		<RuleDialog v-if="dialog.visible" v-model:visible="dialog.visible" :record="currentRow" @success="refresh" />
	</div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { useTable } from '@/hooks/useTable'
import { usePermission } from '@/composables/usePermission'
import { saveBlob } from '@/utils/fileDownload'
import perfApi from '@/api/perf'
import RuleDialog from './rule-dialog.vue'

const typeOptions = [
	{ value: 'sales', label: '销售额提成' },
	{ value: 'profit', label: '利润提成' },
	{ value: 'piece', label: '按件计费' },
	{ value: 'ladder', label: '阶梯提成' },
	{ value: 'single', label: '单品方案' },
]
const calcToType = { sale_rate: 'sales', profit_rate: 'profit', fixed_per_unit: 'piece', tiered: 'ladder', product_specific: 'single' }
const rowType = (row) => row?.type || calcToType[row?.calc_type] || ''
const typeLabel = (v) => typeOptions.find((t) => t.value === v)?.label || v || '—'
const typeTagType = (v) => ({ sales: '', profit: 'success', piece: 'warning', ladder: 'danger', single: 'info' }[v] || '')

const { has: hasPerm } = usePermission()

const search = reactive({ name: '', type: '', status: '' })
const { tableRef, data, total, loading, paginationProps, refresh, handlePageChange, handlePageSizeChange } = useTable({
	apiObj: { get: (params) => perfApi.rule.list.get(params) },
	searchForm: search,
	pageSize: 30,
})

const levelText = (row) => {
	const levels = row?.apply_customer_levels ?? row?.rule?.apply_customer_levels
	if (!levels || !levels.length) return '全部'
	return levels.map((l) => (typeof l === 'object' ? (l?.name || l?.label || l?.id) : l)).join('、')
}
const scopeText = (row) => {
	const scope = row.scope || (!row.apply_employees?.length ? 'all' : 'specified')
	if (scope === 'all') return '全部业务员'
	const ids = row.employee_ids || row.apply_employees || []
	return ids.length ? `指定业务员（${ids.length} 人）` : '指定业务员'
}
const ruleText = (row) => {
	const rule = row.rule && typeof row.rule === 'object' ? row.rule : null
	if (rule) { try { return JSON.stringify(rule, null, 2) } catch { /* ignore */ } }
	const flat = {}
	for (const k of ['calc_type', 'rate', 'unit_base', 'unit_amount', 'tier_data', 'product_rules', 'cap_amount', 'floor_amount', 'base_on_payment', 'deduct_return', 'exclude_baddebt', 'allow_stack']) {
		if (row[k] !== undefined && row[k] !== null) flat[k] = row[k]
	}
	try { return JSON.stringify(flat, null, 2) } catch { return String(row.calc_type || '') }
}

const columns = [
	{ type: 'checkbox', width: 45 },
	{ prop: 'name', title: '方案名称', width: 180, slots: { default: 'name_default' } },
	{ prop: 'type', title: '类型', width: 120, align: 'center', slots: { default: 'type_default' } },
	{ prop: 'apply_customer_levels', title: '客户等级', width: 140, slots: { default: 'levels_default' } },
	{ prop: 'apply_employees_text', title: '适用业务员', width: 160 },
	{ prop: 'product_scope_type', title: '商品范围', width: 140 },
	{ prop: 'date_range', title: '生效起止', width: 200 },
	{ prop: 'status', title: '状态', width: 90, align: 'center', slots: { default: 'status_default' } },
	{ prop: 'action_col', title: '操作', width: 220, align: 'center', fixed: 'right', slots: { default: 'action_default' } },
]

const dialog = reactive({ visible: false })
const currentRow = ref(null)
const detailVisible = ref(false)
const detail = ref(null)

const handleAdd = () => { currentRow.value = null; dialog.visible = true }
const handleEdit = (row) => { currentRow.value = row; dialog.visible = true }
const handleView = async (row) => {
	try {
		const res = await perfApi.rule.detail.get(row.id)
		detail.value = res.code === 200 ? (res.data || row) : row
	} catch { detail.value = row }
	detailVisible.value = true
}
const handleCopy = async (row) => {
	const res = await perfApi.rule.copy.post(row.id)
	if (res.code === 200) { ElMessage.success(res.message || '复制成功'); refresh() }
}
const handleToggle = async (row) => {
	const next = row.status === 'active' ? 'inactive' : 'active'
	try {
		await ElMessageBox.confirm(next === 'active' ? '确认启用该方案吗？' : '确认停用该方案吗？', '操作确认', { type: 'warning' })
	} catch { return }
	const res = await perfApi.rule.edit.put(row.id, { status: next })
	if (res.code === 200) { ElMessage.success(res.message || '保存成功'); refresh() }
}
const handleDelete = async (row) => {
	try {
		await ElMessageBox.confirm('确定删除该方案吗？删除后不可恢复。', '操作确认', { type: 'warning' })
	} catch { return }
	const res = await perfApi.rule.delete.delete(row.id)
	if (res.code === 200) { ElMessage.success(res.message || '删除成功'); refresh() }
}
const handleExport = async () => {
	const res = await perfApi.rule.export.get({ name: search.name, status: search.status, type: search.type })
	saveBlob(res, '提成方案.csv')
}
const resetSearch = () => { search.name = ''; search.type = ''; search.status = ''; refresh() }

onMounted(() => refresh())
</script>

<style scoped>
.perf-list { height: 100%; display: flex; flex-direction: column; }
/* 表格边框 #ebeef5 → #DCDFE6（vxe 单元格/表头边框均走 Element 边框变量） */
.perf-list :deep(.vxe-table) {
	--el-border-color-lighter: #DCDFE6;
	--el-border-color-light: #DCDFE6;
	--el-border-color: #DCDFE6;
}
.perf-filter {
	display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
	padding: 16px 20px; background: #fff; border-bottom: 1px solid #DCDFE6;
}
.perf-filter .label { font-size: 14px; color: #606266; }
.perf-toolbar { margin: 12px 0; display: flex; }
.detail-grid { font-size: 14px; color: #303133; line-height: 32px; }
.detail-grid .k { display: inline-block; width: 90px; color: #909399; }
.detail-grid .v { color: #303133; }
.detail-grid .rule-json { display: block; margin-top: 8px; }
.detail-grid .rule-json .k { display: block; margin-bottom: 4px; }
.detail-grid pre.v {
	margin: 0; padding: 10px 12px; background: #FAFAFA; border: 1px solid #DCDFE6; border-radius: 4px;
	font-size: 12px; color: #606266; max-height: 240px; overflow: auto;
}
</style>
