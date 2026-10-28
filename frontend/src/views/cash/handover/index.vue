<template>
	<div class="page">
		<div class="page-header"><span class="title">收入上交款单</span></div>

		<div class="body">
			<!-- 左：单据列表 + 筛选 -->
			<el-card shadow="never" class="list-card">
				<div class="card-head">
					<span class="card-title">上交款单列表</span>
					<el-button v-permission="'cash:handover:create'" type="primary" size="small" @click="resetForm">
						<el-icon><Plus /></el-icon><span>新上交款</span>
					</el-button>
				</div>
				<div class="filter">
					<el-select v-model="filters.salesman_id" placeholder="业务员" clearable filterable style="width:120px">
						<el-option v-for="s in salesmen" :key="s.id" :label="s.name" :value="s.id" />
					</el-select>
					<el-select v-model="filters.status" placeholder="状态" clearable style="width:100px">
						<el-option label="待确认" value="pending" />
						<el-option label="已确认" value="confirmed" />
					</el-select>
					<el-input v-model="filters.no" placeholder="单号" clearable style="width:130px" @keyup.enter="onSearch" />
					<el-button type="primary" :loading="loading" @click="onSearch">查询</el-button>
					<el-button @click="resetFilter">重置</el-button>
				</div>
				<el-table v-loading="loading" :data="rows" stripe height="100%" empty-text="暂无单据" @row-click="openDetail">
					<el-table-column prop="no" label="单号" width="130" show-overflow-tooltip>
						<template #default="{ row }"><span class="tabular no">{{ row.no }}</span></template>
					</el-table-column>
					<el-table-column prop="date" label="上交日期" width="96" align="center">
						<template #default="{ row }"><span class="tabular">{{ fmtDay(row.date) }}</span></template>
					</el-table-column>
					<el-table-column label="业务员" width="86" align="center">
						<template #default="{ row }">{{ row.salesman_name || '—' }}</template>
					</el-table-column>
					<el-table-column label="金额" width="108" align="right">
						<template #default="{ row }"><span class="tabular amt">{{ fmtMoney(row.amount) }}</span></template>
					</el-table-column>
					<el-table-column label="状态" width="82" align="center">
						<template #default="{ row }">
							<el-tag :type="HANDOVER_STATUS[row.status]?.tag || 'info'" size="small" effect="light">
								{{ row.status_label || HANDOVER_STATUS[row.status]?.label || row.status }}
							</el-tag>
						</template>
					</el-table-column>
				</el-table>
				<div class="list-foot">
					<el-pagination
						v-model:current-page="page"
						small
						layout="total, prev, pager, next"
						:page-size="pageSize"
						:total="total"
						@current-change="loadList"
					/>
				</div>
			</el-card>

			<!-- 右：单据录入 -->
			<el-card shadow="never" class="form-card">
				<div class="card-head">
					<span class="card-title">单据录入 · 新建</span>
				</div>

				<el-form ref="formRef" :model="form" :rules="rules" label-width="84px" class="head-form">
					<el-row :gutter="16">
						<el-col :span="8">
							<el-form-item label="上交日期" prop="handover_date">
								<el-date-picker v-model="form.handover_date" type="date" value-format="YYYY-MM-DD" placeholder="选择日期" style="width:100%" />
							</el-form-item>
						</el-col>
						<el-col :span="8">
							<el-form-item label="上交人" prop="salesman_id">
								<el-select v-model="form.salesman_id" placeholder="选择业务员" clearable filterable style="width:100%">
									<el-option v-for="s in salesmen" :key="s.id" :label="s.name" :value="s.id" />
								</el-select>
							</el-form-item>
						</el-col>
						<el-col :span="8">
							<el-form-item label="收款账户" prop="account_id">
								<el-select v-model="form.account_id" placeholder="选择账户" style="width:100%">
									<el-option v-for="a in accounts" :key="a.id" :label="a.name" :value="a.id" />
								</el-select>
							</el-form-item>
						</el-col>
						<el-col :span="8">
							<el-form-item label="金额" prop="amount">
								<el-input-number v-model="form.amount" :min="0" :precision="2" :step="100" controls-position="right" style="width:100%" />
							</el-form-item>
						</el-col>
					</el-row>
					<el-form-item label="备注">
						<el-input v-model="form.remark" type="textarea" :rows="2" maxlength="200" show-word-limit placeholder="选填" />
					</el-form-item>
				</el-form>

				<!-- 金额校验：一致时绿色提示，不一致时黄色告警条 -->
				<div v-if="items.length && amountMatch" class="check-line">
					<span class="ok"><el-icon><Check /></el-icon>明细合计与表头金额一致（{{ fmtMoney(itemSum) }}）</span>
				</div>
				<el-alert
					v-else
					class="warn-bar"
					type="warning"
					:closable="false"
					show-icon
					:title="items.length
						? `明细合计 ${fmtMoney(itemSum)} 与表头金额 ${fmtMoney(form.amount)} 不符，差额 ${fmtMoney(diff)}`
						: '明细「本次上交」合计需与表头金额一致'"
				/>

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
									<el-descriptions-item label="业务员">{{ row.salesman_name || '—' }}</el-descriptions-item>
									<el-descriptions-item label="订单金额"><span class="tabular">{{ fmtMoney(row.amount) }}</span></el-descriptions-item>
									<el-descriptions-item label="已收款"><span class="tabular">{{ fmtMoney(row.paid_amount) }}</span></el-descriptions-item>
									<el-descriptions-item label="未收款" :span="2"><span class="tabular">{{ fmtMoney(row.unpaid_amount) }}</span></el-descriptions-item>
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
					<el-table-column label="本次上交" width="140" align="right">
						<template #default="{ row }">
							<el-input-number v-model="row.handover_amount" :min="0" :max="row.unpaid_amount" :precision="2" :step="100" controls-position="right" size="small" style="width:100%" />
						</template>
					</el-table-column>
					<el-table-column label="操作" width="72" align="center">
						<template #default="{ $index }">
							<el-button link type="danger" size="small" @click="removeItem($index)">删除</el-button>
						</template>
					</el-table-column>
				</el-table>

				<div class="actions">
					<el-button text @click="resetForm">重置</el-button>
					<el-button v-permission="'cash:handover:create'" type="primary" :loading="saving" :disabled="!canSave" @click="onSave">保存上交款单</el-button>
				</div>
			</el-card>
		</div>

		<!-- 单据详情 -->
		<el-drawer v-model="detailVisible" :title="`上交款单 · ${detail?.no || ''}`" size="640px" append-to-body>
			<template v-if="detail">
				<el-tag :type="HANDOVER_STATUS[detail.status]?.tag || 'info'" size="small" style="margin-bottom:12px">
					{{ detail.status_label }}
				</el-tag>
				<el-descriptions :column="2" border size="small">
					<el-descriptions-item label="单号" :span="2"><span class="tabular">{{ detail.no }}</span></el-descriptions-item>
					<el-descriptions-item label="上交日期">{{ fmtDay(detail.date) }}</el-descriptions-item>
					<el-descriptions-item label="上交人">{{ detail.salesman_name || '—' }}</el-descriptions-item>
					<el-descriptions-item label="收款账户">{{ detail.account_name || '—' }}</el-descriptions-item>
					<el-descriptions-item label="金额"><span class="tabular">{{ fmtMoney(detail.amount) }}</span></el-descriptions-item>
					<el-descriptions-item label="关联订单数">{{ detail.order_count }}</el-descriptions-item>
					<el-descriptions-item label="确认人">{{ detail.confirmed_by || '—' }}</el-descriptions-item>
					<el-descriptions-item label="确认时间">{{ detail.confirmed_at || '—' }}</el-descriptions-item>
					<el-descriptions-item label="备注" :span="2">{{ detail.remark || '—' }}</el-descriptions-item>
				</el-descriptions>

				<div class="det-sub">关联订单明细</div>
				<el-table v-if="detail.items?.length" :data="detail.items" border size="small" class="tabular">
					<el-table-column prop="order_no" label="订单号" width="150" />
					<el-table-column prop="customer_name" label="客户" />
					<el-table-column label="订单金额" width="110" align="right">
						<template #default="{ row }"><span class="tabular">{{ fmtMoney(row.amount) }}</span></template>
					</el-table-column>
					<el-table-column label="本次上交" width="110" align="right">
						<template #default="{ row }"><span class="tabular">{{ fmtMoney(row.handover_amount) }}</span></template>
					</el-table-column>
				</el-table>
				<div v-else class="empty-note">暂无明细</div>
			</template>
		</el-drawer>

		<!-- 关联订单选择 -->
		<el-dialog v-model="pickerVisible" title="关联订单" width="860px" destroy-on-close>
			<div class="picker-filter">
				<el-input v-model="pickerKw" placeholder="订单号 / 客户关键字" clearable style="width:220px" @keyup.enter="loadOrders(1)" />
				<el-select v-model="pickerCustomerId" placeholder="客户" clearable filterable style="width:180px">
					<el-option v-for="c in customers" :key="c.id" :label="c.name" :value="c.id" />
				</el-select>
				<el-button type="primary" size="small" :loading="pickerLoading" @click="loadOrders(1)">查询</el-button>
			</div>
			<el-table
				ref="pickerRef"
				v-loading="pickerLoading"
				:data="pickerRows"
				row-key="id"
				@selection-change="onPickerSelection"
				max-height="440"
				:header-cell-style="{ textAlign: 'center' }"
			>
				<el-table-column type="selection" width="42" />
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
					<template #default="{ row }"><span class="tabular">{{ fmtMoney(row.unpaid_amount) }}</span></template>
				</el-table-column>
			</el-table>
			<div class="picker-foot">
				<el-pagination
					v-model:current-page="pickerPage"
					small
					layout="total, prev, pager, next"
					:page-size="pickerPageSize"
					:total="pickerTotal"
					@current-change="loadOrders()"
				/>
			</div>
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
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Check, Plus } from '@element-plus/icons-vue'
import cashApi from '@/api/cash'
import { HANDOVER_STATUS, fmtMoney, fmtDay, today } from '../shared'

/* ---------- 下拉选项 ---------- */
const accounts = ref([])
const salesmen = ref([])
const customers = ref([])

/* ---------- 左侧列表 ---------- */
const filters = reactive({ salesman_id: '', status: '', no: '' })
const rows = ref([])
const total = ref(0)
const page = ref(1)
const pageSize = ref(50)
const loading = ref(false)

async function loadList() {
	loading.value = true
	try {
		const res = await cashApi.handover.list.get({ ...filters, page: page.value, page_size: pageSize.value })
		const data = res.data || {}
		rows.value = data.list || []
		total.value = data.total || 0
	} catch (e) {
		ElMessage.error(e.message || '查询失败')
	} finally {
		loading.value = false
	}
}

const onSearch = () => {
	page.value = 1
	loadList()
}

const resetFilter = () => {
	Object.assign(filters, { salesman_id: '', status: '', no: '' })
	onSearch()
}

/* ---------- 表单 ---------- */
const blank = () => ({ handover_date: today(), salesman_id: '', account_id: '', amount: 0, remark: '' })
const form = reactive(blank())
const items = reactive([])
const formRef = ref(null)
const saving = ref(false)
const itemSum = computed(() => items.reduce((s, it) => s + Number(it.handover_amount || 0), 0))
const amountMatch = computed(() => Math.round(itemSum.value * 100) === Math.round(Number(form.amount || 0) * 100))
const diff = computed(() => Number(form.amount || 0) - itemSum.value)
// 金额为 0 的单服务端会按 min:0.01 拒绝，这里先拦住
const canSave = computed(() => items.length > 0 && amountMatch.value && itemSum.value > 0)

const rules = {
	handover_date: [{ required: true, message: '请选择上交日期', trigger: 'change' }],
	salesman_id: [{ required: true, message: '请选择上交人', trigger: 'change' }],
	account_id: [{ required: true, message: '请选择收款账户', trigger: 'change' }],
	amount: [{ required: true, message: '请输入金额', trigger: 'change' }],
}

const resetForm = () => {
	Object.assign(form, blank())
	items.splice(0, items.length)
	formRef.value?.clearValidate()
}

/* 点击列表行看详情（保存后单据不可改，需改在查询页作废前无入口） */
const detailVisible = ref(false)
const detail = ref(null)
const openDetail = async (row) => {
	try {
		const res = await cashApi.handover.detail.get(row.id)
		detail.value = res.data
		detailVisible.value = true
	} catch (e) {
		ElMessage.error(e.message || '详情加载失败')
	}
}

/* ---------- 保存 ---------- */
const onSave = () => {
	formRef.value.validate((ok) => {
		if (!ok) return ElMessage.warning('请完善单头必填项')
		if (!amountMatch.value) return ElMessage.error('明细「本次上交」合计需与表头金额一致')
		const salesman = salesmen.value.find((s) => s.id === form.salesman_id)
		ElMessageBox.confirm(
			`保存上交款单？共关联 ${items.length} 张订单，金额 ¥${fmtMoney(itemSum.value)}`,
			'保存确认',
			{ type: 'warning', confirmButtonText: '确认保存', cancelButtonText: '取消' }
		).then(async () => {
			saving.value = true
			try {
				const res = await cashApi.handover.create({
					handover_date: form.handover_date,
					salesman_id: form.salesman_id || null,
					salesman_name: salesman?.name || null,
					account_id: form.account_id,
					amount: itemSum.value,
					remark: form.remark || null,
					items: items.map((it) => ({
						sales_order_id: it.id,
						handover_amount: Number(it.handover_amount || 0),
					})),
				})
				ElMessage.success(res.message || `已保存，单号 ${res.data?.no}`)
				resetForm()
				page.value = 1
				loadList()
			} catch (e) {
				ElMessage.error(e.message || '保存失败')
			} finally {
				saving.value = false
			}
		}).catch(() => {})
	})
}

/* ---------- 关联订单弹窗（服务端分页，已关联的由后端排除） ---------- */
const pickerVisible = ref(false)
const pickerKw = ref('')
const pickerCustomerId = ref('')
const pickerRef = ref(null)
const pickerChecked = ref([])
const pickerRows = ref([])
const pickerTotal = ref(0)
const pickerPage = ref(1)
const pickerPageSize = ref(20)
const pickerLoading = ref(false)

async function loadOrders(p) {
	if (p) pickerPage.value = p
	pickerLoading.value = true
	try {
		const res = await cashApi.handover.orders.get({
			keyword: pickerKw.value.trim(),
			customer_id: pickerCustomerId.value,
			exclude_ids: items.map((it) => it.id),
			page: pickerPage.value,
			page_size: pickerPageSize.value,
		})
		const data = res.data || {}
		pickerRows.value = data.list || []
		pickerTotal.value = data.total || 0
		pickerChecked.value = []
	} catch (e) {
		ElMessage.error(e.message || '订单加载失败')
	} finally {
		pickerLoading.value = false
	}
}

const openOrderPicker = () => {
	pickerKw.value = ''
	pickerCustomerId.value = ''
	pickerChecked.value = []
	pickerVisible.value = true
	loadOrders(1)
}

const onPickerSelection = (sel) => { pickerChecked.value = sel }

const confirmPicker = () => {
	const exist = new Set(items.map((i) => i.id))
	const rest = pickerChecked.value.filter((r) => !exist.has(r.id))
	if (!rest.length) return ElMessage.info('选中订单已全部关联')
	rest.forEach((r) => {
		items.push({
			...r,
			handover_amount: r.unpaid_amount > 0 ? r.unpaid_amount : 0,
		})
	})
	pickerVisible.value = false
	ElMessage.success(`已关联 ${rest.length} 张订单`)
}

/* ---------- 明细行 ---------- */
const removeItem = (i) => items.splice(i, 1)

onMounted(async () => {
	loadList()
	try {
		const res = await cashApi.handover.options.get()
		accounts.value = res.data?.accounts || []
		salesmen.value = res.data?.salesmen || []
		customers.value = res.data?.customers || []
	} catch (e) {
		ElMessage.error(e.message || '选项加载失败')
	}
})
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
.list-foot { padding-top: var(--space-sm); display: flex; justify-content: flex-end; }

.filter { display: flex; gap: var(--space-sm); flex-wrap: wrap; padding-bottom: var(--space-md); }

.link { color: var(--color-primary); cursor: pointer; }
.amt { color: var(--text-primary); font-weight: 500; }
.no { color: var(--text-regular); }

.head-form { padding: var(--space-sm) 0 var(--space-md); border-bottom: 1px dashed var(--border-lighter); }

.check-line { margin: var(--space-md) 0; padding: var(--space-sm) var(--space-md); border-radius: var(--radius-md); font-size: 12px; background: var(--bg-hover); }
.check-line .ok { color: var(--color-success); display: inline-flex; align-items: center; gap: 4px; }
/* 连凯对标：不一致时黄色告警条 #FDF6EC / #E6A23C 12px（el-alert warning 原生即此配色，此处只锁字号） */
.warn-bar { margin: var(--space-md) 0; }
.warn-bar :deep(.el-alert__title) { font-size: 12px; }

.detail-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: var(--space-sm); }

.actions { display: flex; justify-content: flex-end; gap: var(--space-sm); margin-top: var(--space-md); }

.det-sub { margin: var(--space-lg) 0 var(--space-sm); font-size: var(--font-h3); font-weight: 600; color: var(--text-primary); }
.empty-note { padding: var(--space-xl) 0; text-align: center; color: var(--text-secondary); font-size: var(--font-base); }

.picker-filter { display: flex; gap: var(--space-sm); margin-bottom: var(--space-md); }
.picker-foot { display: flex; justify-content: flex-end; padding-top: var(--space-sm); }
</style>
