<template>
	<div class="page">
		<div class="page-header">
			<span class="title">刷卡收款记录</span>
			<el-button @click="onExport">
				<el-icon><Download /></el-icon><span>导出 Excel</span>
			</el-button>
		</div>

		<el-card shadow="never" class="card">
			<div class="filter">
				<el-date-picker
					v-model="dateRange"
					type="daterange"
					value-format="YYYY-MM-DD"
					range-separator="至"
					start-placeholder="开始日期"
					end-placeholder="结束日期"
					style="width:240px"
					@change="onDateChange"
				/>
				<el-select v-model="filters.customer" placeholder="客户" clearable filterable style="width:140px">
					<el-option v-for="c in CUSTOMERS" :key="c.value" :label="c.label" :value="c.value" />
				</el-select>
				<el-select v-model="filters.salesman" placeholder="业务员" clearable style="width:120px">
					<el-option v-for="s in SALESMEN" :key="s.value" :label="s.label" :value="s.value" />
				</el-select>
				<el-input v-model="filters.last4" placeholder="卡号后四位" clearable maxlength="4" class="tabular-input" style="width:110px" />
				<el-select v-model="filters.status" placeholder="状态" clearable style="width:110px">
					<el-option label="成功" value="success" />
					<el-option label="失败" value="fail" />
					<el-option label="退款" value="refund" />
				</el-select>
				<el-button type="primary" @click="onSearch">查询</el-button>
				<el-button @click="onReset">重置</el-button>
			</div>

			<div class="stat-strip">
				<div class="stat">
					<div class="stat-label">记录数</div>
					<div class="stat-value tabular">{{ fmtCount(filtered.length) }}</div>
				</div>
				<div class="stat">
					<div class="stat-label">成功收款</div>
					<div class="stat-value tabular ok">¥{{ fmtMoney(successAmount) }}</div>
				</div>
				<div class="stat">
					<div class="stat-label">失败笔数</div>
					<div class="stat-value tabular warn">{{ fmtCount(failCount) }}</div>
				</div>
				<div class="stat">
					<div class="stat-label">手续费合计</div>
					<div class="stat-value tabular">¥{{ fmtMoney(feeSum) }}</div>
				</div>
			</div>

			<el-table
				:data="filtered"
				border
				stripe
				show-summary
				:summary-method="summaryMethod"
				empty-text="暂无刷卡记录"
			>
				<el-table-column prop="serial_no" label="流水号" width="150">
					<template #default="{ row }"><span class="tabular">{{ row.serial_no }}</span></template>
				</el-table-column>
				<el-table-column prop="txn_time" label="刷卡日期时间" width="160" align="center">
					<template #default="{ row }"><span class="tabular">{{ fmtDateTime(row.txn_time) }}</span></template>
				</el-table-column>
				<el-table-column label="客户" width="160" show-overflow-tooltip>
					<template #default="{ row }">{{ row.customer_name }}</template>
				</el-table-column>
				<el-table-column label="业务员" width="100" align="center">
					<template #default="{ row }">{{ row.salesman_name }}</template>
				</el-table-column>
				<el-table-column label="卡类型" width="90" align="center">
					<template #default="{ row }">{{ CARD_TYPE_LABEL[row.card_type] || '—' }}</template>
				</el-table-column>
				<el-table-column label="卡号后四位" width="100" align="center">
					<template #default="{ row }"><span class="tabular">{{ maskCard(row.card_no || row.card_last4) }}</span></template>
				</el-table-column>
				<el-table-column label="金额" width="130" align="right">
					<template #default="{ row }">
						<span class="tabular amt" :class="row.amount < 0 ? 'neg' : ''">{{ fmtMoney(row.amount) }}</span>
					</template>
				</el-table-column>
				<el-table-column label="手续费" width="60" align="right">
					<template #default="{ row }"><span class="tabular">{{ fmtMoney(row.fee) }}</span></template>
				</el-table-column>
				<el-table-column label="状态" width="90" align="center">
					<template #default="{ row }">
						<el-tag :type="CARD_STATUS[row.status]?.tag || 'info'" size="small" effect="light">
							{{ CARD_STATUS[row.status]?.label || row.status }}
						</el-tag>
					</template>
				</el-table-column>
				<el-table-column label="操作" width="90" align="center" fixed="right">
					<template #default="{ row }">
						<el-button
							v-if="canRefund(row)"
							link
							type="danger"
							size="small"
							@click="onRefund(row)"
						>退款</el-button>
						<span v-else class="dash">—</span>
					</template>
				</el-table-column>
			</el-table>
		</el-card>
	</div>
</template>

<script setup>
import { computed, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Download } from '@element-plus/icons-vue'
import {
	CUSTOMERS, SALESMEN, CARD_STATUS, CARD_TYPE_LABEL,
	fmtMoney, fmtCount, fmtDateTime, maskCard, today,
} from '@/mock/card'
import { listCardTxns, refundCardTxn } from '@/mock/card'

const filters = reactive({ customer: '', salesman: '', last4: '', status: '', start_date: '', end_date: '' })
const dateRange = ref([])
const rows = ref(listCardTxns())

const filtered = computed(() => {
	const last4 = filters.last4.trim()
	return rows.value.filter((r) => {
		const d = String(r.txn_time).slice(0, 10)
		if (filters.customer && r.customer !== filters.customer) return false
		if (filters.salesman && r.salesman !== filters.salesman) return false
		if (filters.status && r.status !== filters.status) return false
		if (last4 && !String(r.card_last4).endsWith(last4)) return false
		if (filters.start_date && d < filters.start_date) return false
		if (filters.end_date && d > filters.end_date) return false
		return true
	})
})

const successAmount = computed(() => filtered.value.reduce((s, r) => s + (r.status === 'success' ? Number(r.amount || 0) : 0), 0))
const failCount = computed(() => filtered.value.filter((r) => r.status === 'fail').length)
const feeSum = computed(() => filtered.value.reduce((s, r) => s + Math.abs(Number(r.fee || 0)), 0))

const onDateChange = (val) => {
	filters.start_date = val?.[0] || ''
	filters.end_date = val?.[1] || ''
}

const summaryMethod = ({ columns, data }) =>
	columns.map((col, i) => {
		if (i === 0) return `合计（${data.length} 笔）`
		if (col.label === '金额') return '¥' + fmtMoney(data.reduce((s, r) => s + Number(r.amount || 0), 0))
		if (col.label === '手续费') return '¥' + fmtMoney(data.reduce((s, r) => s + Number(r.fee || 0), 0))
		return ''
	})

const onSearch = () => ElMessage.success(`查询到 ${filtered.value.length} 笔，成功收款 ¥${fmtMoney(successAmount.value)}`)
const onReset = () => {
	Object.assign(filters, { customer: '', salesman: '', last4: '', status: '', start_date: '', end_date: '' })
	dateRange.value = []
}

/* 退款条件：刷卡成功且为当日流水 */
const canRefund = (row) => row.status === 'success' && String(row.txn_time).slice(0, 10) === today()

const onRefund = (row) => {
	ElMessageBox.confirm(
		`确定对流水 ${row.serial_no} 执行退款？退款金额 ¥${fmtMoney(-Number(row.amount))}，退款后状态变更为「退款」。`,
		'刷卡退款',
		{ type: 'warning', confirmButtonText: '确认退款', cancelButtonText: '取消' }
	).then(() => {
		const updated = refundCardTxn(row.id)
		rows.value = listCardTxns()
		if (updated) ElMessage.success(`流水 ${updated.serial_no} 已退款 ¥${fmtMoney(-Number(updated.amount))}`)
		else ElMessage.info('该流水当前状态不可退款')
	}).catch(() => {})
}

const onExport = () => {
	const list = filtered.value
	if (!list.length) return ElMessage.warning('当前无数据可导出')
	const head = ['流水号', '刷卡日期时间', '客户', '业务员', '卡类型', '卡号后四位', '金额', '手续费', '状态']
	const lines = [head.join('\t')]
	list.forEach((r) =>
		lines.push([
			r.serial_no, fmtDateTime(r.txn_time), r.customer_name, r.salesman_name,
			CARD_TYPE_LABEL[r.card_type] || '', maskCard(r.card_no || r.card_last4),
			Number(r.amount).toFixed(2), Number(r.fee || 0).toFixed(2),
			CARD_STATUS[r.status]?.label || r.status,
		].join('\t'))
	)
	lines.push(['合计', '', '', '', '', '', list.reduce((s, r) => s + Number(r.amount || 0), 0).toFixed(2), list.reduce((s, r) => s + Number(r.fee || 0), 0).toFixed(2), ''].join('\t'))
	const blob = new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' })
	const url = URL.createObjectURL(blob)
	const a = document.createElement('a')
	a.href = url
	a.download = `刷卡收款记录_${Date.now()}.csv`
	a.click()
	setTimeout(() => URL.revokeObjectURL(url), 1000)
	ElMessage.success(`已导出 ${list.length} 条记录`)
}
</script>

<style scoped>
.page { background: var(--bg-body); min-height: 100vh; display: flex; flex-direction: column; }
.page-header { height: 50px; background: var(--bg-card); border-bottom: 1px solid var(--border-lighter); padding: 0 var(--space-xl); display: flex; align-items: center; justify-content: space-between; }
.title { font-size: var(--font-h1); font-weight: 600; color: var(--text-primary); }
.card { flex: 1; margin: var(--space-lg) var(--space-xl); display: flex; flex-direction: column; }

.filter { display: flex; gap: var(--space-sm); flex-wrap: wrap; padding-bottom: var(--space-md); }

.stat-strip { display: flex; gap: var(--space-lg); margin-bottom: var(--space-md); }
.stat { flex: 1; padding: var(--space-sm) var(--space-md); background: var(--bg-hover); border-radius: var(--radius-md); }
.stat-label { font-size: var(--font-aux); color: var(--text-secondary); line-height: 18px; }
.stat-value { font-size: var(--font-stat); font-weight: 600; color: var(--text-primary); line-height: 32px; }
.stat-value.warn { color: var(--color-warning); }
.stat-value.ok { color: var(--color-success); }

.amt { color: var(--text-primary); font-weight: 500; }
.amt.neg { color: var(--color-danger); }
.dash { color: var(--text-placeholder); }
</style>