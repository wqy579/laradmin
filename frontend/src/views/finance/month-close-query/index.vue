<template>
	<div class="mcq-page fin-page">
		<!-- 筛选卡 -->
		<div class="mcq-filter fin-filter fin-toolbar">
			<div class="mcq-filter__row">
				<div class="mcq-filter__group">
					<span class="label">会计年</span>
					<el-select v-model="search.year" size="small" style="width: 120px" @change="refresh">
						<el-option v-for="y in YEARS" :key="y.year" :label="y.year" :value="y.year" />
					</el-select>

					<span class="label">会计月</span>
					<el-select v-model="search.month" size="small" style="width: 120px" @change="refresh">
						<el-option v-for="m in MONTHS" :key="m" :label="`${m} 月`" :value="m" />
					</el-select>
				</div>

				<div class="mcq-filter__actions">
					<el-button @click="resetSearch">重置</el-button>
					<el-button type="primary" @click="refresh">查询</el-button>
				</div>
			</div>

			<div class="mcq-filter__bar">
				<div class="mcq-filter__count">共 {{ filtered.length }} 个期间</div>
				<div class="mcq-filter__toolbar">
					<el-button :disabled="filtered.length === 0" @click="exportExcel">导出Excel</el-button>
				</div>
			</div>
		</div>

		<!-- 期间表格 -->
		<div class="mcq-body">
			<el-table :data="paged" v-loading="loading" stripe class="mcq-table">
				<el-table-column prop="period" label="会计期间" width="120" align="center">
					<template #default="{ row }">
						<span class="tabular">{{ row.period }}</span>
					</template>
				</el-table-column>
				<el-table-column label="状态" width="90" align="center">
					<template #default="{ row }">
						<el-tag :type="row.status === 'closed' ? 'success' : 'info'" effect="light" size="small" class="mcq-tag">
							{{ row.status_label }}
						</el-tag>
					</template>
				</el-table-column>
				<el-table-column prop="voucher_count" label="凭证张数" width="100" align="right">
					<template #default="{ row }">
						<span class="tabular">{{ row.voucher_count }}</span>
					</template>
				</el-table-column>
				<el-table-column label="借方合计" width="140" align="right">
					<template #default="{ row }">
						<span class="tabular">{{ fmt(row.total_debit) }}</span>
					</template>
				</el-table-column>
				<el-table-column label="贷方合计" width="140" align="right">
					<template #default="{ row }">
						<span class="tabular">{{ fmt(row.total_credit) }}</span>
					</template>
				</el-table-column>
				<el-table-column label="结账人" width="110" align="left">
					<template #default="{ row }">
						<span class="mcq-closer">{{ row.closed_by_name || '-' }}</span>
					</template>
				</el-table-column>
				<el-table-column label="结账时间" width="160" align="center">
					<template #default="{ row }">
						<span class="tabular mcq-closed-at">{{ row.closed_at || '-' }}</span>
					</template>
				</el-table-column>
				<el-table-column label="操作" width="170" align="right" fixed="right">
					<template #default="{ row }">
						<el-button link type="primary" size="small" @click="viewSnapshot(row)">结账快照</el-button>
						<el-button v-if="row.status === 'closed' && hasPerm('fin:monthclose:reverse')" link type="danger" size="small" @click="onReverse(row)">反月结</el-button>
					</template>
				</el-table-column>

				<!-- 空数据 -->
				<template #empty>
					<FinEmpty />
				</template>
			</el-table>

			<div class="mcq-pager fin-pager">
				<el-pagination
					background
					layout="total, sizes, prev, pager, next, jumper"
					:total="filtered.length"
					:page-sizes="[10, 20, 50, 100]"
					:current-page="page"
					:page-size="pageSize"
					@current-change="(p) => (page = p)"
					@size-change="onSizeChange"
				/>
			</div>
		</div>

		<!-- 结账快照弹窗 -->
		<el-dialog v-model="snapshotVisible" :title="`结账快照 · ${snapshot.period}`" width="560px" class="fin-dialog" :close-on-click-modal="false">
			<div v-loading="snapshotLoading" class="snapshot-body">
				<el-table :data="snapshot.checks" border size="small" stripe class="fin-table--dense">
					<el-table-column prop="title" label="检查项" min-width="180" />
					<el-table-column label="结果" width="90" align="center">
						<template #default="{ row }">
							<el-tag :type="row.passed ? 'success' : row.blocking ? 'danger' : 'warning'" size="small">
								{{ row.passed ? '通过' : row.blocking ? '阻断' : '警告' }}
							</el-tag>
						</template>
					</el-table-column>
					<el-table-column label="明细" min-width="200">
						<template #default="{ row }">{{ row.detail }}</template>
					</el-table-column>
				</el-table>
			</div>
		</el-dialog>
	</div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { usePermission } from '@/composables/usePermission'
import { saveBlob } from '../../../utils/fileDownload'
import businessApi from '@/api/business'
import FinEmpty from '../_FinEmpty.vue'

const { has: hasPerm } = usePermission()

const loading = ref(false)
const page = ref(1)
const pageSize = ref(10)
const YEARS = ref([])
const MONTHS = ref([])
const list = ref([])
const search = reactive({
	year: new Date().getFullYear(),
	month: null,
})

// 结账快照
const snapshotVisible = ref(false)
const snapshotLoading = ref(false)
const snapshot = reactive({ period: '', checks: [] })

function fmt(v) {
	return Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

const filtered = computed(() => {
	let rows = list.value
	if (search.year) rows = rows.filter((p) => p.year === Number(search.year))
	if (search.month) rows = rows.filter((p) => p.month === Number(search.month))
	return rows
})

const paged = computed(() => {
	const start = (page.value - 1) * pageSize.value
	return filtered.value.slice(start, start + pageSize.value)
})

function refresh() {
	loading.value = true
	businessApi.finance.monthClose.periods
		.get()
		.then((res) => {
			const years = res.data.years || []
			YEARS.value = years.map((y) => ({ year: y.year, periods: y.periods }))
			list.value = years.flatMap((y) => y.periods)
			if (!MONTHS.value.length) MONTHS.value = Array.from({ length: 12 }, (_, i) => i + 1)
		})
		.catch(() => ElMessage.error('月结记录加载失败'))
		.finally(() => (loading.value = false))
}

function resetSearch() {
	search.year = new Date().getFullYear()
	search.month = null
	page.value = 1
	refresh()
}

function onSizeChange(size) {
	pageSize.value = size
	page.value = 1
}

function viewSnapshot(row) {
	snapshot.period = row.period
	snapshot.checks = []
	snapshotVisible.value = true
	snapshotLoading.value = true
	businessApi.finance.monthClose.check
		.get(row.period)
		.then((res) => (snapshot.checks = res.data.checks || []))
		.catch(() => ElMessage.error('结账快照加载失败'))
		.finally(() => (snapshotLoading.value = false))
}

function onReverse(row) {
	ElMessageBox.confirm(`反月结将解锁 ${row.year}年${row.month}月，确认？`, '提示', {
		confirmButtonText: '确定',
		cancelButtonText: '取消',
		type: 'warning',
	})
		.then(() => {
			businessApi.finance.monthClose.reopen
				.post(row.period)
				.then(() => {
					ElMessage.success(`${row.year}年${row.month}月已反月结`)
					refresh()
				})
				.catch((err) => ElMessage.error(err?.message || '反月结失败'))
		})
		.catch(() => {})
}

function toCsv(header, lines) {
	const csv = [header, ...lines].map((r) => r.map((c) => `"${String(c ?? '').replace(/"/g, '""')}"`).join(',')).join('\r\n')
	return '﻿' + csv
}

function exportExcel() {
	const header = ['会计期间', '状态', '凭证张数', '借方合计', '贷方合计', '结账人', '结账时间']
	const lines = filtered.value.map((r) => [
		r.period,
		r.status_label,
		r.voucher_count,
		r.total_debit,
		r.total_credit,
		r.closed_by_name || '',
		r.closed_at || '',
	])
	saveBlob(new Blob([toCsv(header, lines)], { type: 'text/csv;charset=utf-8' }), `月结查询_${search.year}.csv`)
}

onMounted(refresh)
</script>

<style>
@import '../_shared.css';
</style>

<style scoped>
.mcq-page {
	height: 100%;
	display: flex;
	flex-direction: column;
	overflow: auto;
	padding: 16px;
	gap: 12px;
}

/* ---- 筛选卡 ---- */
.mcq-filter {
	border-radius: var(--radius-md);
	box-shadow: var(--shadow-card);
}

.mcq-filter__row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
}

.mcq-filter__group {
	display: flex;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
}

.mcq-filter__actions {
	display: flex;
	align-items: center;
	gap: 8px;
}

.mcq-filter__bar {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	margin-top: 12px;
	padding-top: 12px;
	border-top: 1px solid var(--border-lighter);
}

.mcq-filter__count {
	font-size: var(--font-aux, 12px);
	color: var(--text-secondary);
}

.mcq-filter__toolbar {
	display: flex;
	align-items: center;
	gap: 8px;
}

/* ---- 表格区 ---- */
.mcq-body {
	flex: 1;
	min-height: 0;
	display: flex;
	flex-direction: column;
	background: var(--bg-card);
	border-radius: var(--radius-md);
	box-shadow: var(--shadow-card);
	padding: 12px 16px;
}

.mcq-table {
	width: 100%;
}

.mcq-table :deep(td.el-table__cell) {
	color: var(--text-primary);
	font-size: var(--font-base);
}

.mcq-closer {
	color: var(--text-regular);
}

.mcq-closed-at {
	color: var(--text-regular);
}

.mcq-tag {
	height: 22px;
	line-height: 20px;
	border-radius: 4px;
}

/* ---- 结账快照 ---- */
.snapshot-body {
	min-height: 120px;
}
</style>