<template>
	<div class="pts-page pts-grant">
		<!-- 查询区：单号 / 会员 / 日期 / 状态 四筛选 -->
		<div class="pts-filter">
			<span class="label">发放单号</span>
			<el-input v-model="search.grant_no" placeholder="发放单号" clearable size="small" style="width: 180px" @keyup.enter="doSearch" @clear="doSearch" />
			<span class="label">会员</span>
			<el-select
				v-model="search.member_id"
				filterable
				remote
				reserve-keyword
				clearable
				:remote-method="searchMembers"
				:loading="memberLoading"
				placeholder="会员名称/编号"
				size="small"
				style="width: 200px"
				@change="doSearch"
				@clear="doSearch"
			>
				<el-option v-for="m in filterMembers" :key="m.id" :label="`${m.name}（${m.member_no || '—'}）`" :value="m.id" />
			</el-select>
			<span class="label">单据日期</span>
			<el-date-picker
				v-model="dateRange"
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
			<el-select v-model="search.status" placeholder="全部" clearable size="small" style="width: 110px" @change="doSearch">
				<el-option v-for="(label, value) in STATUS_LABEL" :key="value" :label="label" :value="Number(value)" />
			</el-select>
			<el-button type="primary" size="small" @click="doSearch">查询</el-button>
			<el-button size="small" @click="onReset">重置</el-button>
		</div>

		<div class="pts-toolbar">
			<el-button v-if="can('create')" type="primary" @click="openDialog(null)">新增发放单</el-button>
			<el-button v-if="can('export')" @click="handleExport">导出</el-button>
		</div>

		<!-- 列表 -->
		<div class="pts-table">
			<el-table v-loading="loading" :data="data" row-key="id" stripe border height="100%" empty-text="当前筛选条件下暂无数据">
				<el-table-column type="selection" width="45" align="center" />
				<el-table-column prop="grant_no" label="发放单号" width="170" align="center">
					<template #default="{ row }">
						<span class="tabular link" @click="openDetail(row)">{{ row.grant_no }}</span>
					</template>
				</el-table-column>
				<el-table-column prop="grant_date" label="发放日期" width="120" align="center">
					<template #default="{ row }"><span class="tabular">{{ row.grant_date || '—' }}</span></template>
				</el-table-column>
				<el-table-column prop="grant_type_label" label="类型" width="90" align="center">
					<template #default="{ row }">{{ row.grant_type_label || '—' }}</template>
				</el-table-column>
				<el-table-column label="会员" min-width="200" show-overflow-tooltip>
					<template #default="{ row }">
						<span>{{ row.member_names || '—' }}</span>
						<span v-if="row.member_count > 1" class="count">等 {{ row.member_count }} 人</span>
					</template>
				</el-table-column>
				<el-table-column prop="total_points" label="发放积分" width="120" align="right">
					<template #default="{ row }"><span class="tabular points">{{ fmtInt(row.total_points) }}</span></template>
				</el-table-column>
				<el-table-column prop="status" label="状态" width="100" align="center">
					<template #default="{ row }">
						<span class="pts-tag" :class="statusClass(row.status)">{{ row.status_label || STATUS_LABEL[row.status] }}</span>
					</template>
				</el-table-column>
				<el-table-column prop="remark" label="事由" min-width="180" show-overflow-tooltip />
				<el-table-column prop="operator_name" label="制单人" width="100" align="center">
					<template #default="{ row }">{{ row.operator_name || '—' }}</template>
				</el-table-column>
				<el-table-column prop="audited_at" label="审核时间" width="160" align="center">
					<template #default="{ row }"><span class="tabular">{{ row.audited_at || '—' }}</span></template>
				</el-table-column>
				<el-table-column label="操作" width="210" align="center" fixed="right">
					<template #default="{ row }">
						<el-button v-if="can('edit') && row.status === STATUS.DRAFT" link type="primary" size="small" @click="openDialog(row)">编辑</el-button>
						<el-button v-if="can('edit') && row.status === STATUS.DRAFT" link type="primary" size="small" @click="handleSubmit(row)">提交</el-button>
						<el-button v-if="can('audit') && row.status === STATUS.PENDING" link type="success" size="small" @click="handleAudit(row)">审核</el-button>
						<el-button v-if="can('delete') && row.status !== STATUS.AUDITED && row.status !== STATUS.VOID" link type="danger" size="small" @click="handleVoid(row)">作废</el-button>
						<el-button link type="primary" size="small" @click="openDetail(row)">详情</el-button>
					</template>
				</el-table-column>
				<template #empty><span class="pts-empty">当前筛选条件下暂无数据</span></template>
			</el-table>
		</div>

		<!-- 分页 -->
		<div class="pts-pager">
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

		<!-- 录入弹窗：左单头表单 / 右明细列表 -->
		<el-dialog v-model="dialogVisible" class="pts-dialog pts-entry" :title="form.id ? `编辑发放单 ${form.grant_no}` : '新增积分发放单'" width="980px" :close-on-click-modal="false" destroy-on-close>
			<div class="entry">
				<div class="entry__head">
					<el-form ref="formRef" :model="form" :rules="rules" label-width="110px" label-position="right">
						<el-form-item label="发放日期" prop="grant_date">
							<el-date-picker v-model="form.grant_date" type="date" value-format="YYYY-MM-DD" placeholder="选择发放日期" style="width: 100%" />
						</el-form-item>
						<el-form-item label="发放类型" prop="grant_type">
							<el-select v-model="form.grant_type" placeholder="选择发放类型" style="width: 100%">
								<el-option v-for="(label, value) in TYPE_LABEL" :key="value" :label="label" :value="value" />
							</el-select>
						</el-form-item>
						<el-form-item label="事由" prop="remark">
							<el-input v-model="form.remark" type="textarea" :rows="3" maxlength="255" show-word-limit placeholder="请输入发放事由" style="width: 100%" />
						</el-form-item>
						<el-form-item label="合计积分">
							<span class="tabular total">{{ fmtInt(totalPoints) }}</span>
						</el-form-item>
					</el-form>
				</div>

				<div class="entry__items">
					<div class="entry__adder">
						<el-select
							v-model="draft.member_id"
							filterable
							remote
							reserve-keyword
							clearable
							:remote-method="searchMembers"
							:loading="memberLoading"
							placeholder="会员名称/编号"
							size="small"
							style="width: 220px"
						>
							<el-option v-for="m in filterMembers" :key="m.id" :label="`${m.name}（${m.member_no || '—'}）`" :value="m.id" />
						</el-select>
						<el-input-number v-model="draft.points" :min="1" :max="10000000" :precision="0" :step="100" controls-position="right" size="small" style="width: 140px" placeholder="积分" />
						<el-input v-model="draft.remark" placeholder="明细备注（选填）" maxlength="255" size="small" style="width: 200px" @keyup.enter="addItem" />
						<el-button type="primary" size="small" @click="addItem">添加明细</el-button>
					</div>
					<el-table :data="form.items" class="pts-table--dense" border height="100%" empty-text="请至少添加一条发放明细">
						<el-table-column label="会员" min-width="170" show-overflow-tooltip>
							<template #default="{ row }">{{ memberLabel(row) }}</template>
						</el-table-column>
						<el-table-column label="发放积分" width="120" align="right">
							<template #default="{ row }"><span class="tabular points">{{ fmtInt(row.points) }}</span></template>
						</el-table-column>
						<el-table-column label="备注" min-width="150" show-overflow-tooltip>
							<template #default="{ row }">{{ row.remark || '—' }}</template>
						</el-table-column>
						<el-table-column label="操作" width="70" align="center">
							<template #default="{ $index }">
								<el-button link type="danger" size="small" @click="removeItem($index)">删除</el-button>
							</template>
						</el-table-column>
						<template #empty><span class="pts-empty">请至少添加一条发放明细</span></template>
					</el-table>
				</div>
			</div>
			<template #footer>
				<el-button @click="dialogVisible = false">取消</el-button>
				<el-button type="primary" :loading="saving" @click="save('draft')">保存草稿</el-button>
				<el-button v-if="can('edit')" type="success" :loading="saving" @click="save('submit')">保存并提交</el-button>
			</template>
		</el-dialog>

		<!-- 详情弹窗 -->
		<el-dialog v-model="detailVisible" class="pts-dialog" :title="`发放单详情 ${detail.grant_no || ''}`" width="820px">
			<el-descriptions :column="3" border size="small">
				<el-descriptions-item label="发放单号">{{ detail.grant_no || '—' }}</el-descriptions-item>
				<el-descriptions-item label="发放日期">{{ detail.grant_date || '—' }}</el-descriptions-item>
				<el-descriptions-item label="类型">{{ detail.grant_type_label || '—' }}</el-descriptions-item>
				<el-descriptions-item label="状态">
					<span class="pts-tag" :class="statusClass(detail.status)">{{ detail.status_label || STATUS_LABEL[detail.status] || '—' }}</span>
				</el-descriptions-item>
				<el-descriptions-item label="合计积分">{{ fmtInt(detail.total_points) }}</el-descriptions-item>
				<el-descriptions-item label="制单人">{{ detail.operator_name || '—' }}</el-descriptions-item>
				<el-descriptions-item label="审核时间">{{ detail.audited_at || '—' }}</el-descriptions-item>
				<el-descriptions-item label="事由" :span="2">{{ detail.remark || '—' }}</el-descriptions-item>
			</el-descriptions>
			<el-table :data="detail.items || []" class="pts-table--dense" border max-height="360" style="margin-top: 16px" empty-text="暂无明细">
				<el-table-column label="会员编号" width="140" align="center">
					<template #default="{ row }"><span class="tabular">{{ row.member_no || '—' }}</span></template>
				</el-table-column>
				<el-table-column label="会员名称" min-width="150" show-overflow-tooltip>
					<template #default="{ row }">{{ row.member_name || '—' }}</template>
				</el-table-column>
				<el-table-column label="发放积分" width="120" align="right">
					<template #default="{ row }"><span class="tabular points">{{ fmtInt(row.points) }}</span></template>
				</el-table-column>
				<el-table-column label="备注" min-width="160" show-overflow-tooltip>
					<template #default="{ row }">{{ row.remark || '—' }}</template>
				</el-table-column>
				<template #empty><span class="pts-empty">暂无明细</span></template>
			</el-table>
			<template #footer>
				<el-button @click="detailVisible = false">关闭</el-button>
			</template>
		</el-dialog>
	</div>
</template>

<script setup>
import { ref, reactive, computed } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { useTable } from '@/hooks/useTable'
import { usePermission } from '@/composables/usePermission'
import { saveBlob } from '@/utils/fileDownload'
import businessApi from '@/api/business'
import cashApi from '@/api/cash'

const { has: hasPerm } = usePermission()
const can = (verb) => hasPerm(`cash.integral.grant.${verb}`)

const STATUS = { DRAFT: 0, PENDING: 1, AUDITED: 2, VOID: 3 }
const STATUS_LABEL = { 0: '草稿', 1: '待审核', 2: '已审核', 3: '已作废' }
const STATUS_CLASS = { 0: 'pts-tag--draft', 1: 'pts-tag--pending', 2: 'pts-tag--audited', 3: 'pts-tag--void' }
const TYPE_LABEL = { daily: '日常', activity: '活动', compensate: '补偿', other: '其他' }

const statusClass = (status) => STATUS_CLASS[status] ?? 'pts-tag--draft'
const fmtInt = (v) => (Number.isFinite(Number(v)) ? Number(v).toLocaleString('zh-CN') : '—')
const today = () => new Date().toLocaleDateString('sv-SE')

const dateRange = ref([])
const { data, total, loading, searchForm: search, paginationProps, search: doSearch, resetSearch, refresh, handlePageChange, handlePageSizeChange } = useTable({
	apiObj: { get: (params) => cashApi.integral.grant.list.get(params) },
	searchForm: { grant_no: '', member_id: '', status: '', date_start: '', date_end: '' },
	pageSize: 30,
	pageSizes: [10, 20, 30, 50],
})

// 会员远程搜索：筛选区与明细录入共用一份候选（带已选项，避免回显只剩 ID）
const filterMembers = ref([])
const memberLoading = ref(false)
const searchMembers = async (kw) => {
	memberLoading.value = true
	try {
		const res = await businessApi.member.search.get({ keyword: kw || '', limit: 20 })
		const picked = filterMembers.value.filter((m) => search.member_id === m.id || form.items.some((it) => it.member_id === m.id))
		filterMembers.value = [...new Map([...picked, ...(res.data || [])].map((m) => [m.id, m])).values()]
	} finally {
		memberLoading.value = false
	}
}

const onDateChange = (val) => {
	search.date_start = val?.[0] || ''
	search.date_end = val?.[1] || ''
	doSearch()
}
const onReset = () => {
	dateRange.value = []
	resetSearch()
}

// ---- 录入 ----
const dialogVisible = ref(false)
const saving = ref(false)
const formRef = ref(null)
const emptyForm = { id: null, grant_no: '', grant_date: today(), grant_type: 'daily', remark: '', items: [] }
const form = reactive({ ...emptyForm })
const draft = reactive({ member_id: null, points: 100, remark: '' })

const rules = {
	grant_date: [{ required: true, message: '请选择发放日期', trigger: 'change' }],
	grant_type: [{ required: true, message: '请选择发放类型', trigger: 'change' }],
}

const totalPoints = computed(() => form.items.reduce((sum, it) => sum + (Number(it.points) || 0), 0))

const memberLabel = (row) => {
	const m = filterMembers.value.find((x) => x.id === row.member_id)
	const name = m?.name || row.member_name
	if (!name) return '—'
	return m?.member_no || row.member_no ? `${name}（${m?.member_no || row.member_no}）` : name
}

const addItem = () => {
	if (!draft.member_id) {
		ElMessage.warning('请选择会员')
		return
	}
	if (!Number(draft.points) || Number(draft.points) < 1) {
		ElMessage.warning('发放积分必须为大于 0 的整数')
		return
	}
	if (form.items.some((it) => it.member_id === draft.member_id)) {
		ElMessage.warning('该会员已在明细中，请直接修改其积分')
		return
	}
	const picked = filterMembers.value.find((m) => m.id === draft.member_id)
	form.items.push({ member_id: draft.member_id, member_name: picked?.name, member_no: picked?.member_no, points: Number(draft.points), remark: draft.remark })
	draft.member_id = null
	draft.remark = ''
}

const removeItem = async (index) => {
	try {
		await ElMessageBox.confirm(`确认移除会员「${memberLabel(form.items[index])}」的发放明细吗？`, '操作确认', { type: 'warning' })
	} catch {
		return
	}
	form.items.splice(index, 1)
}

const openDialog = async (row) => {
	Object.assign(form, { ...emptyForm, grant_date: today(), items: [] })
	draft.member_id = null
	draft.points = 100
	draft.remark = ''
	if (row) {
		let res
		try {
			res = await cashApi.integral.grant.detail.get(row.id)
		} catch {
			return
		}
		const g = res.data || {}
		Object.assign(form, {
			id: g.id,
			grant_no: g.grant_no || '',
			grant_date: g.grant_date || today(),
			grant_type: g.grant_type || 'daily',
			remark: g.remark || '',
			items: (g.items || []).map((it) => ({ member_id: it.member_id, member_name: it.member_name, member_no: it.member_no, points: Number(it.points) || 0, remark: it.remark || '' })),
		})
	}
	dialogVisible.value = true
}

const save = async (mode) => {
	const valid = await formRef.value.validate().catch(() => false)
	if (!valid) return
	if (!form.items.length) {
		ElMessage.warning('请至少添加一条发放明细')
		return
	}
	const body = {
		grant_date: form.grant_date,
		grant_type: form.grant_type,
		remark: form.remark,
		items: form.items.map(({ member_id, points, remark }) => ({ member_id, points, remark })),
	}
	saving.value = true
	try {
		const res = form.id ? await cashApi.integral.grant.edit.put(form.id, body) : await cashApi.integral.grant.add.post(body)
		if (res.code !== 200) return
		const id = res.data?.id ?? form.id
		if (mode === 'submit') {
			const sub = await cashApi.integral.grant.submit.post(id)
			if (sub.code === 200) ElMessage.success(sub.message || '提交成功')
		} else {
			ElMessage.success(res.message || '保存成功')
		}
		dialogVisible.value = false
		refresh()
	} catch {
		/* 422 业务提示由 axios 拦截器统一弹出 */
	} finally {
		saving.value = false
	}
}

// ---- 状态流转 ----
const confirmThen = async (message, fn) => {
	try {
		await ElMessageBox.confirm(message, '操作确认', { type: 'warning' })
	} catch {
		return
	}
	try {
		const res = await fn()
		if (res.code === 200) {
			ElMessage.success(res.message || '操作成功')
			refresh()
		}
	} catch {
		/* 非 200 已由 axios 拦截器统一弹错 */
	}
}

const handleSubmit = (row) => confirmThen(`确认提交发放单「${row.grant_no}」进入待审核吗？`, () => cashApi.integral.grant.submit.post(row.id))
const handleAudit = (row) =>
	confirmThen(`确认审核发放单「${row.grant_no}」吗？审核后 ${row.member_count || ''} 名会员的 ${fmtInt(row.total_points)} 积分将实时入账。`, () =>
		cashApi.integral.grant.audit.post(row.id)
	)
const handleVoid = (row) => confirmThen(`确认作废发放单「${row.grant_no}」吗？作废后不可恢复。`, () => cashApi.integral.grant.void.post(row.id))

// ---- 详情 / 导出 ----
const detailVisible = ref(false)
const detail = ref({})
const openDetail = async (row) => {
	let res
	try {
		res = await cashApi.integral.grant.detail.get(row.id)
	} catch {
		return
	}
	detail.value = res.data || {}
	detailVisible.value = true
}

const handleExport = async () => {
	const blob = await cashApi.integral.grant.export({ ...search })
	saveBlob(blob, `积分发放_${Date.now()}.csv`)
}

searchMembers('')
</script>

<style>
@import '../_shared.css';
</style>

<style scoped>
.pts-grant {
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
.link {
	color: var(--color-primary);
	cursor: pointer;
}
.count {
	margin-left: 6px;
	font-size: var(--font-aux);
	color: var(--text-secondary);
}
.points {
	color: var(--color-success);
	font-weight: 600;
	font-family: var(--font-numeric);
	font-variant-numeric: tabular-nums;
}
.total {
	font-size: var(--font-h2);
	font-weight: 700;
	color: var(--text-primary);
	font-family: var(--font-numeric);
	font-variant-numeric: tabular-nums;
}
</style>

<style>
/* 录入弹窗左右分栏：左单头 320px，右明细自适应（弹窗 teleport 到 body，须非 scoped） */
.pts-entry .entry {
	display: flex;
	gap: 20px;
	height: 420px;
}
.pts-entry .entry__head {
	width: 320px;
	flex-shrink: 0;
	border-right: 1px solid var(--border-lighter);
	padding-right: 20px;
}
.pts-entry .entry__items {
	flex: 1;
	min-width: 0;
	display: flex;
	flex-direction: column;
}
.pts-entry .entry__adder {
	display: flex;
	gap: 8px;
	margin-bottom: 12px;
}
</style>
