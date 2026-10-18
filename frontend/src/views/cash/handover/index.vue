<template>
	<div class="page">
		<div class="page-header"><span class="title">收入上交款单</span></div>

		<div class="body">
			<!-- 左：单据列表 + 筛选 -->
			<el-card shadow="never" class="list-card">
				<div class="card-head">
					<span class="card-title">上交款单列表</span>
					<el-button type="primary" size="small" @click="resetForm">
						<el-icon><Plus /></el-icon><span>新建上交款单</span>
					</el-button>
				</div>
				<div class="filter">
					<el-select v-model="filters.salesman" placeholder="业务员" clearable style="width:120px">
						<el-option v-for="s in SALESMEN" :key="s.value" :label="s.label" :value="s.value" />
					</el-select>
					<el-select v-model="filters.customer" placeholder="客户" clearable style="width:140px">
						<el-option v-for="c in CUSTOMERS" :key="c.value" :label="c.label" :value="c.value" />
					</el-select>
					<el-input v-model="filters.min_amount" placeholder="金额起" clearable style="width:86px" />
					<el-input v-model="filters.max_amount" placeholder="金额止" clearable style="width:86px" />
					<el-select v-model="filters.pay_way" placeholder="收款方式" clearable style="width:108px">
						<el-option v-for="p in PAY_WAYS" :key="p.value" :label="p.label" :value="p.value" />
					</el-select>
					<el-button type="primary" @click="search">查询</el-button>
					<el-button @click="resetFilter">重置</el-button>
				</div>
				<el-table :data="filtered" stripe height="100%" empty-text="暂无单据" @row-click="openDetail">
					<el-table-column prop="no" label="单号" width="120" show-overflow-tooltip>
						<template #default="{ row }"><span class="tabular no">{{ row.no }}</span></template>
					</el-table-column>
					<el-table-column prop="date" label="上交日期" width="96" align="center">
						<template #default="{ row }"><span class="tabular">{{ fmtDay(row.date) }}</span></template>
					</el-table-column>
					<el-table-column label="业务员" width="86" align="center">
						<template #default="{ row }">{{ SM_LABEL[row.salesman] || '—' }}</template>
					</el-table-column>
					<el-table-column label="金额" width="108" align="right">
						<template #default="{ row }"><span class="tabular amt">{{ fmtMoney(row.amount) }}</span></template>
					</el-table-column>
					<el-table-column label="状态" width="82" align="center">
						<template #default="{ row }">
							<el-tag :type="HANDOVER_STATUS[row.status]?.tag || 'info'" size="small" effect="light">
								{{ HANDOVER_STATUS[row.status]?.label || row.status }}
							</el-tag>
						</template>
					</el-table-column>
				</el-table>
				<div class="list-foot">共 {{ filtered.length }} 单</div>
			</el-card>

			<!-- 右：单据录入 -->
			<el-card shadow="never" class="form-card">
				<div class="card-head">
					<span class="card-title">{{ isEdit ? '单据录入 · 修改' : '单据录入 · 新建' }}</span>
					<el-tag v-if="editId" type="info" size="small" effect="light">{{ editId }}</el-tag>
				</div>

				<el-form ref="formRef" :model="form" :rules="rules" label-width="84px" class="head-form">
					<el-row :gutter="16">
						<el-col :span="8">
							<el-form-item label="上交日期" prop="date">
								<el-date-picker v-model="form.date" type="date" value-format="YYYY-MM-DD" placeholder="选择日期" :disabled="editExists" style="width:100%" />
							</el-form-item>
						</el-col>
						<el-col :span="8">
							<el-form-item label="业务员" prop="salesman">
								<el-select v-model="form.salesman" placeholder="选择业务员" :disabled="editExists" style="width:100%">
									<el-option v-for="s in SALESMEN" :key="s.value" :label="s.label" :value="s.value" />
								</el-select>
							</el-form-item>
						</el-col>
						<el-col :span="8">
							<el-form-item label="客户" prop="customer">
								<el-select v-model="form.customer" placeholder="选择客户" filterable :disabled="editExists" style="width:100%">
									<el-option v-for="c in CUSTOMERS" :key="c.value" :label="c.label" :value="c.value" />
								</el-select>
							</el-form-item>
						</el-col>
						<el-col :span="8">
							<el-form-item label="收款方式" prop="pay_way">
								<el-select v-model="form.pay_way" placeholder="选择方式" :disabled="editExists" style="width:100%">
									<el-option v-for="p in PAY_WAYS" :key="p.value" :label="p.label" :value="p.value" />
								</el-select>
							</el-form-item>
						</el-col>
						<el-col :span="8">
							<el-form-item label="金额" prop="amount">
								<el-input-number v-model="form.amount" :min="0" :precision="2" :step="100" controls-position="right" :disabled="editExists" style="width:100%" />
							</el-form-item>
						</el-col>
						<el-col :span="8">
							<el-form-item label="经手人" prop="cashier">
								<el-select v-model="form.cashier" placeholder="选择经手人" style="width:100%">
									<el-option v-for="c in CASHIERS" :key="c.value" :label="c.label" :value="c.value" />
								</el-select>
							</el-form-item>
						</el-col>
					</el-row>
					<el-form-item label="备注">
						<el-input v-model="form.remark" type="textarea" :rows="2" maxlength="200" show-word-limit placeholder="选填" />
					</el-form-item>
				</el-form>

				<!-- 金额校验 -->
				<div class="check-line">
					<template v-if="items.length === 0">
						<span class="hint">请先「关联订单」，明细「本次上交」合计需与表头金额一致。</span>
					</template>
					<template v-else-if="amountMatch">
						<span class="ok"><el-icon><Check /></el-icon>明细合计与表头金额一致（{{ fmtMoney(itemSum) }}）</span>
					</template>
					<template v-else>
						<span class="err">明细合计 {{ fmtMoney(itemSum) }} 与表头金额 {{ fmtMoney(form.amount) }} 不符，差额 {{ fmtMoney(diff) }}</span>
					</template>
				</div>

				<!-- 明细 -->
				<div class="detail-head">
					<span class="card-title">上交明细</span>
					<el-button type="primary" plain size="small" @click="openOrderPicker">
						<el-icon><Plus /></el-icon><span>关联订单</span>
					</el-button>
				</div>
				<el-table :data="items" border height="300" empty-text="未关联订单" class="tabular">
					<el-table-column type="index" label="序号" width="50" align="center" />
					<el-table-column label="订单号" width="150" show-overflow-tooltip>
						<template #default="{ row }">
							<el-popover trigger="click" :width="320" placement="right">
								<template #reference>
									<span class="link">{{ row.order_no }}</span>
								</template>
								<el-descriptions :column="2" size="small" border>
									<el-descriptions-item label="订单号">{{ row.order_no }}</el-descriptions-item>
									<el-descriptions-item label="订单日期">{{ fmtDay(row.order_date) }}</el-descriptions-item>
									<el-descriptions-item label="客户">{{ row.customer_name }}</el-descriptions-item>
									<el-descriptions-item label="业务员">{{ row.salesman_name }}</el-descriptions-item>
									<el-descriptions-item label="订单金额"><span class="tabular">{{ fmtMoney(row.amount) }}</span></el-descriptions-item>
									<el-descriptions-item label="已收款"><span class="tabular">{{ fmtMoney(row.paid_amount) }}</span></el-descriptions-item>
									<el-descriptions-item label="未收款" :span="2"><span class="tabular">{{ fmtMoney(row.amount - row.paid_amount) }}</span></el-descriptions-item>
								</el-descriptions>
							</el-popover>
						</template>
					</el-table-column>
					<el-table-column label="客户" width="160" show-overflow-tooltip>
						<template #default="{ row }">{{ row.customer_name }}</template>
					</el-table-column>
					<el-table-column label="订单日期" width="110" align="center">
						<template #default="{ row }"><span class="tabular">{{ fmtDay(row.order_date) }}</span></template>
					</el-table-column>
					<el-table-column label="订单金额" width="120" align="right">
						<template #default="{ row }"><span class="tabular">{{ fmtMoney(row.amount) }}</span></template>
					</el-table-column>
					<el-table-column label="本次上交" width="120" align="right">
						<template #default="{ row }">
							<el-input-number v-model="row.handover_amount" :min="0" :max="row.amount - row.paid_amount" :precision="2" :step="100" controls-position="right" size="small" style="width:100%" />
						</template>
					</el-table-column>
					<el-table-column label="操作" width="80" align="center">
						<template #default="{ $index }">
							<el-button link type="danger" size="small" @click="removeItem($index)">移除</el-button>
						</template>
					</el-table-column>
				</el-table>

				<div class="actions">
					<el-button @click="resetForm">重置</el-button>
					<el-button type="primary" :disabled="!canSave" @click="onSave">保存上交款单</el-button>
				</div>
				<div class="todo">TODO(api) POST /api/cash/handover</div>
			</el-card>
		</div>

		<!-- 单据详情 -->
		<el-drawer v-model="detailVisible" :title="`上交款单 · ${detailRow?.no || ''}`" size="640px" append-to-body>
			<template v-if="detailRow">
				<el-tag :type="HANDOVER_STATUS[detailRow.status]?.tag || 'info'" size="small" style="margin-bottom:12px">
					{{ HANDOVER_STATUS[detailRow.status]?.label }}
				</el-tag>
				<el-descriptions :column="2" border size="small">
					<el-descriptions-item label="单号" :span="2"><span class="tabular">{{ detailRow.no }}</span></el-descriptions-item>
					<el-descriptions-item label="上交日期">{{ fmtDay(detailRow.date) }}</el-descriptions-item>
					<el-descriptions-item label="业务员">{{ SM_LABEL[detailRow.salesman] || '—' }}</el-descriptions-item>
					<el-descriptions-item label="客户">{{ CU_LABEL[detailRow.customer] || detailRow.customer_name || '—' }}</el-descriptions-item>
					<el-descriptions-item label="收款方式">{{ PAY_LABEL[detailRow.pay_way] || '—' }}</el-descriptions-item>
					<el-descriptions-item label="金额"><span class="tabular">{{ fmtMoney(detailRow.amount) }}</span></el-descriptions-item>
					<el-descriptions-item label="经手人">{{ CS_LABEL[detailRow.cashier] || detailRow.cashier_name || '—' }}</el-descriptions-item>
					<el-descriptions-item label="关联订单数">{{ detailRow.order_count }}</el-descriptions-item>
					<el-descriptions-item label="备注" :span="2">{{ detailRow.remark || '—' }}</el-descriptions-item>
				</el-descriptions>

				<div class="det-sub">关联订单明细</div>
				<el-table v-if="detailRow.items?.length" :data="detailRow.items" border size="small" class="tabular">
					<el-table-column prop="order_no" label="订单号" width="150" />
					<el-table-column prop="customer_name" label="客户" />
					<el-table-column label="订单金额" width="110" align="right">
						<template #default="{ row }"><span class="tabular">{{ fmtMoney(row.amount) }}</span></template>
					</el-table-column>
					<el-table-column label="本次上交" width="110" align="right">
						<template #default="{ row }"><span class="tabular">{{ fmtMoney(row.handover_amount) }}</span></template>
					</el-table-column>
				</el-table>
				<div v-else class="empty-note">历史单据未保存明细</div>
			</template>
		</el-drawer>

		<!-- 关联订单选择 -->
		<el-dialog v-model="pickerVisible" title="关联订单" width="860px" destroy-on-close>
			<div class="picker-filter">
				<el-input v-model="pickerKw" placeholder="订单号 / 客户关键字" clearable style="width:220px" />
				<el-button type="primary" size="small" plain @click="pickerChecked = []">清空已选</el-button>
			</div>
			<el-table
				ref="pickerRef"
				:data="pickerRows"
				row-key="id"
				@selection-change="onPickerSelection"
				max-height="440"
				:header-cell-style="{ textAlign: 'center' }"
			>
				<el-table-column type="selection" width="42" :selectable="canPick" />
				<el-table-column prop="order_no" label="订单号" width="150">
					<template #default="{ row }"><span class="tabular">{{ row.order_no }}</span></template>
				</el-table-column>
				<el-table-column prop="customer_name" label="客户" min-width="150" />
				<el-table-column label="订单日期" width="110" align="center">
					<template #default="{ row }"><span class="tabular">{{ fmtDay(row.order_date) }}</span></template>
				</el-table-column>
				<el-table-column label="订单金额" width="110" align="right">
					<template #default="{ row }"><span class="tabular">{{ fmtMoney(row.amount) }}</span></template>
				</el-table-column>
				<el-table-column label="已收款" width="100" align="right">
					<template #default="{ row }"><span class="tabular">{{ fmtMoney(row.paid_amount) }}</span></template>
				</el-table-column>
				<el-table-column label="可上交" width="100" align="right">
					<template #default="{ row }"><span class="tabular">{{ fmtMoney(row.amount - row.paid_amount) }}</span></template>
				</el-table-column>
			</el-table>
			<template #footer>
				<el-button @click="pickerVisible = false">取消</el-button>
				<el-button type="primary" :disabled="!pickerChecked.length" @click="confirmPicker">
					添加选中（{{ pickerChecked.length }}）
				</el-button>
			</template>
		</el-dialog>
	</div>
</template>

<script setup>
import { computed, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Check, Plus } from '@element-plus/icons-vue'
import {
	SALESMEN, CUSTOMERS, CASHIERS, PAY_WAYS,
	SM_LABEL, CU_LABEL, CS_LABEL, PAY_LABEL, HANDOVER_STATUS,
	fmtMoney, fmtDay, today,
} from '../shared'
import { listHandovers, listOrders, saveHandover, updateHandover } from '../store'

/* ---------- 列表筛选 ---------- */
const filters = reactive({ salesman: '', customer: '', min_amount: '', max_amount: '', pay_way: '' })
const rows = ref(listHandovers())
const filtered = computed(() => {
	const fa = Number(filters.min_amount)
	const ta = Number(filters.max_amount)
	return rows.value.filter((r) => {
		if (filters.salesman && r.salesman !== filters.salesman) return false
		if (filters.customer && r.customer !== filters.customer) return false
		if (filters.pay_way && r.pay_way !== filters.pay_way) return false
		const a = Number(r.amount)
		if (filters.min_amount !== '' && a < fa) return false
		if (filters.max_amount !== '' && a > ta) return false
		return true
	})
})
const search = () => ElMessage.success(`查询到 ${filtered.value.length} 单`)
const resetFilter = () => Object.assign(filters, { salesman: '', customer: '', min_amount: '', max_amount: '', pay_way: '' })

/* ---------- 表单 ---------- */
const blank = () => ({ date: today(), salesman: '', customer: '', pay_way: '', amount: 0, cashier: '', remark: '' })
const form = reactive(blank())
const items = reactive([])
const editId = ref('')
const formRef = ref(null)
const isEdit = computed(() => !!editId.value)
/* 载入已有单据后单头/金额锁定；明细仍可调整本次上交 */
const editExists = isEdit
const canSave = computed(() => items.length > 0 && amountMatch.value)
const itemSum = computed(() => items.reduce((s, it) => s + Number(it.handover_amount || 0), 0))
const amountMatch = computed(() => items.length > 0 && Math.round(itemSum.value * 100) === Math.round(Number(form.amount || 0) * 100))
const diff = computed(() => Number(form.amount || 0) - itemSum.value)

const rules = {
	date: [{ required: true, message: '请选择上交日期', trigger: 'change' }],
	salesman: [{ required: true, message: '请选择业务员', trigger: 'change' }],
	customer: [{ required: true, message: '请选择客户', trigger: 'change' }],
	pay_way: [{ required: true, message: '请选择收款方式', trigger: 'change' }],
	amount: [{ required: true, message: '请输入金额', trigger: 'change' }],
	cashier: [{ required: true, message: '请选择经手人', trigger: 'change' }],
}

const orderPool = ref(listOrders())
const pickedIds = computed(() => new Set(items.map((i) => i.id)))
const canPick = (row) => !pickedIds.value.has(row.id)

const resetForm = () => {
	Object.assign(form, blank())
	items.splice(0, items.length)
	editId.value = ''
	formRef.value?.clearValidate()
}

/* 点击列表行：待确认载入编辑，已确认仅打开详情 */
const openDetail = (row) => {
	if (row.status === 'confirmed') {
		detailRow.value = row
		detailVisible.value = true
		return
	}
	Object.assign(form, {
		date: row.date, salesman: row.salesman, customer: row.customer || '', pay_way: row.pay_way,
		amount: Number(row.amount), cashier: row.cashier, remark: row.remark || '',
	})
	items.splice(0, items.length)
	;(row.items || []).forEach((it) => items.push({ ...it }))
	editId.value = row.id
}

/* ---------- 保存 ---------- */
const onSave = () => {
	formRef.value.validate((ok) => {
		if (!ok) return ElMessage.warning('请完善单头必填项')
		if (!amountMatch.value) return ElMessage.error('明细「本次上交」合计需与表头金额一致')
		ElMessageBox.confirm(
			`${isEdit.value ? '保存修改' : '保存'}上交款单？共关联 ${items.length} 张订单，金额 ¥${fmtMoney(itemSum.value)}`,
			'保存确认',
			{ type: 'warning', confirmButtonText: '确认保存', cancelButtonText: '取消' }
		).then(() => {
			const payload = {
				date: form.date, salesman: form.salesman, customer: form.customer,
				pay_way: form.pay_way, amount: itemSum.value, cashier: form.cashier,
				remark: form.remark, items: items.map((it) => ({ ...it })),
			}
			const row = isEdit.value ? updateHandover(editId.value, payload) : saveHandover(payload)
			rows.value = listHandovers()
			ElMessage.success(isEdit.value ? `已保存修改，单号 ${row.no}` : `已保存，单号 ${row.no}，可在「上交款查询」中确认`)
			resetForm()
		}).catch(() => {})
	})
}

/* ---------- 关联订单弹窗 ---------- */
const pickerVisible = ref(false)
const pickerKw = ref('')
const pickerRef = ref(null)
const pickerChecked = ref([])
const pickerRows = computed(() => {
	const kw = pickerKw.value.trim()
	if (!kw) return orderPool.value
	return orderPool.value.filter(
		(r) => r.order_no.includes(kw) || r.customer_name.includes(kw)
	)
})
const openOrderPicker = () => {
	pickerKw.value = ''
	pickerChecked.value = []
	pickerVisible.value = true
}
const onPickerSelection = (sel) => { pickerChecked.value = sel }
const confirmPicker = () => {
	const rest = pickerChecked.value.filter((r) => !pickedIds.value.has(r.id))
	if (!rest.length) return ElMessage.info('选中订单已全部关联')
	rest.forEach((r) => {
		const remain = Math.round((r.amount - r.paid_amount) * 100) / 100
		items.push({
			id: r.id, order_no: r.order_no,
			customer: r.customer, customer_name: r.customer_name,
			salesman_name: r.salesman_name,
			order_date: r.order_date, amount: r.amount, paid_amount: r.paid_amount,
			handover_amount: remain > 0 ? remain : 0,
		})
	})
	pickerVisible.value = false
	ElMessage.success(`已关联 ${rest.length} 张订单`)
}

/* ---------- 明细行 ---------- */
const removeItem = (i) => items.splice(i, 1)

/* ---------- 详情抽屉 ---------- */
const detailVisible = ref(false)
const detailRow = ref(null)
const watchRowClick = () => {}

</script>

<style scoped>
.page { background: var(--bg-body); min-height: 100vh; }
.page-header { height: 50px; background: var(--bg-card); border-bottom: 1px solid var(--border-lighter); padding: 0 var(--space-xl); display: flex; align-items: center; }
.title { font-size: var(--font-h1); font-weight: 600; color: var(--text-primary); }
.body { display: flex; gap: var(--space-lg); padding: var(--space-lg) var(--space-xl); align-items: stretch; }

.list-card { width: 460px; flex-shrink: 0; display: flex; flex-direction: column; }
.form-card { flex: 1; min-width: 0; }

.card-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: var(--space-md); }
.card-title { font-size: var(--font-h3); font-weight: 600; color: var(--text-primary); }
.list-foot { padding-top: var(--space-sm); font-size: var(--font-aux); color: var(--text-secondary); }

.filter { display: flex; gap: var(--space-sm); flex-wrap: wrap; padding-bottom: var(--space-md); }

.link { color: var(--color-primary); cursor: pointer; }
.amt { color: var(--text-primary); font-weight: 500; }
.no { color: var(--text-regular); }

.head-form { padding: var(--space-sm) 0 var(--space-md); border-bottom: 1px dashed var(--border-lighter); }

.check-line { margin: var(--space-md) 0; padding: var(--space-sm) var(--space-md); border-radius: var(--radius-md); font-size: var(--font-aux); background: var(--bg-hover); }
.check-line .ok { color: var(--color-success); display: inline-flex; align-items: center; gap: 4px; }
.check-line .err { color: var(--color-danger); font-weight: 600; }
.check-line .hint { color: var(--text-secondary); }

.detail-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: var(--space-sm); }

.actions { display: flex; justify-content: flex-end; gap: var(--space-sm); margin-top: var(--space-md); }
.todo { margin-top: var(--space-xs); text-align: right; font-size: var(--font-aux); color: var(--text-placeholder); }

.det-sub { margin: var(--space-lg) 0 var(--space-sm); font-size: var(--font-h3); font-weight: 600; color: var(--text-primary); }
.empty-note { padding: var(--space-xl) 0; text-align: center; color: var(--text-secondary); font-size: var(--font-base); }

.picker-filter { display: flex; gap: var(--space-sm); margin-bottom: var(--space-md); }
</style>
