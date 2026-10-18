<template>
	<div class="mcq-page">
		<!-- 筛选卡 -->
		<div class="mcq-filter">
			<div class="mcq-filter__row">
				<div class="mcq-filter__group">
					<span class="mcq-filter__label">会计年</span>
					<el-select v-model="search.year" size="small" style="width: 120px" @change="refresh">
						<el-option v-for="y in YEARS" :key="y" :label="y" :value="y" />
					</el-select>

					<span class="mcq-filter__label">会计月</span>
					<el-select v-model="search.month" size="small" style="width: 120px" @change="refresh">
						<el-option v-for="m in MONTHS" :key="m" :label="`${m} 月`" :value="m" />
					</el-select>
				</div>

				<div class="mcq-filter__actions">
					<el-button size="small" @click="resetSearch">重置</el-button>
					<el-button type="primary" size="small" @click="refresh">查询</el-button>
				</div>
			</div>

			<div class="mcq-filter__bar">
				<div class="mcq-filter__count">共 {{ filtered.length }} 个期间</div>
				<div class="mcq-filter__toolbar">
					<el-button size="small" :disabled="filtered.length === 0" @click="exportExcel">导出Excel</el-button>
				</div>
			</div>
		</div>

		<!-- 期间表格 -->
		<div class="mcq-body">
			<el-table :data="paged" v-loading="loading" class="mcq-table" :empty-text="emptyText">
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
				<el-table-column label="操作" width="100" align="right" fixed="right">
					<template #default="{ row }">
						<el-button link type="primary" size="small" @click="viewVouchers(row)">查看凭证</el-button>
						<el-button v-if="row.status === 'closed' && hasPerm('fin:monthclose:reverse')" link type="danger" size="small" @click="onReverse(row)">反月结</el-button>
					</template>
				</el-table-column>

				<!-- 空数据 -->
				<template #empty>
					<div class="mcq-empty">
						<el-empty description="暂无数据" :image-size="90">
							<template #description>
								<span class="mcq-empty__text">暂无数据</span>
							</template>
						</el-empty>
					</div>
				</template>
			</el-table>

			<div class="mcq-pager">
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
	</div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { usePermission } from '@/composables/usePermission'
import { YEARS, MONTHS, PERIODS } from '../../../mock/month-close'
import { saveBlob } from '../../../utils/fileDownload'

const { has: hasPerm } = usePermission()
const router = useRouter()

const loading = ref(false)
const page = ref(1)
const pageSize = ref(10)
const search = reactive({
	year: new Date().getFullYear(),
	month: new Date().getMonth() + 1,
})

const emptyText = ref('')

function fmt(v) {
	return Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

const filtered = computed(() => {
	let list = PERIODS
	if (search.year) list = list.filter((p) => p.year === Number(search.year))
	if (search.month) list = list.filter((p) => p.month === Number(search.month))
	return list.map((p) => ({
		...p,
		period: `${p.year}-${String(p.month).padStart(2, '0')}`,
	}))
})

const paged = computed(() => {
	const start = (page.value - 1) * pageSize.value
	return filtered.value.slice(start, start + pageSize.value)
})

function refresh() {
	loading.value = true
	// TODO(api): 替换为 GET /api/finance/periods 接口
	setTimeout(() => {
		loading.value = false
	}, 150)
}

function resetSearch() {
	search.year = new Date().getFullYear()
	search.month = new Date().getMonth() + 1
	page.value = 1
	refresh()
}

function onSizeChange(size) {
	pageSize.value = size
	page.value = 1
}

function viewVouchers(row) {
	// 跳转凭证页并携带该期间筛选参数；凭证页可能尚未实现，仅做路由跳转。
	router.push({ path: '/finance/voucher', query: { period: row.period } })
}

function onReverse(row) {
	ElMessageBox.confirm(`反月结将解锁 ${row.year}年${row.month}月，确认？`, '提示', {
		confirmButtonText: '确定',
		cancelButtonText: '取消',
		type: 'warning',
	})
		.then(() => {
			// TODO(api): 替换为 POST /api/finance/periods/{id}/reverse 接口
			row.status = 'open'
			row.status_label = '未结账'
			row.closed_by_name = ''
			row.closed_at = ''
			ElMessage.success(`${row.year}年${row.month}月已反月结`)
		})
		.catch(() => {
			// 取消：无操作
		})
}

function toCsv(header, lines) {
	const csv = [header, ...lines].map((r) => r.map((c) => `"${String(c ?? '').replace(/"/g, '""')}"`).join(',')).join('\r\n')
	return '﻿' + csv
}

function exportExcel() {
	// TODO(api): 替换为 GET /api/finance/periods/export 接口
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
	background: var(--bg-card);
	border-radius: var(--radius-md);
	box-shadow: var(--shadow-card);
	padding: 12px 16px;
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

.mcq-filter__label {
	font-size: var(--font-base, 13px);
	color: var(--text-regular);
	white-space: nowrap;
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

.mcq-table :deep(th.el-table__cell) {
	background: var(--bg-hover);
	color: var(--text-regular);
	font-weight: 600;
	font-size: var(--font-base, 13px);
	height: 44px;
}

.mcq-table :deep(td.el-table__cell) {
	color: var(--text-primary);
	font-size: var(--font-base, 13px);
	height: 40px;
}

.mcq-table :deep(.el-table__row) {
	height: 40px;
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

/* ---- 空数据 ---- */
.mcq-empty {
	height: 200px;
	display: flex;
	align-items: center;
	justify-content: center;
}

.mcq-empty__text {
	font-size: var(--font-base, 13px);
	color: var(--text-secondary);
}

/* ---- 分页 ---- */
.mcq-pager {
	display: flex;
	justify-content: flex-end;
	padding-top: 12px;
}
</style>