<template>
	<div class="asset-page">
		<div class="asset-head">
			<div class="asset-title">资产购置单</div>
			<div class="asset-sub">购置完成后自动生成资产卡片，并可选择登记付款关联</div>
		</div>

		<div class="asset-body-wrap">
			<!-- 表单卡 -->
			<div class="form-card">
				<div class="form-sec-title">单据信息</div>
				<el-form ref="formRef" :model="form" :rules="rules" label-width="112px" label-position="right">
					<el-row :gutter="20">
						<el-col :span="8">
							<el-form-item label="资产名称" prop="name">
								<el-input v-model="form.name" placeholder="如：注塑机" clearable />
							</el-form-item>
						</el-col>
						<el-col :span="8">
							<el-form-item label="类别" prop="category">
								<el-select v-model="form.category" placeholder="请选择" style="width: 100%">
									<el-option v-for="c in ASSET_CATEGORIES" :key="c.value" :label="c.label" :value="c.value" />
								</el-select>
							</el-form-item>
						</el-col>
						<el-col :span="8">
							<el-form-item label="规格" prop="spec">
								<el-input v-model="form.spec" placeholder="如：JS-100T" clearable />
							</el-form-item>
						</el-col>
					</el-row>
					<el-row :gutter="20">
						<el-col :span="8">
							<el-form-item label="数量" prop="quantity">
								<el-input-number v-model="form.quantity" :min="1" :precision="0" style="width: 100%" />
							</el-form-item>
						</el-col>
						<el-col :span="8">
							<el-form-item label="单价" prop="price">
								<el-input-number v-model="form.price" :min="0" :precision="2" style="width: 100%" />
							</el-form-item>
						</el-col>
						<el-col :span="8">
							<el-form-item label="原值（金额）" prop="original">
								<div class="amount-read">
									<span class="tabular amt-big">{{ fmtMoney(form.original) }}</span>
									<span class="amt-unit">元</span>
									<span class="amt-tip">数量 × 单价 自动计算</span>
								</div>
							</el-form-item>
						</el-col>
					</el-row>
					<el-row :gutter="20">
						<el-col :span="8">
							<el-form-item label="供应商" prop="supplier">
								<el-select v-model="form.supplier" placeholder="请选择" clearable style="width: 100%">
									<el-option v-for="s in SUPPLIERS" :key="s.value" :label="s.label" :value="s.value" />
								</el-select>
							</el-form-item>
						</el-col>
						<el-col :span="8">
							<el-form-item label="购入日期" prop="buy_date">
								<el-date-picker
									v-model="form.buy_date"
									type="date"
									value-format="YYYY-MM-DD"
									placeholder="选择日期"
									style="width: 100%"
								/>
							</el-form-item>
						</el-col>
						<el-col :span="8">
							<el-form-item label="使用部门" prop="department">
								<el-select v-model="form.department" placeholder="请选择" style="width: 100%">
									<el-option v-for="d in DEPARTMENTS" :key="d.value" :label="d.label" :value="d.value" />
								</el-select>
							</el-form-item>
						</el-col>
					</el-row>
					<el-row :gutter="20">
						<el-col :span="8">
							<el-form-item label="经手人" prop="handler">
								<el-select v-model="form.handler" placeholder="请选择" clearable style="width: 100%">
									<el-option v-for="e in EMPLOYEES" :key="e.value" :label="e.label" :value="e.value" />
								</el-select>
							</el-form-item>
						</el-col>
						<el-col :span="8">
							<el-form-item label="预计使用月数" prop="months">
								<el-input-number v-model="form.months" :min="1" :step="12" style="width: 100%" />
							</el-form-item>
						</el-col>
						<el-col :span="8">
							<el-form-item label="残值率（%）" prop="salvage_rate">
								<el-input-number v-model="form.salvage_rate" :min="0" :max="50" :step="1" style="width: 100%" />
							</el-form-item>
						</el-col>
					</el-row>
					<el-row :gutter="20">
						<el-col :span="8">
							<el-form-item label="折旧方式" prop="dep_method">
								<el-select v-model="form.dep_method" style="width: 100%">
									<el-option v-for="m in DEP_METHODS" :key="m.value" :label="m.label" :value="m.value" />
								</el-select>
							</el-form-item>
						</el-col>
						<el-col :span="16">
							<el-form-item label="备注" prop="remark">
								<el-input v-model="form.remark" placeholder="选填" clearable />
							</el-form-item>
						</el-col>
					</el-row>
					<el-form-item label=" ">
						<el-checkbox v-model="form.gen_voucher">同时登记现金支出凭证（可选）</el-checkbox>
					</el-form-item>
				</el-form>

				<div class="form-foot">
					<el-button @click="resetForm">重置</el-button>
					<el-button type="primary" :loading="saving" @click="onSave">保存</el-button>
				</div>
			</div>

			<!-- 购置流水 -->
			<div class="list-card">
				<div class="list-head">
					<span class="list-title">购置流水</span>
					<span class="list-count tabular">共 {{ purchases.length }} 条</span>
				</div>
				<el-table
					:data="purchases"
					border
					size="small"
					height="100%"
					empty-text="暂无购置记录"
					row-key="id"
				>
					<el-table-column prop="id" label="单号" width="110">
						<template #default="{ row }"><span class="tabular">{{ row.id }}</span></template>
					</el-table-column>
					<el-table-column prop="name" label="资产名称" min-width="130" show-overflow-tooltip />
					<el-table-column prop="category" label="类别" width="100">
						<template #default="{ row }">{{ CAT_LABEL[row.category] || '—' }}</template>
					</el-table-column>
					<el-table-column prop="quantity" label="数量" width="70" align="right">
						<template #default="{ row }"><span class="tabular">{{ fmtCount(row.quantity) }}</span></template>
					</el-table-column>
					<el-table-column prop="amount" label="原值" width="120" align="right" header-align="right">
						<template #default="{ row }"><span class="tabular">{{ fmtMoney(row.amount) }}</span></template>
					</el-table-column>
					<el-table-column prop="buy_date" label="购入日期" width="110" align="center">
						<template #default="{ row }"><span class="tabular">{{ fmtDay(row.buy_date) }}</span></template>
					</el-table-column>
					<el-table-column prop="department" label="使用部门" width="100">
						<template #default="{ row }">{{ DEPT_LABEL[row.department] || '—' }}</template>
					</el-table-column>
					<el-table-column label="操作" width="70" align="right" header-align="right">
						<template #default="{ row }">
							<el-button type="primary" link @click="gotoCard(row)">卡片</el-button>
						</template>
					</el-table-column>
				</el-table>
			</div>
		</div>
	</div>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { useRouter } from 'vue-router'
import {
	ASSET_CATEGORIES,
	DEPARTMENTS,
	EMPLOYEES,
	SUPPLIERS,
	DEP_METHODS,
	CAT_LABEL,
	DEPT_LABEL,
	fmtMoney,
	fmtCount,
	fmtDay,
} from '../shared'
import { addPurchase, listPurchases } from '../store'

const router = useRouter()

/* ---------- 表单 ---------- */
const formRef = ref(null)
const saving = ref(false)

const defaultForm = () => ({
	name: '',
	category: '',
	spec: '',
	quantity: 1,
	price: 0,
	original: 0,
	supplier: '',
	buy_date: '',
	department: '',
	handler: '',
	months: 60,
	salvage_rate: 5,
	dep_method: 'straight',
	remark: '',
	gen_voucher: false,
})

const form = reactive(defaultForm())

/* 原值 = 数量 × 单价（自动计算，只读） */
watch(
	() => [form.quantity, form.price],
	() => {
		const amt = (Number(form.quantity) || 0) * (Number(form.price) || 0)
		form.original = Math.round(amt * 100) / 100
	},
	{ immediate: true }
)

const rules = {
	name: [{ required: true, message: '请输入资产名称', trigger: 'blur' }],
	category: [{ required: true, message: '请选择类别', trigger: 'change' }],
	quantity: [{ required: true, message: '请输入数量', trigger: 'change' }],
	price: [{ required: true, message: '请输入单价', trigger: 'change' }],
	original: [{ required: true, message: '原值需大于 0', trigger: 'change' }],
	supplier: [{ required: true, message: '请选择供应商', trigger: 'change' }],
	buy_date: [{ required: true, message: '请选择购入日期', trigger: 'change' }],
	department: [{ required: true, message: '请选择使用部门', trigger: 'change' }],
	handler: [{ required: true, message: '请选择经手人', trigger: 'change' }],
	months: [{ required: true, message: '请输入预计使用月数', trigger: 'change' }],
	salvage_rate: [{ required: true, message: '请输入残值率', trigger: 'change' }],
	dep_method: [{ required: true, message: '请选择折旧方式', trigger: 'change' }],
}

const purchases = computed(() => listPurchases())

const onSave = () => {
	if (!formRef.value) return
	formRef.value.validate((valid) => {
		if (!valid) return
		if (Number(form.original) <= 0) return ElMessage.warning('原值需大于 0（检查数量与单价）')
		saveNow()
	})
}

const saveNow = () => {
	saving.value = true
	// TODO(api) POST /api/asset/purchase —— 当前写 mock
	setTimeout(() => {
		const { purchase, card } = addPurchase({ ...form })
		saving.value = false
		ElMessageBox.alert(
			`购置单 ${purchase.id} 已保存<br/>资产卡片 ${card.code}（${card.name}）已生成，净值 ¥${fmtMoney(card.net)}`,
			'保存成功',
			{ dangerouslyUseHTMLString: true, confirmButtonText: '确定' }
		)
		resetForm()
	}, 300)
}

const resetForm = () => {
	Object.assign(form, defaultForm())
	formRef.value?.clearValidate()
}

const gotoCard = (row) => router.push({ path: '/asset/card', query: { focus: row.card_id } })
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
}
.form-card :deep(.el-form-item) {
	margin-bottom: var(--space-lg);
}
.form-card :deep(.el-form-item__label) {
	font-size: var(--font-base);
	color: var(--text-regular);
}
.form-card :deep(.el-input__inner),
.form-card :deep(.el-select__placeholder),
.form-card :deep(.el-input__wrapper .el-input__inner) {
	font-size: var(--font-base);
}
.amount-read {
	width: 100%;
	display: flex;
	align-items: baseline;
	gap: 6px;
}
.amt-big {
	font-size: var(--font-h3);
	font-weight: 600;
	color: var(--color-primary);
}
.amt-unit {
	font-size: var(--font-aux);
	color: var(--text-secondary);
}
.amt-tip {
	font-size: var(--font-aux);
	color: var(--text-secondary);
	margin-left: auto;
}
.form-foot {
	display: flex;
	justify-content: flex-end;
	gap: var(--space-md);
	padding-top: var(--space-sm);
	border-top: 1px solid var(--border-lighter);
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
.list-card :deep(.el-table) {
	flex: 1;
}
:deep(.el-table th.el-table__cell .cell) {
	font-weight: 600;
}
</style>
