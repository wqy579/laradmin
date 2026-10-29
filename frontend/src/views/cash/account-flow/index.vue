<template>
	<div class="fin-page page">
		<div class="page-header">
			<span class="title">现金账户收支查询</span>
		</div>

		<el-card shadow="never" class="card">
			<div class="filter">
				<span class="label">资金账户</span>
				<el-select v-model="filters.accId" placeholder="资金账户" style="width:160px" @change="onAccChange">
					<el-option v-for="a in accounts" :key="a.id" :label="a.name" :value="a.id" />
				</el-select>
				<span class="label">日期</span>
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
				<span class="label">收支类型</span>
				<el-select v-model="filters.direction" placeholder="收支类型" clearable style="width:120px">
					<el-option label="收入" value="income" />
					<el-option label="支出" value="expense" />
				</el-select>
				<el-button type="primary" :loading="loading" @click="onSearch">查询</el-button>
				<el-button @click="onReset">重置</el-button>
				<el-button :disabled="!total" @click="onExport">
					<el-icon><Download /></el-icon><span>导出 Excel</span>
				</el-button>
			</div>

			<el-table
				v-loading="loading"
				:data="rows"
				border
				class="fin-table--dense"
				show-summary
				:summary-method="summaryMethod"
				height="calc(100% - 195px)"
			>
				<template #empty><FinEmpty text="暂无流水数据" /></template>
				<el-table-column prop="date" label="日期" width="110" align="center">
					<template #default="{ row }"><span class="tabular">{{ row.date }}</span></template>
				</el-table-column>
				<el-table-column prop="no" label="单据号" width="150">
					<template #default="{ row }"><span class="tabular">{{ row.no }}</span></template>
				</el-table-column>
				<el-table-column prop="summary" label="摘要" min-width="200" show-overflow-tooltip />
				<el-table-column label="方向" width="80" align="center">
					<template #default="{ row }">
						<span class="dir-tag" :class="row.direction === 'income' ? 'dir-in' : 'dir-out'">
							{{ row.direction === 'income' ? '收入' : '支出' }}
						</span>
					</template>
				</el-table-column>
				<el-table-column prop="income" label="收入" width="130" align="right" header-align="right">
					<template #default="{ row }">
						<span class="tabular amt-ok">{{ row.income ? fmtMoney(row.income) : '' }}</span>
					</template>
				</el-table-column>
				<el-table-column prop="expense" label="支出" width="130" align="right" header-align="right">
					<template #default="{ row }">
						<span class="tabular amt-warn">{{ row.expense ? fmtMoney(row.expense) : '' }}</span>
					</template>
				</el-table-column>
				<el-table-column prop="balance" label="余额" width="130" align="right" header-align="right">
					<template #default="{ row }">
						<span class="tabular">{{ fmtMoney(row.balance) }}</span>
					</template>
				</el-table-column>
			</el-table>

			<div class="pager fin-pager">
				<el-pagination
					v-model:current-page="page"
					v-model:page-size="pageSize"
					:page-sizes="[20, 50, 100]"
					:total="total"
					layout="total, sizes, prev, pager, next, jumper"
					@size-change="load"
					@current-change="load"
				/>
			</div>
		</el-card>
	</div>
</template>

<script setup>
import { reactive, ref, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import { Download } from '@element-plus/icons-vue'
import cashApi from '@/api/cash'
import { saveBlob } from '@/utils/fileDownload'
import { fmtMoney } from '../shared'
import FinEmpty from '../../finance/_FinEmpty.vue'

const accounts = ref([])
const filters = reactive({ accId: '', direction: '', start_date: '', end_date: '' })
const dateRange = ref([])
const rows = ref([])
const total = ref(0)
const incomeTotal = ref(0)
const expenseTotal = ref(0)
const page = ref(1)
const pageSize = ref(20)
const loading = ref(false)

const onDateChange = (val) => {
	filters.start_date = val?.[0] || ''
	filters.end_date = val?.[1] || ''
}

const onAccChange = () => {
	page.value = 1
	load()
}

// 合计行取服务端全窗口口径（不受分页影响），余额列不合计
const summaryMethod = ({ columns }) =>
	columns.map((col, i) => {
		if (i === 0) return `合计（${total.value} 条）`
		if (col.property === 'income') return '¥' + fmtMoney(incomeTotal.value)
		if (col.property === 'expense') return '¥' + fmtMoney(expenseTotal.value)
		return ''
	})

async function load() {
	if (!filters.accId) {
		rows.value = []
		total.value = 0
		return
	}
	loading.value = true
	try {
		const res = await cashApi.account.flows.get(filters.accId, {
			start_date: filters.start_date,
			end_date: filters.end_date,
			direction: filters.direction,
			page: page.value,
			page_size: pageSize.value,
		})
		const data = res.data || {}
		rows.value = data.list || []
		total.value = data.total || 0
		incomeTotal.value = data.income_total || 0
		expenseTotal.value = data.expense_total || 0
	} catch (e) {
		ElMessage.error(e.message || '查询失败')
	} finally {
		loading.value = false
	}
}

const onSearch = () => {
	page.value = 1
	load()
}

const onReset = () => {
	Object.assign(filters, { direction: '', start_date: '', end_date: '' })
	dateRange.value = []
	page.value = 1
	load()
}

const onExport = async () => {
	try {
		const blob = await cashApi.account.export(filters.accId, {
			start_date: filters.start_date,
			end_date: filters.end_date,
			direction: filters.direction,
		})
		saveBlob(blob, `账户流水_${Date.now()}.csv`)
		ElMessage.success('导出成功')
	} catch (e) {
		ElMessage.error(e.message || '导出失败')
	}
}

onMounted(async () => {
	try {
		const res = await cashApi.account.options.get()
		accounts.value = res.data?.list || []
		if (accounts.value.length) {
			filters.accId = accounts.value[0].id
			load()
		}
	} catch (e) {
		ElMessage.error(e.message || '账户加载失败')
	}
})
</script>

<style>
@import '../../finance/_shared.css';
</style>

<style scoped>
.page { background: var(--bg-body); min-height: 100vh; display: flex; flex-direction: column; }
.page-header { height: 50px; background: var(--bg-card); border-bottom: 1px solid var(--border-lighter); padding: 0 var(--space-xl); display: flex; align-items: center; justify-content: space-between; }
.title { font-size: var(--font-h1); font-weight: 600; color: var(--text-primary); }
.card { flex: 1; margin: var(--space-lg) var(--space-xl); display: flex; flex-direction: column; }
.filter { display: flex; gap: var(--space-sm); flex-wrap: wrap; padding-bottom: var(--space-md); }
/* DS：筛选区标签 12px #606266 */
.filter .label {
	font-size: var(--font-aux);
	color: var(--text-regular);
	display: inline-flex;
	align-items: center;
	white-space: nowrap;
}
.pager { display: flex; justify-content: flex-end; padding-top: var(--space-md); }
.amt-ok { color: var(--color-success); font-weight: 500; }
.amt-warn { color: var(--color-danger); font-weight: 500; }
/* 连凯对标：方向 Tag 收入红 #F56C6C / 支出绿 #67C23A，自绘以锁精确色值 */
.dir-tag { display: inline-block; padding: 0 8px; height: 22px; line-height: 22px; font-size: 12px; border-radius: 3px; color: #fff; }
.dir-in { background: #F56C6C; }
.dir-out { background: #67C23A; }
/* D3 简报口径：表头 44px 13px/600，明细行 41px（覆盖 _shared.css 的 14px/500 + 48px） */
.card :deep(.el-table.fin-table--dense th.el-table__cell .cell) {
	font-size: var(--font-base);
	font-weight: 600;
}
.card :deep(.el-table.fin-table--dense .el-table__body .el-table__row) {
	height: 41px;
}
.card :deep(.el-table .el-table__body td.el-table__cell .cell) {
	font-size: var(--font-base);
	color: var(--text-primary);
}
</style>
