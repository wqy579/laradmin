<template>
	<div class="page">
		<div class="page-header"><span class="title">财务报表</span></div>
		<el-card shadow="never" class="card">
			<el-tabs v-model="activeTab" class="report-tabs">
				<!-- 资产负债表 -->
				<el-tab-pane label="资产负债表" name="balance">
					<div class="report-filter">
						<span class="lbl">会计期间：</span>
						<el-date-picker v-model="bsPeriod" type="month" value-format="YYYY-MM" placeholder="期间" style="width:120px" @change="loadBalanceSheet" />
						<el-button @click="exportReport('balance_sheet', bsPeriod)">导出</el-button>
						<el-button @click="printReport">打印</el-button>
					</div>
					<div v-if="balanceSheet.diff !== 0" class="warn-hint err">资产负债表不平衡，请检查凭证与结转（差额 {{ fmt(Math.abs(balanceSheet.diff)) }}）</div>
					<div class="bs-wrap" id="bsPrintArea">
						<div class="rpt-title">资产负债表</div>
						<div class="rpt-sub">{{ companyName }} 　编制日期：{{ balanceSheet.report_date }}</div>
						<table class="bs-table">
							<thead>
								<tr>
									<th>资产</th><th>期末余额</th><th>年初余额</th>
									<th>负债和所有者权益</th><th>期末余额</th><th>年初余额</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="i in bsMaxRows" :key="i">
									<td :class="{ bold: bsLeft[i-1]?.is_total }">{{ bsLeft[i-1]?.name || '' }}</td>
									<td class="amt">{{ bsLeft[i-1] ? fmt(bsLeft[i-1].end) : '' }}</td>
									<td class="amt">{{ bsLeft[i-1] ? fmt(bsLeft[i-1].year) : '' }}</td>
									<td :class="{ bold: bsRight[i-1]?.is_total }">{{ bsRight[i-1]?.name || '' }}</td>
									<td class="amt">{{ bsRight[i-1] ? fmt(bsRight[i-1].end) : '' }}</td>
									<td class="amt">{{ bsRight[i-1] ? fmt(bsRight[i-1].year) : '' }}</td>
								</tr>
							</tbody>
						</table>
					</div>
				</el-tab-pane>

				<!-- 利润表 -->
				<el-tab-pane label="利润表" name="income">
					<div class="report-filter">
						<span class="lbl">会计期间：</span>
						<el-date-picker v-model="isPeriod" type="month" value-format="YYYY-MM" placeholder="期间" style="width:120px" @change="loadIncome" />
						<el-button @click="exportReport('income_statement', isPeriod)">导出</el-button>
						<el-button @click="printIncome">打印</el-button>
					</div>
					<div class="rpt-wrap" id="isPrintArea">
						<div class="rpt-title">利润表</div>
						<div class="rpt-sub">{{ companyName }} 　期间：{{ isPeriod }}</div>
						<table class="is-table">
							<thead><tr><th>项目</th><th>本月数</th><th>本年累计数</th></tr></thead>
							<tbody>
								<tr v-for="r in income.rows" :key="r.code" :class="{ bold: r.bold, total: r.is_total }">
									<td :style="{ paddingLeft: r.name.startsWith('   ') ? '20px' : '0' }">{{ r.name.trim() }}</td>
									<td class="amt" :class="profitColor(r.code, r.month)">{{ fmt(r.month) }}</td>
									<td class="amt" :class="profitColor(r.code, r.year)">{{ fmt(r.year) }}</td>
								</tr>
							</tbody>
						</table>
					</div>
				</el-tab-pane>

				<!-- 明细账/日记账 -->
				<el-tab-pane label="明细账/日记账" name="journal">
					<div class="report-filter">
						<span class="lbl">会计科目：</span>
						<el-select v-model="journalSubjectId" filterable placeholder="选择末级科目" style="width:240px" @change="loadJournal">
							<el-option v-for="s in subjectOptions" :key="s.id" :label="`${s.code} ${s.name}`" :value="s.id" />
						</el-select>
						<span class="lbl">期间：</span>
						<el-date-picker v-model="journalPeriod" type="month" value-format="YYYY-MM" placeholder="期间" style="width:120px" @change="loadJournal" />
					</div>
					<div v-if="journal.subject" class="rpt-sub">科目：{{ journal.subject.code }} {{ journal.subject.name }}　期初余额：{{ fmt(journal.opening_balance) }}　期末余额：{{ fmt(journal.closing_balance) }}</div>
					<el-table :data="journal.rows" border stripe size="small" height="500">
						<el-table-column prop="date" label="日期" width="110" align="center" />
						<el-table-column prop="voucher_no" label="凭证字号" width="170" align="center" />
						<el-table-column prop="summary" label="摘要" min-width="200" />
						<el-table-column prop="opposite_subject" label="对方科目" min-width="160" />
						<el-table-column label="借方发生" width="130" align="right"><template #default="{ row }"><span class="tabular">{{ fmt(row.debit) }}</span></template></el-table-column>
						<el-table-column label="贷方发生" width="130" align="right"><template #default="{ row }"><span class="tabular">{{ fmt(row.credit) }}</span></template></el-table-column>
						<el-table-column prop="direction" label="方向" width="80" align="center" />
						<el-table-column label="余额" width="130" align="right"><template #default="{ row }"><span class="tabular">{{ fmt(row.balance) }}</span></template></el-table-column>
					</el-table>
				</el-tab-pane>
			</el-tabs>
		</el-card>
	</div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import ledgerApi from '@/api/ledger'

const activeTab = ref('balance')
const companyName = ref('公司名称')
const bsPeriod = ref('')
const isPeriod = ref('')
const journalSubjectId = ref(null)
const journalPeriod = ref('')
const subjectOptions = ref([])
const balanceSheet = ref({ left: [], right: [], report_date: '', diff: 0 })
const income = ref({ rows: [] })
const journal = ref({ rows: [], subject: null, opening_balance: 0, closing_balance: 0 })

const fmt = (v) => Number(v || 0).toFixed(2)
// 净利润：正红 #F56C6C、负绿 #67C23A（A7 对齐 DS）
const profitColor = (code, val) => {
	if (code !== 'net_profit') return ''
	if (val > 0) return 'red'
	if (val < 0) return 'green'
	return ''
}
const bsLeft = computed(() => balanceSheet.value.left || [])
const bsRight = computed(() => balanceSheet.value.right || [])
const bsMaxRows = computed(() => Math.max(bsLeft.value.length, bsRight.value.length))

const loadBalanceSheet = async () => {
	if (!bsPeriod.value) return
	try { const res = await ledgerApi.report.balanceSheet.get({ period: bsPeriod.value }); balanceSheet.value = res.data || {} }
	catch { ElMessage.error('加载失败') }
}
const loadIncome = async () => {
	if (!isPeriod.value) return
	try { const res = await ledgerApi.report.incomeStatement.get({ period: isPeriod.value }); income.value = res.data || { rows: [] } }
	catch { ElMessage.error('加载失败') }
}
const loadJournal = async () => {
	if (!journalSubjectId.value || !journalPeriod.value) return
	try { const res = await ledgerApi.report.journal.get({ subject_id: journalSubjectId.value, period: journalPeriod.value }); journal.value = res.data || {} }
	catch { ElMessage.error('加载失败') }
}
const exportReport = async (type, period) => {
	try { const blob = await ledgerApi.report.export({ type, period }); const url = URL.createObjectURL(blob); const a = document.createElement('a'); a.href = url; a.download = `ledger_${type}.csv`; a.click(); setTimeout(() => URL.revokeObjectURL(url), 1000) }
	catch { ElMessage.error('导出失败') }
}
const printReport = () => doPrint('bsPrintArea', '资产负债表')
const printIncome = () => doPrint('isPrintArea', '利润表')
const doPrint = (id, title) => {
	const html = document.getElementById(id)?.innerHTML
	const w = window.open('', '_blank', 'width=1000,height=700')
	w.document.write(`<html><head><title>${title}</title><style>
		.rpt-title{font-size:20px;font-weight:700;text-align:center;margin:16px 0;}
		.rpt-sub{font-size:14px;color:#606266;text-align:center;margin-bottom:12px;}
		table{width:100%;border-collapse:collapse;}
		th,td{border:1px solid #303133;padding:6px 8px;font-size:14px;text-align:center;}
		.amt{text-align:right;}
		.bold{font-weight:700;}
		.red{color:#f56c6c;}
		.green{color:#67c23a;}
	</style></head><body style="padding:40px;">${html}</body></html>`)
	w.document.close(); w.focus(); w.print()
}
const loadSubjects = async () => { const res = await ledgerApi.subject.options.get(); subjectOptions.value = res.data?.options || [] }
onMounted(() => {
	bsPeriod.value = new Date().toISOString().slice(0, 7)
	isPeriod.value = new Date().toISOString().slice(0, 7)
	journalPeriod.value = new Date().toISOString().slice(0, 7)
	loadSubjects()
	loadBalanceSheet()
	loadIncome()
})
</script>

<style scoped>
.page { background: var(--bg-body); min-height: 100vh; }
.page-header { height: 50px; background: #fff; border-bottom: 1px solid var(--border-lighter); padding: 0 20px; display: flex; align-items: center; }
.title { font-size: 20px; font-weight: 700; color: #303133; }
.card { margin: 16px 20px; }
.report-tabs :deep(.el-tabs__item) { font-size: 14px; color: #606266; }
.report-tabs :deep(.is-active) { color: #409eff; }
.report-filter { display: flex; gap: 12px; align-items: center; padding: 12px 0; flex-wrap: wrap; }
.lbl { font-size: 14px; color: #606266; }
.rpt-title { font-size: 20px; font-weight: 700; text-align: center; color: #303133; margin: 16px 0; }
.rpt-sub { font-size: 14px; color: #606266; text-align: center; margin-bottom: 12px; }
.bs-table, .is-table { width: 100%; border-collapse: collapse; }
.bs-table th, .bs-table td, .is-table th, .is-table td { border: 1px solid #ebeef5; padding: 8px; font-size: 14px; text-align: center; }
.bs-table th, .is-table th { background: #f5f7fa; color: #606266; font-weight: 600; }
.amt { text-align: right; font-variant-numeric: tabular-nums; font-family: var(--font-numeric); }
.bold { font-weight: 700; }
.total { background: #fafafa; }
.red { color: #f56c6c; }
.green { color: #67c23a; }
.warn-hint { color: #e6a23c; font-size: 14px; margin: 8px 0; padding: 8px 12px; background: #fdf6ec; border-radius: 4px; }
.warn-hint.err { color: #f56c6c; background: #fef0f0; }
</style>
