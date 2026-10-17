<template>
	<div class="perf-list">
		<!-- 筛选卡：日期类型/起止 + 科目(树选) + 凭证字号 + 摘要关键字 + 业务员/单据类型/红冲；动作右置 -->
		<div class="perf-filter">
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
				:props="{ label: 'label', children: 'children', value: 'id' }"
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
			<el-select v-model="search.salesman" placeholder="全部" clearable style="width: 110px" size="small">
				<el-option v-for="s in salesmans" :key="s" :label="s" :value="s" />
			</el-select>
			<span class="label">单据类型</span>
			<el-select v-model="search.doc_type" placeholder="全部" clearable style="width: 110px" size="small">
				<el-option v-for="d in docTypes" :key="d" :label="d" :value="d" />
			</el-select>
			<el-checkbox v-model="search.include_red" class="red-check">包含红冲</el-checkbox>

			<div class="filter-actions">
				<el-button size="small" @click="reorderBalance">科目余额重排</el-button>
				<el-button type="primary" size="small" @click="refresh">查询</el-button>
				<el-button size="small" @click="resetSearch">重置</el-button>
			</div>
		</div>

		<div class="perf-content">
			<el-table
				:data="pagedList"
				border
				height="100%"
				v-loading="loading"
				:show-summary="true"
				:summary-method="summaryMethod"
			>
				<el-table-column prop="date" label="日期" width="110" align="center">
					<template #default="{ row }"><span class="tabular" :class="row.is_red ? 'num-red' : ''">{{ row.date }}</span></template>
				</el-table-column>
				<el-table-column prop="doc_type" label="单据类型" width="110" align="left">
					<template #default="{ row }"><span :class="row.is_red ? 'num-red' : 'cell-regular'">{{ row.doc_type }}</span></template>
				</el-table-column>
				<el-table-column prop="doc_no" label="单据号" width="150" align="left">
					<template #default="{ row }"><span class="tabular">{{ row.doc_no }}</span></template>
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
				<template #empty>
					<span class="empty-text">暂无数据</span>
				</template>
			</el-table>
		</div>

		<div class="perf-pager">
			<el-pagination background layout="total, prev, pager, next" :total="total" :page-size="pageSize" :current-page="page" @current-change="(p) => (page = p)" />
		</div>

	</div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import { subjectTree, dateTypes, docTypes, salesmans, buildJournalRows, reorderBalances } from './mock'

// 金额千分位 + 两位小数；负值着红由各列 class 控制
const fmt = (v) => Number(v || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

const defaultSearch = () => ({
	date_type: 'book',
	daterange: [],
	subject_id: '',
	voucher_no: '',
	summary: '',
	salesman: '',
	doc_type: '',
	include_red: false,
})

const search = reactive(defaultSearch()) // 待应用的筛选草稿
const applied = ref(defaultSearch()) // 点「查询」后生效的筛选
const rows = ref(buildJournalRows())
const loading = ref(false)
const page = ref(1)
const pageSize = 30

// —— 前端筛选（mock 数据一次到位）——
const filteredList = computed(() => {
	const f = applied.value
	return rows.value.filter((r) => {
		// 日期：按当前「日期类型」取 记账日期/操作日期
		const d = f.date_type === 'op' ? r.op_date : r.date
		if (f.daterange?.length === 2) {
			if (d < f.daterange[0] || d > f.daterange[1]) return false
		}
		if (f.subject_id && !r.subject_id.startsWith(String(f.subject_id))) return false // 树选父节点匹配其子科目
		if (f.voucher_no && !r.voucher_no.includes(f.voucher_no)) return false
		if (f.summary && !r.summary.includes(f.summary)) return false
		if (f.salesman && r.salesman !== f.salesman) return false
		if (f.doc_type && r.doc_type !== f.doc_type) return false
		if (!f.include_red && r.is_red) return false
		return true
	})
})
const total = computed(() => filteredList.value.length)
const pagedList = computed(() => {
	const start = (page.value - 1) * pageSize
	return filteredList.value.slice(start, start + pageSize)
})

const refresh = () => {
	applied.value = JSON.parse(JSON.stringify(search))
	page.value = 1
}
const resetSearch = () => {
	Object.assign(search, defaultSearch())
	refresh()
}

// 合计：借方(收入)/贷方(支出) 对当前筛选全集求和（非仅当页）
const summaryMethod = ({ columns }) => {
	const incomeTotal = filteredList.value.reduce((s, r) => s + Number(r.income || 0), 0)
	const outcomeTotal = filteredList.value.reduce((s, r) => s + Number(r.outcome || 0), 0)
	return columns.map((col, i) => {
		if (i === 0) return '合计'
		if (col.property === 'income') return fmt(incomeTotal)
		if (col.property === 'outcome') return fmt(outcomeTotal)
		return ''
	})
}
// 「科目余额重排」：按当前筛选重算余额列并回写
const reorderBalance = () => {
	const map = new Map()
	reorderBalances(filteredList.value).forEach((r) => map.set(r.id, r.balance))
	rows.value = rows.value.map((r) => (map.has(r.id) ? { ...r, balance: map.get(r.id) } : r))
	ElMessage.success('余额已重排')
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
	display: flex;
	justify-content: flex-end;
	padding: 10px 12px;
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
	color: var(--text-primary);
	font-weight: 600;
	opacity: 0.35;
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
/* 合计行：背景 --bg-hover，13/600，「合计」左对齐 */
:deep(.el-table__footer td) {
	background: var(--bg-hover);
	color: var(--text-primary);
	font-weight: 600;
	font-size: var(--font-base);
}
:deep(.el-table__footer td:first-child) {
	text-align: left;
}
</style>
