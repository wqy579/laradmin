<template>
	<div class="pts-page pts-use">
		<!-- 查询区：单号 / 会员 / 日期 / 状态 四筛选 -->
		<div class="pts-filter">
			<span class="label">核销单号</span>
			<el-input v-model="search.verify_no" placeholder="核销单号" clearable size="small" style="width: 180px" @keyup.enter="doSearch" @clear="doSearch" />
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
			<span class="label">核销日期</span>
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
			<el-button v-if="can('create')" type="primary" @click="openDialog(null)">新增核销单</el-button>
			<el-button v-if="can('export')" @click="handleExport">导出</el-button>
		</div>

		<!-- 列表 -->
		<div class="pts-table">
			<el-table v-loading="loading" :data="data" row-key="id" stripe border height="100%" empty-text="当前筛选条件下暂无数据">
				<el-table-column type="selection" width="45" align="center" />
				<el-table-column prop="verify_no" label="核销单号" width="170" align="center">
					<template #default="{ row }">
						<span class="tabular link" @click="openDetail(row)">{{ row.verify_no }}</span>
					</template>
				</el-table-column>
				<el-table-column prop="verify_date" label="核销日期" width="120" align="center">
					<template #default="{ row }"><span class="tabular">{{ row.verify_date || '—' }}</span></template>
				</el-table-column>
				<el-table-column label="会员" min-width="180" show-overflow-tooltip>
					<template #default="{ row }">
						<span>{{ row.member_name || '—' }}</span>
						<span v-if="row.member_no" class="count">{{ row.member_no }}</span>
					</template>
				</el-table-column>
				<el-table-column prop="points" label="核销积分" width="120" align="right">
					<template #default="{ row }"><span class="tabular points">-{{ fmtInt(row.points) }}</span></template>
				</el-table-column>
				<el-table-column prop="points_after" label="核销后余额" width="120" align="right">
					<template #default="{ row }"><span class="tabular">{{ isNil(row.points_after) ? '—' : fmtInt(row.points_after) }}</span></template>
				</el-table-column>
				<el-table-column prop="verify_type_label" label="类型" width="90" align="center">
					<template #default="{ row }">{{ row.verify_type_label || '—' }}</template>
				</el-table-column>
				<el-table-column prop="related_order_no" label="关联单号" width="150" align="center">
					<template #default="{ row }"><span class="tabular">{{ row.related_order_no || '—' }}</span></template>
				</el-table-column>
				<el-table-column prop="status" label="状态" width="100" align="center">
					<template #default="{ row }">
						<span class="pts-tag" :class="statusClass(row.status)">{{ row.status_label || STATUS_LABEL[row.status] }}</span>
					</template>
				</el-table-column>
				<el-table-column prop="remark" label="事由" min-width="160" show-overflow-tooltip />
				<el-table-column prop="operator_name" label="制单人" width="100" align="center">
					<template #default="{ row }">{{ row.operator_name || '—' }}</template>
				</el-table-column>
				<el-table-column prop="audited_at" label="审核时间" width="160" align="center">
					<template #default="{ row }"><span class="tabular">{{ row.audited_at || '—' }}</span></template>
				</el-table-column>
				<el-table-column label="操作" width="220" align="center" fixed="right">
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

		<!-- 录入弹窗：单会员核销 -->
		<el-dialog v-model="dialogVisible" class="pts-dialog" :title="form.id ? `编辑核销单 ${form.verify_no}` : '新增积分核销单'" width="620px" :close-on-click-modal="false" destroy-on-close>
			<el-form ref="formRef" :model="form" :rules="rules" label-width="110px" label-position="right">
				<el-form-item label="核销日期" prop="verify_date">
					<el-date-picker v-model="form.verify_date" type="date" value-format="YYYY-MM-DD" placeholder="选择核销日期" style="width: 100%" />
				</el-form-item>
				<el-form-item label="会员" prop="member_id">
					<el-select
						v-model="form.member_id"
						filterable
						remote
						reserve-keyword
						clearable
						:remote-method="searchMembers"
						:loading="memberLoading"
						placeholder="会员名称/编号"
						style="width: 100%"
						@change="loadSummary"
					>
						<el-option v-for="m in filterMembers" :key="m.id" :label="`${m.name}（${m.member_no || '—'}）`" :value="m.id" />
					</el-select>
				</el-form-item>
				<el-form-item label="可用积分">
					<span v-if="summary.member_id" class="available">{{ fmtInt(summary.points_current) }}</span>
					<span v-else class="pts-empty">请先选择会员</span>
					<span v-if="summary.member_id" class="count">累计发放 {{ fmtInt(summary.points_grant) }} · 累计核销 {{ fmtInt(summary.points_verify) }}</span>
				</el-form-item>
				<el-form-item label="核销积分" prop="points">
					<el-input-number v-model="form.points" :min="1" :max="pointsMax" :precision="0" :step="10" controls-position="right" style="width: 100%" />
				</el-form-item>
				<el-form-item label="核销类型" prop="verify_type">
					<el-select v-model="form.verify_type" placeholder="选择核销类型" style="width: 100%">
						<el-option v-for="(label, value) in TYPE_LABEL" :key="value" :label="label" :value="value" />
					</el-select>
				</el-form-item>
				<el-form-item label="关联单号" prop="related_order_no">
					<el-input v-model="form.related_order_no" maxlength="32" placeholder="选填，如消费订单号" clearable style="width: 100%" />
				</el-form-item>
				<el-form-item label="事由" prop="remark">
					<el-input v-model="form.remark" type="textarea" :rows="3" maxlength="255" show-word-limit placeholder="请输入核销事由" style="width: 100%" />
				</el-form-item>
			</el-form>
			<template #footer>
				<el-button @click="dialogVisible = false">取消</el-button>
				<el-button type="primary" :loading="saving" @click="save('draft')">保存草稿</el-button>
				<el-button v-if="can('edit')" type="success" :loading="saving" @click="save('submit')">保存并提交</el-button>
			</template>
		</el-dialog>

		<!-- 详情弹窗 -->
		<el-dialog v-model="detailVisible" class="pts-dialog" :title="`核销单详情 ${detail.verify_no || ''}`" width="820px">
			<el-descriptions :column="3" border size="small">
				<el-descriptions-item label="核销单号">{{ detail.verify_no || '—' }}</el-descriptions-item>
				<el-descriptions-item label="核销日期">{{ detail.verify_date || '—' }}</el-descriptions-item>
				<el-descriptions-item label="类型">{{ detail.verify_type_label || '—' }}</el-descriptions-item>
				<el-descriptions-item label="会员">{{ detail.member_name || '—' }}</el-descriptions-item>
				<el-descriptions-item label="会员编号">{{ detail.member_no || '—' }}</el-descriptions-item>
				<el-descriptions-item label="状态">
					<span class="pts-tag" :class="statusClass(detail.status)">{{ detail.status_label || STATUS_LABEL[detail.status] || '—' }}</span>
				</el-descriptions-item>
				<el-descriptions-item label="核销积分">
					<span class="tabular points">-{{ fmtInt(detail.points) }}</span>
				</el-descriptions-item>
				<el-descriptions-item label="核销前积分">{{ isNil(detail.points_before) ? '—' : fmtInt(detail.points_before) }}</el-descriptions-item>
				<el-descriptions-item label="核销后余额">{{ isNil(detail.points_after) ? '—' : fmtInt(detail.points_after) }}</el-descriptions-item>
				<el-descriptions-item label="关联单号">{{ detail.related_order_no || '—' }}</el-descriptions-item>
				<el-descriptions-item label="制单人">{{ detail.operator_name || '—' }}</el-descriptions-item>
				<el-descriptions-item label="审核时间">{{ detail.audited_at || '—' }}</el-descriptions-item>
				<el-descriptions-item label="事由" :span="3">{{ detail.remark || '—' }}</el-descriptions-item>
			</el-descriptions>
			<template #footer>
				<el-button @click="goLedger">查看该会员积分流水</el-button>
				<el-button type="primary" @click="detailVisible = false">关闭</el-button>
			</template>
		</el-dialog>
	</div>
</template>

<script setup>
import { ref, reactive, computed } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { useTable } from '@/hooks/useTable'
import { usePermission } from '@/composables/usePermission'
import { saveBlob } from '@/utils/fileDownload'
import businessApi from '@/api/business'
import cashApi from '@/api/cash'

const router = useRouter()
const { has: hasPerm } = usePermission()
const can = (verb) => hasPerm(`cash.integral.use.${verb}`)

const STATUS = { DRAFT: 0, PENDING: 1, AUDITED: 2, VOID: 3 }
const STATUS_LABEL = { 0: '草稿', 1: '待审核', 2: '已审核', 3: '已作废' }
const STATUS_CLASS = { 0: 'pts-tag--draft', 1: 'pts-tag--pending', 2: 'pts-tag--audited', 3: 'pts-tag--void' }
const TYPE_LABEL = { consume: '消费核销', exchange: '礼品兑换', adjust: '调整', other: '其他' }

const statusClass = (status) => STATUS_CLASS[status] ?? 'pts-tag--draft'
const fmtInt = (v) => (Number.isFinite(Number(v)) ? Number(v).toLocaleString('zh-CN') : '—')
const isNil = (v) => v === null || v === undefined
const today = () => new Date().toLocaleDateString('sv-SE')

const dateRange = ref([])
const { data, total, loading, searchForm: search, paginationProps, search: doSearch, resetSearch, refresh, handlePageChange, handlePageSizeChange } = useTable({
	apiObj: { get: (params) => cashApi.integral.verify.list.get(params) },
	searchForm: { verify_no: '', member_id: '', status: '', date_start: '', date_end: '' },
	pageSize: 30,
	pageSizes: [10, 20, 30, 50],
})

// 会员远程搜索：筛选区与录入区共用一份候选（带已选项，避免回显只剩 ID）
const filterMembers = ref([])
const memberLoading = ref(false)
const searchMembers = async (kw) => {
	memberLoading.value = true
	try {
		const res = await businessApi.member.search.get({ keyword: kw || '', limit: 20 })
		const picked = filterMembers.value.filter((m) => search.member_id === m.id || form.member_id === m.id)
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
const emptyForm = { id: null, verify_no: '', verify_date: today(), member_id: null, points: 10, verify_type: 'consume', related_order_no: '', remark: '' }
const form = reactive({ ...emptyForm })

const summary = ref({})
// 未选会员时不封顶；选了则最多核销到可用积分（后端审核仍二次校验）
const pointsMax = computed(() => {
	const available = Number(summary.value.points_current)
	if (!Number.isFinite(available)) return 10000000
	return Math.max(available, Number(form.points) || 1)
})

const rules = {
	verify_date: [{ required: true, message: '请选择核销日期', trigger: 'change' }],
	member_id: [{ required: true, message: '请选择会员', trigger: 'change' }],
	points: [{ required: true, type: 'integer', min: 1, message: '核销积分必须为大于 0 的整数', trigger: 'change' }],
}

const loadSummary = async (memberId) => {
	summary.value = {}
	if (!memberId) return
	try {
		const res = await cashApi.integral.verify.memberSummary.get({ member_id: memberId })
		if (res.code === 200) summary.value = res.data || {}
	} catch {
		/* 取不到可用积分时按未知处理，交给后端审核兜底 */
	}
}

const openDialog = async (row) => {
	Object.assign(form, { ...emptyForm, verify_date: today() })
	summary.value = {}
	if (row) {
		let res
		try {
			res = await cashApi.integral.verify.detail.get(row.id)
		} catch {
			return
		}
		const v = res.data || {}
		Object.assign(form, {
			id: v.id,
			verify_no: v.verify_no || '',
			verify_date: v.verify_date || today(),
			member_id: v.member_id || null,
			points: Number(v.points) || 1,
			verify_type: v.verify_type || 'consume',
			related_order_no: v.related_order_no || '',
			remark: v.remark || '',
		})
		if (v.member_id) {
			// 详情里的会员先入候选，否则远程下拉框只会回显 ID
			const picked = { id: v.member_id, name: v.member_name, member_no: v.member_no }
			filterMembers.value = [...new Map([[v.member_id, picked], ...filterMembers.value.map((m) => [m.id, m])]).values()]
		}
		loadSummary(v.member_id)
	}
	dialogVisible.value = true
}

const save = async (mode) => {
	const valid = await formRef.value.validate().catch(() => false)
	if (!valid) return
	if (Number.isFinite(Number(summary.value.points_current)) && Number(form.points) > Number(summary.value.points_current)) {
		ElMessage.warning(`可用积分仅 ${fmtInt(summary.value.points_current)}，不足以核销 ${fmtInt(form.points)}`)
		return
	}
	const body = {
		verify_date: form.verify_date,
		member_id: form.member_id,
		points: Number(form.points),
		verify_type: form.verify_type,
		related_order_no: form.related_order_no || null,
		remark: form.remark || null,
	}
	saving.value = true
	try {
		const res = form.id ? await cashApi.integral.verify.edit.put(form.id, body) : await cashApi.integral.verify.add.post(body)
		if (res.code !== 200) return
		const id = res.data?.id ?? form.id
		if (mode === 'submit') {
			const sub = await cashApi.integral.verify.submit.post(id)
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

const handleSubmit = (row) => confirmThen(`确认提交核销单「${row.verify_no}」进入待审核吗？`, () => cashApi.integral.verify.submit.post(row.id))
const handleAudit = (row) =>
	confirmThen(`确认审核核销单「${row.verify_no}」吗？审核后会员「${row.member_name || ''}」的 ${fmtInt(row.points)} 积分将实时扣减。`, () => cashApi.integral.verify.audit.post(row.id))
const handleVoid = (row) => confirmThen(`确认作废核销单「${row.verify_no}」吗？作废后不可恢复。`, () => cashApi.integral.verify.void.post(row.id))

// ---- 详情 / 导出 ----
const detailVisible = ref(false)
const detail = ref({})
const openDetail = async (row) => {
	let res
	try {
		res = await cashApi.integral.verify.detail.get(row.id)
	} catch {
		return
	}
	detail.value = res.data || {}
	detailVisible.value = true
}

const goLedger = () => {
	if (!detail.value.member_id) return
	detailVisible.value = false
	router.push({ path: '/points/query', query: { member_id: detail.value.member_id } })
}

const handleExport = async () => {
	const blob = await cashApi.integral.verify.export({ ...search })
	saveBlob(blob, `积分核销_${Date.now()}.csv`)
}

searchMembers('')
</script>

<style>
@import '../_shared.css';
</style>

<style scoped>
.pts-use {
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
	color: var(--color-danger);
	font-weight: 600;
	font-family: var(--font-numeric);
	font-variant-numeric: tabular-nums;
}
.available {
	font-size: var(--font-h2);
	font-weight: 700;
	color: var(--color-success);
	font-family: var(--font-numeric);
	font-variant-numeric: tabular-nums;
}
</style>
