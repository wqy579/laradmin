<template>
	<div class="page">
		<!-- 页面头：标题 + 期间状态 -->
		<div class="page-header">
			<div class="ph-left">
				<el-icon class="ph-icon"><ElIconLock /></el-icon>
				<span class="title">财务月结</span>
				<span class="sub">5 步向导 · 逐期结账并锁定期间</span>
			</div>
			<div v-if="current" class="ph-right">
				<el-tag :type="current.status === 'closed' ? 'success' : 'info'" class="ph-tag">{{ current.status === 'closed' ? '已结账' : '未结账' }}</el-tag>
				<span v-if="current.status === 'closed' && current.closed_at" class="ph-meta tabular">结账：{{ current.closed_at }}</span>
			</div>
		</div>

		<el-card shadow="never" class="card">
			<!-- 步骤条：当前步主色、完成步成功色、未到步灰 -->
			<el-steps :active="step" finish-status="success" align-center class="steps">
				<el-step v-for="(s, i) in STEPS" :key="i" :title="s.title" :description="s.desc" />
			</el-steps>

			<!-- 步骤内容 -->
			<div class="step-body">
				<!-- ========== Step 1 会计期间 ========== -->
				<div v-show="step === 0" class="step-pane">
					<div class="period-card">
						<div class="pc-main">
							<div class="pc-label">选择结账期间</div>
							<div class="pc-pickers">
								<el-select v-model="year" class="pc-pick">
									<el-option v-for="y in YEARS" :key="y" :label="`${y} 年`" :value="y" />
								</el-select>
								<el-select v-model="month" class="pc-pick">
									<el-option v-for="m in MONTHS" :key="m" :label="`${m} 月`" :value="m" />
								</el-select>
							</div>
						</div>
						<div class="pc-info">
							<div class="pc-row"><span class="k">会计期间</span><span class="v tabular">{{ pk }}</span></div>
							<div class="pc-row"><span class="k">期间状态</span><el-tag :type="current.status === 'closed' ? 'success' : 'info'" size="small">{{ current.status_label }}</el-tag></div>
							<div class="pc-row"><span class="k">凭证张数</span><span class="v tabular">{{ current.voucher_count }}</span></div>
							<div class="pc-row"><span class="k">借方合计</span><span class="v tabular">{{ fmt(current.total_debit) }}</span></div>
							<div class="pc-row"><span class="k">贷方合计</span><span class="v tabular">{{ fmt(current.total_credit) }}</span></div>
						</div>
						<div v-if="isClosed" class="pc-warn">该期间已结账并锁定，仅供查看。如需反结账请到「月结查询」处理。</div>
					</div>
				</div>

				<!-- ========== Step 2 上交款 / 凭证检查 ========== -->
				<div v-show="step === 1" class="step-pane">
					<el-alert v-if="checks.unconfirmed_remit > 0" type="warning" :closable="false" show-icon class="step-alert">
						存在 {{ checks.unconfirmed_remit }} 笔未确认上交款，暂不阻断结账，建议确认后继续。
					</el-alert>
					<div class="check-grid">
						<div v-for="c in checkCards" :key="c.key" class="check-card" :class="{ 'is-fail': c.blocking && c.count > 0 }">
							<div class="cc-head">
								<el-icon class="cc-icon"><component :is="c.icon" /></el-icon>
								<span class="cc-no">{{ c.no }}</span>
								<el-tag :type="c.blocking && c.count > 0 ? 'danger' : c.count > 0 ? 'warning' : 'success'" size="small" class="cc-tag">
									{{ c.count > 0 ? (c.blocking ? '待处理' : '有警告') : '已通过' }}
								</el-tag>
							</div>
							<div class="cc-count tabular">{{ c.count }}</div>
							<div class="cc-unit">张 / 笔</div>
							<div class="cc-title">{{ c.title }}</div>
							<div class="cc-desc">{{ c.desc }}</div>
							<el-button class="cc-btn" :disabled="c.count === 0" :type="c.count > 0 ? 'primary' : ''" link @click="handleCheck(c)">{{ c.count > 0 ? '去处理' : '已完成' }}</el-button>
						</div>
					</div>
					<div class="check-actions">
						<el-button :disabled="checks.draft_vouchers === 0" @click="auditAll">全部审核草稿凭证（{{ checks.draft_vouchers }} 张）</el-button>
					</div>
				</div>

				<!-- ========== Step 3 资产负债表 ========== -->
				<div v-show="step === 2" class="step-pane">
					<div class="report-head">
						<div class="rh-title">资产负债表</div>
						<div class="rh-meta"><span class="tabular">{{ balance.companyName }}</span><span class="tabular">　编制日期：{{ balance.report_date }}</span></div>
					</div>
					<div v-if="!balance.diff_ok" class="bs-warn">资产负债表不平衡，差额 ¥{{ fmt(Math.abs(balance.diff)) }}，请检查凭证与结转。</div>
					<table class="fin-table">
						<thead>
							<tr>
								<th class="col-item">项目</th>
								<th class="col-line">行次</th>
								<th class="col-amt">年初数</th>
								<th class="col-amt">期末数</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="(r, i) in balance.rows" :key="i" :class="{ 'row-group': r.type === 'group', 'row-total': r.type === 'total' }">
								<td class="col-item">{{ r.name }}</td>
								<td class="col-line">{{ r.line ?? '' }}</td>
								<td class="col-amt tabular">{{ r.type === 'group' ? '' : fmt(r.year) }}</td>
								<td class="col-amt tabular">{{ r.type === 'group' ? '' : fmt(r.period) }}</td>
							</tr>
						</tbody>
					</table>
				</div>

				<!-- ========== Step 4 利润表 ========== -->
				<div v-show="step === 3" class="step-pane">
					<div class="report-head">
						<div class="rh-title">利润表</div>
						<div class="rh-meta"><span class="tabular">{{ income.companyName }}</span><span class="tabular">　期间：{{ income.period }}</span></div>
					</div>
					<!-- 关键指标大数字 -->
					<div class="kpi-row">
						<div class="kpi">
							<div class="kpi-label">营业收入</div>
							<div class="kpi-value tabular">{{ fmt(income.revenue) }}</div>
						</div>
						<div class="kpi">
							<div class="kpi-label">营业成本</div>
							<div class="kpi-value tabular">{{ fmt(income.cost) }}</div>
						</div>
						<div class="kpi">
							<div class="kpi-label">期间费用</div>
							<div class="kpi-value tabular">{{ fmt(income.expense) }}</div>
						</div>
						<div class="kpi kpi--profit">
							<div class="kpi-label">净利润</div>
							<div class="kpi-value tabular" :class="income.net_profit < 0 ? 'is-neg' : 'is-pos'">{{ fmt(income.net_profit) }}</div>
						</div>
					</div>
					<table class="fin-table">
						<thead>
							<tr>
								<th class="col-item">项目</th>
								<th class="col-amt">本月数</th>
								<th class="col-amt">本年累计数</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="r in income.rows" :key="r.code" :class="{ 'row-total': r.is_total, 'row-bold': r.bold }">
								<td class="col-item">{{ r.name }}</td>
								<td class="col-amt tabular" :class="{ 'amt-neg': r.month < 0 }">{{ fmt(r.month) }}</td>
								<td class="col-amt tabular" :class="{ 'amt-neg': r.year < 0 }">{{ fmt(r.year) }}</td>
							</tr>
						</tbody>
					</table>
				</div>

				<!-- ========== Step 5 月结执行 ========== -->
				<div v-show="step === 4" class="step-pane">
					<div v-if="!executed" class="confirm-card">
						<div class="cf-title">确认月结</div>
						<div class="cf-text">
							将结账 <b class="tabular">{{ pk }}</b>，结账后期间锁定，凭证不可增删改，期初余额与科目余额固化。
						</div>
						<el-table :data="reportRows" border size="small" class="cf-table">
							<el-table-column prop="label" label="结账检查项" width="240" />
							<el-table-column label="结果" align="center">
								<template #default="{ row }">
									<el-tag :type="row.ok ? 'success' : 'danger'" size="small">{{ row.ok ? '通过' : '异常' }}</el-tag>
								</template>
							</el-table-column>
							<el-table-column label="明细" align="right">
								<template #default="{ row }">{{ row.detail }}</template>
							</el-table-column>
						</el-table>
					</div>
					<div v-else class="done-card">
						<el-icon class="done-icon"><ElIconCircleCheck /></el-icon>
						<div class="done-title">月结完成</div>
						<div class="done-text">
							<b class="tabular">{{ pk }}</b> 已结账并锁定，结账人 <b>{{ reporter }}</b>，时间 <b class="tabular">{{ doneAt }}</b>。
						</div>
						<el-tag type="success" class="done-tag">已结账</el-tag>
					</div>
				</div>
			</div>

			<!-- 底部操作条 -->
			<div class="footer">
				<div class="ft-tips">
					<el-icon><ElIconInfoFilled /></el-icon>
					<span>{{ footerTips }}</span>
				</div>
				<div class="ft-btns">
					<el-button v-if="step > 0" @click="prev">上一步</el-button>
					<el-button v-if="step < 4 && !isClosed && canNext" type="primary" @click="next">下一步</el-button>
					<el-tooltip v-if="step < 4 && !isClosed && !canNext" content="存在未生成凭证 / 借贷不平凭证，请先处理" placement="top">
						<el-button type="primary" class="is-blocked" @click="next">下一步</el-button>
					</el-tooltip>
					<el-button v-if="step === 4 && !executed && !isClosed" @click="exportExcel"><el-icon class="ft-bi"><ElIconDownload /></el-icon>导出 Excel</el-button>
					<el-button v-if="step === 4 && !executed && !isClosed" type="success" :loading="executing" @click="execute">执行月结</el-button>
					<el-button v-if="step === 4 && !executed && !isClosed" @click="backToEdit">取消</el-button>
					<el-button v-if="step === 4 && (executed || isClosed)" @click="reset">重新开始</el-button>
				</div>
			</div>
		</el-card>
	</div>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { YEARS, MONTHS, PERIODS, periodKey, getChecks, getBalanceSheet, getIncomeStatement, getCloseReport } from './mock'

const fmt = (v) => Number(v || 0).toFixed(2)

const STEPS = [
	{ title: '会计期间', desc: '选择结账年与月' },
	{ title: '上交款 / 凭证', desc: '结账前完整性检查' },
	{ title: '资产负债表', desc: '期间资产负债预览' },
	{ title: '利润表', desc: '收入·成本·费用·净利润' },
	{ title: '月结', desc: '确认并锁定期间' },
]

const step = ref(0)
const year = ref(2026)
const month = ref(10)
const pk = computed(() => periodKey(year.value, month.value))
const current = computed(() => PERIODS[pk.value] || PERIODS['2026-10'])
const isClosed = computed(() => current.value.status === 'closed')

// ---- Step2 状态（去处理 / 全部审核 驱动）----
const flags = reactive({ audited: false, docsHandled: false, remitHandled: false })
const checks = computed(() => getChecks(pk.value, flags))

const checkCards = computed(() => [
	{
		key: 'docs',
		no: '①',
		title: '未生成凭证的业务单据',
		desc: '销售、退货、调拨等业务单据尚未生成会计凭证',
		icon: 'ElIconDocument',
		count: checks.value.unbilled_docs,
		blocking: true,
	},
	{
		key: 'remit',
		no: '②',
		title: '未确认上交款',
		desc: '车销上交款尚未确认，不影响结账但建议核对',
		icon: 'ElIconMoney',
		count: checks.value.unconfirmed_remit,
		blocking: false,
	},
	{
		key: 'balance',
		no: '③',
		title: '凭证借贷不平',
		desc: '借方合计与贷方合计不相等的草稿凭证',
		icon: 'ElIconScaleToOriginal',
		count: checks.value.unbalanced,
		blocking: true,
	},
])

// 阻断条件：① 或 ③ 存在则禁止进入下一步
const canNext = computed(() => {
	if (step.value !== 1) return true
	return checks.value.unbilled_docs === 0 && checks.value.unbalanced === 0
})

const handleCheck = (c) => {
	if (c.key === 'docs') {
		flags.docsHandled = true
		ElMessage.success('已跳转业务单据列表生成凭证（mock）')
	} else if (c.key === 'remit') {
		flags.remitHandled = true
		ElMessage.success('已确认上交款（mock）')
	} else if (c.key === 'balance') {
		flags.audited = true
		ElMessage.success('已审核全部草稿凭证（mock）')
	}
}

const auditAll = () => {
	flags.audited = true
	ElMessage.success(`已一键审核 ${checks.value.draft_vouchers} 张草稿凭证（mock）`)
}

// ---- Step3 资产负债表 ----
const balance = computed(() => {
	const b = getBalanceSheet(pk.value)
	const asset = b.rows.find((r) => r.name === '资产总计')
	const le = b.rows.find((r) => r.name === '负债和所有者权益总计')
	const diff = (asset?.period || 0) - (le?.period || 0)
	return { ...b, diff_ok: diff === 0, diff: Math.round(diff * 100) / 100 }
})

// ---- Step4 利润表 ----
const income = computed(() => {
	const s = getIncomeStatement(pk.value)
	const row = (code) => s.rows.find((r) => r.code === code)?.month || 0
	return {
		...s,
		revenue: row('revenue'),
		cost: row('cost'),
		expense: row('tax') + row('sell') + row('admin') + row('rd') + row('fin'),
		net_profit: row('net_profit'),
	}
})

// ---- Step5 月结报告 ----
const executed = ref(false)
const executing = ref(false)
const reporter = '王会计'
const doneAt = ref('')
const reportRows = computed(() => {
	const rep = getCloseReport(pk.value, balance.value, income.value)
	return [
		{ label: '业务单据凭证化', ok: checks.value.unbilled_docs === 0, detail: checks.value.unbilled_docs === 0 ? '全部已生成凭证' : `${checks.value.unbilled_docs} 张待生成` },
		{ label: '上交款确认', ok: checks.value.unconfirmed_remit === 0, detail: checks.value.unconfirmed_remit === 0 ? '全部已确认' : `${checks.value.unconfirmed_remit} 笔待确认` },
		{ label: '凭证借贷平衡', ok: checks.value.unbalanced === 0, detail: checks.value.unbalanced === 0 ? '借贷平衡' : `${checks.value.unbalanced} 张不平` },
		{ label: '资产负债表平衡', ok: rep.balance_ok, detail: rep.balance_ok ? '资产 = 负债+权益' : `差额 ¥${fmt(rep.diff)}` },
		{ label: '本期净利润', ok: true, detail: `¥${fmt(rep.net_profit)}` },
	]
})

const execute = () => {
	ElMessageBox.confirm(
		`确认月结 ${year.value} 年 ${month.value} 月？结账后该期间锁定，凭证不可增删改，期初余额与科目余额固化，且不可撤销。`,
		'危险操作确认',
		{ type: 'warning', confirmButtonText: '确认月结', cancelButtonText: '取消', confirmButtonClass: 'el-button--danger' },
	).then(() => {
		executing.value = true
		// TODO(api)：POST /finance/month-close/execute { period }
		setTimeout(() => {
			const now = new Date()
			doneAt.value = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')} ${String(now.getHours()).padStart(2, '0')}:${String(now.getMinutes()).padStart(2, '0')}:${String(now.getSeconds()).padStart(2, '0')}`
			// 状态落 mock：标记该期间已结账
			Object.assign(PERIODS[pk.value], { status: 'closed', status_label: '已结账', closed_by_name: reporter, closed_at: doneAt.value })
			executed.value = true
			executing.value = false
			ElMessage.success(`${year.value} 年 ${month.value} 月月结成功，期间已锁定`)
		}, 900)
	}).catch(() => {})
}

const backToEdit = () => {
	step.value = 3
}

const reset = () => {
	// 还原 mock 期间状态，重置向导
	Object.assign(PERIODS[pk.value], { status: 'open', status_label: '未结账', closed_by_name: '', closed_at: '' })
	executed.value = false
	flags.audited = false
	flags.docsHandled = false
	flags.remitHandled = false
	step.value = 0
}

// ---- 导出 Excel（前端生成 CSV，UTF-8 BOM）----
const exportExcel = () => {
	const rows = []
	rows.push([`资产负债表 · ${balance.value.period}`])
	rows.push(['项目', '行次', '年初数', '期末数'])
	balance.value.rows.forEach((r) => rows.push([r.name, r.line ?? '', r.type === 'group' ? '' : fmt(r.year), r.type === 'group' ? '' : fmt(r.period)]))
	rows.push([])
	rows.push([`利润表 · ${income.value.period}`])
	rows.push(['项目', '本月数', '本年累计数'])
	income.value.rows.forEach((r) => rows.push([r.name, fmt(r.month), fmt(r.year)]))
	rows.push([])
	rows.push([`月结报告 · ${pk.value}`])
	rows.push(['检查项', '结果', '明细'])
	reportRows.value.forEach((r) => rows.push([r.label, r.ok ? '通过' : '异常', r.detail]))

	const csv = rows.map((r) => r.map((c) => `"${String(c).replace(/"/g, '""')}"`).join(',')).join('\r\n')
	const blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8;' })
	const url = URL.createObjectURL(blob)
	const a = document.createElement('a')
	a.href = url
	a.download = `月结报告_${pk.value}.csv`
	a.click()
	setTimeout(() => URL.revokeObjectURL(url), 1000)
	ElMessage.success('已导出 Excel（CSV）')
}

// ---- 步骤切换 ----
const next = () => {
	if (step.value < 4 && canNext.value) step.value++
}
const prev = () => {
	if (step.value > 0) step.value--
}

const footerTips = computed(() => {
	if (isClosed.value && !executed.value) return '该期间已结账并锁定，仅可查看'
	const tips = [
		'选择需要结账的会计年与月，确认后进入结账检查',
		'①未生成凭证 ③借贷不平 将阻断结账；②未确认上交款仅警告',
		'资产负债表预览，确认资产 = 负债 + 所有者权益',
		'利润表预览，关注净利润红涨绿跌',
		'核对结账报告，确认无误后执行月结（不可撤销）',
	]
	return tips[step.value]
})

// 期间变更 → 重置向导检查态（不动 step，便于对比）
watch(pk, () => {
	flags.audited = false
	flags.docsHandled = false
	flags.remitHandled = false
	executed.value = false
})

onMounted(() => {
	const d = new Date()
	// 默认落到最近一个未结账期间
	const cur = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`
	year.value = Number(cur.slice(0, 4))
	month.value = Number(cur.slice(5, 7))
	if (!PERIODS[pk.value]) {
		year.value = 2026
		month.value = 10
	}
})
</script>

<style scoped>
/* ===== 页面骨架 ===== */
.page {
	background: var(--bg-body);
	min-height: 100vh;
	padding: var(--space-lg);
}
.page-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	background: var(--bg-card);
	border: 1px solid var(--border-lighter);
	border-bottom: none;
	border-radius: var(--radius-md) var(--radius-md) 0 0;
	padding: 0 var(--space-lg);
	height: 56px;
}
.ph-left {
	display: flex;
	align-items: center;
	gap: var(--space-sm);
}
.ph-icon {
	font-size: var(--font-h1);
	color: var(--color-primary);
}
.title {
	font-size: var(--font-h1);
	font-weight: 600;
	color: var(--text-primary);
	line-height: 28px;
}
.sub {
	font-size: var(--font-aux);
	color: var(--text-secondary);
}
.ph-right {
	display: flex;
	align-items: center;
	gap: var(--space-md);
}
.ph-meta {
	font-size: var(--font-base);
	color: var(--text-secondary);
}

.card {
	border-radius: 0 0 var(--radius-md) var(--radius-md);
	border: 1px solid var(--border-lighter);
	border-top: none;
}
/* 卡片三段（步骤条 / 内容 / 操作条）自带 padding，清零 card body 避免双重内边距 */
.card :deep(.el-card__body) {
	padding: 0;
}

/* ===== 步骤条 ===== */
.steps {
	padding: var(--space-xl) var(--space-lg) var(--space-md);
	border-bottom: 1px solid var(--border-lighter);
}
.steps :deep(.el-step__title.is-process) {
	font-size: var(--font-h3);
	font-weight: 600;
	color: var(--color-primary);
}
.steps :deep(.el-step__title.is-finish) {
	font-size: var(--font-h3);
	font-weight: 400;
	color: var(--color-success);
}
.steps :deep(.el-step__title.is-wait) {
	font-size: var(--font-h3);
	font-weight: 400;
	color: var(--text-placeholder);
}
.steps :deep(.el-step__description) {
	font-size: var(--font-aux);
	color: var(--text-secondary);
	margin-top: 2px;
}
.steps :deep(.el-step__icon.is-process) {
	color: var(--color-primary);
	border-color: var(--color-primary);
}
.steps :deep(.el-step__icon.is-finish) {
	color: var(--bg-card);
	background: var(--color-success);
	border-color: var(--color-success);
}
.steps :deep(.el-step__head.is-process)::after,
.steps :deep(.el-step__head.is-finish)::after {
	border-color: var(--color-primary);
}

/* ===== 步骤内容 ===== */
.step-body {
	padding: var(--space-xl);
	min-height: 360px;
}
.step-pane {
	animation: fade .25s ease;
}
@keyframes fade {
	from {
		opacity: 0;
		transform: translateY(6px);
	}
	to {
		opacity: 1;
		transform: none;
	}
}

/* ----- Step1 ----- */
.period-card {
	max-width: 720px;
}
.pc-main {
	display: flex;
	flex-direction: column;
	gap: var(--space-md);
	margin-bottom: var(--space-lg);
}
.pc-label {
	font-size: var(--font-h3);
	font-weight: 600;
	color: var(--text-primary);
}
.pc-pickers {
	display: flex;
	gap: var(--space-md);
}
.pc-pick {
	width: 140px;
}
.pc-info {
	background: var(--bg-hover);
	border: 1px solid var(--border-lighter);
	border-radius: var(--radius-md);
	padding: var(--space-md) var(--space-lg);
}
.pc-row {
	display: flex;
	justify-content: space-between;
	padding: 6px 0;
	font-size: var(--font-base);
	border-bottom: 1px dashed var(--border-lighter);
}
.pc-row:last-child {
	border-bottom: none;
}
.pc-row .k {
	color: var(--text-secondary);
}
.pc-row .v {
	color: var(--text-primary);
	font-weight: 600;
}
.pc-warn {
	margin-top: var(--space-md);
	padding: var(--space-sm) var(--space-md);
	background: var(--bg-active);
	color: var(--color-primary);
	border-radius: var(--radius-sm);
	font-size: var(--font-base);
}

/* ----- Step2 ----- */
.step-alert {
	margin-bottom: var(--space-lg);
}
.check-grid {
	display: grid;
	grid-template-columns: repeat(3, 1fr);
	gap: var(--space-lg);
}
.check-card {
	border: 1px solid var(--border-lighter);
	border-radius: var(--radius-md);
	padding: var(--space-lg);
	background: var(--bg-card);
	display: flex;
	flex-direction: column;
	transition: box-shadow .2s, border-color .2s;
}
.check-card:hover {
	box-shadow: var(--shadow-card);
}
.cc-head {
	display: flex;
	align-items: center;
	gap: var(--space-xs);
	margin-bottom: var(--space-sm);
}
.cc-icon {
	font-size: var(--font-h2);
	color: var(--text-secondary);
}
.cc-no {
	font-size: var(--font-h3);
	font-weight: 600;
	color: var(--text-primary);
}
.cc-tag {
	margin-left: auto;
}
.cc-count {
	font-size: var(--font-stat);
	font-weight: 600;
	color: var(--text-primary);
	line-height: 32px;
}
.check-card.is-fail .cc-count {
	color: var(--color-danger);
}
.cc-unit {
	font-size: var(--font-aux);
	color: var(--text-secondary);
	margin-top: -4px;
}
.cc-title {
	font-size: var(--font-h3);
	font-weight: 600;
	color: var(--text-primary);
	margin-top: var(--space-sm);
}
.cc-desc {
	font-size: var(--font-aux);
	color: var(--text-secondary);
	line-height: 18px;
	flex: 1;
}
.cc-btn {
	align-self: flex-start;
	margin-top: var(--space-sm);
}

.check-actions {
	margin-top: var(--space-lg);
	display: flex;
	justify-content: flex-end;
}

/* ----- 报表（Step3 / Step4）----- */
.report-head {
	display: flex;
	flex-direction: column;
	align-items: center;
	margin-bottom: var(--space-md);
}
.rh-title {
	font-size: var(--font-h1);
	font-weight: 600;
	color: var(--text-primary);
}
.rh-meta {
	font-size: var(--font-aux);
	color: var(--text-secondary);
	margin-top: 4px;
}
.bs-warn {
	margin: var(--space-md) 0;
	padding: var(--space-sm) var(--space-md);
	background: var(--bg-hover);
	border: 1px solid var(--border-light);
	border-left: 3px solid var(--color-warning);
	color: var(--color-warning);
	border-radius: var(--radius-sm);
	font-size: var(--font-base);
}

.fin-table {
	width: 100%;
	border-collapse: collapse;
	font-size: var(--font-base);
}
.fin-table th,
.fin-table td {
	border: 1px solid var(--border-lighter);
	padding: 0 var(--space-md);
	height: 34px;
	white-space: nowrap;
}
.fin-table thead th {
	background: var(--bg-hover);
	color: var(--text-regular);
 font-weight: 600;
	height: 40px;
	text-align: center;
}
.col-item {
	width: 240px;
	text-align: left;
	color: var(--text-primary);
}
.col-line {
	width: 60px;
	text-align: center;
	color: var(--text-secondary);
}
.col-amt {
	width: 140px;
	text-align: right;
	color: var(--text-primary);
}
.fin-table thead .col-amt {
	text-align: center;
}
.row-group td {
	background: var(--bg-active);
	color: var(--color-primary);
	font-weight: 600;
}
.row-total td {
	background: var(--bg-hover);
	font-weight: 600;
}
.row-total .col-amt {
	font-weight: 600;
}
.row-bold .col-amt {
	font-weight: 600;
}
.amt-neg {
	color: var(--color-danger);
}

/* ----- Step4 KPI ----- */
.kpi-row {
	display: grid;
	grid-template-columns: repeat(4, 1fr);
	gap: var(--space-lg);
	margin: var(--space-lg) 0;
}
.kpi {
	background: var(--bg-card);
	border: 1px solid var(--border-lighter);
	border-radius: var(--radius-md);
	padding: var(--space-md) var(--space-lg);
	box-shadow: var(--shadow-card);
}
.kpi-label {
	font-size: var(--font-base);
	color: var(--text-secondary);
}
.kpi-value {
	font-size: var(--font-stat);
	font-weight: 600;
	color: var(--text-primary);
	line-height: 32px;
	margin-top: var(--space-xs);
}
.kpi--profit .kpi-value.is-pos {
	color: var(--color-danger);
}
.kpi--profit .kpi-value.is-neg {
	color: var(--color-success);
}

/* ----- Step5 ----- */
.confirm-card,
.done-card {
	max-width: 760px;
}
.cf-title {
	font-size: var(--font-h2);
	font-weight: 600;
	color: var(--text-primary);
	margin-bottom: var(--space-sm);
}
.cf-text {
	font-size: var(--font-base);
	color: var(--text-regular);
	padding: var(--space-md) var(--space-lg);
	background: var(--bg-hover);
	border-left: 3px solid var(--color-primary);
	border-radius: var(--radius-sm);
	line-height: 20px;
	margin-bottom: var(--space-lg);
}
.cf-table {
	width: 100%;
}

.done-card {
	text-align: center;
	padding: var(--space-xl) 0;
}
.done-icon {
	font-size: var(--font-stat);
	color: var(--color-success);
}
.done-title {
	font-size: var(--font-h1);
	font-weight: 600;
	color: var(--text-primary);
	margin-top: var(--space-md);
}
.done-text {
	font-size: var(--font-base);
	color: var(--text-regular);
	margin-top: var(--space-sm);
	line-height: 20px;
}
.done-tag {
	margin-top: var(--space-md);
}

/* ===== 底部操作条 ===== */
.footer {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: var(--space-md) var(--space-xl);
	border-top: 1px solid var(--border-lighter);
	background: var(--bg-hover);
	border-radius: 0 0 var(--radius-md) var(--radius-md);
}
.ft-tips {
	display: flex;
	align-items: center;
	gap: var(--space-xs);
	font-size: var(--font-aux);
	color: var(--text-secondary);
}
.ft-btns {
	display: flex;
	gap: var(--space-sm);
}
.ft-bi {
	margin-right: 4px;
}
/* 阻断态按钮：不可点但仍需触发 tooltip hover，故用样式而非 disabled */
.ft-btns .is-blocked {
	color: var(--text-placeholder) !important;
	background: var(--bg-hover) !important;
	border-color: var(--border-lighter) !important;
	cursor: not-allowed !important;
}
</style>
