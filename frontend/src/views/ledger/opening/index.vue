<template>
	<div class="page">
		<div class="page-header">
			<span class="title">期初余额</span>
		</div>
		<el-card shadow="never" class="card">
			<div class="filter">
				<span class="lbl">会计期间：</span>
				<el-date-picker v-model="period" type="month" placeholder="选择期间" value-format="YYYY-MM" style="width:140px" @change="load" />
				<el-button type="primary" @click="trialBalance">试算平衡</el-button>
				<el-button type="primary" @click="save">保存</el-button>
				<div class="balance-hint" :class="total.balanced ? 'ok' : 'err'">
					{{ total.message }}
				</div>
			</div>
			<el-table :data="leafRows" border stripe v-loading="loading" height="600" :row-class-name="rowClass" show-summary :summary-method="summary">
				<el-table-column prop="code" label="科目编码" width="140" fixed />
				<el-table-column prop="name" label="科目名称" min-width="220" />
				<el-table-column label="科目类别" width="120" align="center">
					<template #default="{ row }">{{ categoryLabel(row.category) }}</template>
				</el-table-column>
				<el-table-column label="方向" width="80" align="center">
					<template #default="{ row }">{{ row.direction === 'debit' ? '借' : '贷' }}</template>
				</el-table-column>
				<el-table-column label="年初余额借方" width="160" align="right">
					<template #default="{ row }">
						<el-input-number v-if="row.is_leaf && !periodClosed" v-model="row.opening_debit" :precision="2" :controls="false" size="small" class="amt-input" @change="recompute(row)" />
						<span v-else>{{ fmt(row.opening_debit) }}</span>
					</template>
				</el-table-column>
				<el-table-column label="年初余额贷方" width="160" align="right">
					<template #default="{ row }">
						<el-input-number v-if="row.is_leaf && !periodClosed" v-model="row.opening_credit" :precision="2" :controls="false" size="small" class="amt-input" @change="recompute(row)" />
						<span v-else>{{ fmt(row.opening_credit) }}</span>
					</template>
				</el-table-column>
				<el-table-column label="本期累计借方" width="140" align="right">
					<template #default="{ row }">
						<el-input-number v-if="row.is_leaf && !periodClosed" v-model="row.year_debit" :precision="2" :controls="false" size="small" class="amt-input" @change="recompute(row)" />
						<span v-else>{{ fmt(row.year_debit) }}</span>
					</template>
				</el-table-column>
				<el-table-column label="本期累计贷方" width="140" align="right">
					<template #default="{ row }">
						<el-input-number v-if="row.is_leaf && !periodClosed" v-model="row.year_credit" :precision="2" :controls="false" size="small" class="amt-input" @change="recompute(row)" />
						<span v-else>{{ fmt(row.year_credit) }}</span>
					</template>
				</el-table-column>
				<el-table-column prop="current_debit" label="当前期初借方" width="140" align="right">
					<template #default="{ row }">{{ fmt(row.current_debit) }}</template>
				</el-table-column>
				<el-table-column prop="current_credit" label="当前期初贷方" width="140" align="right">
					<template #default="{ row }">{{ fmt(row.current_credit) }}</template>
				</el-table-column>
			</el-table>
		</el-card>
	</div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import ledgerApi from '@/api/ledger'

const period = ref('')
const rows = ref([])
const loading = ref(false)
const periodClosed = ref(false)
const total = ref({ balanced: true, message: '试算平衡', total_debit: 0, total_credit: 0 })

const leafRows = computed(() => rows.value)

const categoryLabel = (c) => ({ asset: '资产类', liability: '负债类', common: '共同类', equity: '所有者权益类', cost: '成本类', profit_loss: '损益类' }[c] || c)
const fmt = (v) => Number(v || 0).toFixed(2)
const recompute = (row) => {
	row.current_debit = round(row.opening_debit + row.year_debit)
	row.current_credit = round(row.opening_credit + row.year_credit)
	recomputeTotal()
}
const round = (v) => Math.round((Number(v) || 0) * 100) / 100
const recomputeTotal = () => {
	const d = round(rows.value.reduce((s, r) => s + (r.current_debit || 0), 0))
	const c = round(rows.value.reduce((s, r) => s + (r.current_credit || 0), 0))
	const balanced = Math.abs(d - c) < 0.005
	total.value = { balanced, message: balanced ? '试算平衡' : `借贷不平衡，差额 ${Math.abs(round(d - c)).toFixed(2)}`, total_debit: d, total_credit: c }
}

const load = async () => {
	if (!period.value) return
	loading.value = true
	try {
		const res = await ledgerApi.opening.list.get({ period: period.value })
		rows.value = res.data?.rows || []
		periodClosed.value = res.data?.period_closed || false
		recomputeTotal()
	} catch { ElMessage.error('加载失败') }
	finally { loading.value = false }
}
const trialBalance = async () => {
	if (!period.value) return ElMessage.warning('请先选择期间')
	try {
		const res = await ledgerApi.opening.balanceCheck.post({ period: period.value })
		total.value = res.data
		ElMessage[res.data.balanced ? 'success' : 'warning'](res.data.message)
	} catch { ElMessage.error('试算失败') }
}
const save = async () => {
	if (periodClosed.value) return ElMessage.warning('该期间已月结，禁止修改期初')
	try {
		await ledgerApi.opening.save.post({ period: period.value, items: rows.value.filter(r => r.is_leaf) })
		ElMessage.success('保存成功')
		load()
	} catch (e) { ElMessage.error(e?.message || '保存失败') }
}
const rowClass = ({ row }) => row.is_leaf ? '' : 'non-leaf-row'
const summary = () => {
	return ['合计', '', '', '', '',
		fmt(total.value.total_debit), fmt(total.value.total_credit), '', '',
		fmt(total.value.total_debit), fmt(total.value.total_credit),
	]
}
onMounted(() => { period.value = new Date().toISOString().slice(0, 7); load() })
</script>

<style scoped>
.page { background: #f0f2f5; min-height: 100vh; }
.page-header { height: 50px; background: #fff; border-bottom: 1px solid #e8e8e8; padding: 0 20px; display: flex; align-items: center; }
.title { font-size: 18px; font-weight: 700; color: #303133; }
.card { margin: 16px 20px; }
.filter { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; margin-bottom: 16px; }
.lbl { font-size: 14px; color: #606266; }
.balance-hint { margin-left: 16px; font-size: 14px; font-weight: 700; }
.balance-hint.ok { color: #67c23a; }
.balance-hint.err { color: #f56c6c; }
.amt-input { width: 120px; text-align: right; }
:deep(.non-leaf-row) { background: #fafafa !important; color: #909399; }
</style>
