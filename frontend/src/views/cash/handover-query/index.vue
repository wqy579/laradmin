<template>
	<div class="page">
		<div class="page-header">
			<span class="title">上交款查询</span>
			<el-button type="primary" plain size="small" @click="goForm">
				<el-icon><Plus /></el-icon><span>录入上交款单</span>
			</el-button>
		</div>

		<el-card shadow="never" class="card">
			<div class="filter">
				<el-date-picker
					v-model="dateRange"
					type="daterange"
					value-format="YYYY-MM-DD"
					range-separator="至"
					start-placeholder="开始"
					end-placeholder="结束"
					style="width:250px"
					@change="onDateChange"
				/>
				<el-select v-model="filters.salesman" placeholder="上交人" clearable style="width:120px">
					<el-option v-for="s in SALESMEN" :key="s.value" :label="s.label" :value="s.value" />
				</el-select>
				<el-select v-model="filters.pay_way" placeholder="收款方式" clearable style="width:110px">
					<el-option v-for="p in PAY_WAYS" :key="p.value" :label="p.label" :value="p.value" />
				</el-select>
				<el-select v-model="filters.status" placeholder="确认状态" clearable style="width:110px">
					<el-option label="待确认" value="pending" />
					<el-option label="已确认" value="confirmed" />
				</el-select>
				<el-input v-model="filters.keyword" placeholder="单号 / 客户关键字" clearable style="width:170px" />
				<el-button type="primary" @click="onSearch">查询</el-button>
				<el-button @click="onReset">重置</el-button>
				<el-button @click="onExport">
					<el-icon><Download /></el-icon><span>导出 Excel</span>
				</el-button>
			</div>

			<!-- 统计条 -->
			<div class="stat-strip">
				<div class="stat">
					<div class="stat-label">单据数</div>
					<div class="stat-value tabular">{{ fmtCount(filtered.length) }}</div>
				</div>
				<div class="stat">
					<div class="stat-label">上交金额合计</div>
					<div class="stat-value tabular">¥{{ fmtMoney(sumAmount) }}</div>
				</div>
				<div class="stat">
					<div class="stat-label">待确认</div>
					<div class="stat-value tabular warn">{{ fmtCount(pendingCount) }}</div>
				</div>
				<div class="stat">
					<div class="stat-label">已确认</div>
					<div class="stat-value tabular ok">{{ fmtCount(confirmedCount) }}</div>
				</div>
			</div>

			<el-table
				:data="filtered"
				border
				stripe
				show-summary
				:summary-method="summaryMethod"
				height="calc(100% - 150px)"
				empty-text="暂无上交款单"
			>
				<el-table-column prop="no" label="上交单号" width="140">
					<template #default="{ row }"><span class="tabular">{{ row.no }}</span></template>
				</el-table-column>
				<el-table-column prop="date" label="上交日期" width="110" align="center">
					<template #default="{ row }"><span class="tabular">{{ fmtDay(row.date) }}</span></template>
				</el-table-column>
				<el-table-column label="上交人" width="100" align="center">
					<template #default="{ row }">{{ SM_LABEL[row.salesman] || '—' }}</template>
				</el-table-column>
				<el-table-column label="收款方式" width="100" align="center">
					<template #default="{ row }">{{ PAY_LABEL[row.pay_way] || '—' }}</template>
				</el-table-column>
				<el-table-column prop="order_count" label="订单数" width="80" align="right">
					<template #default="{ row }"><span class="tabular">{{ row.order_count }}</span></template>
				</el-table-column>
				<el-table-column prop="amount" label="金额" width="130" align="right">
					<template #default="{ row }"><span class="tabular amt">{{ fmtMoney(row.amount) }}</span></template>
				</el-table-column>
				<el-table-column label="经手人" width="100" align="center">
					<template #default="{ row }">{{ CS_LABEL[row.cashier] || row.cashier_name || '—' }}</template>
				</el-table-column>
				<el-table-column label="确认状态" width="90" align="center">
					<template #default="{ row }">
						<el-tag :type="HANDOVER_STATUS[row.status]?.tag || 'info'" size="small" effect="light">
							{{ HANDOVER_STATUS[row.status]?.label || row.status }}
						</el-tag>
					</template>
				</el-table-column>
				<el-table-column label="操作" width="120" align="center" fixed="right">
					<template #default="{ row }">
						<el-button link type="primary" size="small" @click="onView(row)">查看</el-button>
						<el-button v-if="row.status === 'pending'" link type="success" size="small" @click="onConfirm(row)">确认</el-button>
					</template>
				</el-table-column>
			</el-table>
		</el-card>

		<!-- 单据详情 -->
		<el-drawer v-model="detailVisible" :title="`上交款单 · ${detailRow?.no || ''}`" size="640px" append-to-body>
			<template v-if="detailRow">
				<el-descriptions :column="2" border size="small">
					<el-descriptions-item label="单号" :span="2"><span class="tabular">{{ detailRow.no }}</span></el-descriptions-item>
					<el-descriptions-item label="上交日期"><span class="tabular">{{ fmtDay(detailRow.date) }}</span></el-descriptions-item>
					<el-descriptions-item label="确认状态">
						<el-tag :type="HANDOVER_STATUS[detailRow.status]?.tag || 'info'" size="small" effect="light">
							{{ HANDOVER_STATUS[detailRow.status]?.label }}
						</el-tag>
					</el-descriptions-item>
					<el-descriptions-item label="上交人">{{ SM_LABEL[detailRow.salesman] || '—' }}</el-descriptions-item>
					<el-descriptions-item label="客户">{{ CU_LABEL[detailRow.customer] || detailRow.customer_name || '—' }}</el-descriptions-item>
					<el-descriptions-item label="收款方式">{{ PAY_LABEL[detailRow.pay_way] || '—' }}</el-descriptions-item>
					<el-descriptions-item label="金额"><span class="tabular">{{ fmtMoney(detailRow.amount) }}</span></el-descriptions-item>
					<el-descriptions-item label="经手人">{{ CS_LABEL[detailRow.cashier] || detailRow.cashier_name || '—' }}</el-descriptions-item>
					<el-descriptions-item label="关联订单数">{{ detailRow.order_count }}</el-descriptions-item>
					<el-descriptions-item label="备注" :span="2">{{ detailRow.remark || '—' }}</el-descriptions-item>
				</el-descriptions>

				<div class="det-sub">关联订单明细</div>
				<el-table v-if="detailRow.items?.length" :data="detailRow.items" border size="small" show-summary :summary-method="itemSummary">
					<el-table-column prop="order_no" label="订单号" width="150">
						<template #default="{ row }"><span class="tabular">{{ row.order_no }}</span></template>
					</el-table-column>
					<el-table-column prop="customer_name" label="客户" />
					<el-table-column label="订单金额" width="108" align="right">
						<template #default="{ row }"><span class="tabular">{{ fmtMoney(row.amount) }}</span></template>
					</el-table-column>
					<el-table-column label="本次上交" width="108" align="right">
						<template #default="{ row }"><span class="tabular">{{ fmtMoney(row.handover_amount) }}</span></template>
					</el-table-column>
				</el-table>
				<div v-else class="empty-note">该单据未保存明细</div>
			</template>
		</el-drawer>
	</div>
</template>

<script setup>
import { computed, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus, Download } from '@element-plus/icons-vue'
import { useRouter } from 'vue-router'
import {
	SALESMEN, PAY_WAYS, SM_LABEL, CU_LABEL, CS_LABEL, PAY_LABEL, HANDOVER_STATUS,
	fmtMoney, fmtCount, fmtDay,
} from '../shared'
import { listHandovers, confirmHandover } from '../store'

const router = useRouter()

const filters = reactive({ salesman: '', pay_way: '', status: '', keyword: '', start_date: '', end_date: '' })
const dateRange = ref([])
const rows = ref(listHandovers())

const filtered = computed(() => {
	const kw = filters.keyword.trim()
	return rows.value.filter((r) => {
		if (filters.salesman && r.salesman !== filters.salesman) return false
		if (filters.pay_way && r.pay_way !== filters.pay_way) return false
		if (filters.status && r.status !== filters.status) return false
		if (filters.start_date && r.date < filters.start_date) return false
		if (filters.end_date && r.date > filters.end_date) return false
		if (kw && !String(r.no).includes(kw) && !(CU_LABEL[r.customer] || '').includes(kw)) return false
		return true
	})
})

const sumAmount = computed(() => filtered.value.reduce((s, r) => s + Number(r.amount || 0), 0))
const pendingCount = computed(() => filtered.value.filter((r) => r.status === 'pending').length)
const confirmedCount = computed(() => filtered.value.filter((r) => r.status === 'confirmed').length)

const onDateChange = (val) => {
	filters.start_date = val?.[0] || ''
	filters.end_date = val?.[1] || ''
}

const summaryMethod = ({ columns, data }) =>
	columns.map((col, i) => {
		if (i === 0) return `合计（${data.length} 单）`
		if (col.property === 'order_count') return fmtCount(data.reduce((s, r) => s + Number(r.order_count || 0), 0))
		if (col.property === 'amount') return '¥' + fmtMoney(data.reduce((s, r) => s + Number(r.amount || 0), 0))
		return ''
	})

const itemSummary = ({ columns, data }) =>
	columns.map((col, i) => {
		if (i === 0) return '合计'
		if (col.property === 'amount') return fmtMoney(data.reduce((s, r) => s + Number(r.amount || 0), 0))
		if (col.label === '本次上交') return fmtMoney(data.reduce((s, r) => s + Number(r.handover_amount || 0), 0))
		return ''
	})

const onSearch = () => ElMessage.success(`查询到 ${filtered.value.length} 单，合计 ¥${fmtMoney(sumAmount.value)}`)
const onReset = () => {
	Object.assign(filters, { salesman: '', pay_way: '', status: '', keyword: '', start_date: '', end_date: '' })
	dateRange.value = []
}

/* 明细行内联导出（无需文件保存弹窗） */
const onExport = () => {
	const list = filtered.value
	if (!list.length) return ElMessage.warning('当前无数据可导出')
	const head = ['上交单号', '上交日期', '上交人', '收款方式', '订单数', '金额', '经手人', '确认状态']
	const lines = [head.join('\t')]
	list.forEach((r) =>
		lines.push([
			r.no, r.date, SM_LABEL[r.salesman] || '', PAY_LABEL[r.pay_way] || '',
			r.order_count, Number(r.amount).toFixed(2), CS_LABEL[r.cashier] || '',
			HANDOVER_STATUS[r.status]?.label || r.status,
		].join('\t'))
	)
	lines.push(['合计', '', '', '', list.reduce((s, r) => s + Number(r.order_count || 0), 0), sumAmount.value.toFixed(2), '', ''].join('\t'))
	const blob = new Blob(['' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' })
	const url = URL.createObjectURL(blob)
	const a = document.createElement('a')
	a.href = url
	a.download = `上交款查询_${Date.now()}.csv`
	a.click()
	setTimeout(() => URL.revokeObjectURL(url), 1000)
	ElMessage.success(`已导出 ${list.length} 条记录`)
}

/* ---------- 操作 ---------- */
const detailVisible = ref(false)
const detailRow = ref(null)
const onView = (row) => {
	detailRow.value = row
	detailVisible.value = true
}

const onConfirm = (row) => {
	ElMessageBox.confirm(
		`确认上交款单 ${row.no}？金额 ¥${fmtMoney(row.amount)}，确认后进入财务确认流程且不可再修改。`,
		'确认上交款',
		{ type: 'warning', confirmButtonText: '确认并入库', cancelButtonText: '取消', dangerouslyUseHTMLString: false }
	).then(() => {
		const updated = confirmHandover(row.id)
		rows.value = listHandovers()
		if (updated) {
			detailRow.value = { ...updated }
			ElMessage.success(`单号 ${updated.no} 已确认，进财务确认流程`)
		} else {
			ElMessage.info('该单已确认，无需重复操作')
		}
	}).catch(() => {})
}

const goForm = () => router.push('/cash/handover')
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

.det-sub { margin: var(--space-lg) 0 var(--space-sm); font-size: var(--font-h3); font-weight: 600; color: var(--text-primary); }
.empty-note { padding: var(--space-xl) 0; text-align: center; color: var(--text-secondary); font-size: var(--font-base); }
</style>
