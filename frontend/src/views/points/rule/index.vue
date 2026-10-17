<template>
	<div class="pts-page">
		<!-- 筛选 -->
		<div class="pts-filter">
			<span class="pts-filter__label">规则名称</span>
			<el-input v-model="search.name" placeholder="规则名称" clearable size="small" style="width: 200px" @keyup.enter="refresh" @clear="refresh" />
			<el-button type="primary" size="small" @click="refresh">查询</el-button>
			<el-button size="small" @click="resetSearch">重置</el-button>
			<div style="flex: 1" />
			<el-button type="primary" size="small" @click="openDialog(null)">新增规则</el-button>
		</div>

		<!-- 列表 -->
		<div class="pts-table">
			<el-table :data="pagedList" border v-loading="loading" :header-row-style="{ background: 'var(--bg-hover)' }">
				<el-table-column type="index" label="序号" width="50" align="center" />
				<el-table-column prop="name" label="规则名称" min-width="160" show-overflow-tooltip />
				<el-table-column label="积分比例" width="130" align="right">
					<template #default="{ row }">
						<span class="tabular rule-ratio">1 : {{ row.ratio }}</span>
					</template>
				</el-table-column>
				<el-table-column prop="effectiveDate" label="生效日期" width="130" align="center">
					<template #default="{ row }">
						<span class="tabular">{{ row.effectiveDate }}</span>
					</template>
				</el-table-column>
				<el-table-column label="状态" width="90" align="center">
					<template #default="{ row }">
						<el-tag :type="row.status === 1 ? 'success' : 'info'" size="small" :disable-transitions="true">{{ row.status === 1 ? '启用' : '停用' }}</el-tag>
					</template>
				</el-table-column>
				<el-table-column prop="remark" label="备注" min-width="180" show-overflow-tooltip />
				<el-table-column label="操作" width="70" align="center">
					<template #default="{ row }">
						<el-button link type="primary" size="small" @click="openDialog(row)">编辑</el-button>
					</template>
				</el-table-column>
			</el-table>
		</div>

		<!-- 分页 -->
		<div class="pts-pager">
			<el-pagination background layout="total, prev, pager, next, sizes" :total="total" :page-size="pageSize" :current-page="page" :page-sizes="[10, 20, 50]" @current-change="onPageChange" @size-change="onSizeChange" />
		</div>

		<!-- 新增/编辑弹窗 -->
		<el-dialog v-model="dialogVisible" :title="dialogTitle" width="600px" :close-on-click-modal="false" destroy-on-close>
			<el-form ref="ruleFormRef" :model="ruleForm" :rules="rules" label-width="110px" label-position="right">
				<el-form-item label="规则名称" prop="name">
					<el-input v-model="ruleForm.name" placeholder="请输入规则名称" maxlength="30" show-word-limit style="width: 100%" />
				</el-form-item>
				<el-form-item label="积分比例" prop="ratio">
					<el-input-number v-model="ruleForm.ratio" :min="0.1" :max="999" :precision="1" :step="0.5" size="small" style="width: 200px" />
					<span class="pts-form__hint">1 元消费累计 {{ ruleForm.ratio || '—' }} 积分</span>
				</el-form-item>
				<el-form-item label="生效日期" prop="effectiveDate">
					<el-date-picker v-model="ruleForm.effectiveDate" type="date" value-format="YYYY-MM-DD" placeholder="选择生效日期" size="small" style="width: 200px" />
				</el-form-item>
				<el-form-item label="状态" prop="status">
					<el-radio-group v-model="ruleForm.status">
						<el-radio :value="1">启用</el-radio>
						<el-radio :value="0">停用</el-radio>
					</el-radio-group>
				</el-form-item>
				<el-form-item label="备注" prop="remark">
					<el-input v-model="ruleForm.remark" type="textarea" :rows="2" maxlength="200" show-word-limit placeholder="备注说明" style="width: 100%" />
				</el-form-item>
			</el-form>
			<template #footer>
				<el-button size="small" @click="dialogVisible = false">取消</el-button>
				<el-button type="primary" size="small" :loading="saving" @click="save">确定</el-button>
			</template>
		</el-dialog>
	</div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import { MOCK_RULES } from '../../../mock/points'

const search = reactive({ name: '' })
const total = ref(MOCK_RULES.length)
const page = ref(1)
const pageSize = ref(20)
const loading = ref(false)
const saving = ref(false)
const dialogVisible = ref(false)
const dialogTitle = ref('新增积分规则')
const ruleFormRef = ref(null)

const ruleForm = reactive({
	id: null,
	name: '',
	ratio: 1,
	effectiveDate: '',
	status: 1,
	remark: '',
})

const rules = {
	name: [{ required: true, message: '请输入规则名称', trigger: 'blur' }],
	ratio: [{ required: true, message: '请输入积分比例', trigger: 'blur' }],
	effectiveDate: [{ required: true, message: '请选择生效日期', trigger: 'change' }],
}

const filtered = computed(() => {
	if (!search.name.trim()) return [...MOCK_RULES]
	const kw = search.name.trim().toLowerCase()
	return MOCK_RULES.filter((r) => r.name.toLowerCase().includes(kw))
})

const pagedList = computed(() => {
	const start = (page.value - 1) * pageSize.value
	return filtered.value.slice(start, start + pageSize.value)
})

function refresh() {
	loading.value = true
	// TODO(api): 替换为 integral_rule.list 接口
	setTimeout(() => {
		loading.value = false
		total.value = filtered.value.length
	}, 200)
}

function resetSearch() {
	search.name = ''
	page.value = 1
	refresh()
}

function onPageChange(p) {
	page.value = p
}

function onSizeChange(s) {
	pageSize.value = s
	page.value = 1
}

function openDialog(row) {
	if (row) {
		dialogTitle.value = '编辑积分规则'
		Object.assign(ruleForm, { id: row.id, name: row.name, ratio: row.ratio, effectiveDate: row.effectiveDate, status: row.status, remark: row.remark })
	} else {
		dialogTitle.value = '新增积分规则'
		Object.assign(ruleForm, { id: null, name: '', ratio: 1, effectiveDate: '', status: 1, remark: '' })
	}
	dialogVisible.value = true
}

function save() {
	ruleFormRef.value.validate((valid) => {
		if (!valid) return
		saving.value = true
		// TODO(api): 替换为 integral_rule.save 接口
		setTimeout(() => {
			saving.value = false
			dialogVisible.value = false
			ElMessage.success(ruleForm.id ? '规则已更新' : '规则已创建')
		}, 300)
	})
}

onMounted(refresh)
</script>

<style scoped>
.pts-page {
	display: flex;
	flex-direction: column;
	height: 100%;
}
.pts-filter {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 12px 16px;
	background: var(--bg-card);
	border-bottom: 1px solid var(--border-lighter);
}
.pts-filter__label {
	font-size: var(--font-base);
	color: var(--text-regular);
	white-space: nowrap;
}
.pts-table {
	flex: 1;
	padding: 0 16px;
	display: flex;
	flex-direction: column;
	min-height: 0;
}
.pts-table :deep(.el-table) {
	flex: 1;
}
.pts-table :deep(.el-table th.el-table__cell) {
	background: var(--bg-hover);
	color: var(--text-regular);
	font-weight: 600;
	font-size: var(--font-base);
}
.pts-table :deep(.el-table td.el-table__cell) {
	color: var(--text-primary);
	font-size: var(--font-base);
}
.pts-table :deep(.el-table__row) {
	height: 40px;
}
.rule-ratio {
	color: var(--text-primary);
	font-weight: 600;
	font-family: var(--font-numeric);
	font-variant-numeric: tabular-nums;
}
.pts-pager {
	display: flex;
	justify-content: flex-end;
	padding: 12px 16px 0;
}
.pts-form__hint {
	margin-left: 12px;
	font-size: var(--font-aux);
	color: var(--text-secondary);
	line-height: 28px;
}
</style>
