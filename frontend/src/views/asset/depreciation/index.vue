<template>
	<div class="fin-page asset-page">
		<div class="asset-head">
			<div class="head-row">
				<div>
					<div class="asset-title">资产折旧单</div>
					<div class="asset-sub">按月批量计提折旧：生成折旧记录，累计折旧累加、净值减少，并自动出折旧凭证</div>
				</div>
				<el-button type="success" :loading="running" @click="onRun">
					<el-icon><Check /></el-icon><span>计提折旧</span>
				</el-button>
			</div>
			<div class="head-filter">
				<span class="f-label">折旧期间</span>
				<el-date-picker
					v-model="period"
					type="month"
					value-format="YYYY-MM"
					placeholder="选择年月"
					style="width: 160px"
					@change="loadPreview"
				/>
				<el-button @click="onReset">重置</el-button>
			</div>
		</div>

		<!-- 计提汇总 -->
		<div class="sum-row">
			<div class="sum-cell">
				<span class="sum-k">本期应折资产</span>
				<span class="sum-v tabular">{{ items.length }} <em>项</em></span>
			</div>
			<div class="sum-cell">
				<span class="sum-k">本月折旧合计</span>
				<span class="sum-v tabular">{{ fmtMoney(totalAmount) }} <em>元</em></span>
			</div>
			<div class="sum-cell">
				<span class="sum-k">计提后净值</span>
				<span class="sum-v tabular">{{ fmtMoney(afterNet) }} <em>元</em></span>
			</div>
			<div class="sum-cell">
				<span class="sum-k">已执行批次</span>
				<span class="sum-v tabular">{{ runs.length }} <em>次</em></span>
			</div>
		</div>

		<div class="grid-wrap">
			<!-- 折旧明细 -->
			<div class="panel">
				<div class="panel-head">
					<span class="panel-title">本期应折明细</span>
					<span class="panel-tip">本月折旧额 = 原值 × (1 − 残值率) ÷ 预计月数，封顶剩余净值</span>
				</div>
				<el-table
					:data="items"
					border
					size="small"
					class="fin-table--dense"
					height="100%"
					show-summary
					:summary-method="summaryMethod"
					empty-text="本期无应折资产（仅统计「在用」状态）"
					row-key="id"
				>
					<template #empty><FinEmpty text="本期无应折资产" /></template>

					<el-table-column prop="code" label="资产编号" width="130">
						<template #default="{ row }"><span class="tabular">{{ row.code }}</span></template>
					</el-table-column>
					<el-table-column prop="name" label="资产名称" min-width="140" show-overflow-tooltip />
					<el-table-column prop="original" label="原值" width="130" align="right" header-align="right">
						<template #default="{ row }"><span class="tabular">{{ fmtMoney(row.original) }}</span></template>
					</el-table-column>
					<el-table-column prop="residual_rate" label="残值率" width="90" align="right" header-align="right">
						<template #default="{ row }"><span class="tabular">{{ Number(row.residual_rate || 0).toFixed(2) }}%</span></template>
					</el-table-column>
					<el-table-column prop="month_amount" label="本月折旧额" width="140" align="right" header-align="right">
						<template #default="{ row }"><span class="tabular strong">{{ fmtMoney(row.month_amount) }}</span></template>
					</el-table-column>
					<el-table-column prop="depreciated" label="已折累计" width="130" align="right" header-align="right">
						<template #default="{ row }"><span class="tabular">{{ fmtMoney(row.depreciated) }}</span></template>
					</el-table-column>
					<el-table-column prop="net" label="剩余净值" width="130" align="right" header-align="right">
						<template #default="{ row }"><span class="tabular">{{ fmtMoney(row.net) }}</span></template>
					</el-table-column>
				</el-table>
			</div>

			<!-- 执行流水 -->
			<div class="panel side">
				<div class="panel-head">
					<span class="panel-title">折旧执行流水</span>
				</div>
				<el-table :data="runs" border size="small" class="fin-table--dense" height="100%" empty-text="暂无执行记录">
					<template #empty><FinEmpty text="暂无执行记录" /></template>
					<el-table-column prop="id" label="折旧单号" width="120">
						<template #default="{ row }"><span class="tabular">{{ row.id }}</span></template>
					</el-table-column>
					<el-table-column prop="month" label="期间" width="90" align="center">
						<template #default="{ row }"><span class="tabular">{{ fmtMonth(row.month) }}</span></template>
					</el-table-column>
					<el-table-column prop="count" label="资产数" width="70" align="right">
						<template #default="{ row }"><span class="tabular">{{ row.count }}</span></template>
					</el-table-column>
					<el-table-column prop="amount" label="折旧额" min-width="120" align="right" header-align="right">
						<template #default="{ row }"><span class="tabular">{{ fmtMoney(row.amount) }}</span></template>
					</el-table-column>
				</el-table>
			</div>
		</div>
	</div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Check } from '@element-plus/icons-vue'
import assetApi from '@/api/asset'
import { fmtMoney, fmtMonth, curMonth } from '../shared'
import FinEmpty from '../../finance/_FinEmpty.vue'

const period = ref(curMonth())
const running = ref(false)

const items = ref([])
const runs = ref([])
const totalAmount = computed(() => items.value.reduce((s, i) => s + Number(i.month_amount || 0), 0))
const afterNet = computed(() => items.value.reduce((s, i) => s + Number(i.after_net || 0), 0))

const summaryMethod = ({ columns }) =>
	columns.map((col, i) => {
		if (i === 0) return '合计'
		if (col.prop === 'original') return fmtMoney(items.value.reduce((s, r) => s + Number(r.original || 0), 0))
		if (col.prop === 'month_amount') return fmtMoney(totalAmount.value)
		if (col.prop === 'depreciated') return fmtMoney(items.value.reduce((s, r) => s + Number(r.depreciated || 0), 0))
		if (col.prop === 'net') return fmtMoney(items.value.reduce((s, r) => s + Number(r.net || 0), 0))
		return ''
	})

async function loadPreview() {
	if (!period.value) return
	try {
		const res = await assetApi.depreciation.preview.get({ month: period.value })
		const data = res.data || {}
		items.value = data.list || []
	} catch (e) {
		ElMessage.error(e.message || '加载应折明细失败')
	}
}

async function loadRuns() {
	try {
		const res = await assetApi.depreciation.runs.get({ page: 1, page_size: 100 })
		runs.value = (res.data || {}).list || []
	} catch (e) {
		ElMessage.error(e.message || '加载折旧流水失败')
	}
}

const onReset = () => {
	period.value = curMonth()
	loadPreview()
}

const onRun = () => {
	if (!period.value) return ElMessage.warning('请选择折旧期间')
	if (!items.value.length) return ElMessage.warning('本期无应折资产，无法计提')
	ElMessageBox.confirm(
		`确认对 ${period.value} 计提折旧？将生成 ${items.value.length} 条折旧记录，本月折旧合计 ¥${fmtMoney(totalAmount.value)}，计提后不可撤销。`,
		'计提折旧',
		{
			confirmButtonText: '确认计提',
			cancelButtonText: '取消',
			type: 'warning',
		}
	).then(async () => {
		running.value = true
		try {
			const res = await assetApi.depreciation.run({ month: period.value })
			const run = res.data || {}
			const msg = `折旧单 ${run.batch_no} 已生成<br/>计提 ${run.count} 项资产，本月折旧 ¥${fmtMoney(run.amount)}<br/>凭证：借 管理费用-折旧 / 贷 累计折旧`
			ElMessageBox.alert(msg, '计提成功', { dangerouslyUseHTMLString: true, confirmButtonText: '确定' })
			await Promise.all([loadPreview(), loadRuns()])
		} catch (e) {
			ElMessage.error(e.message || '计提失败')
		} finally {
			running.value = false
		}
	}).catch(() => {})
}

onMounted(() => {
	loadPreview()
	loadRuns()
})
</script>

<style>
@import '../../finance/_shared.css';
</style>

<style scoped>
.asset-page {
	height: 100%;
	display: flex;
	flex-direction: column;
	overflow: hidden;
	background: var(--bg-body);
	gap: var(--space-md);
	padding: var(--space-md);
	font-family: var(--font-family);
}
.asset-head {
	background: var(--bg-card);
	border-radius: var(--radius-lg);
	box-shadow: var(--shadow-card);
	padding: var(--space-lg) var(--space-xl);
	display: flex;
	flex-direction: column;
	gap: var(--space-md);
}
.head-row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: var(--space-md);
}
.asset-title {
	font-size: var(--font-h1);
	font-weight: 600;
	color: var(--text-primary);
}
.asset-sub {
	font-size: var(--font-aux);
	color: var(--text-secondary);
	margin-top: 4px;
}
.head-row .el-button {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	height: 32px;
	padding: 0 var(--space-lg);
	border-radius: var(--radius-md);
}
.head-filter {
	display: flex;
	align-items: center;
	gap: var(--space-md);
	flex-wrap: wrap;
}
.f-label {
	font-size: var(--font-base);
	color: var(--text-regular);
}

/* 汇总条 */
.sum-row {
	background: var(--bg-card);
	border-radius: var(--radius-lg);
	box-shadow: var(--shadow-card);
	display: flex;
	padding: 10px 0;
}
.sum-cell {
	flex: 1;
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 4px;
	padding: 6px 0;
}
.sum-cell + .sum-cell {
	border-left: 1px solid var(--border-lighter);
}
.sum-k {
	font-size: var(--font-aux);
	color: var(--text-secondary);
}
.sum-v {
	font-size: var(--font-h3);
	font-weight: 600;
	color: var(--text-primary);
}
.sum-v em {
	font-style: normal;
	font-size: var(--font-aux);
	font-weight: 400;
	color: var(--text-secondary);
	margin-left: 2px;
}

/* 双面板 */
.grid-wrap {
	flex: 1;
	min-height: 0;
	display: grid;
	grid-template-columns: minmax(0, 1.7fr) minmax(0, 1fr);
	gap: var(--space-md);
}
.panel {
	background: var(--bg-card);
	border-radius: var(--radius-lg);
	box-shadow: var(--shadow-card);
	display: flex;
	flex-direction: column;
	min-height: 0;
	overflow: hidden;
}
.panel-head {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: var(--space-md);
	padding: var(--space-md) var(--space-xl);
	border-bottom: 1px solid var(--border-lighter);
}
.panel-title {
	font-size: var(--font-h3);
	font-weight: 600;
	color: var(--text-primary);
}
.panel-tip {
	font-size: var(--font-aux);
	color: var(--text-secondary);
}
.panel :deep(.el-table) {
	flex: 1;
}
.strong {
	font-weight: 600;
	color: var(--color-primary);
}
:deep(.el-table th.el-table__cell .cell) {
	font-weight: 600;
}
:deep(.el-table__footer td .cell) {
	font-weight: 600;
}
/* D3 简报口径：表头 44px 13px/600，明细行 41px（覆盖 _shared.css 的 14px/500 + 48px） */
:deep(.el-table.fin-table--dense th.el-table__cell .cell) {
	font-size: var(--font-base);
	font-weight: 600;
}
:deep(.el-table.fin-table--dense .el-table__body .el-table__row) {
	height: 41px;
}
:deep(.el-table .el-table__body td.el-table__cell .cell) {
	font-size: var(--font-base);
	color: var(--text-primary);
}
</style>
