<template>
	<div class="fin-page asset-page">
		<!-- 筛选卡 -->
		<div class="asset-head">
			<div class="asset-head-top">
				<div class="asset-title">固定资产台账</div>
				<div class="asset-head-actions">
					<div class="view-switch">
						<button
							type="button"
							class="view-btn"
							:class="{ active: viewMode === 'card' }"
							title="卡片视图"
							@click="viewMode = 'card'"
						>
							<el-icon :size="18"><Grid /></el-icon>
						</button>
						<button
							type="button"
							class="view-btn"
							:class="{ active: viewMode === 'list' }"
							title="列表视图"
							@click="viewMode = 'list'"
						>
							<el-icon :size="18"><List /></el-icon>
						</button>
					</div>
					<el-button type="primary" @click="goPurchase">
						<el-icon><Plus /></el-icon><span>新增购置</span>
					</el-button>
				</div>
			</div>
			<div class="asset-filter">
				<span class="f-label">取得日期</span>
				<el-date-picker
					v-model="dateRange"
					type="daterange"
					value-format="YYYY-MM-DD"
					range-separator="至"
					start-placeholder="开始"
					end-placeholder="结束"
					style="width: 230px"
				/>
				<span class="f-label">使用部门</span>
				<el-select v-model="filters.department_id" placeholder="全部" clearable style="width: 130px">
					<el-option v-for="d in departments" :key="d.id" :label="d.name" :value="d.id" />
				</el-select>
				<span class="f-label">状态</span>
				<el-select v-model="filters.status" placeholder="全部" clearable style="width: 110px">
					<el-option v-for="s in statuses" :key="s.value" :label="s.label" :value="s.value" />
				</el-select>
				<span class="f-label">关键字</span>
				<el-input
					v-model="filters.keyword"
					placeholder="资产编号 / 名称"
					clearable
					style="width: 200px"
					@keyup.enter="onSearch"
				/>
				<el-button type="primary" :loading="loading" @click="onSearch">查询</el-button>
				<el-button @click="onReset">重置</el-button>
			</div>
		</div>

		<!-- 统计条（后端同口径汇总，与视图无关） -->
		<div class="asset-stats">
			<span class="st-item">资产 <b class="tabular">{{ summary.count }}</b> 项</span>
			<span class="st-div"></span>
			<span class="st-item">原值 <b class="tabular">{{ fmtMoney(summary.original_value) }}</b> 元</span>
			<span class="st-div"></span>
			<span class="st-item">累计折旧 <b class="tabular">{{ fmtMoney(summary.accumulated_dep) }}</b> 元</span>
			<span class="st-div"></span>
			<span class="st-item">净值合计 <b class="tabular strong">{{ fmtMoney(summary.net_value) }}</b> 元</span>
		</div>

		<!-- 双视图共用 rows/total/summary，仅渲染层不同 -->
		<div v-show="viewMode === 'card'" v-loading="loading" class="asset-body asset-body--card">
			<div v-if="rows.length" class="asset-grid">
				<div v-for="a in rows" :key="a.id" class="asset-card" @click="openDetail(a)">
					<div class="ac-head">
						<span class="ac-name">{{ a.asset_name }}</span>
						<el-tag :type="STATUS_TAG[a.status] || 'info'" size="small" effect="light">
							{{ a.status_label }}
						</el-tag>
					</div>
					<div class="ac-code tabular">{{ a.asset_no }}</div>
					<div class="ac-rows">
						<div class="ac-row"><span class="ac-k">部门</span><span class="ac-v">{{ a.department_name || '—' }}</span></div>
						<div class="ac-row"><span class="ac-k">使用人</span><span class="ac-v">{{ a.user_name || '—' }}</span></div>
						<div class="ac-row"><span class="ac-k">取得</span><span class="ac-v tabular">{{ fmtDay(a.acquire_date) }}</span></div>
						<div class="ac-row"><span class="ac-k">原值</span><span class="ac-v tabular">{{ fmtMoney(a.original_value) }}</span></div>
					</div>
					<div class="ac-foot">
						<span class="ac-net-label">净值</span>
						<span class="ac-net tabular">{{ fmtMoney(a.net_value) }}</span>
					</div>
					<div class="ac-progress">
						<div class="ac-bar" :style="{ width: depPercent(a) + '%' }"></div>
					</div>
					<div class="ac-dep tabular">累计折旧 {{ depPercent(a) }}%</div>
				</div>
			</div>
			<FinEmpty v-else-if="!loading" text="暂无资产卡片" />
		</div>

		<div v-show="viewMode === 'list'" class="asset-body">
			<el-table
				v-loading="loading"
				:data="rows"
				border
				highlight-current-row
				height="100%"
				class="fin-table--dense"
				row-key="id"
			>
				<template #empty><FinEmpty text="暂无资产卡片" /></template>
				<el-table-column prop="asset_no" label="资产编号" width="140">
					<template #default="{ row }"><span class="tabular">{{ row.asset_no }}</span></template>
				</el-table-column>
				<el-table-column prop="asset_name" label="资产名称" min-width="150" show-overflow-tooltip />
				<el-table-column prop="spec" label="规格" width="110" show-overflow-tooltip>
					<template #default="{ row }">{{ row.spec || '—' }}</template>
				</el-table-column>
				<el-table-column prop="acquire_date" label="取得日期" width="110" align="center">
					<template #default="{ row }"><span class="tabular">{{ fmtDay(row.acquire_date) }}</span></template>
				</el-table-column>
				<el-table-column prop="original_value" label="原值" width="120" align="right" header-align="right">
					<template #default="{ row }"><span class="tabular">{{ fmtMoney(row.original_value) }}</span></template>
				</el-table-column>
				<el-table-column prop="accumulated_dep" label="累计折旧" width="120" align="right" header-align="right">
					<template #default="{ row }"><span class="tabular">{{ fmtMoney(row.accumulated_dep) }}</span></template>
				</el-table-column>
				<el-table-column prop="net_value" label="净值" width="120" align="right" header-align="right">
					<template #default="{ row }"><span class="tabular net-strong">{{ fmtMoney(row.net_value) }}</span></template>
				</el-table-column>
				<el-table-column prop="department_name" label="使用部门" width="110" show-overflow-tooltip>
					<template #default="{ row }">{{ row.department_name || '—' }}</template>
				</el-table-column>
				<el-table-column prop="user_name" label="使用人" width="90" show-overflow-tooltip>
					<template #default="{ row }">{{ row.user_name || '—' }}</template>
				</el-table-column>
				<el-table-column prop="status" label="状态" width="90" align="center">
					<template #default="{ row }">
						<el-tag :type="STATUS_TAG[row.status] || 'info'" size="small" effect="light">
							{{ row.status_label }}
						</el-tag>
					</template>
				</el-table-column>
				<el-table-column label="操作" width="80" align="right" header-align="right" fixed="right">
					<template #default="{ row }">
						<el-button type="primary" link @click="openDetail(row)">详情</el-button>
					</template>
				</el-table-column>
			</el-table>
		</div>

		<div class="fin-pager">
			<el-pagination
				v-model:current-page="page"
				v-model:page-size="pageSize"
				:page-sizes="[20, 50, 100]"
				:total="total"
				layout="total, sizes, prev, pager, next"
				@size-change="load"
				@current-change="load"
			/>
		</div>

		<!-- 详情抽屉 -->
		<el-drawer v-model="detailVisible" size="560px" :with-header="false">
			<div v-if="detail" class="drawer-body">
				<div class="drawer-head">
					<div>
						<div class="drawer-title">{{ detail.asset_name }}</div>
						<div class="drawer-sub tabular">{{ detail.asset_no }}</div>
					</div>
					<el-tag :type="STATUS_TAG[detail.status] || 'info'" effect="light">{{ detail.status_label }}</el-tag>
				</div>

				<div class="amt-grid">
					<div class="amt-cell">
						<div class="amt-k">原值</div>
						<div class="amt-v tabular">{{ fmtMoney(detail.original_value) }}</div>
					</div>
					<div class="amt-cell">
						<div class="amt-k">累计折旧</div>
						<div class="amt-v tabular">{{ fmtMoney(detail.accumulated_dep) }}</div>
					</div>
					<div class="amt-cell">
						<div class="amt-k">净值</div>
						<div class="amt-v tabular strong">{{ fmtMoney(detail.net_value) }}</div>
					</div>
				</div>

				<el-descriptions :column="2" border size="small">
					<el-descriptions-item label="规格">{{ detail.spec || '—' }}</el-descriptions-item>
					<el-descriptions-item label="取得日期">{{ fmtDay(detail.acquire_date) }}</el-descriptions-item>
					<el-descriptions-item label="使用部门">{{ detail.department_name || '—' }}</el-descriptions-item>
					<el-descriptions-item label="使用人">{{ detail.user_name || '—' }}</el-descriptions-item>
					<el-descriptions-item label="预计使用月数">{{ detail.use_months }}</el-descriptions-item>
					<el-descriptions-item label="残值率">{{ detail.residual_rate + '%' }}</el-descriptions-item>
					<el-descriptions-item label="折旧方式">{{ detail.dep_method_label }}</el-descriptions-item>
				</el-descriptions>
			</div>
		</el-drawer>
	</div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import { Grid, List, Plus } from '@element-plus/icons-vue'
import assetApi from '@/api/asset'
import FinEmpty from '../../finance/_FinEmpty.vue'
import { fmtMoney, fmtDay } from '../shared'

const route = useRoute()
const router = useRouter()

const STATUS_TAG = { inuse: 'success', idle: 'warning', disposed: 'info', sold: 'success' }

/* ---------- 视图切换（仅渲染层，数据源唯一） ---------- */
const viewMode = ref('card')

/* ---------- 筛选 ---------- */
const dateRange = ref([])
const filters = reactive({ keyword: '', status: '', department_id: '' })

const departments = ref([])
const statuses = ref([])

/* ---------- 数据（卡片视图与列表视图共用） ---------- */
const rows = ref([])
const total = ref(0)
const page = ref(1)
const pageSize = ref(20)
const loading = ref(false)
const summary = reactive({ count: 0, original_value: 0, accumulated_dep: 0, net_value: 0 })

async function load() {
	loading.value = true
	try {
		const res = await assetApi.card.list.get({
			start_date: dateRange.value?.[0] || '',
			end_date: dateRange.value?.[1] || '',
			keyword: filters.keyword,
			status: filters.status,
			department_id: filters.department_id || '',
			page: page.value,
			page_size: pageSize.value,
		})
		const data = res.data || {}
		rows.value = data.list || []
		total.value = data.total || 0
		Object.assign(summary, { count: 0, original_value: 0, accumulated_dep: 0, net_value: 0 }, data.summary || {})
	} catch (e) {
		ElMessage.error(e.message || '查询失败')
	} finally {
		loading.value = false
	}
}

const onSearch = () => {
	page.value = 1
	load()
}

const onReset = () => {
	dateRange.value = []
	Object.assign(filters, { keyword: '', status: '', department_id: '' })
	page.value = 1
	load()
}

const depPercent = (a) => {
	const o = Number(a.original_value || 0)
	return o > 0 ? Math.min(100, Math.round((Number(a.accumulated_dep || 0) / o) * 100)) : 0
}

/* ---------- 详情抽屉 ---------- */
const detailVisible = ref(false)
const detail = ref(null)

const openDetail = (row) => {
	detail.value = row
	detailVisible.value = true
}

const goPurchase = () => router.push('/asset/purchase')

async function loadOptions() {
	try {
		const res = await assetApi.card.options.get()
		const data = res.data || {}
		departments.value = data.departments || []
		statuses.value = data.statuses || []
	} catch (e) {
		ElMessage.error(e.message || '下拉数据加载失败')
	}
}

onMounted(() => {
	// 购置单「卡片」按钮深链：?keyword=<资产名称> 直接过滤该批卡片
	if (route.query.keyword) {
		filters.keyword = String(route.query.keyword)
		router.replace({ query: { ...route.query, keyword: undefined } })
	}
	loadOptions()
	load()
})
</script>

<style>
@import '../../finance/_shared.css';
</style>

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
	font-size: 16px;
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
/* 36×36 图标按钮切换双视图 */
.view-switch {
	display: flex;
	gap: 4px;
}
.view-btn {
	width: 36px;
	height: 36px;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	padding: 0;
	background: var(--bg-card);
	border: 1px solid var(--border-base, #dcdfe6);
	border-radius: var(--radius-md);
	color: var(--text-secondary);
	cursor: pointer;
	transition: color 0.2s, border-color 0.2s, background 0.2s;
}
.view-btn:hover {
	color: var(--color-primary);
	border-color: var(--color-primary);
}
.view-btn.active {
	color: var(--color-primary);
	border-color: var(--color-primary);
	background: var(--el-color-primary-light-9, #ecf5ff);
}
.asset-filter {
	display: flex;
	align-items: center;
	gap: var(--space-md);
	flex-wrap: wrap;
	padding: var(--space-lg) var(--space-xl);
	border-top: 1px solid var(--border-lighter);
	margin-top: var(--space-lg);
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
.asset-body--card {
	background: var(--bg-card);
	border-radius: var(--radius-lg);
	box-shadow: var(--shadow-card);
	overflow: auto;
	align-items: flex-start;
	justify-content: center;
}

/* ---- 卡片网格 ---- */
.asset-grid {
	flex: 1;
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(268px, 1fr));
	gap: var(--space-lg);
	align-content: start;
	padding: var(--space-lg);
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

/* ---- 列表视图（表头 44px 13px/600，行 41px 13px） ---- */
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
:deep(.el-table .el-table__body .el-table__row) {
	height: 41px;
}
:deep(.el-table td.el-table__cell) {
	height: 41px;
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
.net-strong {
	font-weight: 600;
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
</style>
