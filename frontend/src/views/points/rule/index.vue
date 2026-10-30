<template>
	<div class="pts-page pts-rule">
		<!-- 查询区 -->
		<div class="pts-filter">
			<span class="label">规则名称</span>
			<el-input v-model="search.rule_name" placeholder="规则名称" clearable size="small" style="width: 200px" @keyup.enter="search_" @clear="search_" />
			<span class="label">生效日期</span>
			<el-date-picker
				v-model="effectiveRange"
				type="daterange"
				value-format="YYYY-MM-DD"
				range-separator="-"
				start-placeholder="开始日期"
				end-placeholder="结束日期"
				size="small"
				style="width: 240px"
				@change="onDateChange"
			/>
			<span class="label">状态</span>
			<el-select v-model="search.status" placeholder="全部" clearable size="small" style="width: 110px" @change="search_">
				<el-option label="启用" :value="1" />
				<el-option label="停用" :value="0" />
			</el-select>
			<el-button type="primary" size="small" @click="search_">查询</el-button>
			<el-button size="small" @click="onReset">重置</el-button>
		</div>

		<div class="pts-toolbar">
			<el-button v-if="can('create')" type="primary" @click="openDialog(null)">新增规则</el-button>
			<el-button v-if="can('export')" @click="handleExport">导出</el-button>
		</div>

		<!-- 列表 -->
		<div class="pts-table">
			<el-table v-loading="loading" :data="data" row-key="id" stripe border height="100%" class="fin-table--dense">
				<el-table-column type="selection" width="45" align="center" />
				<el-table-column prop="rule_name" label="规则名称" min-width="180" show-overflow-tooltip />
				<el-table-column prop="ratio" label="积分比例" width="140" align="right" header-align="right">
					<template #default="{ row }"><span class="tabular ratio">1 : {{ fmtRatio(row.ratio) }}</span></template>
				</el-table-column>
				<el-table-column prop="effective_date" label="生效日期" width="130" align="center">
					<template #default="{ row }"><span class="tabular">{{ row.effective_date || '—' }}</span></template>
				</el-table-column>
				<el-table-column prop="status" label="状态" width="100" align="center">
					<template #default="{ row }">
						<span class="pts-tag" :class="row.status === 1 ? 'pts-tag--audited' : 'pts-tag--draft'">{{ row.status_label }}</span>
					</template>
				</el-table-column>
				<el-table-column prop="remark" label="备注" min-width="200" show-overflow-tooltip />
				<el-table-column prop="created_at" label="创建时间" width="160" align="center">
					<template #default="{ row }"><span class="tabular">{{ row.created_at || '—' }}</span></template>
				</el-table-column>
				<el-table-column label="操作" width="180" align="center" fixed="right">
					<template #default="{ row }">
						<el-button v-if="can('edit')" link type="primary" size="small" @click="openDialog(row)">编辑</el-button>
						<el-button v-if="can('edit')" link type="warning" size="small" @click="handleToggle(row)">{{ row.status === 1 ? '停用' : '启用' }}</el-button>
						<el-button v-if="can('delete')" link type="danger" size="small" @click="handleDelete(row)">删除</el-button>
					</template>
				</el-table-column>
				<template #empty><FinEmpty text="当前筛选条件下暂无数据" /></template>
			</el-table>
		</div>

		<!-- 分页 -->
		<div class="pts-pager fin-pager">
			<el-pagination
				background
				layout="total, prev, pager, next, jumper, sizes"
				:total="total"
				:page-size="paginationProps.pageSize"
				:current-page="paginationProps.currentPage"
				:page-sizes="[10, 20, 30, 50]"
				@current-change="handlePageChange"
				@size-change="handlePageSizeChange"
			/>
		</div>

		<!-- 新增/编辑弹窗 -->
		<el-dialog v-model="dialogVisible" class="pts-dialog" :title="form.id ? '编辑积分规则' : '新增积分规则'" width="600px" :close-on-click-modal="false" destroy-on-close>
			<el-form ref="formRef" :model="form" :rules="rules" label-width="110px" label-position="right">
				<el-form-item label="规则名称" prop="rule_name">
					<el-input v-model="form.rule_name" placeholder="请输入规则名称" maxlength="50" show-word-limit style="width: 100%" />
				</el-form-item>
				<el-form-item label="积分比例" prop="ratio">
					<el-input-number v-model="form.ratio" :min="0.0001" :max="999999" :precision="4" :step="0.5" controls-position="right" style="width: 200px" />
					<span class="hint">1 元消费累计 {{ fmtRatio(form.ratio) }} 积分</span>
				</el-form-item>
				<el-form-item label="生效日期" prop="effective_date">
					<el-date-picker v-model="form.effective_date" type="date" value-format="YYYY-MM-DD" placeholder="选择生效日期" style="width: 200px" />
				</el-form-item>
				<el-form-item label="状态" prop="status">
					<el-radio-group v-model="form.status">
						<el-radio :value="1">启用</el-radio>
						<el-radio :value="0">停用</el-radio>
					</el-radio-group>
				</el-form-item>
				<el-form-item label="备注" prop="remark">
					<el-input v-model="form.remark" type="textarea" :rows="2" maxlength="255" show-word-limit placeholder="备注说明" style="width: 100%" />
				</el-form-item>
			</el-form>
			<template #footer>
				<el-button @click="dialogVisible = false">取消</el-button>
				<el-button type="primary" :loading="saving" @click="save">确定</el-button>
			</template>
		</el-dialog>
	</div>
</template>

<script setup>
import { ref, reactive } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { useTable } from '@/hooks/useTable'
import { usePermission } from '@/composables/usePermission'
import { saveBlob } from '@/utils/fileDownload'
import cashApi from '@/api/cash'
import FinEmpty from '../../finance/_FinEmpty.vue'

const { has: hasPerm } = usePermission()
const can = (verb) => hasPerm(`cash.integral.rule.${verb}`)

const effectiveRange = ref([])
const { data, total, loading, searchForm: search, paginationProps, search: doSearch, resetSearch, refresh, handlePageChange, handlePageSizeChange } = useTable({
	apiObj: { get: (params) => cashApi.integral.rule.list.get(params) },
	searchForm: { rule_name: '', status: '', effective_date_start: '', effective_date_end: '' },
	pageSize: 30,
	pageSizes: [10, 20, 30, 50],
})

const fmtRatio = (v) => {
	const n = Number(v || 0)
	return n ? String(Number(n.toFixed(4))) : '—'
}

const onDateChange = (val) => {
	search.effective_date_start = val?.[0] || ''
	search.effective_date_end = val?.[1] || ''
	doSearch()
}
const search_ = () => doSearch()
const onReset = () => {
	effectiveRange.value = []
	resetSearch()
}

const dialogVisible = ref(false)
const saving = ref(false)
const formRef = ref(null)
const emptyForm = { id: null, rule_name: '', ratio: 1, effective_date: '', status: 1, remark: '' }
const form = reactive({ ...emptyForm })

const rules = {
	rule_name: [{ required: true, message: '请输入规则名称', trigger: 'blur' }],
	ratio: [{ required: true, message: '请输入积分比例', trigger: 'blur' }],
	effective_date: [{ required: true, message: '请选择生效日期', trigger: 'change' }],
}

const openDialog = (row) => {
	Object.assign(form, row ? { ...emptyForm, ...row } : { ...emptyForm })
	dialogVisible.value = true
}

const save = () => {
	formRef.value.validate(async (valid) => {
		if (!valid) return
		const body = {
			rule_name: form.rule_name,
			ratio: form.ratio,
			effective_date: form.effective_date,
			status: form.status,
			remark: form.remark,
		}
		saving.value = true
		try {
			const res = form.id ? await cashApi.integral.rule.edit.put(form.id, body) : await cashApi.integral.rule.add.post(body)
			if (res.code === 200) {
				ElMessage.success(res.message || '保存成功')
				dialogVisible.value = false
				refresh()
			}
		} catch {
			/* 422 业务提示由 axios 拦截器统一弹出 */
		} finally {
			saving.value = false
		}
	})
}

const handleToggle = async (row) => {
	const next = row.status === 1 ? '停用' : '启用'
	try {
		await ElMessageBox.confirm(`确认${next}规则「${row.rule_name}」吗？`, '操作确认', { type: 'warning' })
	} catch {
		return
	}
	try {
		const res = await cashApi.integral.rule.toggle.post(row.id)
		if (res.code === 200) {
			ElMessage.success(res.message || '保存成功')
			refresh()
		}
	} catch {
		/* 非 200 已由 axios 拦截器统一弹错 */
	}
}

const handleDelete = async (row) => {
	try {
		await ElMessageBox.confirm(`确定删除规则「${row.rule_name}」吗？删除后不可恢复。`, '操作确认', { type: 'warning' })
	} catch {
		return
	}
	try {
		const res = await cashApi.integral.rule.delete.delete(row.id)
		if (res.code === 200) {
			ElMessage.success(res.message || '删除成功')
			refresh()
		}
	} catch {
		/* 非 200 已由 axios 拦截器统一弹错 */
	}
}

const handleExport = async () => {
	const blob = await cashApi.integral.rule.export({
		rule_name: search.rule_name,
		status: search.status,
		effective_date_start: search.effective_date_start,
		effective_date_end: search.effective_date_end,
	})
	saveBlob(blob, `积分规则_${Date.now()}.csv`)
}
</script>

<style>
@import '../../finance/_shared.css';
@import '../_shared.css';
</style>

<style scoped>
.pts-rule {
	height: 100%;
	display: flex;
	flex-direction: column;
}
.pts-toolbar {
	margin: 12px 0;
	display: flex;
}
.pts-table {
	flex: 1;
	padding: 0 20px;
	display: flex;
	flex-direction: column;
	min-height: 0;
}
.pts-table :deep(.el-table) {
	flex: 1;
}
.ratio {
	color: var(--text-primary);
	font-weight: 600;
	font-family: var(--font-numeric);
	font-variant-numeric: tabular-nums;
}
.hint {
	margin-left: 12px;
	font-size: var(--font-aux);
	color: var(--text-secondary);
	line-height: 28px;
}
/* D3 简报口径：表头 44px 13px/600，明细行 41px（覆盖 _shared.css 的 14px/500 + 48px） */
.pts-rule :deep(.el-table.fin-table--dense th.el-table__cell .cell) {
	font-size: var(--font-base);
	font-weight: 600;
}
.pts-rule :deep(.el-table.fin-table--dense .el-table__body .el-table__row) {
	height: 41px;
}
.pts-rule :deep(.el-table .el-table__body td.el-table__cell .cell) {
	font-size: var(--font-base);
	color: var(--text-primary);
}
</style>
