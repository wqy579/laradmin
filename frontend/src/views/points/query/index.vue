<template>
	<div class="pts-page pts-query">
		<!-- 筛选：会员 + 统计期间 -->
		<div class="pts-filter">
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
				style="width: 220px"
				@change="doSearch"
				@clear="onClearMember"
			>
				<el-option v-for="m in filterMembers" :key="m.id" :label="`${m.name}（${m.member_no || '—'}）`" :value="m.id" />
			</el-select>
			<span class="label">统计期间</span>
			<el-date-picker
				v-model="dateRange"
				type="daterange"
				value-format="YYYY-MM-DD"
				range-separator="-"
				start-placeholder="开始日期"
				end-placeholder="结束日期"
				size="small"
				style="width: 260px"
				@change="onDateChange"
			/>
			<el-button type="primary" size="small" @click="doSearch">查询</el-button>
			<el-button size="small" @click="onReset">重置</el-button>
			<div style="flex: 1" />
			<el-button v-if="can('export')" size="small" :loading="exporting" @click="handleExport">导出</el-button>
		</div>

		<!-- 汇总：会员 / 期初 / 发放 / 核销 / 期末 / 实时余额 -->
		<div class="pts-table">
			<el-table
				v-loading="loading"
				:data="data"
				row-key="member_id"
				stripe
				border
				height="100%"
				show-summary
				:summary-method="summaries"
				class="fin-table--dense"
				@row-dblclick="openFlows"
			>
				<el-table-column prop="member_no" label="会员编号" width="150" align="center">
					<template #default="{ row }"><span class="tabular">{{ row.member_no || '—' }}</span></template>
				</el-table-column>
				<el-table-column prop="member_name" label="会员名称" min-width="180" show-overflow-tooltip>
					<template #default="{ row }">
						<span class="link" @click="openFlows(row)">{{ row.member_name || '—' }}</span>
					</template>
				</el-table-column>
				<el-table-column prop="points_open" label="期初积分" width="120" align="right" header-align="right">
					<template #default="{ row }"><span class="tabular">{{ fmtInt(row.points_open) }}</span></template>
				</el-table-column>
				<el-table-column prop="points_grant" label="本期发放" width="120" align="right" header-align="right">
					<template #default="{ row }">
						<span class="tabular pts-num--up">{{ signed(row.points_grant) }}</span>
					</template>
				</el-table-column>
				<el-table-column prop="points_verify" label="本期核销" width="120" align="right" header-align="right">
					<template #default="{ row }">
						<span class="tabular pts-num--down">{{ row.points_verify ? `-${fmtInt(row.points_verify)}` : '0' }}</span>
					</template>
				</el-table-column>
				<el-table-column prop="points_close" label="期末积分" width="120" align="right" header-align="right">
					<template #default="{ row }"><span class="tabular pts-num--total">{{ fmtInt(row.points_close) }}</span></template>
				</el-table-column>
				<el-table-column prop="points_current" label="实时余额" width="120" align="right" header-align="right">
					<template #default="{ row }"><span class="tabular">{{ fmtInt(row.points_current) }}</span></template>
				</el-table-column>
				<el-table-column label="操作" width="80" align="center" fixed="right">
					<template #default="{ row }">
						<el-button link type="primary" size="small" @click.stop="openFlows(row)">流水</el-button>
					</template>
				</el-table-column>
				<template #empty><FinEmpty text="当前筛选条件下暂无数据" /></template>
			</el-table>
			<div class="pts-hint">提示：期初 + 本期发放 − 本期核销 = 期末，双击行或点击会员名可查看该会员的积分流水</div>
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

		<!-- 积分流水 -->
		<el-drawer v-model="flowsVisible" :title="flowsTitle" size="720px" destroy-on-close @open="loadFlows">
			<div class="drawer-summary">
				<span>会员：<b>{{ current.member_name || '—' }}</b></span>
				<span>编号：<b class="tabular">{{ current.member_no || '—' }}</b></span>
				<span>期初 <b class="tabular">{{ fmtInt(current.points_open) }}</b></span>
				<span>发放 <b class="tabular pts-num--up">{{ signed(current.points_grant) }}</b></span>
				<span>核销 <b class="tabular pts-num--down">{{ current.points_verify ? `-${fmtInt(current.points_verify)}` : '0' }}</b></span>
				<span>期末 <b class="tabular">{{ fmtInt(current.points_close) }}</b></span>
			</div>
			<el-table v-loading="flowsLoading" :data="flows" border size="small" height="calc(100% - 60px)" class="fin-table--dense">
				<el-table-column prop="date" label="发生时间" width="150" align="center">
					<template #default="{ row }"><span class="tabular">{{ row.date || '—' }}</span></template>
				</el-table-column>
				<el-table-column prop="consume_type_label" label="类型" width="100" align="center">
					<template #default="{ row }">{{ row.consume_type_label || row.consume_type || '—' }}</template>
				</el-table-column>
				<el-table-column label="积分变动" width="110" align="right" header-align="right">
					<template #default="{ row }">
						<span class="tabular" :class="Number(row.points) >= 0 ? 'pts-num--up' : 'pts-num--down'">{{ signed(row.points) }}</span>
					</template>
				</el-table-column>
				<el-table-column prop="points_after" label="变动后余额" width="110" align="right" header-align="right">
					<template #default="{ row }"><span class="tabular">{{ fmtInt(row.points_after) }}</span></template>
				</el-table-column>
				<el-table-column prop="related_order_no" label="来源单号" min-width="140" show-overflow-tooltip>
					<template #default="{ row }"><span class="tabular">{{ row.related_order_no || '—' }}</span></template>
				</el-table-column>
				<el-table-column prop="remark" label="备注" min-width="140" show-overflow-tooltip />
				<template #empty><FinEmpty text="该会员在统计期间内暂无积分流水" /></template>
			</el-table>
			<div class="pts-pager fin-pager">
				<el-pagination
					background
					small
					layout="total, prev, pager, next"
					:total="flowsTotal"
					:page-size="flowsPageSize"
					:current-page="flowsPage"
					@current-change="onFlowsPageChange"
				/>
			</div>
		</el-drawer>
	</div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { saveBlob } from '@/utils/fileDownload'
import { useTable } from '@/hooks/useTable'
import { usePermission } from '@/composables/usePermission'
import businessApi from '@/api/business'
import cashApi from '@/api/cash'
import FinEmpty from '../../finance/_FinEmpty.vue'

const route = useRoute()
const { has: hasPerm } = usePermission()
const can = (verb) => hasPerm(`cash.integral.query.${verb}`)

const fmtInt = (v) => (Number.isFinite(Number(v)) ? Number(v).toLocaleString('zh-CN') : '—')
const signed = (v) => {
	const n = Number(v) || 0
	return n > 0 ? `+${fmtInt(n)}` : fmtInt(n)
}

const dateRange = ref([])
const { data, total, loading, searchForm: search, paginationProps, search: doSearch, resetSearch, handlePageChange, handlePageSizeChange } = useTable({
	apiObj: { get: (params) => cashApi.integral.ledger.members.get(params) },
	searchForm: { member_id: '', start_date: '', end_date: '' },
	pageSize: 30,
	pageSizes: [10, 20, 30, 50],
})

// 会员远程搜索：保留已选项，避免回显只剩 ID
const filterMembers = ref([])
const memberLoading = ref(false)
const searchMembers = async (kw) => {
	memberLoading.value = true
	try {
		const res = await businessApi.member.search.get({ keyword: kw || '', limit: 20 })
		const picked = filterMembers.value.filter((m) => Number(search.member_id) === Number(m.id))
		filterMembers.value = [...new Map([...picked, ...(res.data || [])].map((m) => [m.id, m])).values()]
	} finally {
		memberLoading.value = false
	}
}

const onDateChange = (val) => {
	search.start_date = val?.[0] || ''
	search.end_date = val?.[1] || ''
	doSearch()
}
const onClearMember = () => {
	current.value = {}
	doSearch()
}
const onReset = () => {
	dateRange.value = []
	current.value = {}
	resetSearch()
}

// 合计行：只对当前页的积分数值列求和
const SUM_COLS = ['points_open', 'points_grant', 'points_verify', 'points_close', 'points_current']
const summaries = ({ columns }) =>
	columns.map((col, index) => {
		if (index === 0) return '合计'
		if (!SUM_COLS.includes(col.property)) return ''
		return fmtInt(data.value.reduce((sum, row) => sum + (Number(row[col.property]) || 0), 0))
	})

// ---- 流水 ----
const flowsVisible = ref(false)
const flowsLoading = ref(false)
const flows = ref([])
const flowsTotal = ref(0)
const flowsPage = ref(1)
const flowsPageSize = ref(20)
const current = ref({})

const flowsTitle = computed(() => `积分流水 ${current.value.member_name || ''}`)

const loadFlows = async () => {
	const memberId = Number(current.value.member_id)
	if (!memberId) return
	flowsLoading.value = true
	try {
		const res = await cashApi.integral.ledger.flows.get({
			member_id: memberId,
			start_date: search.start_date || '',
			end_date: search.end_date || '',
			page: flowsPage.value,
			page_size: flowsPageSize.value,
		})
		const d = res.data || {}
		flows.value = d.list || []
		flowsTotal.value = d.total || 0
	} catch {
		flows.value = []
		flowsTotal.value = 0
	} finally {
		flowsLoading.value = false
	}
}

const openFlows = (row) => {
	current.value = { ...row }
	flowsPage.value = 1
	flows.value = []
	flowsTotal.value = 0
	flowsVisible.value = true
}

const onFlowsPageChange = (page) => {
	flowsPage.value = page
	loadFlows()
}

const exporting = ref(false)
const handleExport = async () => {
	exporting.value = true
	try {
		const blob = await cashApi.integral.ledger.export({ ...search })
		saveBlob(blob, `积分查询_${Date.now()}.csv`)
	} finally {
		exporting.value = false
	}
}

// 核销页「查看该会员积分流水」深链：/points/query?member_id=x
const applyDeepLink = async (memberId) => {
	const id = Number(memberId)
	if (!id) return
	search.member_id = id
	dateRange.value = []
	search.start_date = ''
	search.end_date = ''
	doSearch()
	// 深链带进来的会员不在远程候选里，按同一区间反查一次汇总，补齐下拉回显与抽屉标题
	let row = {}
	try {
		const res = await cashApi.integral.ledger.members.get({ member_id: id, page: 1, page_size: 1 })
		row = (res.data?.list || [])[0] || {}
	} catch {
		/* 查不到汇总时抽屉只展示流水 */
	}
	current.value = { member_id: id, ...row }
	if (current.value.member_name) {
		filterMembers.value = [...new Map([[id, { id, name: current.value.member_name, member_no: current.value.member_no }], ...filterMembers.value.map((m) => [m.id, m])]).values()]
	}
	flowsPage.value = 1
	flowsVisible.value = true // 打开抽屉由 @open 触发 loadFlows
}

onMounted(() => {
	searchMembers('')
	if (route.query.member_id) applyDeepLink(route.query.member_id)
})

watch(
	() => route.query.member_id,
	(v) => {
		if (v) applyDeepLink(v)
	}
)
</script>

<style>
@import '../../finance/_shared.css';
@import '../_shared.css';
</style>

<style scoped>
.pts-query {
	height: 100%;
	display: flex;
	flex-direction: column;
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
.pts-table :deep(.el-table__footer) {
	background: var(--bg-hover);
}
.pts-table :deep(.el-table__footer td.el-table__cell) {
	color: var(--text-primary);
	font-weight: 600;
	font-size: var(--font-base);
}
.pts-hint {
	margin-top: 8px;
	font-size: var(--font-aux);
	color: var(--text-secondary);
}
.link {
	color: var(--color-primary);
	cursor: pointer;
}
/* 积分变动：正数绿、负数红（与发放/核销方向一致） */
.pts-num--up {
	color: var(--color-success);
	font-weight: 600;
}
.pts-num--down {
	color: var(--color-danger);
	font-weight: 600;
}
.pts-num--total {
	color: var(--text-primary);
	font-weight: 600;
}
.pts-pager {
	display: flex;
	justify-content: flex-end;
	padding: 12px 16px 0;
}
.drawer-summary {
	display: flex;
	gap: 20px;
	flex-wrap: wrap;
	padding: 0 4px 12px;
	margin-bottom: 12px;
	border-bottom: 1px solid var(--border-lighter);
	font-size: var(--font-base);
	color: var(--text-regular);
}
.drawer-summary b {
	color: var(--text-primary);
	font-family: var(--font-numeric);
	font-variant-numeric: tabular-nums;
}
/* D3 简报口径：表头 44px 13px/600，明细行 41px（覆盖 _shared.css 的 14px/500 + 48px） */
.pts-query :deep(.el-table.fin-table--dense th.el-table__cell .cell) {
	font-size: var(--font-base);
	font-weight: 600;
}
.pts-query :deep(.el-table.fin-table--dense .el-table__body .el-table__row) {
	height: 41px;
}
.pts-query :deep(.el-table .el-table__body td.el-table__cell .cell) {
	font-size: var(--font-base);
	color: var(--text-primary);
}
</style>
