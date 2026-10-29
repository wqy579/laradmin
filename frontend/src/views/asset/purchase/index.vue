<template>
	<div class="fin-page asset-page">
		<div class="asset-head">
			<div class="asset-title">资产购置单</div>
			<div class="asset-sub">保存后按数量自动生成资产卡片（净值=原值、累计折旧 0），并可选登记现金支出凭证</div>
		</div>

		<div class="asset-body-wrap">
			<!-- 左：录入表单 -->
			<div class="form-card">
				<el-form ref="formRef" :model="form" :rules="rules" label-width="112px" label-position="right">
					<div class="form-sec-title">单据信息</div>
					<el-row :gutter="20">
						<el-col :span="8">
							<el-form-item label="资产名称" prop="asset_name">
								<el-input v-model="form.asset_name" placeholder="如：注塑机" clearable />
							</el-form-item>
						</el-col>
						<el-col :span="8">
							<el-form-item label="规格" prop="spec">
								<el-input v-model="form.spec" placeholder="如：JS-100T" clearable />
							</el-form-item>
						</el-col>
						<el-col :span="8">
							<el-form-item label="购置日期" prop="purchase_date">
								<el-date-picker
									v-model="form.purchase_date"
									type="date"
									value-format="YYYY-MM-DD"
									placeholder="选择日期"
									style="width: 100%"
								/>
							</el-form-item>
						</el-col>
					</el-row>
					<el-row :gutter="20">
						<el-col :span="8">
							<el-form-item label="数量" prop="quantity">
								<el-input-number v-model="form.quantity" :min="1" :max="9999" :precision="0" style="width: 100%" />
							</el-form-item>
						</el-col>
						<el-col :span="8">
							<el-form-item label="单价" prop="unit_price">
								<el-input-number v-model="form.unit_price" :min="0" :max="99999999" :precision="2" style="width: 100%" />
							</el-form-item>
						</el-col>
						<el-col :span="8">
							<el-form-item label="原值（金额）" prop="original_value">
								<el-input class="amt-readonly tabular" :model-value="fmtMoney(originalValue)" disabled>
									<template #suffix><span class="amt-unit">元</span></template>
								</el-input>
							</el-form-item>
						</el-col>
					</el-row>
					<el-row :gutter="20">
						<el-col :span="8">
							<el-form-item label="供应商" prop="supplier">
								<el-select
									v-model="form.supplier"
									placeholder="选择或输入新供应商"
									filterable
									allow-create
									default-first-option
									clearable
									style="width: 100%"
								>
									<el-option v-for="s in suppliers" :key="s.name" :label="s.name" :value="s.name" />
								</el-select>
							</el-form-item>
						</el-col>
						<el-col :span="16">
							<el-form-item label="备注" prop="remark">
								<el-input v-model="form.remark" placeholder="选填" clearable />
							</el-form-item>
						</el-col>
					</el-row>

					<div class="form-sec-title">折旧与使用</div>
					<el-row :gutter="20">
						<el-col :span="8">
							<el-form-item label="预计使用月数" prop="use_months">
								<el-input-number v-model="form.use_months" :min="1" :max="1200" :step="12" style="width: 100%" />
							</el-form-item>
						</el-col>
						<el-col :span="8">
							<el-form-item label="残值率（%）" prop="residual_rate">
								<el-input-number v-model="form.residual_rate" :min="0" :max="100" :step="1" style="width: 100%" />
							</el-form-item>
						</el-col>
						<el-col :span="8">
							<el-form-item label="折旧方式" prop="dep_method">
								<el-select v-model="form.dep_method" style="width: 100%">
									<el-option v-for="m in DEP_METHODS" :key="m.value" :label="m.label" :value="m.value" />
								</el-select>
							</el-form-item>
						</el-col>
					</el-row>
					<el-row :gutter="20">
						<el-col :span="8">
							<el-form-item label="使用部门" prop="department_id">
								<el-select v-model="form.department_id" placeholder="请选择" clearable style="width: 100%">
									<el-option v-for="d in departments" :key="d.id" :label="d.name" :value="d.id" />
								</el-select>
							</el-form-item>
						</el-col>
						<el-col :span="8">
							<el-form-item label="使用人" prop="user_id">
								<el-select v-model="form.user_id" placeholder="请选择" clearable filterable style="width: 100%">
									<el-option v-for="u in users" :key="u.id" :label="u.name" :value="u.id" />
								</el-select>
							</el-form-item>
						</el-col>
						<el-col :span="8">
							<el-form-item label=" ">
								<el-checkbox v-model="form.register_voucher">同时登记现金支出凭证（可选）</el-checkbox>
							</el-form-item>
						</el-col>
					</el-row>
				</el-form>

				<div class="form-foot">
					<el-button @click="resetForm">重置</el-button>
					<el-button type="primary" :loading="saving" @click="onSave">保存</el-button>
				</div>
			</div>

			<!-- 右：购置流水 -->
			<div class="list-card">
				<div class="list-head">
					<span class="list-title">购置流水</span>
					<span class="list-count tabular">共 {{ total }} 条</span>
				</div>

				<div class="fin-filter list-filter">
					<el-date-picker
						v-model="dateRange"
						type="daterange"
						value-format="YYYY-MM-DD"
						range-separator="至"
						start-placeholder="开始"
						end-placeholder="结束"
						style="width: 230px"
						@change="onDateChange"
					/>
					<el-input v-model="filters.purchase_no" placeholder="购置单号" clearable style="width: 140px" @keyup.enter="onSearch" />
					<el-input v-model="filters.asset_name" placeholder="资产名称" clearable style="width: 140px" @keyup.enter="onSearch" />
					<el-button type="primary" :loading="loading" @click="onSearch">查询</el-button>
					<el-button @click="onReset">重置</el-button>
				</div>

				<el-table v-loading="loading" :data="rows" border class="fin-table--dense" height="100%" row-key="id">
					<template #empty><FinEmpty text="暂无购置记录" /></template>
					<el-table-column prop="purchase_no" label="单号" width="130">
						<template #default="{ row }"><span class="tabular">{{ row.purchase_no }}</span></template>
					</el-table-column>
					<el-table-column prop="asset_name" label="资产名称" min-width="120" show-overflow-tooltip />
					<el-table-column prop="quantity" label="数量" width="70" align="right" header-align="right">
						<template #default="{ row }"><span class="tabular">{{ fmtCount(row.quantity) }}</span></template>
					</el-table-column>
					<el-table-column prop="original_value" label="原值" width="120" align="right" header-align="right">
						<template #default="{ row }"><span class="tabular">{{ fmtMoney(row.original_value) }}</span></template>
					</el-table-column>
					<el-table-column prop="purchase_date" label="购置日期" width="110" align="center">
						<template #default="{ row }"><span class="tabular">{{ fmtDay(row.purchase_date) }}</span></template>
					</el-table-column>
					<el-table-column prop="department_name" label="使用部门" width="100" show-overflow-tooltip>
						<template #default="{ row }">{{ row.department_name || '—' }}</template>
					</el-table-column>
					<el-table-column prop="voucher_id" label="凭证" width="70" align="center">
						<template #default="{ row }">
							<el-tag v-if="row.voucher_id" type="success" size="small" effect="light">已登记</el-tag>
							<span v-else class="muted">—</span>
						</template>
					</el-table-column>
					<el-table-column label="操作" width="70" align="right" header-align="right">
						<template #default="{ row }">
							<el-button type="primary" link @click="gotoCard(row)">卡片</el-button>
						</template>
					</el-table-column>
				</el-table>

				<div class="fin-pager">
					<el-pagination
						v-model:current-page="page"
						v-model:page-size="pageSize"
						:page-sizes="[20, 50, 100]"
						:total="total"
						layout="total, sizes, prev, pager, next"
						@size-change="load"
						@current-change="load"
					/>
				</div>
			</div>
		</div>
	</div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { ElMessage } from 'element-plus'
import { useRouter } from 'vue-router'
import assetApi from '@/api/asset'
import FinEmpty from '../../finance/_FinEmpty.vue'
import { DEP_METHODS, fmtMoney, fmtCount, fmtDay } from '../shared'

const router = useRouter()

const today = () => {
	const d = new Date()
	return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

/* ---------- 下拉数据 ---------- */
const departments = ref([])
const users = ref([])
const suppliers = ref([])

/* ---------- 表单 ---------- */
const formRef = ref(null)
const saving = ref(false)

const defaultForm = () => ({
	asset_name: '',
	spec: '',
	purchase_date: today(),
	quantity: 1,
	unit_price: 0,
	use_months: 60,
	residual_rate: 5,
	dep_method: 'straight',
	department_id: '',
	user_id: '',
	supplier: '',
	remark: '',
	register_voucher: false,
})

const form = reactive(defaultForm())

/* 原值仅用于展示；后端会重算，不一致直接 422 */
const originalValue = computed(() => Math.round((Number(form.quantity) || 0) * (Number(form.unit_price) || 0) * 100) / 100)

const rules = {
	asset_name: [{ required: true, message: '请输入资产名称', trigger: 'blur' }],
	purchase_date: [{ required: true, message: '请选择购置日期', trigger: 'change' }],
	quantity: [{ required: true, message: '请输入数量', trigger: 'change' }],
	unit_price: [{ required: true, message: '请输入单价', trigger: 'change' }],
	use_months: [{ required: true, message: '请输入预计使用月数', trigger: 'change' }],
	residual_rate: [{ required: true, message: '请输入残值率', trigger: 'change' }],
	dep_method: [{ required: true, message: '请选择折旧方式', trigger: 'change' }],
}

/* ---------- 购置流水 ---------- */
const rows = ref([])
const total = ref(0)
const page = ref(1)
const pageSize = ref(20)
const loading = ref(false)
const dateRange = ref([])
const filters = reactive({ purchase_no: '', asset_name: '', start_date: '', end_date: '' })

const onDateChange = (val) => {
	filters.start_date = val?.[0] || ''
	filters.end_date = val?.[1] || ''
}

async function load() {
	loading.value = true
	try {
		const res = await assetApi.purchase.list.get({
			start_date: filters.start_date,
			end_date: filters.end_date,
			purchase_no: filters.purchase_no,
			asset_name: filters.asset_name,
			page: page.value,
			page_size: pageSize.value,
		})
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
	load()
}

const onReset = () => {
	Object.assign(filters, { purchase_no: '', asset_name: '', start_date: '', end_date: '' })
	dateRange.value = []
	page.value = 1
	load()
}

/* ---------- 保存 ---------- */
const onSave = () => {
	if (!formRef.value) return
	formRef.value.validate(async (valid) => {
		if (!valid) return
		if (originalValue.value <= 0) return ElMessage.warning('原值需大于 0（检查数量与单价）')
		saving.value = true
		try {
			const res = await assetApi.purchase.save({ ...form, original_value: originalValue.value })
			ElMessage.success(res.message || '保存成功')
			resetForm()
			await loadOptions()
			page.value = 1
			load()
		} catch (e) {
			ElMessage.error(e.message || '保存失败')
		} finally {
			saving.value = false
		}
	})
}

const resetForm = () => {
	Object.assign(form, defaultForm())
	formRef.value?.clearValidate()
}

/* 台账按关键字定位该批卡片（编号前缀 = 取得年月，故用资产名称检索最直观） */
const gotoCard = (row) => router.push({ path: '/asset/card', query: { keyword: row.asset_name } })

async function loadOptions() {
	try {
		const res = await assetApi.purchase.options.get()
		const data = res.data || {}
		departments.value = data.departments || []
		users.value = data.users || []
		suppliers.value = data.suppliers || []
	} catch (e) {
		ElMessage.error(e.message || '下拉数据加载失败')
	}
}

onMounted(() => {
	loadOptions()
	load()
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
.asset-body-wrap {
	flex: 1;
	min-height: 0;
	display: grid;
	grid-template-columns: minmax(0, 1.5fr) minmax(0, 1fr);
	gap: var(--space-md);
}
.form-card {
	background: var(--bg-card);
	border-radius: var(--radius-lg);
	box-shadow: var(--shadow-card);
	padding: var(--space-xl);
	overflow: auto;
	display: flex;
	flex-direction: column;
	gap: var(--space-md);
}
.form-sec-title {
	font-size: var(--font-h3);
	font-weight: 600;
	color: var(--text-primary);
	padding-left: 8px;
	border-left: 3px solid var(--color-primary);
	line-height: 1.2;
	margin-bottom: var(--space-lg);
}
.form-card :deep(.el-form-item) {
	margin-bottom: var(--space-lg);
}
.form-card :deep(.el-form-item__label) {
	font-size: var(--font-base);
	color: var(--text-regular);
}
/* 原值由数量×单价自动算出，灰底只读 */
.amt-readonly :deep(.el-input__inner) {
	color: var(--text-regular);
	-webkit-text-fill-color: var(--text-regular);
	font-variant-numeric: tabular-nums;
}
.amt-unit {
	font-size: var(--font-aux);
	color: var(--text-secondary);
}
.form-foot {
	display: flex;
	justify-content: flex-end;
	gap: var(--space-md);
	padding-top: var(--space-sm);
	border-top: 1px solid var(--border-lighter);
	margin-top: auto;
}

.list-card {
	background: var(--bg-card);
	border-radius: var(--radius-lg);
	box-shadow: var(--shadow-card);
	display: flex;
	flex-direction: column;
	min-height: 0;
	overflow: hidden;
}
.list-head {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: var(--space-lg) var(--space-xl);
	border-bottom: 1px solid var(--border-lighter);
}
.list-title {
	font-size: var(--font-h3);
	font-weight: 600;
	color: var(--text-primary);
}
.list-count {
	font-size: var(--font-aux);
	color: var(--text-secondary);
}
.list-filter {
	display: flex;
	align-items: center;
	gap: var(--space-sm);
	flex-wrap: wrap;
}
/* D3 简报口径：表头 44px 13px/600，明细行 41px（覆盖 _shared.css 的 14px/500 + 48px） */
.list-card :deep(.el-table.fin-table--dense th.el-table__cell .cell) {
	font-size: var(--font-base);
	font-weight: 600;
}
.list-card :deep(.el-table.fin-table--dense .el-table__body .el-table__row) {
	height: 41px;
}
.list-card :deep(.el-table .el-table__body td.el-table__cell .cell) {
	font-size: var(--font-base);
	color: var(--text-primary);
}
.list-card .el-table {
	flex: 1;
	min-height: 0;
}
.list-card .fin-pager {
	padding: 0 var(--space-xl);
	border-top: 1px solid var(--border-lighter);
}
.muted {
	color: var(--text-placeholder);
}
</style>
