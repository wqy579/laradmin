<template>
	<div class="asset-page">
		<div class="asset-head">
			<div class="asset-title">资产变卖单</div>
			<div class="asset-sub">仅可选择「在用」资产；保存后资产状态改为「已售」，自动计算变卖损益并出凭证</div>
		</div>

		<div class="grid-wrap">
			<!-- 表单卡 -->
			<div class="panel form-panel">
				<div class="panel-head">
					<span class="panel-title">变卖信息</span>
				</div>
				<el-form ref="formRef" :model="form" :rules="rules" label-width="112px" label-position="right">
					<el-form-item label="资产" prop="card_id">
						<el-select
							v-model="form.card_id"
							placeholder="选择在用资产"
							filterable
							style="width: 100%"
							@change="onCardChange"
						>
							<el-option
								v-for="a in inUseCards"
								:key="a.id"
								:label="`${a.code}｜${a.name}`"
								:value="a.id"
							/>
						</el-select>
					</el-form-item>

					<!-- 资产净值提示条 -->
					<div v-if="card" class="card-banner">
						<div class="cb-left">
							<span class="cb-name">{{ card.name }}</span>
							<span class="cb-code tabular">{{ card.code }}</span>
						</div>
						<div class="cb-right">
							<span class="cb-item"><em>原值</em><b class="tabular">{{ fmtMoney(card.original) }}</b></span>
							<span class="cb-item"><em>累计折旧</em><b class="tabular">{{ fmtMoney(card.depreciated) }}</b></span>
							<span class="cb-item"><em>净值</em><b class="tabular strong">{{ fmtMoney(card.net) }}</b></span>
						</div>
					</div>

					<el-row :gutter="20">
						<el-col :span="12">
							<el-form-item label="变卖日期" prop="sale_date">
								<el-date-picker
									v-model="form.sale_date"
									type="date"
									value-format="YYYY-MM-DD"
									placeholder="选择日期"
									style="width: 100%"
								/>
							</el-form-item>
						</el-col>
						<el-col :span="12">
							<el-form-item label="变卖金额" prop="sale_amount">
								<el-input-number v-model="form.sale_amount" :min="0" :precision="2" style="width: 100%" />
							</el-form-item>
						</el-col>
					</el-row>
					<el-row :gutter="20">
						<el-col :span="12">
							<el-form-item label="买方" prop="buyer">
								<el-input v-model="form.buyer" placeholder="买方单位 / 个人" clearable />
							</el-form-item>
						</el-col>
						<el-col :span="12">
							<el-form-item label="经手人" prop="handler">
								<el-select v-model="form.handler" placeholder="请选择" clearable style="width: 100%">
									<el-option v-for="e in users" :key="e.id" :label="e.name" :value="e.name" />
								</el-select>
							</el-form-item>
						</el-col>
					</el-row>
					<el-row :gutter="20">
						<el-col :span="12">
							<el-form-item label="清理费用" prop="closing_fee">
								<el-input-number v-model="form.closing_fee" :min="0" :precision="2" style="width: 100%" />
							</el-form-item>
						</el-col>
						<el-col :span="12">
							<el-form-item label="备注" prop="remark">
								<el-input v-model="form.remark" placeholder="选填" clearable />
							</el-form-item>
						</el-col>
					</el-row>
				</el-form>

				<!-- 损益计算 -->
				<div class="pnl-box">
					<div class="pnl-row">
						<span class="pnl-k">变卖金额</span>
						<span class="pnl-v tabular">{{ fmtMoney(form.sale_amount) }}</span>
					</div>
					<div class="pnl-row">
						<span class="pnl-k">资产净值</span>
						<span class="pnl-v tabular">− {{ fmtMoney(card?.net || 0) }}</span>
					</div>
					<div class="pnl-divider"></div>
					<div class="pnl-row total">
						<span class="pnl-k">变卖损益</span>
						<span class="pnl-v tabular" :class="pnl >= 0 ? 'pnl-up' : 'pnl-down'">
							{{ pnl >= 0 ? '盈利' : '亏损' }} {{ fmtMoney(Math.abs(pnl)) }}
						</span>
					</div>
				</div>

				<div class="form-foot">
					<el-button @click="resetForm">重置</el-button>
					<el-button type="primary" :loading="saving" @click="onSave">保存</el-button>
				</div>
			</div>

			<!-- 变卖流水 -->
			<div class="panel">
				<div class="panel-head">
					<span class="panel-title">变卖流水</span>
					<span class="panel-count tabular">共 {{ sales.length }} 条</span>
				</div>
				<el-table :data="sales" border size="small" height="100%" empty-text="暂无变卖记录" row-key="id">
					<el-table-column prop="id" label="单号" width="110">
						<template #default="{ row }"><span class="tabular">{{ row.id }}</span></template>
					</el-table-column>
					<el-table-column prop="card_name" label="资产" min-width="130" show-overflow-tooltip />
					<el-table-column prop="sale_date" label="变卖日期" width="100" align="center">
						<template #default="{ row }"><span class="tabular">{{ fmtDay(row.sale_date) }}</span></template>
					</el-table-column>
					<el-table-column prop="sale_amount" label="变卖金额" width="110" align="right" header-align="right">
						<template #default="{ row }"><span class="tabular">{{ fmtMoney(row.sale_amount) }}</span></template>
					</el-table-column>
					<el-table-column prop="pnl" label="损益" width="100" align="right" header-align="right">
						<template #default="{ row }">
							<span class="tabular" :class="row.pnl >= 0 ? 'pnl-up' : 'pnl-down'">{{ fmtMoney(row.pnl) }}</span>
						</template>
					</el-table-column>
				</el-table>
			</div>
		</div>
	</div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import assetApi from '@/api/asset'
import { fmtMoney, fmtDay } from '../shared'

/* ---------- 表单 ---------- */
const formRef = ref(null)
const saving = ref(false)

const defaultForm = () => ({
	card_id: '',
	sale_date: '',
	sale_amount: 0,
	buyer: '',
	handler: '',
	closing_fee: 0,
	remark: '',
})

const form = reactive(defaultForm())

const rules = {
	card_id: [{ required: true, message: '请选择资产', trigger: 'change' }],
	sale_date: [{ required: true, message: '请选择变卖日期', trigger: 'change' }],
	sale_amount: [{ required: true, message: '请输入变卖金额', trigger: 'change' }],
	buyer: [{ required: true, message: '请输入买方', trigger: 'blur' }],
	handler: [{ required: true, message: '请选择经手人', trigger: 'change' }],
}

/* 数据源：在用的可选资产（后端 options 提供）+ 变卖流水 */
const inUseCards = ref([])
const sales = ref([])
const users = ref([])

const card = computed(() => inUseCards.value.find((c) => c.id === form.card_id) || null)

/* 默认变卖金额取资产净值，便于快速核对 */
watch(
	() => card.value,
	(c) => {
		if (c && Number(form.sale_amount || 0) === 0) {
			form.sale_amount = Number(c.net)
		}
	},
	{ immediate: true }
)

/* 变卖损益 = 变卖金额 − 净值 */
const pnl = computed(() => {
	const net = Number(card.value?.net || 0)
	const amt = Number(form.sale_amount || 0)
	return Math.round((amt - net) * 100) / 100
})

async function loadOptions() {
	try {
		const res = await assetApi.sale.options.get()
		const data = res.data || {}
		inUseCards.value = data.cards || []
		users.value = data.users || []
	} catch (e) {
		ElMessage.error(e.message || '加载下拉数据失败')
	}
}

async function loadSales() {
	try {
		const res = await assetApi.sale.list.get({ page: 1, page_size: 100 })
		sales.value = (res.data || {}).list || []
	} catch (e) {
		ElMessage.error(e.message || '加载变卖流水失败')
	}
}

const onCardChange = () => {
	if (card.value) form.sale_amount = Number(card.value.net)
}

const onSave = () => {
	if (!formRef.value) return
	formRef.value.validate((valid) => {
		if (!valid) return
		if (!card.value) return ElMessage.warning('请选择资产')
		if (Number(form.sale_amount || 0) <= 0) return ElMessage.warning('变卖金额需大于 0')
		ElMessageBox.confirm(
			`确认变卖 ${card.value.code}（${card.value.name}）？<br/>变卖金额 ¥${fmtMoney(form.sale_amount)}，资产净值 ¥${fmtMoney(card.value.net)}，${pnl >= 0 ? '盈利' : '亏损'} ¥${fmtMoney(Math.abs(pnl))}。<br/>保存后资产状态将改为「已售」，不可撤销。`,
			'确认变卖',
			{ confirmButtonText: '确认变卖', cancelButtonText: '取消', type: 'warning', dangerouslyUseHTMLString: true }
		).then(async () => {
			saving.value = true
			try {
				const res = await assetApi.sale.save({ ...form })
				const d = res.data || {}
				const p = Number(d.pnl || 0)
				ElMessageBox.alert(
					`变卖单 ${d.sale_no} 已保存<br/>资产 ${card.value.code} 状态已改为「已售」<br/>变卖损益：${p >= 0 ? '盈利' : '亏损'} ¥${fmtMoney(Math.abs(p))}`,
					'保存成功',
					{ dangerouslyUseHTMLString: true, confirmButtonText: '确定' }
				)
				resetForm()
				await Promise.all([loadOptions(), loadSales()])
			} catch (e) {
				ElMessage.error(e.message || '保存失败')
			} finally {
				saving.value = false
			}
		}).catch(() => {})
	})
}

const resetForm = () => {
	Object.assign(form, defaultForm())
	formRef.value?.clearValidate()
}

onMounted(() => {
	loadOptions()
	loadSales()
})
</script>

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
.grid-wrap {
	flex: 1;
	min-height: 0;
	display: grid;
	grid-template-columns: minmax(0, 1.15fr) minmax(0, 1fr);
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
.form-panel {
	overflow: auto;
	padding: 0 var(--space-xl) var(--space-xl);
	gap: var(--space-md);
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
.panel-count {
	font-size: var(--font-aux);
	color: var(--text-secondary);
}
.panel :deep(.el-table) {
	flex: 1;
}
.form-panel :deep(.el-form-item) {
	margin-bottom: var(--space-lg);
}
.form-panel :deep(.el-form-item__label) {
	font-size: var(--font-base);
	color: var(--text-regular);
}

/* 资产净值提示条 */
.card-banner {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: var(--space-md);
	background: var(--bg-hover);
	border-left: 3px solid var(--color-primary);
	border-radius: var(--radius-md);
	padding: 10px var(--space-lg);
	margin-bottom: var(--space-md);
}
.cb-name {
	font-size: var(--font-h3);
	font-weight: 600;
	color: var(--text-primary);
}
.cb-code {
	font-size: var(--font-aux);
	color: var(--text-secondary);
	margin-left: 8px;
}
.cb-right {
	display: flex;
	gap: var(--space-xl);
}
.cb-item {
	display: flex;
	flex-direction: column;
	gap: 2px;
	font-size: var(--font-aux);
	color: var(--text-secondary);
}
.cb-item em {
	font-style: normal;
}
.cb-item b {
	font-size: var(--font-base);
	font-weight: 600;
	color: var(--text-primary);
}
.cb-item b.strong {
	color: var(--color-primary);
}

/* 损益计算 */
.pnl-box {
	background: var(--bg-hover);
	border-radius: var(--radius-md);
	padding: var(--space-md) var(--space-lg);
	margin-bottom: var(--space-md);
}
.pnl-row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 6px 0;
	font-size: var(--font-base);
	color: var(--text-regular);
}
.pnl-v {
	font-weight: 600;
	color: var(--text-primary);
}
.pnl-divider {
	height: 1px;
	background: var(--border-lighter);
	margin: 4px 0;
}
.pnl-row.total {
	font-size: var(--font-h3);
	font-weight: 600;
	color: var(--text-primary);
}
.pnl-row.total .pnl-v {
	font-size: var(--font-h3);
}
/* 损益配色（DS 约定）：盈利红 #F56C6C、亏损绿 #67C23A */
.pnl-up {
	color: var(--color-danger);
}
.pnl-down {
	color: var(--color-success);
}

.form-foot {
	display: flex;
	justify-content: flex-end;
	gap: var(--space-md);
	padding-top: var(--space-sm);
	border-top: 1px solid var(--border-lighter);
}
:deep(.el-table th.el-table__cell .cell) {
	font-weight: 600;
}
</style>
