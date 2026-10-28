<template>
	<div class="dp-page">
		<!-- 单据类型区：胶囊按钮切换，带该类数量徽标（数量随其余筛选联动） -->
		<div class="dp-tabs">
			<div class="dp-tabs__group">
				<button
					v-for="t in typeTabs"
					:key="t.value"
					type="button"
					class="dp-capsule"
					:class="{ 'is-active': search.type === t.value }"
					@click="onTypeChange(t.value)"
				>
					<span class="dp-capsule__label">{{ t.label }}</span>
					<span class="dp-capsule__count">{{ counts[t.key] }}</span>
				</button>
			</div>
			<span class="dp-tabs__hint">共 {{ filtered.length }} 张单据，按单据日期倒序</span>
		</div>

		<!-- 统一筛选卡：单据号 + 往来单位 + 起止日期 + 金额区间 + 状态 -->
		<div class="dp-filter">
			<span class="dp-filter__label">单据号</span>
			<el-input v-model="search.doc_no" placeholder="单据号关键字" clearable size="small" style="width: 150px" @keyup.enter="refresh" @clear="refresh" />
			<span class="dp-filter__label">往来单位</span>
			<el-select v-model="search.unit" placeholder="全部单位" clearable filterable size="small" style="width: 180px" @change="refresh">
				<el-option v-for="u in unitOptions" :key="u" :label="u" :value="u" />
			</el-select>
			<el-date-picker
				v-model="dateRange"
				type="daterange"
				size="small"
				style="width: 240px"
				start-placeholder="开始日期"
				end-placeholder="结束日期"
				value-format="YYYY-MM-DD"
				@change="onDateChange"
			/>
			<span class="dp-filter__label">金额</span>
			<el-input-number v-model="search.amount_min" :controls="false" :min="0" placeholder="起" size="small" style="width: 100px" class="dp-num" @change="refresh" />
			<span class="dp-filter__dash">至</span>
			<el-input-number v-model="search.amount_max" :controls="false" :min="0" placeholder="止" size="small" style="width: 100px" class="dp-num" @change="refresh" />
			<span class="dp-filter__label">状态</span>
			<el-select v-model="search.status" placeholder="全部状态" clearable size="small" style="width: 120px" @change="refresh">
				<el-option v-for="(s, k) in MOCK_DOC_STATUS" :key="k" :label="s.label" :value="k" />
			</el-select>
			<el-button type="primary" size="small" @click="refresh">查询</el-button>
			<el-button size="small" @click="resetSearch">重置</el-button>
		</div>

		<div class="dp-content">
			<div class="dp-toolbar">
				<span class="dp-toolbar__title">单据列表</span>
				<el-button size="small" v-permission="'fin:document:query'" @click="exportData">
					<el-icon><Download /></el-icon><span>导出</span>
				</el-button>
			</div>

			<div class="dp-table">
			<el-table :data="pagedList" border height="100%" v-loading="loading" :show-summary="true" :summary-method="summaryMethod">
				<el-table-column prop="type" label="单据类型" width="100" align="center">
					<template #default="{ row }">
						<el-tag :type="typeMeta[row.type].tag" size="small" effect="light">{{ typeMeta[row.type].label }}</el-tag>
					</template>
				</el-table-column>
				<el-table-column prop="doc_no" label="单据号" width="150" align="left">
					<template #default="{ row }">
						<span class="dp-link tabular" @click="openDetail(row)">{{ row.doc_no }}</span>
					</template>
				</el-table-column>
				<el-table-column prop="date" label="日期" width="110" align="center">
					<template #default="{ row }"><span class="tabular">{{ row.date }}</span></template>
				</el-table-column>
				<el-table-column prop="unit" label="往来单位" width="160" align="left" show-overflow-tooltip />
				<!-- 卷00：收入为正着 success、支出为负着 danger -->
				<el-table-column prop="amount" label="金额" width="130" align="right">
					<template #default="{ row }">
						<span class="tabular dp-amt" :class="Number(row.amount) >= 0 ? 'dp-amt--in' : 'dp-amt--out'">{{ fmt(row.amount) }}</span>
					</template>
				</el-table-column>
				<el-table-column prop="status" label="状态" width="100" align="center">
					<template #default="{ row }">
						<el-tag :type="MOCK_DOC_STATUS[row.status].tag" size="small" effect="light">{{ MOCK_DOC_STATUS[row.status].label }}</el-tag>
					</template>
				</el-table-column>
				<el-table-column prop="summary" label="摘要" min-width="220" align="left" show-overflow-tooltip />
				<el-table-column label="操作" width="90" align="right">
					<template #default="{ row }">
						<el-button link type="primary" size="small" @click="openDetail(row)">查看</el-button>
					</template>
				</el-table-column>
				<template #empty>
					<span class="dp-empty">暂无数据</span>
				</template>
			</el-table>
			</div>
		</div>

		<div class="dp-pager">
			<el-pagination
				background
				layout="total, prev, pager, next"
				:total="filtered.length"
				:page-size="pageSize"
				:current-page="page"
				@current-change="(p) => (page = p)"
			/>
		</div>

		<!-- 查看 Dialog：公共信息 + 按单据类型分区的扩展字段 -->
		<el-dialog v-model="dialogVisible" :title="current ? `单据详情 · ${current.doc_no}` : '单据详情'" width="620px" destroy-on-close>
			<div v-if="current" class="dp-detail">
				<div class="dp-detail__section">单据信息</div>
				<div class="dp-grid">
					<div class="dp-item"><span class="dp-item__label">单据类型</span><el-tag :type="typeMeta[current.type].tag" size="small">{{ typeMeta[current.type].label }}</el-tag></div>
					<div class="dp-item"><span class="dp-item__label">单据号</span><span class="dp-item__value tabular">{{ current.doc_no }}</span></div>
					<div class="dp-item"><span class="dp-item__label">日期</span><span class="dp-item__value tabular">{{ current.date }}</span></div>
					<div class="dp-item"><span class="dp-item__label">往来单位</span><span class="dp-item__value">{{ current.unit }}</span></div>
					<div class="dp-item"><span class="dp-item__label">金额</span><span class="tabular dp-amt" :class="Number(current.amount) >= 0 ? 'dp-amt--in' : 'dp-amt--out'">{{ fmt(current.amount) }}</span></div>
					<div class="dp-item"><span class="dp-item__label">状态</span><el-tag :type="MOCK_DOC_STATUS[current.status].tag" size="small">{{ MOCK_DOC_STATUS[current.status].label }}</el-tag></div>
					<div class="dp-item dp-item--wide"><span class="dp-item__label">摘要</span><span class="dp-item__value">{{ current.summary }}</span></div>
				</div>

				<div class="dp-detail__section">{{ typeMeta[current.type].label }}扩展信息</div>
				<div class="dp-grid">
					<div v-for="(v, k) in current.extra" :key="k" class="dp-item">
						<span class="dp-item__label">{{ k }}</span>
						<span class="dp-item__value" :class="{ tabular: isNumericText(v) }">{{ fmtExtra(v) }}</span>
					</div>
				</div>
			</div>
			<template #footer>
				<el-button size="small" @click="dialogVisible = false">关闭</el-button>
			</template>
		</el-dialog>
	</div>
</template>

<script setup>
/**
 * 财务单据统一查询门户（D-14）：费用报销 / 收付款 / 存取款 / 调账 四类单据的统一查询。
 * 本期纯前端 mock（src/mock/document-portal.js），后端接口以 TODO(api) 标注在 mock 文件头。
 */
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { Download } from '@element-plus/icons-vue'
import { saveBlob } from '@/utils/fileDownload'
import { MOCK_DOC_STATUS, MOCK_DOC_TYPES, queryDocuments, queryUnits } from '@/mock/document-portal'

const fmt = (v) => Number(v || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

const typeMeta = computed(() => Object.fromEntries(MOCK_DOC_TYPES.map((t) => [t.value, t])))

// 顶部胶囊：全部 + 四类单据（key 对应 mock 返回的 counts 字段）
const typeTabs = computed(() => [{ value: '', label: '全部', key: 'all' }, ...MOCK_DOC_TYPES.map((t) => ({ ...t, key: t.value }))])

const defaultSearch = () => ({
	type: '',
	doc_no: '',
	unit: '',
	start_date: '',
	end_date: '',
	amount_min: undefined,
	amount_max: undefined,
	status: '',
})

const search = reactive(defaultSearch())
const dateRange = ref([])
const counts = ref({ all: 0, ...Object.fromEntries(MOCK_DOC_TYPES.map((t) => [t.value, 0])) })
const filtered = ref([])
const loading = ref(false)
const page = ref(1)
const pageSize = 20
const dialogVisible = ref(false)
const current = ref(null)

// 单位候选取全量，不随筛选收窄，避免选完单位后下拉里找不到别的单位
const unitOptions = ref([])

// mock 前端分页；后端就绪后换成接口分页参数即可
const pagedList = computed(() => filtered.value.slice((page.value - 1) * pageSize, page.value * pageSize))

const onTypeChange = (value) => {
	search.type = value
	page.value = 1
	load()
}

const onDateChange = (val) => {
	search.start_date = val?.[0] || ''
	search.end_date = val?.[1] || ''
	refresh()
}

const refresh = () => {
	page.value = 1
	load()
}

const resetSearch = () => {
	Object.assign(search, defaultSearch())
	dateRange.value = []
	refresh()
}

const load = () => {
	loading.value = true
	try {
		// mock 侧一次取全量，前端切片，保证分页与合计跨页口径一致
		const res = queryDocuments({ ...search, page: 1, page_size: Number.MAX_SAFE_INTEGER })
		counts.value = res.counts
		filtered.value = res.list
		if ((page.value - 1) * pageSize >= filtered.value.length) page.value = 1
	} finally {
		loading.value = false
	}
}

// 合计行：对当前筛选全集（跨页）求净额，而非仅当前页
const summaryMethod = ({ columns }) =>
	columns.map((col, i) => {
		if (i === 0) return '合计'
		if (col.property === 'amount') return `¥${fmt(filtered.value.reduce((s, r) => s + Number(r.amount || 0), 0))}`
		return ''
	})

const openDetail = (row) => {
	current.value = row
	dialogVisible.value = true
}

const fmtExtra = (v) => {
	if (v === null || v === undefined || v === '') return '—'
	return typeof v === 'number' ? fmt(v) : String(v)
}
const isNumericText = (v) => typeof v === 'number' || /^[\d.]+$/.test(String(v ?? ''))

const exportData = () => {
	const list = filtered.value
	if (!list.length) return ElMessage.warning('当前筛选无数据可导出')
	const header = ['单据类型', '单据号', '日期', '往来单位', '金额', '状态', '摘要']
	const esc = (v) => '"' + String(v ?? '').replace(/"/g, '""') + '"'
	const lines = [header.map(esc).join(',')]
	list.forEach((r) => {
		lines.push(
			[typeMeta.value[r.type].label, r.doc_no, r.date, r.unit, fmt(r.amount), MOCK_DOC_STATUS[r.status].label, r.summary].map(esc).join(',')
		)
	})
	lines.push(['合计', '', '', '', fmt(list.reduce((s, r) => s + Number(r.amount || 0), 0)), '', ''].map(esc).join(','))
	const range = search.start_date && search.end_date ? `${search.start_date}_${search.end_date}` : '全期'
	saveBlob('﻿' + lines.join('\r\n'), `财务单据_${range}.csv`)
	ElMessage.success('导出成功')
}

onMounted(() => {
	unitOptions.value = queryUnits()
	load()
})
</script>

<style scoped>
.dp-page {
	height: 100%;
	display: flex;
	flex-direction: column;
	overflow: hidden;
}

/* 单据类型区 */
.dp-tabs {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	padding: 12px 20px;
	background: var(--bg-card);
	border-bottom: 1px solid var(--border-lighter);
}
.dp-tabs__group {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
}
.dp-capsule {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	height: 28px;
	padding: 0 12px;
	border: 1px solid var(--border-base);
	border-radius: 14px;
	background: var(--bg-card);
	font-size: var(--font-base);
	color: var(--text-regular);
	cursor: pointer;
	transition: all 0.2s;
}
.dp-capsule:hover {
	color: var(--color-primary);
	border-color: var(--color-primary);
}
.dp-capsule.is-active {
	color: #fff;
	background: var(--color-primary);
	border-color: var(--color-primary);
}
.dp-capsule__count {
	min-width: 18px;
	height: 16px;
	padding: 0 5px;
	border-radius: 8px;
	background: var(--bg-hover);
	color: var(--text-secondary);
	font-size: var(--font-aux);
	line-height: 16px;
	text-align: center;
	font-variant-numeric: tabular-nums;
}
.dp-capsule.is-active .dp-capsule__count {
	background: rgba(255, 255, 255, 0.24);
	color: #fff;
}
.dp-tabs__hint {
	font-size: var(--font-aux);
	color: var(--text-secondary);
	white-space: nowrap;
}

/* 统一筛选卡 */
.dp-filter {
	display: flex;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
	padding: 12px 20px;
	background: var(--bg-card);
	border-bottom: 1px solid var(--border-base);
}
.dp-filter__label {
	font-size: var(--font-base);
	color: var(--text-regular);
	white-space: nowrap;
}
.dp-filter__dash {
	color: var(--text-secondary);
	font-size: var(--font-base);
}
:deep(.dp-num .el-input__inner) {
	text-align: left;
}

/* 表格区 */
.dp-content {
	flex: 1;
	min-height: 0;
	display: flex;
	flex-direction: column;
	padding: 12px 12px 0;
}
.dp-toolbar {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 0 0 10px;
}
.dp-toolbar__title {
	font-size: var(--font-h3);
	font-weight: 600;
	color: var(--text-primary);
}
.dp-table {
	flex: 1;
	min-height: 0;
}
.dp-pager {
	display: flex;
	justify-content: flex-end;
	padding: 10px 12px;
}

/* 表格：表头 13/600 #606266 背景 #F5F7FA、行长 40、正文 13 #303133、无斑马纹 */
:deep(.el-table) {
	font-size: var(--font-base);
	color: var(--text-primary);
}
:deep(.el-table th.el-table__cell) {
	background: var(--bg-hover);
	height: 44px;
	color: var(--text-regular);
	font-weight: 600;
}
:deep(.el-table .el-table__row) {
	height: 40px;
}
:deep(.el-table td.el-table__cell),
:deep(.el-table th.el-table__cell) {
	border-bottom-color: var(--border-lighter);
}
:deep(.el-tag) {
	height: 22px;
	border-radius: var(--radius-md);
}
:deep(.el-table__footer td) {
	background: #fff;
	color: var(--text-primary);
	font-weight: 700;
	font-size: var(--font-base);
}
:deep(.el-table__footer td:first-child) {
	text-align: left;
}
.dp-empty {
	color: var(--text-secondary);
	font-size: var(--font-base);
}
.dp-link {
	color: var(--color-primary);
	cursor: pointer;
}
.dp-amt {
	font-weight: 600;
}
.dp-amt--in {
	color: var(--color-success);
}
.dp-amt--out {
	color: var(--color-danger);
}

/* 查看 Dialog：分区 + 两列字段网格（标题栏 50px） */
:deep(.el-dialog__title) {
	font-size: var(--font-h2);
	font-weight: 600;
	color: var(--text-primary);
	line-height: 50px;
}
:deep(.el-dialog__header) {
	margin-right: 0;
	padding-bottom: 0;
}
.dp-detail {
	font-size: var(--font-base);
	color: var(--text-primary);
}
.dp-detail__section {
	font-size: var(--font-h3);
	font-weight: 600;
	color: var(--text-primary);
	margin: 0 0 10px;
	padding-left: 8px;
	border-left: 3px solid var(--color-primary);
	line-height: 18px;
}
.dp-detail__section + .dp-grid {
	margin-bottom: 18px;
}
.dp-grid {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 10px 24px;
	padding: 14px 16px;
	background: var(--bg-hover);
	border: 1px solid var(--border-lighter);
	border-radius: var(--radius-md);
}
.dp-item {
	display: flex;
	gap: 8px;
	align-items: center;
	min-width: 0;
}
.dp-item--wide {
	grid-column: span 2;
}
.dp-item__label {
	flex-shrink: 0;
	width: 76px;
	color: var(--text-secondary);
}
.dp-item__value {
	color: var(--text-primary);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
</style>
