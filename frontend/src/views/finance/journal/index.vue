<template>
	<div class="perf-list fin-page">
		<!-- 筛选卡：日期类型/起止 + 科目(树选) + 凭证字号 + 摘要关键字 + 业务员 + 含红冲；动作右置 -->
		<div class="perf-filter fin-filter">
			<span class="label">日期类型</span>
			<el-select v-model="search.date_type" style="width: 110px" size="small">
				<el-option v-for="t in dateTypes" :key="t.value" :label="t.label" :value="t.value" />
			</el-select>
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
				:props="{ label: 'label', children: 'children', value: 'value' }"
				placeholder="全部科目"
				clearable
				filterable
				check-strictly
				style="width: 170px"
				size="small"
			/>
			<span class="label">凭证字号</span>
			<el-input v-model="search.voucher_no" placeholder="如：记-001" clearable size="small" style="width: 130px" @keyup.enter="refresh" @clear="refresh" />
			<span class="label">摘要</span>
			<el-input v-model="search.summary" placeholder="摘要关键字" clearable size="small" style="width: 150px" @keyup.enter="refresh" @clear="refresh" />
			<span class="label">业务员</span>
			<el-input v-model="search.salesman" placeholder="经手人" clearable size="small" style="width: 110px" @keyup.enter="refresh" @clear="refresh" />
			<el-checkbox v-model="search.include_red" class="red-check">包含红冲</el-checkbox>

			<div class="filter-actions fin-toolbar">
				<el-button @click="openHistory">经营历程</el-button>
				<el-button @click="reorderBalance">科目余额重排</el-button>
				<el-button type="primary" @click="refresh">查询</el-button>
				<el-button @click="resetSearch">重置</el-button>
				<el-button @click="exportData">导出</el-button>
			</div>
		</div>

		<div class="perf-content">
			<el-table
				:data="pagedList"
				border
				stripe
				height="100%"
				v-loading="loading"
				:show-summary="true"
				:summary-method="summaryMethod"
				@row-dblclick="openVoucher"
			>
				<el-table-column prop="date" label="日期" width="110" align="center">
					<template #default="{ row }"><span class="tabular" :class="row.is_red ? 'num-red' : ''">{{ row.date }}</span></template>
				</el-table-column>
				<el-table-column prop="doc_type" label="单据类型" width="110" align="left">
					<template #default="{ row }"><span :class="row.is_red ? 'num-red' : 'cell-regular'">{{ row.doc_type }}</span></template>
				</el-table-column>
				<el-table-column prop="doc_no" label="单据号" width="150" align="left">
					<template #default="{ row }">
						<span class="link tabular" @click="openVoucher(row)">{{ row.doc_no }}</span>
					</template>
				</el-table-column>
				<el-table-column prop="subject" label="科目" width="140" align="left">
					<template #default="{ row }"><span :class="row.is_red ? 'num-red' : ''">{{ row.subject }}</span></template>
				</el-table-column>
				<el-table-column prop="summary" label="摘要" width="200" align="left">
					<template #default="{ row }">
						<span :class="row.is_red ? 'num-red' : ''">{{ (row.is_red ? '(红冲) ' : '') + row.summary }}</span>
					</template>
				</el-table-column>
				<!-- 红收绿支（卷00 约定：增加用红、减少用绿） -->
				<el-table-column prop="income" label="收入(借)" width="120" align="right">
					<template #default="{ row }"><span class="tabular" :class="row.income ? 'num-red' : 'num-dim'">{{ fmt(row.income) }}</span></template>
				</el-table-column>
				<el-table-column prop="outcome" label="支出(贷)" width="120" align="right">
					<template #default="{ row }">
						<span class="tabular" :class="row.outcome ? (row.is_red ? 'num-red' : 'num-green') : 'num-dim'">{{ fmt(row.outcome) }}</span>
					</template>
				</el-table-column>
				<el-table-column prop="balance" label="余额" width="130" align="right">
					<template #default="{ row }"><span class="tabular" :class="Number(row.balance) < 0 || row.is_red ? 'num-red' : 'num-balance'">{{ fmt(row.balance) }}</span></template>
				</el-table-column>
				<el-table-column prop="salesman" label="业务员" width="100" align="left">
					<template #default="{ row }"><span :class="row.is_red ? 'num-red' : 'cell-regular'">{{ row.salesman }}</span></template>
				</el-table-column>
				<el-table-column label="操作" width="90" align="right">
					<template #default="{ row }">
						<el-button link type="primary" size="small" @click="openVoucher(row)">查看</el-button>
					</template>
				</el-table-column>
				<template #empty>
					<FinEmpty />
				</template>
			</el-table>
		</div>

		<div class="perf-pager fin-pager">
			<el-pagination background layout="total, prev, pager, next" :total="total" :page-size="pageSize" :current-page="page" @current-change="(p) => { page = p; load() }" />
		</div>

		<!-- 凭证穿透：只读（凭证头 + 分录），mock -->
		<el-drawer v-model="drawer" size="640px" :title="current ? `凭证详情 ${current.voucher_no}` : '凭证详情'" destroy-on-close>
			<div v-if="current" class="v-wrap">
				<div class="v-head">
					<div class="v-item"><span class="v-label">凭证字号</span><span class="v-value tabular">{{ current.voucher_no }}</span></div>
					<div class="v-item"><span class="v-label">日期</span><span class="v-value tabular">{{ current.date }}</span></div>
					<div class="v-item"><span class="v-label">单据类型</span><span class="v-value">{{ current.doc_type }}</span></div>
					<div class="v-item"><span class="v-label">单据号</span><span class="v-value tabular">{{ current.doc_no }}</span></div>
					<div class="v-item"><span class="v-label">科目</span><span class="v-value">{{ current.subject }}</span></div>
					<div class="v-item"><span class="v-label">业务员</span><span class="v-value">{{ current.salesman }}</span></div>
					<div class="v-item v-item--wide"><span class="v-label">摘要</span><span class="v-value">{{ (current.is_red ? '(红冲) ' : '') + current.summary }}</span></div>
					<div class="v-item v-item--wide"><span class="v-label">状态</span><span class="v-value"><el-tag size="small" :type="current.is_red ? 'danger' : 'success'">{{ current.is_red ? '红冲' : '正常' }}</el-tag></span></div>
				</div>
				<div class="v-section-title">凭证分录</div>
				<el-table :data="entries" border stripe class="fin-table--dense" size="small" :show-summary="true" :summary-method="entrySummary">
					<el-table-column prop="summary" label="摘要" min-width="180" align="left" />
					<el-table-column prop="subject" label="科目" width="140" align="left" />
					<el-table-column prop="debit" label="借方" width="110" align="right">
						<template #default="{ row }"><span class="tabular">{{ fmt(row.debit) }}</span></template>
					</el-table-column>
					<el-table-column prop="credit" label="贷方" width="110" align="right">
						<template #default="{ row }"><span class="tabular">{{ fmt(row.credit) }}</span></template>
					</el-table-column>
				</el-table>
				<div class="v-foot">制单：{{ current.salesman }}　附单据数：1　记账：—　审核：—</div>
			</div>
		</el-drawer>
	</div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import { saveBlob } from '@/utils/fileDownload'
import { useRouter } from 'vue-router'
import businessApi from '@/api/business'
import ledgerApi from '@/api/ledger'
import FinEmpty from '../_FinEmpty.vue'

const dateTypes = [
	{ value: 'book', label: '记账日期' },
	{ value: 'op', label: '操作日期' },
]

// 金额千分位 + 两位小数；负值着红由各列 class 控制
const fmt = (v) => Number(v || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

const defaultSearch = () => ({
	date_type: 'book',
	daterange: [],
	subject_id: '',
	voucher_no: '',
	summary: '',
	salesman: '',
	include_red: false,
})

const search = reactive(defaultSearch()) // 待应用的筛选草稿
const applied = ref(defaultSearch()) // 点「查询」后生效的筛选
const rows = ref([])
const total = ref(0)
const incomeTotal = ref(0)
const outcomeTotal = ref(0)
const loading = ref(false)
const page = ref(1)
const pageSize = 30
const drawer = ref(false)
const current = ref(null)
const router = useRouter()
const subjectTree = ref([])

// 现金/银行科目前缀：只展示日记账涉及的这两类科目
const CASH_BANK_PREFIXES = ['1001', '1002']
const isCashBank = (code) => CASH_BANK_PREFIXES.some((p) => String(code || '').startsWith(p))

// 把 ledger 科目树（{id,parent_id,code,name,category,...,children}）映射为树选择
// {label, value: code, children}，并过滤到现金/银行两类。value 用编码，后端按编码前缀过滤。
const mapSubjectTree = (nodes) =>
	(nodes || []).filter((n) => isCashBank(n.code)).map((n) => ({
		label: `${n.code} ${n.name}`,
		value: n.code,
		children: mapSubjectTree(n.children || []),
	}))

// 加载真实科目树（ledger，machine-a），仅现金/银行
const loadSubjectTree = async () => {
	try {
		const res = await ledgerApi.subject.tree.get()
		subjectTree.value = mapSubjectTree(res?.data?.tree || [])
	} catch {
		subjectTree.value = []
	}
}

// —— 后端筛选分页（真实 ledger 数据）——
const pagedList = computed(() => rows.value)

const refresh = async () => {
	applied.value = JSON.parse(JSON.stringify(search))
	page.value = 1
	await load()
}
const resetSearch = () => {
	Object.assign(search, defaultSearch())
	refresh()
}

// 查询日记账：把 6 项筛选 + 含红冲 传给后端，后端返回已算余额/合计的分页行
const load = async () => {
	const f = applied.value
	const params = {
		page: page.value,
		page_size: pageSize,
		date_type: f.date_type,
		include_red: f.include_red ? 1 : 0,
	}
	if (f.daterange?.length === 2) {
		params.start_date = f.daterange[0]
		params.end_date = f.daterange[1]
	}
	if (f.subject_id) params.subject_id = f.subject_id
	if (f.voucher_no) params.voucher_no = f.voucher_no
	if (f.summary) params.summary = f.summary
	if (f.salesman) params.salesman = f.salesman

	loading.value = true
	try {
		const res = await businessApi.finance.journal.get(params)
		const data = res?.data?.data ?? res?.data ?? {}
		rows.value = data.list ?? []
		total.value = data.total ?? 0
		incomeTotal.value = Number(data.income_total ?? 0)
		outcomeTotal.value = Number(data.outcome_total ?? 0)
	} catch {
		rows.value = []
		total.value = 0
		incomeTotal.value = 0
		outcomeTotal.value = 0
	} finally {
		loading.value = false
	}
}

// 合计：后端对当前筛选全集求和（跨页准确）；summary 输出会被 EP 转义，只能返回纯文本，
// 收入红/支出绿由合计行 nth-child 着色（见样式）
const summaryMethod = ({ columns }) =>
	columns.map((col, i) => {
		if (i === 0) return '合计'
		if (col.property === 'income') return fmt(incomeTotal.value)
		if (col.property === 'outcome') return fmt(outcomeTotal.value)
		return ''
	})
const entrySummary = ({ columns }) =>
	columns.map((col, i) => {
		if (i === 0) return '合计'
		if (col.property === 'debit') return fmt(entries.value.reduce((s, e) => s + Number(e.debit || 0), 0))
		if (col.property === 'credit') return fmt(entries.value.reduce((s, e) => s + Number(e.credit || 0), 0))
		return ''
	})

// 「科目余额重排」：后端已按记账日期逐行滚余额，重排即重新拉取（保证跨页连续）
const reorderBalance = () => {
	load()
	ElMessage.success('余额已重排')
}

// 跳转经营历程（全业务资金流水视图）
const openHistory = () => {
	router.push('/business/history')
}

// 凭证穿透（双击行 / 单击单据号 / 查看）：单行流水即分录视图
const entries = computed(() =>
	current.value
		? [{ summary: current.value.summary, subject: current.value.subject, debit: current.value.income, credit: current.value.outcome }]
		: []
)
const openVoucher = (row) => {
	if (!row) return
	current.value = row
	drawer.value = true
}

// 导出：当前筛选全集（后端分页），前端导出当前页
const exportData = () => {
	const list = pagedList.value
	if (!list.length) return ElMessage.warning('无数据')
	const header = ['日期', '单据类型', '单据号', '科目', '摘要', '收入(借)', '支出(贷)', '余额', '业务员']
	const esc = (v) => '"' + String(v ?? '').replace(/"/g, '""') + '"'
	const lines = [header.map(esc).join(',')]
	list.forEach((r) => {
		lines.push(
			[r.date, r.doc_type, r.doc_no, r.subject, (r.is_red ? '(红冲) ' : '') + r.summary, fmt(r.income), fmt(r.outcome), fmt(r.balance), r.salesman].map(esc).join(',')
		)
	})
	lines.push(
		['合计', '', '', '', '', fmt(incomeTotal.value), fmt(outcomeTotal.value), '', ''].map(esc).join(',')
	)
	const range = applied.value.daterange?.length === 2 ? `${applied.value.daterange[0]}_${applied.value.daterange[1]}` : '全期'
	saveBlob('﻿' + lines.join('\r\n'), `日记账_${range}.csv`)
	ElMessage.success('导出成功')
}

onMounted(() => {
	loadSubjectTree()
	refresh()
})
</script>

<style>
@import '../_shared.css';
</style>

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
}
.filter-actions {
	margin-left: auto;
	display: flex;
	gap: 12px;
}
.red-check {
	margin: 0;
}
.perf-content {
	flex: 1;
	min-height: 0;
	overflow: auto;
	padding: 0 12px;
}
.perf-pager {
	padding: 0 12px;
}
.cell-regular {
	color: var(--text-regular);
}
.num-red {
	color: var(--color-danger);
	font-weight: 600;
}
.num-green {
	color: var(--color-success);
	font-weight: 600;
}
.num-balance {
	color: var(--text-primary);
	font-weight: 600;
}
.num-dim {
	color: var(--text-placeholder);
	font-weight: 600;
}
.link {
	color: var(--color-primary);
	cursor: pointer;
}
/* 表格：正文 13px；表头/行高/斑马纹由 _shared.css 提供 */
:deep(.el-table) {
	font-size: var(--font-base);
	color: var(--text-primary);
}
:deep(.el-table td.el-table__cell),
:deep(.el-table th.el-table__cell) {
	border-bottom-color: var(--border-lighter);
}
/* 合计行：白底加粗，「合计」左对齐；收入(第6列)红 / 支出(第7列)绿 ——
 * summary-method 输出经 EP 转义无法带 class，故按列位着色 */
:deep(.el-table__footer td) {
	background: var(--bg-card);
	color: var(--text-primary);
	font-weight: 700;
	font-size: var(--font-base);
}
:deep(.el-table__footer td:first-child) {
	text-align: left;
}
:deep(.perf-content .el-table__footer td:nth-child(6)) {
	color: var(--color-danger);
}
:deep(.perf-content .el-table__footer td:nth-child(7)) {
	color: var(--color-success);
}
/* 凭证穿透抽屉（640px 只读） */
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
	height: 50px;
	line-height: 50px;
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
