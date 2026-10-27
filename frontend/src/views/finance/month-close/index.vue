<template>
	<div class="page">
		<!-- 页面头：标题 + 期间状态 -->
		<div class="page-header">
			<div class="ph-left">
				<el-icon class="ph-icon"><ElIconLock /></el-icon>
				<span class="title">财务月结</span>
				<span class="sub">3 步向导 · 预检 → 一键月结 → 确认</span>
			</div>
			<div v-if="current" class="ph-right">
				<el-tag :type="current.status === 'closed' ? 'success' : 'info'" class="ph-tag">{{ current.status_label }}</el-tag>
				<span v-if="current.status === 'closed' && current.closed_at" class="ph-meta tabular">结账：{{ current.closed_at }}</span>
			</div>
		</div>

		<el-card shadow="never" class="card">
			<!-- 步骤条：当前步主色、完成步成功色、未到步灰 -->
			<el-steps :active="step" finish-status="success" align-center class="steps">
				<el-step v-for="(s, i) in STEPS" :key="i" :title="s.title" :description="s.desc" />
			</el-steps>

			<div v-loading="loading" class="step-body">
				<!-- ========== Step 1 会计期间 + 预检 ========== -->
				<div v-show="step === 0" class="step-pane">
					<div class="period-card">
						<div class="pc-main">
							<div class="pc-label">选择结账期间</div>
							<div class="pc-pickers">
								<el-select v-model="year" class="pc-pick" @change="loadCheck">
									<el-option v-for="y in years" :key="y.year" :label="`${y.year} 年`" :value="y.year" />
								</el-select>
								<el-select v-model="month" class="pc-pick" @change="loadCheck">
									<el-option v-for="p in currentYearPeriods" :key="p.period" :label="`${p.month} 月`" :value="p.month" />
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

					<!-- 结账预检 -->
					<div v-if="!isClosed && checks.length" class="precheck">
						<div class="precheck-head">
							<span class="precheck-title">结账预检</span>
							<el-tag :type="canClose ? 'success' : 'danger'" size="small">{{ canClose ? '预检通过' : '存在必检未通过' }}</el-tag>
						</div>
						<div class="check-grid">
							<div v-for="c in checks" :key="c.key" class="check-card" :class="{ 'is-fail': !c.passed, 'is-warn': !c.blocking && !c.passed }">
								<div class="cc-head">
									<el-icon class="cc-icon"><component :is="iconOf(c)" /></el-icon>
									<span class="cc-title">{{ c.title }}</span>
									<el-tag :type="c.passed ? 'success' : c.blocking ? 'danger' : 'warning'" size="small" class="cc-tag">
										{{ c.passed ? '通过' : c.blocking ? '阻断' : '警告' }}
									</el-tag>
								</div>
								<div class="cc-desc">{{ c.detail }}</div>
							</div>
						</div>
					</div>
				</div>

				<!-- ========== Step 2 一键月结 ========== -->
				<div v-show="step === 1" class="step-pane">
					<div class="confirm-card">
						<div class="cf-title">确认月结</div>
						<div class="cf-text">
							将结账 <b class="tabular">{{ pk }}</b>，结账后期间锁定，凭证不可增删改，期初余额与科目余额固化。
						</div>
						<el-table :data="reportRows" border size="small" class="cf-table">
							<el-table-column prop="title" label="结账检查项" width="240" />
							<el-table-column label="结果" align="center">
								<template #default="{ row }">
									<el-tag :type="row.passed ? 'success' : 'danger'" size="small">{{ row.passed ? '通过' : '异常' }}</el-tag>
								</template>
							</el-table-column>
							<el-table-column label="明细" align="right">
								<template #default="{ row }">{{ row.detail }}</template>
							</el-table-column>
						</el-table>
					</div>
				</div>

				<!-- ========== Step 3 结果确认 ========== -->
				<div v-show="step === 2" class="step-pane">
					<div class="done-card">
						<el-icon class="done-icon"><ElIconCircleCheck /></el-icon>
						<div class="done-title">月结完成</div>
						<div class="done-text">
							<b class="tabular">{{ done.period }}</b> 已结账并锁定，结账人 <b>{{ done.closed_by_name }}</b>，时间 <b class="tabular">{{ done.closed_at }}</b>。
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
					<el-button v-if="step === 0 && !isClosed && canClose" type="primary" @click="next">下一步</el-button>
					<el-button v-if="step === 1" type="danger" v-permission="'fin:monthclose:execute'" :loading="executing" @click="execute">一键月结</el-button>
					<el-button v-if="step === 2" @click="reset">重新开始</el-button>
				</div>
			</div>
		</el-card>
	</div>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import businessApi from '@/api/business'

const fmt = (v) => Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

const STEPS = [
	{ title: '期间与预检', desc: '选择期间并预检' },
	{ title: '一键月结', desc: '确认并锁定期间' },
	{ title: '结果确认', desc: '结账完成' },
]

const loading = ref(false)
const executing = ref(false)
const step = ref(0)
const year = ref(null)
const month = ref(null)
const years = ref([])
const periods = ref([])
const checks = ref([])
const done = reactive({ period: '', closed_by_name: '', closed_at: '' })

const pk = computed(() => (year.value != null && month.value != null ? `${year.value}-${String(month.value).padStart(2, '0')}` : ''))
const currentYearPeriods = computed(() => years.value.find((y) => y.year === year.value)?.periods || [])
const current = computed(() => periods.value.find((p) => p.period === pk.value) || { status: 'open', status_label: '未结账', voucher_count: 0, total_debit: 0, total_credit: 0 })
const isClosed = computed(() => current.value.status === 'closed')
const canClose = computed(() => checks.value.length > 0 && checks.value.every((c) => !c.blocking || c.passed))

const reportRows = computed(() => checks.value)

function iconOf(c) {
	if (c.key === 'trial_balance') return 'ElIconScaleToOriginal'
	if (c.key === 'carry_forward') return 'ElIconRefresh'
	if (c.key === 'business_voucher') return 'ElIconDocument'
	if (c.key === 'ar_ap_cash') return 'ElIconWallet'
	return 'ElIconCircleCheck'
}

function loadPeriods() {
	loading.value = true
	businessApi.finance.monthClose.periods
		.get()
		.then((res) => {
			years.value = res.data.years || []
			periods.value = years.value.flatMap((y) => y.periods)
			if (!years.value.length) return
			// 默认落到最近一个未结账期间
			const openP = periods.value.find((p) => p.status === 'open')
			const target = openP || periods.value[0]
			year.value = target.year
			month.value = target.month
		})
		.catch(() => ElMessage.error('期间列表加载失败'))
		.finally(() => (loading.value = false))
}

function loadCheck() {
	if (isClosed.value || !pk.value) {
		checks.value = []
		return
	}
	loading.value = true
	businessApi.finance.monthClose.check
		.get(pk.value)
		.then((res) => (checks.value = res.data.checks || []))
		.catch(() => {
			checks.value = []
			ElMessage.error('结账预检失败')
		})
		.finally(() => (loading.value = false))
}

function next() {
	if (step.value < 2) step.value++
}

function prev() {
	if (step.value > 0) step.value--
}

function execute() {
	ElMessageBox.confirm(
		`确认月结 ${year.value} 年 ${month.value} 月？结账后该期间锁定，凭证不可增删改，期初余额与科目余额固化。`,
		'危险操作确认',
		{ type: 'warning', confirmButtonText: '一键月结', cancelButtonText: '取消', confirmButtonClass: 'el-button--danger' },
	)
		.then(() => {
			executing.value = true
			businessApi.finance.monthClose.close
				.post(pk.value)
				.then((res) => {
					Object.assign(done, res.data || {})
					step.value = 2
					loadPeriods()
					ElMessage.success(`${year.value} 年 ${month.value} 月月结成功，期间已锁定`)
				})
				.catch((err) => {
					ElMessage.error(err?.message || '月结失败')
					loadCheck()
				})
				.finally(() => (executing.value = false))
		})
		.catch(() => {})
}

function reset() {
	step.value = 0
	done.period = ''
	done.closed_by_name = ''
	done.closed_at = ''
	loadPeriods()
}

const footerTips = computed(() => {
	if (isClosed.value) return '该期间已结账并锁定，仅可查看'
	const tips = [
		'选择需要结账的会计年与月，系统将自动执行结账前预检',
		'核对结账预检，确认无误后一键月结（不可撤销）',
		'月结完成，期间已锁定',
	]
	return tips[Math.min(step.value, tips.length - 1)]
})

watch(pk, loadCheck)

onMounted(() => {
	step.value = 0
	loadPeriods()
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

/* ----- 预检 ----- */
.precheck {
	margin-top: var(--space-xl);
}
.precheck-head {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: var(--space-md);
}
.precheck-title {
	font-size: var(--font-h2);
	font-weight: 600;
	color: var(--text-primary);
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
.check-card.is-fail {
	border-color: var(--color-danger);
}
.check-card.is-warn {
	border-color: var(--color-warning);
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
.cc-title {
	font-size: var(--font-h3);
	font-weight: 600;
	color: var(--text-primary);
	flex: 1;
}
.cc-tag {
	margin-left: auto;
}
.cc-desc {
	font-size: var(--font-aux);
	color: var(--text-secondary);
	line-height: 18px;
}

/* ----- Step2 ----- */
.confirm-card {
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

/* ----- Step3 ----- */
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
</style>