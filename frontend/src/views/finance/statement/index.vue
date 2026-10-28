<template>
	<div class="st-page fin-page">
		<!-- 筛选区 -->
		<div class="st-filter fin-filter">
			<span class="label">往来类型</span>
			<el-radio-group v-model="kind" size="small" @change="onKindChange">
				<el-radio-button value="customer">客户应收</el-radio-button>
				<el-radio-button value="supplier">供应商应付</el-radio-button>
			</el-radio-group>

			<span class="label">往来单位</span>
			<el-select v-model="search.unitId" clearable filterable placeholder="全部单位" size="small" style="width: 220px" @change="refresh">
				<el-option v-for="u in unitOptions" :key="u.id" :label="u.name" :value="u.id" />
			</el-select>

			<span class="label">期间</span>
			<el-date-picker v-model="search.month" type="month" value-format="YYYY-MM" placeholder="全部月份" size="small" style="width: 140px" @change="refresh" />

			<span class="label">对账状态</span>
			<el-select v-model="search.reconStatus" clearable placeholder="全部状态" size="small" style="width: 140px" @change="refresh">
				<el-option v-for="(s, k) in MOCK_RECON_STATUS" :key="k" :label="s.label" :value="k" />
			</el-select>

			<div class="st-actions fin-toolbar">
				<el-button type="primary" @click="refresh">查询</el-button>
				<el-button @click="resetSearch">重置</el-button>
			</div>
			<div style="flex: 1" />
			<div class="st-actions fin-toolbar">
				<el-button :disabled="periods.length === 0" @click="exportPeriods">导出汇总</el-button>
				<el-button :disabled="details.length === 0" @click="exportDetails">导出明细</el-button>
			</div>
		</div>

		<!-- 汇总统计卡 -->
		<div class="st-stat">
			<div class="st-stat__item">
				<div class="st-stat__label">期间{{ kindLabel }}总额</div>
				<div class="st-stat__value tabular">{{ fmt(sumOf(periods, kind === 'customer' ? 'receivable' : 'payable')) }}</div>
			</div>
			<div class="st-stat__item">
				<div class="st-stat__label">期间已收/已付</div>
				<div class="st-stat__value tabular">{{ fmt(sumOf(periods, kind === 'customer' ? 'received' : 'paid')) }}</div>
			</div>
			<div class="st-stat__item">
				<div class="st-stat__label">期末余额合计</div>
				<div class="st-stat__value tabular">{{ fmt(sumOf(periods, 'closing_balance')) }}</div>
			</div>
			<div class="st-stat__item">
				<div class="st-stat__label">待对账期间</div>
				<div class="st-stat__value tabular">{{ unsettledCount }}</div>
			</div>
		</div>

		<!-- 期间汇总表 -->
		<div class="st-section">
			<h3 class="st-section__title">期间汇总（{{ kindLabel }}发生额与余额）</h3>
			<el-table :data="periods" border stripe v-loading="loading" show-summary :summary-method="periodSummary" highlight-current-row @current-change="onPeriodRowChange" class="st-table">
				<el-table-column prop="period" label="期间" width="110" align="center">
					<template #default="{ row }">
						<span class="tabular">{{ row.period }}</span>
					</template>
				</el-table-column>
				<el-table-column prop="unitName" label="往来单位" min-width="160" show-overflow-tooltip />
				<el-table-column label="期初余额" width="130" align="right">
					<template #default="{ row }">
						<span class="tabular">{{ fmt(row.opening_balance) }}</span>
					</template>
				</el-table-column>
				<el-table-column :label="kind === 'customer' ? '本期应收' : '本期应付'" width="130" align="right">
					<template #default="{ row }">
						<span class="tabular" :class="row.amount < 0 ? 'st-neg' : ''">{{ fmt(row.amount) }}</span>
					</template>
				</el-table-column>
				<el-table-column :label="kind === 'customer' ? '本期已收' : '本期已付'" width="130" align="right">
					<template #default="{ row }">
						<span class="tabular">{{ fmt(row.settle) }}</span>
					</template>
				</el-table-column>
				<el-table-column label="期末余额" width="130" align="right">
					<template #default="{ row }">
						<span class="tabular st-closing">{{ fmt(row.closing_balance) }}</span>
					</template>
				</el-table-column>
				<el-table-column label="对账状态" width="120" align="center">
					<template #default="{ row }">
						<el-tag :type="MOCK_RECON_STATUS[row.recon_status].type" size="small" effect="light">{{ MOCK_RECON_STATUS[row.recon_status].label }}</el-tag>
					</template>
				</el-table-column>
				<el-table-column label="对账日期" width="110" align="center">
					<template #default="{ row }">
						<span class="tabular">{{ row.recon_date || '-' }}</span>
					</template>
				</el-table-column>
				<el-table-column label="操作" width="120" align="center" fixed="right">
					<template #default="{ row }">
						<el-button v-if="row.recon_status !== 'settled'" link type="primary" size="small" @click="markSettled(row)">标记已对账</el-button>
						<el-button v-else link size="small" @click="markUnsettled(row)">取消对账</el-button>
					</template>
				</el-table-column>
				<template #empty>
					<FinEmpty />
				</template>
			</el-table>
		</div>

		<!-- 往来明细表 -->
		<div class="st-section">
			<h3 class="st-section__title">
				往来明细
				<span class="st-section__sub">选择一条期间汇总行查看该期明细；未选择时展示全部明细</span>
			</h3>
			<el-table :data="details" border stripe class="st-table fin-table--dense" v-loading="loading" highlight-current-row @current-change="onDetailRowChange">
				<el-table-column type="index" label="序号" width="60" align="center" />
				<el-table-column prop="unitName" label="往来单位" min-width="150" show-overflow-tooltip />
				<el-table-column prop="period" label="期间" width="90" align="center">
					<template #default="{ row }">
						<span class="tabular">{{ row.period }}</span>
					</template>
				</el-table-column>
				<el-table-column prop="date" label="单据日期" width="110" align="center">
					<template #default="{ row }">
						<span class="tabular">{{ row.date }}</span>
					</template>
				</el-table-column>
				<el-table-column prop="doc_no" label="单据编号" width="160" />
				<el-table-column prop="doc_type" label="单据类型" width="110" align="center" />
				<el-table-column prop="summary" label="摘要" min-width="200" show-overflow-tooltip />
				<el-table-column label="发生金额" width="130" align="right">
					<template #default="{ row }">
						<span class="tabular" :class="row.amount < 0 ? 'st-neg' : ''">{{ fmt(row.amount) }}</span>
					</template>
				</el-table-column>
				<el-table-column label="已收/已付" width="120" align="right">
					<template #default="{ row }">
						<span class="tabular">{{ fmt(row.settle) }}</span>
					</template>
				</el-table-column>
				<el-table-column label="核对入账" width="100" align="center">
					<template #default="{ row }">
						<el-tag :type="row.matched ? 'success' : 'info'" size="small" effect="plain">{{ row.matched ? '已核对' : '未核对' }}</el-tag>
					</template>
				</el-table-column>
				<template #empty>
					<FinEmpty />
				</template>
			</el-table>
		</div>
	</div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import businessApi from '@/api/business'
import { saveBlob } from '@/utils/fileDownload'
import FinEmpty from '../_FinEmpty.vue'

// 对账状态字典（非数据，仅标签/颜色）
const MOCK_RECON_STATUS = {
	settled: { label: '已对账', type: 'success' },
	partial: { label: '部分对账', type: 'warning' },
	unsettled: { label: '未对账', type: 'info' },
}

const kind = ref('customer')
const loading = ref(false)
const search = reactive({
	unitId: null,
	month: '',
	reconStatus: '',
})
const selectedRow = ref(null)

// 后端返回的真实数据（B3，读 ledger 往来科目）
const units = ref([])
const periodRows = ref([])
const detailRows = ref([])

const kindLabel = computed(() => (kind.value === 'customer' ? '应收' : '应付'))

const unitOptions = computed(() => units.value.filter((u) => u.kind === kind.value))

function onKindChange() {
	search.unitId = null
	search.reconStatus = ''
	selectedRow.value = null
	refresh()
}

function fmt(v) {
	return Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function sumOf(list, key) {
	return list.reduce((s, r) => s + Number(r[key] || 0), 0)
}

const periods = computed(() => {
	let list = periodRows.value
	if (search.unitId) list = list.filter((p) => p.unit_id === search.unitId)
	if (search.month) {
		const m = search.month // YYYY-MM
		list = list.filter((p) => `${p.year}-${String(p.month).padStart(2, '0')}` === m)
	}
	if (search.reconStatus) list = list.filter((p) => p.recon_status === search.reconStatus)
	return list.map((p) => ({
		...p,
		period: `${p.year}-${String(p.month).padStart(2, '0')}`,
		amount: kind.value === 'customer' ? p.receivable : p.payable,
		settle: kind.value === 'customer' ? p.received : p.paid,
	}))
})

const details = computed(() => {
	let list = detailRows.value
	if (search.unitId) list = list.filter((d) => d.unit_id === search.unitId)
	if (search.month) {
		const m = search.month
		list = list.filter((d) => `${d.year}-${String(d.month).padStart(2, '0')}` === m)
	}
	// 选中了汇总行 → 只看该期明细
	if (selectedRow.value) {
		list = list.filter((d) => d.unit_id === selectedRow.value.unit_id && d.year === selectedRow.value.year && d.month === selectedRow.value.month)
	}
	return list.map((d) => ({
		...d,
		period: `${d.year}-${String(d.month).padStart(2, '0')}`,
	}))
})

const unsettledCount = computed(() => periods.value.filter((p) => p.recon_status !== 'settled').length)

function onPeriodRowChange(row) {
	// 选中某期汇总行 → 明细表只看该单位该期的往来单据；取消选择 → 回到全部明细
	selectedRow.value = row || null
}

function onDetailRowChange(row) {
	// 明细行点击仅用于高亮；不反向影响期间汇总的选中态
	void row
}

function markSettled(row) {
	row.recon_status = 'settled'
	row.recon_date = new Date().toISOString().slice(0, 10)
	ElMessage.success(`已将 ${row.period} ${row.unitName} 标记为已对账`)
}

function markUnsettled(row) {
	row.recon_status = 'unsettled'
	row.recon_date = null
	ElMessage.info(`已取消 ${row.period} ${row.unitName} 的对账标记`)
}

function periodSummary({ columns }) {
	return columns.map((col, i) => {
		if (i === 0) return '合计'
		if (col.label === '期初余额') return fmt(sumOf(periods.value, 'opening_balance'))
		if (col.label === '本期应收' || col.label === '本期应付') return fmt(sumOf(periods.value, 'amount'))
		if (col.label === '本期已收' || col.label === '本期已付') return fmt(sumOf(periods.value, 'settle'))
		if (col.label === '期末余额') return fmt(sumOf(periods.value, 'closing_balance'))
		return ''
	})
}

const refresh = async () => {
	loading.value = true
	try {
		const res = await businessApi.finance.statement.get({ kind: kind.value, unit_id: search.unitId || undefined })
		const data = res?.data?.data ?? res?.data ?? {}
		units.value = data.units || []
		periodRows.value = data.periods || []
		detailRows.value = data.details || []
	} catch {
		units.value = []
		periodRows.value = []
		detailRows.value = []
	} finally {
		loading.value = false
	}
}

function resetSearch() {
	search.unitId = null
	search.month = ''
	search.reconStatus = ''
	selectedRow.value = null
	refresh()
}

function toCsv(header, lines) {
	const csv = [header, ...lines].map((r) => r.map((c) => `"${String(c ?? '').replace(/"/g, '""')}"`).join(',')).join('\r\n')
	return '﻿' + csv
}

function exportPeriods() {
	const header = ['期间', '往来单位', '期初余额', '本期应收/应付', '本期已收/已付', '期末余额', '对账状态', '对账日期']
	const lines = periods.value.map((r) => [r.period, r.unitName, r.amount === undefined ? '' : r.opening_balance, r.amount, r.settle, r.closing_balance, MOCK_RECON_STATUS[r.recon_status].label, r.recon_date || ''])
	lines.push(['合计', '', '', '', '', sumOf(periods.value, 'closing_balance'), '', ''])
	saveBlob(new Blob([toCsv(header, lines)], { type: 'text/csv;charset=utf-8' }), `对账单_期间汇总_${kindLabel.value}.csv`)
}

function exportDetails() {
	const header = ['序号', '往来单位', '期间', '单据日期', '单据编号', '单据类型', '摘要', '发生金额', '已收/已付', '核对入账']
	const lines = details.value.map((r, i) => [i + 1, r.unitName, r.period, r.date, r.doc_no, r.doc_type, r.summary, r.amount, r.settle, r.matched ? '已核对' : '未核对'])
	saveBlob(new Blob([toCsv(header, lines)], { type: 'text/csv;charset=utf-8' }), `对账单_往来明细_${kindLabel.value}.csv`)
}

onMounted(refresh)
</script>

<style>
@import '../_shared.css';
</style>

<style scoped>
.st-page {
	height: 100%;
	display: flex;
	flex-direction: column;
	overflow: auto;
}

.st-filter {
	display: flex;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
}

.st-stat {
	display: grid;
	grid-template-columns: repeat(4, 1fr);
	gap: 16px;
	padding: 16px;
}

.st-stat__item {
	background: var(--bg-card);
	border-radius: var(--radius-md);
	padding: 14px 18px;
	box-shadow: var(--shadow-card);
}

.st-stat__label {
	font-size: var(--font-aux);
	color: var(--text-secondary);
}

.st-stat__value {
	margin-top: 6px;
	font-size: var(--font-stat);
	font-weight: 600;
	color: var(--text-primary);
}

.st-section {
	margin: 0 16px 16px;
	background: var(--bg-card);
	border-radius: var(--radius-md);
	padding: 16px;
	box-shadow: var(--shadow-card);
}

.st-section__title {
	margin: 0 0 12px;
	font-size: var(--font-h3);
	font-weight: 600;
	color: var(--text-primary);
	display: flex;
	align-items: baseline;
	gap: 10px;
}

.st-section__sub {
	font-size: var(--font-aux);
	font-weight: 400;
	color: var(--text-secondary);
}

.st-table {
	width: 100%;
}

.st-table :deep(td.el-table__cell) {
	color: var(--text-primary);
	font-size: var(--font-base);
}

.st-table :deep(.el-table__footer td.el-table__cell) {
	color: var(--text-primary);
	font-weight: 600;
}

.st-neg {
	color: var(--color-danger);
}

.st-closing {
	font-weight: 600;
}
</style>