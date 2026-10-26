<template>
	<div class="page">
		<div class="page-header">
			<span class="title">现金账户收支查询</span>
		</div>

		<el-card shadow="never" class="card">
			<div class="filter">
				<el-select v-model="filters.accId" placeholder="资金账户" clearable style="width:160px" @change="onAccChange">
					<el-option v-for="a in accounts" :key="a.id" :label="a.name" :value="a.id" />
				</el-select>
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
				<el-select v-model="filters.type" placeholder="收支类型" clearable style="width:120px">
					<el-option label="全部" value="" />
					<el-option label="收入" value="income" />
					<el-option label="支出" value="expense" />
				</el-select>
				<el-button type="primary" @click="onSearch">查询</el-button>
				<el-button @click="onReset">重置</el-button>
				<el-button @click="onExport">
					<el-icon><Download /></el-icon><span>导出 Excel</span>
				</el-button>
			</div>

			<el-table
				:data="filtered"
				border
				show-summary
				:summary-method="summaryMethod"
				height="calc(100% - 150px)"
				empty-text="暂无流水数据"
			>
				<el-table-column prop="date" label="日期" width="110" align="center">
					<template #default="{ row }"><span class="tabular">{{ row.date }}</span></template>
				</el-table-column>
				<el-table-column prop="no" label="单据号" width="150">
					<template #default="{ row }"><span class="tabular">{{ row.no }}</span></template>
				</el-table-column>
				<el-table-column prop="summary" label="摘要" min-width="200" show-overflow-tooltip />
				<el-table-column prop="income" label="收入" width="130" align="right">
					<template #default="{ row }">
						<span class="tabular amt-ok">{{ fmtMoney(row.income) }}</span>
					</template>
				</el-table-column>
				<el-table-column prop="expense" label="支出" width="130" align="right">
					<template #default="{ row }">
						<span class="tabular amt-warn">{{ fmtMoney(row.expense) }}</span>
					</template>
				</el-table-column>
				<el-table-column prop="balance" label="余额" width="130" align="right">
					<template #default="{ row }">
						<span class="tabular">{{ fmtMoney(row.balance) }}</span>
					</template>
				</el-table-column>
			</el-table>
		</el-card>
	</div>
</template>

<script setup>
import { computed, reactive, ref, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import { Download } from '@element-plus/icons-vue'
import { MOCK_CASH_ACCOUNTS, MOCK_ACCOUNT_FLOWS, MOCK_ACCOUNT_OPENING } from '../../../mock/cash-account-flow'
import { fmtMoney } from '../shared'

const accounts = MOCK_CASH_ACCOUNTS
const filters = reactive({ accId: '', type: '', start_date: '', end_date: '' })
const dateRange = ref([])
const rows = ref([])

const onDateChange = (val) => {
	filters.start_date = val?.[0] || ''
	filters.end_date = val?.[1] || ''
}

const onAccChange = () => {
	refresh()
}

const filtered = computed(() => {
	let list = rows.value.filter(r => r.accId === filters.accId)
	
	if (filters.start_date) list = list.filter(r => r.date >= filters.start_date)
	if (filters.end_date) list = list.filter(r => r.date <= filters.end_date)
	if (filters.type === 'income') list = list.filter(r => r.income > 0)
	if (filters.type === 'expense') list = list.filter(r => r.expense > 0)
	
	return list
})

const summaryMethod = ({ columns, data }) =>
	columns.map((col, i) => {
		if (i === 0) return `合计（${data.length} 条）`
		if (col.property === 'income') return '¥' + fmtMoney(data.reduce((s, r) => s + Number(r.income || 0), 0))
		if (col.property === 'expense') return '¥' + fmtMoney(data.reduce((s, r) => s + Number(r.expense || 0), 0))
		return ''
	})

function refresh() {
	if (!filters.accId) {
		rows.value = []
		return
	}
	
	// 模拟 API: GET /api/cash/accounts/{id}/flows
	const accFlows = MOCK_ACCOUNT_FLOWS.filter(f => f.accId === filters.accId)
		.sort((a, b) => a.date.localeCompare(b.date))
	
	// 滚动计算余额
	let currentBal = MOCK_ACCOUNT_OPENING[filters.accId] || 0
	const result = accFlows.map(f => {
		currentBal += (f.income || 0) - (f.expense || 0)
		return { ...f, balance: currentBal }
	})
	
	rows.value = result
}

const onSearch = () => {
	refresh()
	ElMessage.success(`查询到 ${filtered.value.length} 条流水`)
}

const onReset = () => {
	Object.assign(filters, { accId: '', type: '', start_date: '', end_date: '' })
	dateRange.value = []
	refresh()
}

const onExport = () => {
	const list = filtered.value
	if (!list.length) return ElMessage.warning('当前无数据可导出')
	const head = ['日期', '单据号', '摘要', '收入', '支出', '余额']
	const lines = [head.join('\t')]
	list.forEach(r => {
		lines.push([r.date, r.no, r.summary, r.income, r.expense, r.balance].join('\t'))
	})
	const blob = new Blob(['\ufeff' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' })
	const url = URL.createObjectURL(blob)
	const a = document.createElement('a')
	a.href = url
	a.download = `账户流水_${Date.now()}.csv`
	a.click()
	setTimeout(() => URL.revokeObjectURL(url), 1000)
	ElMessage.success('导出成功')
}

onMounted(() => {
	filters.accId = accounts[0].id
	refresh()
})
</script>

<style scoped>
.page { background: var(--bg-body); min-height: 100vh; display: flex; flex-direction: column; }
.page-header { height: 50px; background: var(--bg-card); border-bottom: 1px solid var(--border-lighter); padding: 0 var(--space-xl); display: flex; align-items: center; justify-content: space-between; }
.title { font-size: var(--font-h1); font-weight: 600; color: var(--text-primary); }
.card { flex: 1; margin: var(--space-lg) var(--space-xl); display: flex; flex-direction: column; }
.filter { display: flex; gap: var(--space-sm); flex-wrap: wrap; padding-bottom: var(--space-md); }
.amt-ok { color: var(--color-success); font-weight: 500; }
.amt-warn { color: var(--color-danger); font-weight: 500; }
</style>
