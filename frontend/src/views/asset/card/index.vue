<template>
	<div class="asset-page">
		<!-- 筛选卡 -->
		<div class="asset-head">
			<div class="asset-head-top">
				<div class="asset-title">固定资产台账</div>
				<div class="asset-head-actions">
					<el-segmented
						v-model="viewMode"
						:options="viewOptions"
						size="small"
						style="width: 176px"
					/>
					<el-button type="primary" @click="goPurchase">
						<el-icon><Plus /></el-icon><span>新增购置</span>
					</el-button>
				</div>
			</div>
			<div class="asset-filter">
				<span class="f-label">类别</span>
				<el-select v-model="fCategory" placeholder="全部" clearable style="width: 130px">
					<el-option v-for="c in ASSET_CATEGORIES" :key="c.value" :label="c.label" :value="c.value" />
				</el-select>
				<span class="f-label">使用部门</span>
				<el-select v-model="fDepartment" placeholder="全部" clearable style="width: 130px">
					<el-option v-for="d in DEPARTMENTS" :key="d.value" :label="d.label" :value="d.value" />
				</el-select>
				<span class="f-label">状态</span>
				<el-select v-model="fStatus" placeholder="全部" clearable style="width: 110px">
					<el-option v-for="(v, k) in ASSET_STATUS" :key="k" :label="v.label" :value="k" />
				</el-select>
				<span class="f-label">关键字</span>
				<el-input
					v-model="fKw"
					placeholder="编号 / 名称 / 经手人"
					clearable
					style="width: 200px"
					@keyup.enter="applyFilter"
				/>
				<el-button type="primary" @click="applyFilter">查询</el-button>
				<el-button @click="onReset">重置</el-button>
			</div>
		</div>

		<!-- 统计条 -->
		<div class="asset-stats">
			<span class="st-item">资产 <b class="tabular">{{ filtered.length }}</b> 项</span>
			<span class="st-div"></span>
			<span class="st-item">原值 <b class="tabular">{{ fmtMoney(sumOriginal) }}</b> 元</span>
			<span class="st-div"></span>
			<span class="st-item">累计折旧 <b class="tabular">{{ fmtMoney(sumDep) }}</b> 元</span>
			<span class="st-div"></span>
			<span class="st-item">净值合计 <b class="tabular strong">{{ fmtMoney(sumNet) }}</b> 元</span>
			<span class="st-div"></span>
			<span class="st-item">在用 <b class="tabular">{{ countInUse }}</b> 项</span>
		</div>

		<!-- 卡片视图 -->
		<div v-show="viewMode === 'card'" class="asset-body">
			<div v-if="filtered.length" class="asset-grid">
				<div v-for="a in filtered" :key="a.id" class="asset-card" @click="openDetail(a)">
					<div class="ac-head">
						<span class="ac-name">{{ a.name }}</span>
						<el-tag :type="ASSET_STATUS[a.status]?.tag || 'info'" size="small" effect="light">
							{{ ASSET_STATUS[a.status]?.label || a.status }}
						</el-tag>
					</div>
					<div class="ac-code tabular">{{ a.code }}</div>
					<div class="ac-rows">
						<div class="ac-row"><span class="ac-k">类别</span><span class="ac-v">{{ CAT_LABEL[a.category] || '—' }}</span></div>
						<div class="ac-row"><span class="ac-k">部门</span><span class="ac-v">{{ DEPT_LABEL[a.department] || '—' }}</span></div>
						<div class="ac-row"><span class="ac-k">购入</span><span class="ac-v tabular">{{ fmtDay(a.buy_date) }}</span></div>
					</div>
					<div class="ac-foot">
						<span class="ac-net-label">净值</span>
						<span class="ac-net tabular">{{ fmtMoney(a.net) }}</span>
					</div>
					<div class="ac-progress">
						<div class="ac-bar" :style="{ width: depPercent(a) + '%' }"></div>
					</div>
					<div class="ac-dep tabular">累计折旧 {{ depPercent(a) }}%</div>
				</div>
			</div>
			<el-empty v-else description="暂无数据" />
		</div>

		<!-- 列表视图 -->
		<div v-show="viewMode === 'list'" class="asset-body">
			<el-table
				:data="filtered"
				border
				highlight-current-row
				height="100%"
				show-summary
				:summary-method="summaryMethod"
				empty-text="暂无数据"
				row-key="id"
			>
				<el-table-column prop="code" label="资产编号" width="140" align="left">
					<template #default="{ row }"><span class="tabular">{{ row.code }}</span></template>
				</el-table-column>
				<el-table-column prop="name" label="资产名称" width="180" align="left" show-overflow-tooltip />
				<el-table-column prop="category" label="类别" width="110" align="left">
					<template #default="{ row }">{{ CAT_LABEL[row.category] || '—' }}</template>
				</el-table-column>
				<el-table-column prop="buy_date" label="购入日期" width="120" align="center">
					<template #default="{ row }"><span class="tabular">{{ fmtDay(row.buy_date) }}</span></template>
				</el-table-column>
				<el-table-column prop="original" label="原值" width="130" align="right" header-align="right">
					<template #default="{ row }"><span class="tabular">{{ fmtMoney(row.original) }}</span></template>
				</el-table-column>
				<el-table-column prop="depreciated" label="累计折旧" width="130" align="right" header-align="right">
					<template #default="{ row }"><span class="tabular">{{ fmtMoney(row.depreciated) }}</span></template>
				</el-table-column>
				<el-table-column prop="net" label="净值" width="130" align="right" header-align="right">
					<template #default="{ row }"><span class="tabular net-strong">{{ fmtMoney(row.net) }}</span></template>
				</el-table-column>
				<el-table-column prop="department" label="使用部门" width="120" align="left">
					<template #default="{ row }">{{ DEPT_LABEL[row.department] || '—' }}</template>
				</el-table-column>
				<el-table-column prop="status" label="状态" width="90" align="center">
					<template #default="{ row }">
						<el-tag :type="ASSET_STATUS[row.status]?.tag || 'info'" size="small" effect="light">
							{{ ASSET_STATUS[row.status]?.label || row.status }}
						</el-tag>
					</template>
				</el-table-column>
				<el-table-column label="操作" width="130" align="right" header-align="right" fixed="right">
					<template #default="{ row }">
						<el-button type="primary" link @click="openDetail(row)">详情</el-button>
						<el-button type="primary" link @click="openDep(row)">折旧记录</el-button>
					</template>
				</el-table-column>
			</el-table>
		</div>

		<!-- 详情抽屉 -->
		<el-drawer v-model="detailVisible" size="640px" :with-header="false">
			<div v-if="detail" class="drawer-body">
				<div class="drawer-head">
					<div>
						<div class="drawer-title">{{ detail.name }}</div>
						<div class="drawer-sub tabular">{{ detail.code }}</div>
					</div>
					<el-tag :type="ASSET_STATUS[detail.status]?.tag || 'info'" effect="light">
						{{ ASSET_STATUS[detail.status]?.label || detail.status }}
					</el-tag>
				</div>

				<div class="amt-grid">
					<div class="amt-cell">
						<div class="amt-k">原值</div>
						<div class="amt-v tabular">{{ fmtMoney(detail.original) }}</div>
					</div>
					<div class="amt-cell">
						<div class="amt-k">累计折旧</div>
						<div class="amt-v tabular">{{ fmtMoney(detail.depreciated) }}</div>
					</div>
					<div class="amt-cell">
						<div class="amt-k">净值</div>
						<div class="amt-v tabular strong">{{ fmtMoney(detail.net) }}</div>
					</div>
				</div>

				<el-descriptions :column="2" border size="small">
					<el-descriptions-item label="类别">{{ CAT_LABEL[detail.category] || '—' }}</el-descriptions-item>
					<el-descriptions-item label="使用部门">{{ DEPT_LABEL[detail.department] || '—' }}</el-descriptions-item>
					<el-descriptions-item label="购入日期">{{ fmtDay(detail.buy_date) }}</el-descriptions-item>
					<el-descriptions-item label="经手人">{{ EMP_LABEL[detail.handler] || '—' }}</el-descriptions-item>
					<el-descriptions-item label="预计使用月数">{{ detail.months || '—' }}</el-descriptions-item>
					<el-descriptions-item label="残值率">{{ (detail.salvage_rate || 0) + '%' }}</el-descriptions-item>
					<el-descriptions-item label="折旧方式">{{ DEP_LABEL[detail.dep_method] || '—' }}</el-descriptions-item>
				</el-descriptions>

				<div class="drawer-sec">折旧记录</div>
				<el-table :data="depRecords" border size="small" empty-text="暂无折旧记录" height="220">
					<el-table-column prop="id" label="折旧单号" width="150">
						<template #default="{ row }"><span class="tabular">{{ row.id }}</span></template>
					</el-table-column>
					<el-table-column prop="month" label="折旧期间" width="110" align="center">
						<template #default="{ row }"><span class="tabular">{{ fmtMonth(row.month) }}</span></template>
					</el-table-column>
					<el-table-column prop="amount" label="折旧额" width="130" align="right" header-align="right">
						<template #default="{ row }"><span class="tabular">{{ fmtMoney(row.amount) }}</span></template>
					</el-table-column>
				</el-table>
			</div>
		</el-drawer>
	</div>
</template>

<script setup>
import { computed, ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Plus } from '@element-plus/icons-vue'
import {
	ASSET_CATEGORIES,
	ASSET_STATUS,
	DEPARTMENTS,
	CAT_LABEL,
	DEPT_LABEL,
	EMP_LABEL,
	DEP_METHODS,
	fmtMoney,
	fmtDay,
	fmtMonth,
} from '../shared'
import { listCards, listDepRuns } from '../store'

const DEP_LABEL = Object.fromEntries(DEP_METHODS.map((m) => [m.value, m.label]))
const route = useRoute()
const router = useRouter()

/* ---------- 筛选 ---------- */
const fCategory = ref('')
const fDepartment = ref('')
const fStatus = ref('')
const fKw = ref('')

const filtered = computed(() => {
	const kw = fKw.value.trim().toLowerCase()
	return listCards().filter((a) => {
		if (fCategory.value && a.category !== fCategory.value) return false
		if (fDepartment.value && a.department !== fDepartment.value) return false
		if (fStatus.value && a.status !== fStatus.value) return false
		if (kw) {
			const hay = `${a.code} ${a.name} ${EMP_LABEL[a.handler] || ''}`.toLowerCase()
			if (!hay.includes(kw)) return false
		}
		return true
	})
})

const sumOriginal = computed(() => filtered.value.reduce((s, a) => s + Number(a.original || 0), 0))
const sumDep = computed(() => filtered.value.reduce((s, a) => s + Number(a.depreciated || 0), 0))
const sumNet = computed(() => filtered.value.reduce((s, a) => s + Number(a.net || 0), 0))
const countInUse = computed(() => filtered.value.filter((a) => a.status === 'inuse').length)

const applyFilter = () => {}
const onReset = () => {
	fCategory.value = ''
	fDepartment.value = ''
	fStatus.value = ''
	fKw.value = ''
}

/* ---------- 视图切换 ---------- */
const viewMode = ref('card')
const viewOptions = [
	{ label: '卡片视图', value: 'card' },
	{ label: '列表视图', value: 'list' },
]

/* ---------- 表格合计行 ---------- */
const summaryMethod = ({ columns }) =>
	columns.map((col, i) => {
		if (i === 0) return '合计'
		if (col.prop === 'original') return fmtMoney(sumOriginal.value)
		if (col.prop === 'depreciated') return fmtMoney(sumDep.value)
		if (col.prop === 'net') return fmtMoney(sumNet.value)
		return ''
	})

/* ---------- 折旧进度 ---------- */
const depPercent = (a) => {
	const o = Number(a.original || 0)
	return o > 0 ? Math.min(100, Math.round((Number(a.depreciated || 0) / o) * 100)) : 0
}

/* ---------- 详情抽屉 ---------- */
const detailVisible = ref(false)
const detail = ref(null)

const depRecords = computed(() => {
	if (!detail.value) return []
	const rows = []
	for (const run of listDepRuns()) {
		const it = (run.items || []).find((x) => x.id === detail.value.id)
		if (it && Number(it.month_amount) > 0) {
			rows.push({ id: run.id, month: run.month, amount: it.month_amount })
		}
	}
	return rows
})

const openDetail = (row) => {
	detail.value = row
	detailVisible.value = true
}
const openDep = (row) => openDetail(row)

const goPurchase = () => router.push('/asset/purchase')

/* 购置单「卡片」按钮深链：?focus=<资产 id> 自动展开详情 */
onMounted(() => {
	const focus = route.query.focus
	if (focus) {
		const target = listCards().find((a) => a.id === focus)
		if (target) {
			openDetail(target)
			router.replace({ query: { ...route.query, focus: '' } })
		}
	}
})
</script>

<style scoped>
.asset-page {
	height: 100%;
	display: flex;
	flex-direction: column;
	overflow: hidden;
	background: var(--bg-body);
	gap: var(--space-md);
	padding: var(--space-md);
	font-family: var(--font-family);
}

/* ---- 筛选卡 ---- */
.asset-head {
	background: var(--bg-card);
	border-radius: var(--radius-lg);
	box-shadow: var(--shadow-card);
}
.asset-head-top {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: var(--space-lg) var(--space-xl) 0;
}
.asset-title {
	font-size: var(--font-h1);
	font-weight: 600;
	color: var(--text-primary);
}
.asset-head-actions {
	display: flex;
	align-items: center;
	gap: var(--space-md);
}
.asset-head-actions .el-button {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	height: 32px;
	padding: 0 var(--space-lg);
	border-radius: var(--radius-md);
	font-size: var(--font-base);
}
.asset-filter {
	display: flex;
	align-items: center;
	gap: var(--space-md);
	flex-wrap: wrap;
	padding: var(--space-lg) var(--space-xl);
}
.f-label {
	font-size: var(--font-base);
	color: var(--text-regular);
}

/* ---- 统计条 ---- */
.asset-stats {
	background: var(--bg-card);
	border-radius: var(--radius-lg);
	box-shadow: var(--shadow-card);
	display: flex;
	align-items: center;
	gap: var(--space-lg);
	padding: 10px var(--space-xl);
	font-size: var(--font-base);
	color: var(--text-regular);
	flex-wrap: wrap;
}
.st-item b {
	color: var(--text-primary);
	font-weight: 600;
}
.st-item b.strong {
	color: var(--color-primary);
}
.st-div {
	width: 1px;
	height: 16px;
	background: var(--border-lighter);
}

/* ---- 主体 ---- */
.asset-body {
	flex: 1;
	min-height: 0;
	display: flex;
}

/* ---- 卡片网格 ---- */
.asset-grid {
	flex: 1;
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(268px, 1fr));
	gap: var(--space-lg);
	align-content: start;
	overflow: auto;
	padding: var(--space-md);
}
.asset-card {
	background: var(--bg-card);
	border-radius: var(--radius-lg);
	box-shadow: var(--shadow-card);
	padding: var(--space-lg) var(--space-xl);
	display: flex;
	flex-direction: column;
	gap: 6px;
	cursor: pointer;
	transition: box-shadow 0.2s, transform 0.2s, border-color 0.2s;
	border: 1px solid transparent;
}
.asset-card:hover {
	box-shadow: 0 4px 16px rgba(0, 21, 41, 0.1);
	border-color: var(--color-primary-light);
	transform: translateY(-2px);
}
.ac-head {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: var(--space-sm);
}
.ac-name {
	font-size: var(--font-h3);
	font-weight: 600;
	color: var(--text-primary);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
.ac-code {
	font-size: var(--font-aux);
	color: var(--text-secondary);
	letter-spacing: 0.2px;
}
.ac-rows {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 4px var(--space-md);
	margin: 4px 0;
}
.ac-row {
	display: flex;
	gap: var(--space-sm);
	font-size: var(--font-base);
}
.ac-k {
	color: var(--text-secondary);
	flex-shrink: 0;
}
.ac-v {
	color: var(--text-regular);
}
.ac-foot {
	display: flex;
	align-items: baseline;
	justify-content: space-between;
	margin-top: var(--space-sm);
	padding-top: var(--space-sm);
	border-top: 1px solid var(--border-lighter);
}
.ac-net-label {
	font-size: var(--font-aux);
	color: var(--text-secondary);
}
.ac-net {
	font-size: var(--font-h3);
	font-weight: 600;
	color: var(--color-primary);
}
.ac-progress {
	height: 6px;
	background: var(--bg-hover);
	border-radius: 3px;
	overflow: hidden;
	margin-top: 4px;
}
.ac-bar {
	height: 100%;
	background: var(--color-primary);
	border-radius: 3px;
	transition: width 0.3s;
}
.ac-dep {
	font-size: var(--font-aux);
	color: var(--text-secondary);
}

/* ---- 列表视图 ---- */
.asset-body > .el-table {
	width: 100%;
	background: var(--bg-card);
	border-radius: var(--radius-lg);
	overflow: hidden;
}
:deep(.el-table th.el-table__cell) {
	background: var(--bg-hover) !important;
	height: 44px;
	padding-top: 0;
	padding-bottom: 0;
}
:deep(.el-table th.el-table__cell .cell) {
	color: var(--text-regular);
	font-size: var(--font-base);
	font-weight: 600;
	line-height: 20px;
	padding-top: 0;
	padding-bottom: 0;
}
:deep(.el-table td.el-table__cell) {
	height: 40px;
	padding-top: 0;
	padding-bottom: 0;
}
:deep(.el-table td.el-table__cell .cell) {
	color: var(--text-primary);
	font-size: var(--font-base);
	line-height: 20px;
	padding-top: 0;
	padding-bottom: 0;
}
:deep(.el-table .el-table__body tr:hover > td.el-table__cell) {
	background: var(--bg-hover) !important;
}
:deep(.el-table .el-table__fixed-right::after),
:deep(.el-table .el-table__fixed-right-patch::after) {
	background-color: var(--bg-hover);
}
.net-strong {
	font-weight: 600;
}
:deep(.el-table__footer td.el-table__cell .cell) {
	font-weight: 600;
	color: var(--text-primary);
}

/* ---- 详情抽屉 ---- */
.drawer-body {
	padding: var(--space-xl);
	display: flex;
	flex-direction: column;
	gap: var(--space-lg);
}
.drawer-head {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	gap: var(--space-md);
}
.drawer-title {
	font-size: var(--font-h2);
	font-weight: 600;
	color: var(--text-primary);
}
.drawer-sub {
	font-size: var(--font-aux);
	color: var(--text-secondary);
	margin-top: 4px;
}
.amt-grid {
	display: grid;
	grid-template-columns: repeat(3, 1fr);
	gap: var(--space-md);
}
.amt-cell {
	background: var(--bg-hover);
	border-radius: var(--radius-md);
	padding: var(--space-md) var(--space-lg);
}
.amt-k {
	font-size: var(--font-aux);
	color: var(--text-secondary);
}
.amt-v {
	font-size: var(--font-h3);
	font-weight: 600;
	color: var(--text-primary);
	margin-top: 4px;
}
.amt-v.strong {
	color: var(--color-primary);
}
.drawer-sec {
	font-size: var(--font-h3);
	font-weight: 600;
	color: var(--text-primary);
	padding-left: 8px;
	border-left: 3px solid var(--color-primary);
	line-height: 1.2;
}
</style>
