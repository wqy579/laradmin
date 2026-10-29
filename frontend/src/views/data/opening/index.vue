<template>
	<div class="page">
		<!-- 页头：与 finance/month-close 同构 -->
		<div class="page-header">
			<div class="ph-left">
				<el-icon class="ph-icon"><ElIconFolderOpened /></el-icon>
				<span class="title">开账向导</span>
				<span class="sub">科目设置 → 期初库存 → 期初余额 → 导入/确认</span>
			</div>
			<div v-if="wizard.id" class="ph-right">
				<span class="status-tag" :class="wizard.locked ? 'status-locked' : 'status-draft'">
					{{ wizard.locked ? '已开账锁定' : '草稿' }}
				</span>
				<span v-if="wizard.locked && wizard.locked_at" class="ph-meta tabular">
					锁定：{{ wizard.locked_at }} · {{ wizard.locked_by_name }}
				</span>
			</div>
		</div>

		<el-card shadow="never" class="card">
			<!-- 步骤条：后端 step 是 1 基，:active 传 step-1 -->
			<el-steps :active="step - 1" align-center class="steps">
				<el-step v-for="s in STEPS" :key="s.step" :title="s.title" :description="s.desc" />
			</el-steps>

			<!-- 主体定位：公司 + 事业部 + 期间 -->
			<div class="filter-bar">
				<el-select v-model="search.company_id" placeholder="请选择公司" filterable class="w220">
					<el-option v-for="c in companies" :key="c.id" :label="c.name" :value="c.id" />
				</el-select>
				<el-select v-model="search.division_id" placeholder="全部事业部（公司级）" clearable filterable class="w220">
					<el-option v-for="d in divisions" :key="d.id" :label="d.name" :value="d.id" />
				</el-select>
				<el-date-picker v-model="search.period" type="month" value-format="YYYY-MM" placeholder="期初期间" class="w140" />
				<el-button type="primary" class="btn-load" :loading="loading" @click="load">打 开</el-button>
			</div>

			<div v-loading="loading" class="step-body">
				<!-- ① 科目设置 -->
				<div v-show="step === 1" class="step-pane">
					<el-alert type="info" :closable="false" show-icon class="pane-alert">
						<p>开账前请先确认会计科目已维护完成：只有「末级科目」可以录入期初余额。</p>
						<p>科目在「总账 - 会计科目」中维护，本向导只读取，不在这里新增。</p>
					</el-alert>
					<div class="stat-row">
						<div class="stat-box">
							<span class="stat-label">末级科目数</span>
							<span class="stat-value tabular">{{ wizard.subject_count }}</span>
						</div>
						<el-tag v-if="isDone(1)" type="success" class="step-tag">
							已完成{{ stepBy(1).done_by_name ? ' · ' + stepBy(1).done_by_name : '' }}
						</el-tag>
						<el-tag v-else type="info" class="step-tag">待确认</el-tag>
					</div>
				</div>

				<!-- ② 期初库存 -->
				<div v-show="step === 2" class="step-pane">
					<div class="pane-toolbar">
						<span class="pane-tip">录入各仓库的期初结存；确认开账后写入真实库存。</span>
						<div class="pane-actions">
							<el-button v-permission="'data:opening:import'" @click="openImport(2)">Excel 导入</el-button>
							<el-button v-permission="'data:opening:edit'" type="primary" class="btn-add" :disabled="wizard.locked" @click="addStockRow">添加一行</el-button>
						</div>
					</div>
					<el-table :data="stockRows" border show-summary :summary-method="stockSummary"
						:header-cell-style="headerStyle" :row-style="rowStyle">
						<el-table-column label="商品编码" width="200">
							<template #default="{ row }">
								<el-select v-model="row.product_id" filterable placeholder="选择商品" size="small" class="w-full" :disabled="wizard.locked" @change="() => syncProduct(row)">
									<el-option v-for="p in products" :key="p.id" :label="p.label" :value="p.id" />
								</el-select>
							</template>
						</el-table-column>
						<el-table-column label="商品名称" min-width="140" show-overflow-tooltip>
							<template #default="{ row }">{{ productName(row.product_id) }}</template>
						</el-table-column>
						<el-table-column label="仓库" width="180">
							<template #default="{ row }">
								<el-select v-model="row.warehouse_id" filterable placeholder="选择仓库" size="small" class="w-full" :disabled="wizard.locked">
									<el-option v-for="w in warehouses" :key="w.id" :label="w.name" :value="w.id" />
								</el-select>
							</template>
						</el-table-column>
						<el-table-column label="数量" width="150" align="right">
							<template #default="{ row }">
								<el-input-number v-model="row.quantity" :precision="3" :controls="false" size="small" class="num-input" :disabled="wizard.locked" @change="recomputeStock(row)" />
							</template>
						</el-table-column>
						<el-table-column label="成本单价" width="150" align="right">
							<template #default="{ row }">
								<el-input-number v-model="row.cost_price" :precision="2" :controls="false" size="small" class="num-input" :disabled="wizard.locked" @change="recomputeStock(row)" />
							</template>
						</el-table-column>
						<el-table-column label="金额" align="right" class-name="tabular">
							<template #default="{ row }">{{ fmtMoney(row.quantity * row.cost_price) }}</template>
						</el-table-column>
						<el-table-column v-if="!wizard.locked" label="操作" width="70" fixed="right">
							<template #default="{ $index }">
								<span class="op-link op-danger" @click="stockRows.splice($index, 1)">删除</span>
							</template>
						</el-table-column>
						<template #empty><span class="empty-hint">暂无期初库存，请添加一行或使用 Excel 导入</span></template>
					</el-table>
				</div>

				<!-- ③ 期初余额：叶子科目可编辑，非末级只读置灰；试算红/绿 -->
				<div v-show="step === 3" class="step-pane">
					<div class="pane-toolbar">
						<span class="pane-tip">只录末级科目；口径与总账一致：期初 + 本年累计，借贷必须平衡。</span>
						<el-button v-permission="'data:opening:import'" @click="openImport(3)">Excel 导入</el-button>
					</div>
					<div class="balance-hint" :class="trial.balanced ? 'ok' : 'err'">
						<template v-if="trial.balanced">试算平衡：借方 {{ fmtMoney(trial.debit_total) }} = 贷方 {{ fmtMoney(trial.credit_total) }}</template>
						<template v-else>借贷不平衡，差额 {{ fmtMoney(trial.diff) }}（借方 {{ fmtMoney(trial.debit_total) }}，贷方 {{ fmtMoney(trial.credit_total) }}）</template>
					</div>
					<el-table :data="balanceRows" border show-summary :summary-method="balanceSummary"
						:header-cell-style="headerStyle" :row-style="rowStyle">
						<el-table-column label="科目编码" prop="subject_code" width="120" />
						<el-table-column label="科目名称" min-width="170">
							<template #default="{ row }">
								<span :class="{ 'non-leaf-name': !row.is_leaf }">{{ row.subject_name }}</span>
								<el-tag v-if="!row.is_leaf" size="small" type="info" class="nonleaf-tag">非末级</el-tag>
							</template>
						</el-table-column>
						<el-table-column label="期初借方" width="150" align="right">
							<template #default="{ row }">
								<el-input-number v-if="row.is_leaf && !wizard.locked" v-model="row.opening_debit" :precision="2" :controls="false" size="small" class="num-input" />
								<span v-else class="tabular">{{ fmtMoney(row.opening_debit) }}</span>
							</template>
						</el-table-column>
						<el-table-column label="期初贷方" width="150" align="right">
							<template #default="{ row }">
								<el-input-number v-if="row.is_leaf && !wizard.locked" v-model="row.opening_credit" :precision="2" :controls="false" size="small" class="num-input" />
								<span v-else class="tabular">{{ fmtMoney(row.opening_credit) }}</span>
							</template>
						</el-table-column>
						<el-table-column label="年初借方" width="150" align="right">
							<template #default="{ row }">
								<el-input-number v-if="row.is_leaf && !wizard.locked" v-model="row.year_debit" :precision="2" :controls="false" size="small" class="num-input" />
								<span v-else class="tabular">{{ fmtMoney(row.year_debit) }}</span>
							</template>
						</el-table-column>
						<el-table-column label="年初贷方" width="150" align="right">
							<template #default="{ row }">
								<el-input-number v-if="row.is_leaf && !wizard.locked" v-model="row.year_credit" :precision="2" :controls="false" size="small" class="num-input" />
								<span v-else class="tabular">{{ fmtMoney(row.year_credit) }}</span>
							</template>
						</el-table-column>
						<el-table-column v-if="!wizard.locked" label="操作" width="70" fixed="right">
							<template #default="{ $index }">
								<span class="op-link op-danger" @click="balanceRows.splice($index, 1)">删除</span>
							</template>
						</el-table-column>
						<template #empty><span class="empty-hint">暂无期初余额，请添加科目或使用 Excel 导入</span></template>
					</el-table>
				</div>

				<!-- ④ 导入 / 确认 -->
				<div v-show="step === 4" class="step-pane">
					<div class="confirm-grid">
						<div class="confirm-card">
							<div class="cc-title">期初库存</div>
							<div class="cc-row"><span>明细行数</span><span class="v tabular">{{ stockRows.length }}</span></div>
							<div class="cc-row"><span>库存金额合计</span><span class="v tabular">{{ fmtMoney(stockTotal) }}</span></div>
						</div>
						<div class="confirm-card">
							<div class="cc-title">期初余额（试算）</div>
							<div class="cc-row"><span>末级科目数</span><span class="v tabular">{{ trial.leaf_count || 0 }}</span></div>
							<div class="cc-row"><span>借方合计</span><span class="v tabular">{{ fmtMoney(trial.debit_total) }}</span></div>
							<div class="cc-row"><span>贷方合计</span><span class="v tabular">{{ fmtMoney(trial.credit_total) }}</span></div>
							<div class="balance-hint" :class="trial.balanced ? 'ok' : 'err'">
								{{ trial.balanced ? '试算平衡，可以确认开账' : '借贷不平衡，差额 ' + fmtMoney(trial.diff) }}
							</div>
						</div>
					</div>

					<div class="import-block">
						<div class="ib-title">Excel 导入</div>
						<div class="import-btns">
							<el-button @click="downloadTemplate('stock')">下载期初库存模板</el-button>
							<el-button @click="downloadTemplate('balance')">下载期初余额模板</el-button>
							<el-button v-permission="'data:opening:import'" type="primary" plain @click="openImport(2)">导入期初库存</el-button>
							<el-button v-permission="'data:opening:import'" plain @click="openImport(3)">导入期初余额</el-button>
						</div>
						<template v-if="importResult">
							<div class="import-summary">
								<span class="ok">成功 {{ importResult.success_count }} 条</span>
								<span :class="importResult.error_count > 0 ? 'err' : ''">失败 {{ importResult.error_count }} 条</span>
							</div>
							<div v-if="importResult.errors && importResult.errors.length" class="error-panel">
								<div v-for="(e, i) in importResult.errors" :key="i" class="error-line">{{ e }}</div>
							</div>
							<div v-if="importResult.unmatched && importResult.unmatched.length" class="unmatched">
								未匹配编码：{{ importResult.unmatched.join('、') }}
							</div>
						</template>
					</div>

					<el-alert v-if="!trial.balanced" type="warning" :closable="false" show-icon class="pane-alert">
						试算不平衡或步骤未完成时不能确认开账。
					</el-alert>
				</div>
			</div>

			<!-- 底部操作条 -->
			<div class="footer">
				<div class="ft-tips">
					<el-icon><ElIconInfoFilled /></el-icon>
					<span>{{ footerTips }}</span>
				</div>
				<div class="ft-btns">
					<el-button v-if="step > 1" :disabled="wizard.locked" @click="step--">上一步</el-button>
					<el-button v-if="step < 4" type="primary" :loading="saving" :disabled="wizard.locked" @click="saveCurrent">保存并下一步</el-button>
					<el-button v-if="step === 4 && !wizard.locked" v-permission="'data:opening:lock'" type="primary" :loading="locking" @click="onLock">确认开账并锁定</el-button>
					<el-button v-if="wizard.locked" v-permission="'data:opening:unlock'" type="danger" plain @click="onUnlock">反开账解锁</el-button>
				</div>
			</div>
		</el-card>

		<!-- 导入对话框：sImport 负责选文件 / 进度 / FormData 组装 -->
		<sImport
			ref="importRef"
			:title="importStep === 2 ? '导入期初库存' : '导入期初余额'"
			:api-method="dataApi.opening.importFile"
			:form-data="importFormData"
			:template-api="downloadCurrentTemplate"
			accept=".xlsx,.xls"
			@success="onImportSuccess"
			@error="load"
		/>
	</div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import sImport from '@/components/sImport/index.vue'
import dataApi from '@/api/data.js'
import { downloadApiResponse } from '@/utils/download'

const STEPS = [
	{ step: 1, title: '科目设置', desc: '确认会计科目已维护' },
	{ step: 2, title: '期初库存', desc: '录入各仓库期初结存' },
	{ step: 3, title: '期初余额', desc: '末级科目试算平衡' },
	{ step: 4, title: '导入/确认', desc: 'Excel 导入并锁定开账' },
]

const fmtMoney = (v) => Number(v || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const fmtQty = (v) => Number(v || 0).toLocaleString('zh-CN', { minimumFractionDigits: 3, maximumFractionDigits: 3 })

const loading = ref(false)
const saving = ref(false)
const locking = ref(false)
const step = ref(1)
const search = reactive({ company_id: null, division_id: null, period: '' })
const companies = ref([])
const divisions = ref([])
const products = ref([])
const warehouses = ref([])
const wizard = reactive({
	id: 0, status: 'draft', locked: false, step: 1,
	locked_at: '', locked_by_name: '', lock_report: {}, steps: [],
	subject_count: 0, stock_total: 0, trial: {}, logs: [],
})
const stockRows = ref([])
const balanceRows = ref([])
const importRef = ref(null)
const importStep = ref(2)
const importResult = ref(null)

const trial = computed(() => wizard.trial || {})
const footerTips = computed(() => [
	'请先确认会计科目已维护，末级科目才能录期初。',
	'同一商品同一仓库只能有一行；金额 = 数量 × 成本单价。',
	'期初 + 本年累计借贷必须平衡，口径与总账一致。',
	'确认开账后数据写入真实库存与总账期初，向导整单锁定。',
][step.value - 1])
const importFormData = computed(() => ({
	company_id: search.company_id || '',
	division_id: search.division_id || '',
	period: search.period,
	step: importStep.value,
}))

const isDone = (n) => {
	const s = (wizard.steps || []).find((x) => x.step === n)
	return !!s && s.status === 'done'
}
const stepBy = (n) => (wizard.steps || []).find((s) => s.step === n) || {}

// 表头/表体按 F6 视觉规格：48px 行高、斑马纹、悬停高亮（与公司/事业部档案一致）
const headerStyle = {
	background: '#F5F7FA',
	color: '#606266',
	fontWeight: '500',
	fontSize: '14px',
	height: '48px',
	borderBottom: '1px solid #EBEEF5',
}
const rowStyle = ({ rowIndex }) => ({
	height: '48px',
	fontSize: '14px',
	color: '#606266',
	background: rowIndex % 2 === 0 ? '#ffffff' : '#FAFAFA',
})

const stockTotal = computed(() => stockRows.value.reduce((s, r) => s + Number(r.quantity || 0) * Number(r.cost_price || 0), 0))
const stockSummary = () => ['合计', '', '', fmtQty(0), '', fmtMoney(stockTotal.value), '']
const balanceSummary = () => {
	let d1 = 0; let c1 = 0; let d2 = 0; let c2 = 0
	for (const r of balanceRows.value) {
		d1 += Number(r.opening_debit || 0)
		c1 += Number(r.opening_credit || 0)
		d2 += Number(r.year_debit || 0)
		c2 += Number(r.year_credit || 0)
	}
	return ['合计', '', '', fmtMoney(d1), fmtMoney(c1), fmtMoney(d2), fmtMoney(c2), '']
}

const addStockRow = () => stockRows.value.push({ product_id: null, warehouse_id: null, quantity: 0, cost_price: 0, total_amount: 0 })
const recomputeStock = (row) => { row.total_amount = Math.round(Number(row.quantity || 0) * Number(row.cost_price || 0) * 100) / 100 }
const syncProduct = (row) => {
	const p = products.value.find((x) => x.id === row.product_id)
	if (p) row.product_name = p.name
}

const openImport = (n) => {
	importStep.value = n
	importResult.value = null
	importRef.value?.open()
}
const downloadCurrentTemplate = () => dataApi.opening.template(importStep.value === 2 ? 'stock' : 'balance')
const downloadTemplate = async (kind) => {
	try {
		const res = await dataApi.opening.template(kind)
		downloadApiResponse(res, kind === 'stock' ? '期初库存导入模板.xlsx' : '期初余额导入模板.xlsx')
	} catch { /* 拦截器已提示 */ }
}
const onImportSuccess = (data) => {
	importResult.value = data
	load(true)
}

const load = async (silent = false) => {
	if (!search.company_id || !search.period) {
		ElMessage.warning('请先选择公司与期初期间')
		return
	}
	if (!silent) loading.value = true
	try {
		const res = await dataApi.opening.show({ company_id: search.company_id, division_id: search.division_id || '', period: search.period })
		if (res.code !== 200) return
		Object.assign(wizard, res.data || {})
		stockRows.value = (res.data.stocks || []).map((r) => ({ ...r }))
		balanceRows.value = (res.data.balances || []).map((r) => ({ ...r }))
		step.value = Math.min(Math.max(res.data.active_step || 1, 1), 4)
	} catch { /* 拦截器已提示 */ } finally {
		loading.value = false
	}
}

const payload = () => ({
	company_id: search.company_id,
	division_id: search.division_id || '',
	period: search.period,
})

const saveCurrent = async () => {
	saving.value = true
	try {
		const body = { company_id: search.company_id, division_id: search.division_id || '', period: search.period, rows: step.value === 2 ? stockRows.value : balanceRows.value }
		const res = await dataApi.opening.saveStep(step.value, body)
		if (res.code !== 200) return
		ElMessage.success(res.message || '已保存')
		step.value++
		load(true)
	} catch { /* 拦截器已提示 */ } finally {
		saving.value = false
	}
}

const onLock = () => {
	ElMessageBox.confirm(
		`确认开账？确认后 ${search.period} 的期初库存与期初余额将写入真实库存和总账，向导锁定，所有数据不能再修改。`,
		'操作确认',
		{ confirmButtonText: '确认开账', cancelButtonText: '取消', type: 'warning' }
	)
		.then(() => dataApi.opening.lock(baseParams()))
		.then((res) => {
			if (res.code !== 200) return
			ElMessage.success('开账已确认并锁定')
			load()
		})
		.catch(() => {})
}

const onUnlock = () => {
	ElMessageBox.prompt('反开账会把已锁定的向导退回草稿；已写入的库存与总账期初不会自动删除，需要人工核对修正。请填写反开账原因：', '操作确认', {
		confirmButtonText: '确认解锁',
		cancelButtonText: '取消',
		inputPlaceholder: '请填写反开账原因（必填，最多 200 字）',
		inputValidator: (v) => (v && v.trim() ? true : '请填写反开账原因'),
		type: 'warning',
	})
		.then(({ value }) => dataApi.opening.unlock({ ...baseParams(), reason: value.trim() }))
		.then((res) => {
			if (res.code !== 200) return
			ElMessage.success('已解锁，可继续修改（已写入的库存与总账期初不会自动删除）')
			load()
		})
		.catch(() => {})
}

onMounted(async () => {
	try {
		const [co, dv] = await Promise.all([
			dataApi.company.options.get(),
			dataApi.division.options.get(),
		])
		if (co.code === 200) companies.value = co.data?.list || []
		if (dv.code === 200) divisions.value = dv.data?.list || []
	} catch { /* 拦截器已提示 */ }
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
.ph-left { display: flex; align-items: center; gap: var(--space-sm); }
.ph-icon { font-size: var(--font-h1); color: var(--color-primary); }
.title { font-size: var(--font-h1); font-weight: 600; color: var(--text-primary); line-height: 28px; }
.sub { font-size: var(--font-aux); color: var(--text-secondary); }
.ph-right { display: flex; align-items: center; gap: var(--space-md); }
.ph-meta { font-size: var(--font-base); color: var(--text-secondary); }

.card {
	border-radius: 0 0 var(--radius-md) var(--radius-md);
	border: 1px solid var(--border-lighter);
	border-top: none;
}
.card :deep(.el-card__body) { padding: 0; }

/* ===== 步骤条（与 finance/month-close 同一套规则） ===== */
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

/* ===== 主体定位条 ===== */
.filter-bar {
	display: flex;
	align-items: center;
	gap: var(--space-sm);
	padding: var(--space-md) var(--space-xl);
	border-bottom: 1px solid var(--border-lighter);
}
.w220 { width: 220px; }
.w-full { width: 100%; }
.btn-load { height: 36px; border-radius: 4px; }

/* ===== 步骤内容 ===== */
.step-body {
	padding: var(--space-xl);
	min-height: 360px;
}
.step-pane { animation: fade 0.25s ease; }
@keyframes fade {
	from { opacity: 0; transform: translateY(6px); }
	to { opacity: 1; transform: none; }
}

/* ===== 表格（views/data 的规格：48px 行高 / 斑马纹 / 悬停高亮） ===== */
.num-input { width: 100%; }
.num-input :deep(.el-input__inner) { text-align: right; }
.cell-amt,
.cell-amt2 { color: #303133; }
.non-leaf-name { color: #909399; }
.nonleaf-tag { margin-left: 6px; font-size: 12px; }
:deep(.non-leaf-row) { background: #fafafa !important; color: #909399; }

/* 试算红/绿（与 ledger/opening 同色） */
.balance-hint { margin: 12px 0; font-size: 14px; font-weight: 700; }
.balance-hint.ok { color: #67c23a; }
.balance-hint.err { color: #f56c6c; }

/* 状态标签（12px）与操作链接 */
.status-tag { display: inline-block; padding: 2px 10px; font-size: 12px; border-radius: 4px; }
.step-tag { color: #67C23A; background: #F0F9EB; }
.nonleaf-tag { color: #909399; }
.op-link { color: #409eff; cursor: pointer; font-size: 14px; }
.op-link:hover { text-decoration: underline; }
.op-danger { color: #f56c6c; }
.empty-hint { font-size: 14px; color: #909399; }

/* 底部操作条 */
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
.ft-btns { display: flex; gap: var(--space-sm); }
</style>
