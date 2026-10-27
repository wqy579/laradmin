<template>
	<div class="perf-list">
		<!-- 筛选卡：日期起止 + 科目(树选) + 单据类型 + 不含开账前数据；动作右置 -->
		<div class="perf-filter">
			<span class="label">日期起止</span>
			<el-date-picker
				v-model="search.daterange"
				type="daterange"
				size="small"
				style="width: 240px"
				start-placeholder="开始日期"
				end-placeholder="结束日期"
				value-format="YYYY-MM-DD"
			/>
			<span class="label">科目</span>
			<el-tree-select
				v-model="search.subject_id"
				:data="subjectTree"
				:props="{ label: 'label', children: 'children', value: 'id' }"
				placeholder="全部科目"
				clearable
				filterable
				check-strictly
				style="width: 170px"
				size="small"
			/>
			<span class="label">单据类型</span>
			<el-select v-model="search.doc_type" placeholder="全部" clearable style="width: 110px" size="small">
				<el-option v-for="d in docTypes" :key="d" :label="d" :value="d" />
			</el-select>
			<el-checkbox v-model="search.exclude_before_open" class="open-check">不含开账前数据</el-checkbox>

			<div class="filter-actions">
				<el-button type="primary" size="small" @click="refresh">查询</el-button>
				<el-button size="small" @click="resetSearch">重置</el-button>
				<el-button size="small" @click="exportData">导出</el-button>
			</div>
		</div>

		<div class="perf-content">
			<el-table :data="pagedList" border height="100%" v-loading="loading" @row-dblclick="openDoc">
				<el-table-column prop="date" label="日期" width="110" align="center">
					<template #default="{ row }"><span class="tabular">{{ row.date }}</span></template>
				</el-table-column>
				<!-- 科目列点击 → 反查该科目全部变动（联动筛选） -->
				<el-table-column prop="subject" label="科目" width="160" align="left">
					<template #default="{ row }">
						<span class="link" @click="filterBySubject(row.subject_id)">{{ row.subject }}</span>
					</template>
				</el-table-column>
				<el-table-column prop="doc_type" label="单据类型" width="120" align="left">
					<template #default="{ row }"><span class="cell-regular">{{ row.doc_type }}</span></template>
				</el-table-column>
				<el-table-column prop="doc_no" label="单据号" width="150" align="left">
					<template #default="{ row }">
						<span class="link tabular" @click="openDoc(row)">{{ row.doc_no }}</span>
					</template>
				</el-table-column>
				<el-table-column prop="summary" label="摘要" width="200" align="left" />
				<!-- 借贷金额：普通黑字 600，方向只靠「方向」Tag 区分（与 P0-4 红收绿支不同） -->
				<el-table-column prop="debit" label="借方" width="120" align="right">
					<template #default="{ row }"><span class="tabular num-strong">{{ fmt(row.debit) }}</span></template>
				</el-table-column>
				<el-table-column prop="credit" label="贷方" width="120" align="right">
					<template #default="{ row }"><span class="tabular num-strong">{{ fmt(row.credit) }}</span></template>
				</el-table-column>
				<el-table-column prop="direction" label="方向" width="70" align="center">
					<template #default="{ row }">
						<el-tag size="small" :type="row.direction === '借' ? 'primary' : 'info'">{{ row.direction }}</el-tag>
					</template>
				</el-table-column>
				<el-table-column prop="balance" label="余额" width="120" align="right">
					<template #default="{ row }">
						<span class="tabular" :class="Number(row.balance) < 0 ? 'num-red' : 'num-balance'">{{ fmt(row.balance) }}</span>
					</template>
				</el-table-column>
				<template #empty>
					<span class="empty-text">暂无数据</span>
				</template>
			</el-table>
		</div>

		<div class="perf-pager">
			<el-pagination background layout="total, prev, pager, next" :total="total" :page-size="pageSize" :current-page="page" @current-change="onPageChange" />
		</div>

		<!-- 业务单据穿透：只读（单据头 + 该单据科目变动），mock -->
		<el-drawer v-model="drawer" size="640px" :title="current ? `单据详情 ${current.doc_no}` : '单据详情'" destroy-on-close>
			<div v-if="current" class="v-wrap">
				<div class="v-head">
					<div class="v-item"><span class="v-label">单据号</span><span class="v-value tabular">{{ current.doc_no }}</span></div>
					<div class="v-item"><span class="v-label">日期</span><span class="v-value tabular">{{ current.date }}</span></div>
					<div class="v-item"><span class="v-label">单据类型</span><span class="v-value">{{ current.doc_type }}</span></div>
					<div class="v-item"><span class="v-label">状态</span><span class="v-value"><el-tag size="small" type="success">已审核</el-tag></span></div>
					<div class="v-item v-item--wide"><span class="v-label">摘要</span><span class="v-value">{{ current.summary }}</span></div>
				</div>
				<div class="v-section-title">单据科目变动</div>
				<el-table :data="docEntries" border size="small" :show-summary="true" :summary-method="entrySummary">
					<el-table-column prop="subject" label="科目" width="160" align="left" />
					<el-table-column prop="summary" label="摘要" min-width="160" align="left" />
					<el-table-column prop="debit" label="借方" width="110" align="right">
						<template #default="{ row }"><span class="tabular">{{ fmt(row.debit) }}</span></template>
					</el-table-column>
					<el-table-column prop="credit" label="贷方" width="110" align="right">
						<template #default="{ row }"><span class="tabular">{{ fmt(row.credit) }}</span></template>
					</el-table-column>
				</el-table>
				<div class="v-foot">制单：系统（mock 演示数据）　附单据数：1　审核：—</div>
			</div>
		</el-drawer>
	</div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import { saveBlob } from '@/utils/fileDownload'
import businessApi from '@/api/business'
import { subjectTree } from './mock'

// 金额千分位 + 两位小数；负值着红由 class 控制
const fmt = (v) => Number(v || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

const defaultSearch = () => ({
	daterange: [],
	subject_id: '',
	doc_type: '',
	exclude_before_open: true, // 文档默认勾选
})

const search = reactive(defaultSearch()) // 待应用的筛选草稿
const loading = ref(false)
const rows = ref([])
const docTypes = ref([])
const page = ref(1)
const pageSize = 30
const total = ref(0)
const drawer = ref(false)
const current = ref(null)

const pagedList = computed(() => rows.value)

// 拉取后端（B3，读 ledger 全部分录）——筛选以 query 参数下传，滚动余额由后端跨页计算
const refresh = async () => {
	loading.value = true
	try {
		const params = {
			subject_id: search.subject_id || undefined,
			doc_type: search.doc_type || undefined,
			exclude_before_open: search.exclude_before_open ? 1 : undefined,
			page: page.value,
			page_size: pageSize,
		}
		if (search.daterange?.length === 2) {
			params.start_date = search.daterange[0]
			params.end_date = search.daterange[1]
		}
		const res = await businessApi.finance.subjectChange.get(params)
		const data = res?.data?.data ?? res?.data ?? {}
		rows.value = data.list || []
		total.value = data.total || 0
		docTypes.value = data.doc_types || []
	} catch {
		rows.value = []
		total.value = 0
		docTypes.value = []
	} finally {
		loading.value = false
	}
}
const resetSearch = () => {
	Object.assign(search, defaultSearch())
	page.value = 1
	refresh()
}

// 翻页 → 重新拉后端对应页
const onPageChange = (p) => {
	page.value = p
	refresh()
}

// 科目列点击 → 反查该科目全部变动（联动筛选）
const filterBySubject = (subjectId) => {
	search.subject_id = subjectId
	page.value = 1
	refresh()
	ElMessage.success('已按该科目反查')
}

// 业务单据穿透（双击行 / 单击单据号）：取该单据下全部科目变动（当前页内匹配）
const docEntries = computed(() => (current.value ? rows.value.filter((r) => r.doc_no === current.value.doc_no) : []))
const openDoc = (row) => {
	if (!row) return
	current.value = row
	drawer.value = true
}
const entrySummary = ({ columns }) =>
	columns.map((col, i) => {
		if (i === 0) return '合计'
		if (col.property === 'debit') return fmt(docEntries.value.reduce((s, e) => s + Number(e.debit || 0), 0))
		if (col.property === 'credit') return fmt(docEntries.value.reduce((s, e) => s + Number(e.credit || 0), 0))
		return ''
	})

// 导出：当前筛选结果生成 CSV（带 BOM，Excel 直开）
const exportData = () => {
	const list = pagedList.value
	if (!list.length) return ElMessage.warning('无数据')
	const header = ['日期', '科目', '单据类型', '单据号', '摘要', '借方', '贷方', '方向', '余额']
	const esc = (v) => '"' + String(v ?? '').replace(/"/g, '""') + '"'
	const lines = [header.map(esc).join(',')]
	list.forEach((r) => {
		lines.push([r.date, r.subject, r.doc_type, r.doc_no, r.summary, fmt(r.debit), fmt(r.credit), r.direction, fmt(r.balance)].map(esc).join(','))
	})
	lines.push(
		['合计', '', '', '', '', fmt(list.reduce((s, r) => s + Number(r.debit || 0), 0)), fmt(list.reduce((s, r) => s + Number(r.credit || 0), 0)), '', ''].map(esc).join(',')
	)
	const range = search.daterange?.length === 2 ? `${search.daterange[0]}_${search.daterange[1]}` : '全期'
	// BOM（﻿）让 Excel 双击直开不乱码
	saveBlob('﻿' + lines.join('\r\n'), `科目变化明细表_${range}.csv`)
	ElMessage.success('导出成功')
}

onMounted(refresh)
</script>

<style scoped>
.perf-list {
	height: 100%;
	display: flex;
	flex-direction: column;
	overflow: hidden;
}
.perf-filter {
	display: flex;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
	padding: 16px 20px;
	background: var(--bg-card);
	border-bottom: 1px solid var(--border-base);
}
.perf-filter .label {
	font-size: var(--font-base);
	color: var(--text-regular);
}
.filter-actions {
	margin-left: auto;
	display: flex;
	gap: 12px;
}
.open-check {
	margin: 0;
}
.perf-content {
	flex: 1;
	min-height: 0;
	overflow: auto;
	padding: 0 12px;
}
.perf-pager {
	display: flex;
	justify-content: flex-end;
	padding: 10px 12px;
}
.cell-regular {
	color: var(--text-regular);
}
.num-strong {
	color: var(--text-primary);
	font-weight: 600;
}
.num-red {
	color: var(--color-danger);
	font-weight: 600;
}
.num-balance {
	color: var(--text-primary);
}
.link {
	color: var(--color-primary);
	cursor: pointer;
}
.empty-text {
	color: var(--text-secondary);
	font-size: var(--font-base);
}
/* 表格：表头 13/600 #606266 背景 #F5F7FA、行高 40、正文 13 #303133、斑马纹关闭 */
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
/* 合计行（抽屉分录）：背景 --bg-hover，13/600，「合计」左对齐 */
:deep(.el-table__footer td) {
	background: var(--bg-hover);
	color: var(--text-primary);
	font-weight: 600;
	font-size: var(--font-base);
}
:deep(.el-table__footer td:first-child) {
	text-align: left;
}
/* 单据穿透抽屉（640px 只读） */
.v-wrap {
	font-size: var(--font-base);
	color: var(--text-primary);
}
:deep(.el-drawer__header) {
	height: 50px;
	margin-bottom: 8px;
	padding-top: 0;
	padding-bottom: 0;
	border-bottom: 1px solid var(--border-lighter);
}
:deep(.el-drawer__title) {
	font-size: var(--font-h2);
	font-weight: 600;
	color: var(--text-primary);
}
.v-head {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 12px 24px;
	padding: 16px;
	background: var(--bg-hover);
	border: 1px solid var(--border-lighter);
	border-radius: var(--radius-md);
}
.v-item {
	display: flex;
	gap: 8px;
	align-items: center;
}
.v-item--wide {
	grid-column: span 2;
}
.v-label {
	color: var(--text-regular);
	flex-shrink: 0;
}
.v-value {
	color: var(--text-primary);
}
.v-section-title {
	font-size: var(--font-h3);
	font-weight: 600;
	color: var(--text-primary);
	margin: 16px 0 8px;
}
.v-foot {
	margin-top: 16px;
	color: var(--text-secondary);
	font-size: var(--font-aux);
}
</style>
