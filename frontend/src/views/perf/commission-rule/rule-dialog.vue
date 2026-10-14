<template>
	<el-dialog v-model="visible" :title="record ? '编辑方案' : '新增方案'" width="900px" class="perf-rule-dialog" header-class="perf-rule-dialog__header"
		:close-on-click-modal="false" @open="initForm">
		<el-form ref="formRef" :model="form" :rules="rules" label-width="110px" size="default">
			<!-- 基础信息 -->
			<div class="section-title">基础信息</div>
			<div class="section-body">
				<div class="form-row">
					<el-form-item label="方案名称" prop="name">
						<el-input v-model="form.name" placeholder="请输入方案名称" style="width: 320px" />
					</el-form-item>
					<el-form-item label="类型" prop="type">
						<el-select v-model="form.type" placeholder="选择类型" style="width: 180px">
							<el-option v-for="t in typeOptions" :key="t.value" :label="t.label" :value="t.value" />
						</el-select>
					</el-form-item>
				</div>
				<div class="form-row">
					<el-form-item label="生效日期">
						<el-date-picker v-model="form.start_date" type="date" value-format="YYYY-MM-DD" placeholder="生效日期" style="width: 150px" />
						<span class="date-sep">~</span>
						<el-date-picker v-model="form.end_date" type="date" value-format="YYYY-MM-DD" placeholder="失效日期" style="width: 150px" />
					</el-form-item>
					<el-form-item label="状态">
						<el-radio-group v-model="form.status">
							<el-radio value="active">启用</el-radio>
							<el-radio value="inactive">停用</el-radio>
						</el-radio-group>
					</el-form-item>
				</div>
				<el-form-item label="备注">
					<el-input v-model="form.remark" type="textarea" :rows="2" placeholder="备注说明（可选）" style="width: 520px" />
				</el-form-item>
			</div>

			<!-- 适用范围 -->
			<div class="section-title">适用范围</div>
			<div class="section-body">
				<el-form-item label="适用业务员">
					<el-radio-group v-model="form.scope">
						<el-radio value="all">全部业务员</el-radio>
						<el-radio value="specified">指定业务员</el-radio>
					</el-radio-group>
				</el-form-item>
				<el-form-item v-if="form.scope === 'specified'" label="选择业务员" prop="employee_ids">
					<el-select v-model="form.employee_ids" multiple filterable placeholder="请选择业务员（必选）" style="width: 420px">
						<el-option v-for="e in employees" :key="e.id" :label="e.name" :value="e.id" />
					</el-select>
				</el-form-item>
				<el-form-item label="适用客户等级">
					<el-select v-model="form.apply_customer_levels" multiple placeholder="默认全部" style="width: 420px">
						<el-option v-for="l in levels" :key="l.id" :label="l.name" :value="l.id" />
					</el-select>
				</el-form-item>
				<el-form-item label="适用商品范围">
					<el-radio-group v-model="form.product_scope_type">
						<el-radio value="all">全部商品</el-radio>
						<el-radio value="category">按分类</el-radio>
						<el-radio value="brand">按品牌</el-radio>
						<el-radio value="product">指定商品</el-radio>
					</el-radio-group>
				</el-form-item>
			</div>

			<!-- 提成方式 -->
			<div class="section-title">提成方式</div>
			<div class="section-body">
				<el-radio-group v-model="form.type" class="calc-cards">
					<el-radio-button v-for="t in typeOptions" :key="t.value" :value="t.value">{{ t.label }}</el-radio-button>
				</el-radio-group>

				<!-- 销售额提成 -->
				<div v-if="form.type === 'sales'" class="calc-params">
					<el-form-item label="提成比例">
						<el-input-number v-model="form.rate" :min="0" :max="100" :precision="1" :step="0.1" controls-position="right" />
						<span class="suffix">%</span>
					</el-form-item>
				</div>

				<!-- 利润提成 -->
				<div v-if="form.type === 'profit'" class="calc-params">
					<el-form-item label="提成比例">
						<el-input-number v-model="form.rate" :min="0" :max="100" :precision="1" :step="0.1" controls-position="right" />
						<span class="suffix">%</span>
					</el-form-item>
					<div class="hint-block">毛利 = 销售金额 − 成本金额 − 退货 − 分摊费用</div>
				</div>

				<!-- 按件计费 -->
				<div v-if="form.type === 'piece'" class="calc-params">
					<el-form-item label="数量基数">
						<el-select v-model="form.unit_base" placeholder="选择" style="width: 140px">
							<el-option label="按件" value="件" /><el-option label="按箱" value="箱" /><el-option label="按最小单位" value="最小单位" />
						</el-select>
					</el-form-item>
					<el-form-item label="固定金额">
						<span class="prefix">¥</span>
						<el-input-number v-model="form.unit_amount" :min="0" :precision="4" :step="0.1" controls-position="right" />
						<span class="suffix">/{{ form.unit_base || '件' }}</span>
					</el-form-item>
				</div>

				<!-- 阶梯提成 -->
				<div v-if="form.type === 'ladder'" class="calc-params">
					<div v-if="tierError" class="tier-error">{{ tierError }}</div>
					<table class="tier-table">
						<thead><tr><th>下限</th><th>上限</th><th>比例(%)</th><th>操作</th></tr></thead>
						<tbody>
							<tr v-for="(row, i) in form.tier_data" :key="i">
								<td><el-input-number v-model="row.min" :min="0" :precision="2" :controls="false" size="small" /></td>
								<td><el-input-number v-model="row.max" :min="0" :precision="2" :controls="false" size="small" placeholder="空=无上限" /></td>
								<td><el-input-number v-model="row.rate" :min="0" :max="100" :precision="1" :controls="false" size="small" /></td>
								<td><el-button type="danger" link size="small" @click="form.tier_data.splice(i, 1)">删除</el-button></td>
							</tr>
						</tbody>
					</table>
					<el-button type="primary" link size="small" @click="form.tier_data.push({ min: 0, max: null, rate: 0 })">+ 增加阶梯</el-button>
				</div>

				<!-- 单品方案 -->
				<div v-if="form.type === 'single'" class="calc-params">
					<table class="tier-table">
						<thead><tr><th>商品</th><th>比例(%)或单件金额</th><th>类型</th><th>操作</th></tr></thead>
						<tbody>
							<tr v-for="(row, i) in form.product_rules" :key="i">
								<td><el-select v-model="row.product_id" filterable placeholder="选择商品" style="width: 220px">
									<el-option v-for="p in products" :key="p.id" :label="p.name" :value="p.id" />
								</el-select></td>
								<td><el-input-number v-model="row.value" :min="0" :precision="2" :controls="false" size="small" /></td>
								<td><el-select v-model="row.mode" style="width: 100px">
									<el-option label="比例%" value="rate" /><el-option label="单件金额" value="amount" />
								</el-select></td>
								<td><el-button type="danger" link size="small" @click="form.product_rules.splice(i, 1)">删除</el-button></td>
							</tr>
						</tbody>
					</table>
					<el-button type="primary" link size="small" @click="form.product_rules.push({ product_id: null, value: 0, mode: 'rate' })">+ 增加商品</el-button>
				</div>

				<!-- 预估示例 -->
				<div class="estimate-box">
					<span>月销</span>
					<el-input-number v-model="sampleAmount" :min="0" :step="10000" size="small" controls-position="right" style="width: 140px" />
					<span>元，预计提成：</span>
					<b class="text-red">¥ {{ fmtMoney(estimateText) }}</b>
				</div>
			</div>

			<!-- 高级规则 -->
			<el-collapse class="adv-collapse">
				<el-collapse-item name="adv" title="高级规则">
					<el-form-item label="按回款计提"><el-switch v-model="form.base_on_payment" /> <span class="hint">默认按实际回款而非订单额</span></el-form-item>
					<el-form-item label="扣退货"><el-switch v-model="form.deduct_return" /></el-form-item>
					<el-form-item label="排除坏账"><el-switch v-model="form.exclude_baddebt" /></el-form-item>
					<el-form-item label="封顶">
						<span class="prefix">¥</span>
						<el-input-number v-model="form.cap_amount" :min="0" :precision="2" controls-position="right" /> <span class="hint">0 为不封顶</span>
					</el-form-item>
					<el-form-item label="保底">
						<span class="prefix">¥</span>
						<el-input-number v-model="form.floor_amount" :min="0" :precision="2" controls-position="right" />
					</el-form-item>
					<el-form-item label="允许叠加方案"><el-switch v-model="form.allow_stack" /> <span class="hint">默认取最高不重复计</span></el-form-item>
				</el-collapse-item>
			</el-collapse>
		</el-form>

		<template #footer>
			<div class="dialog-footer">
				<el-button @click="visible = false">取消</el-button>
				<el-button type="primary" :loading="saving" @click="handleSave">保存</el-button>
			</div>
		</template>
	</el-dialog>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import perfApi from '@/api/perf'
import businessApi from '@/api/business'

const props = defineProps({ visible: Boolean, record: Object })
const emit = defineEmits(['update:visible', 'success'])

const visible = ref(props.visible)
watch(() => props.visible, (v) => { visible.value = v })
watch(visible, (v) => emit('update:visible', v))

const typeOptions = [
	{ value: 'sales', label: '销售额提成' },
	{ value: 'profit', label: '利润提成' },
	{ value: 'piece', label: '按件计费' },
	{ value: 'ladder', label: '阶梯提成' },
	{ value: 'single', label: '单品方案' },
]
const calcToType = { sale_rate: 'sales', profit_rate: 'profit', fixed_per_unit: 'piece', tiered: 'ladder', product_specific: 'single' }
const typeToCalc = { sales: 'sale_rate', profit: 'profit_rate', piece: 'fixed_per_unit', ladder: 'tiered', single: 'product_specific' }

const employees = ref([])
const levels = ref([])
const products = ref([])
const sampleAmount = ref(100000)
const estimateText = ref('0.00')
const saving = ref(false)

// 金额：千分位 + 2 位小数；负数场景由调用处 class 上色
const fmtMoney = (n) => {
	const num = Number(n || 0)
	const s = Math.abs(num).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
	return (num < 0 ? '-' : '') + s
}

const defaultForm = () => ({
	name: '', type: 'sales', calc_type: 'sale_rate',
	scope: 'all', employee_ids: [], apply_employees: [],
	apply_customer_levels: [],
	product_scope_type: 'all', product_scope_ids: [],
	rate: 0, unit_base: '件', unit_amount: 0,
	tier_data: [{ min: 0, max: null, rate: 0 }],
	product_rules: [],
	base_on_payment: true, deduct_return: true, exclude_baddebt: true,
	cap_amount: 0, floor_amount: 0, allow_stack: false,
	start_date: null, end_date: null, status: 'active', remark: '',
})

const form = reactive(defaultForm())
const rules = {
	name: [{ required: true, message: '请输入方案名称', trigger: 'blur' }],
	type: [{ required: true, message: '请选择类型', trigger: 'change' }],
	employee_ids: [{
		validator: (rule, value, callback) => {
			if (form.scope === 'specified' && !value?.length) callback(new Error('指定业务员时至少选择 1 人'))
			else callback()
		},
		trigger: 'change',
	}],
}
const formRef = ref(null)

// 阶梯区间连续且不重叠（首档从 0 起）
const tierError = computed(() => {
	if (form.type !== 'ladder') return ''
	const tiers = [...(form.tier_data || [])].sort((a, b) => Number(a.min) - Number(b.min))
	if (!tiers.length) return '请至少添加一档阶梯'
	if (Number(tiers[0].min) !== 0) return '第一档下限必须为 0'
	for (let i = 0; i < tiers.length; i++) {
		const t = tiers[i]
		const min = Number(t.min)
		const max = t.max === null || t.max === undefined || t.max === '' ? null : Number(t.max)
		if (max !== null && max <= min) return `第 ${i + 1} 档上限必须大于下限`
		if (i < tiers.length - 1) {
			const nextMin = Number(tiers[i + 1].min)
			if (max === null) return `第 ${i + 1} 档设为无上限后不能再有后续阶梯`
			if (nextMin < max) return `第 ${i + 1} 档与第 ${i + 2} 档区间重叠`
			if (nextMin > max) return `第 ${i + 1} 档与第 ${i + 2} 档之间有空隙（应为 ${max} 起）`
		}
	}
	return ''
})

const parseJson = (v, fallback) => {
	if (typeof v === 'string') { try { return JSON.parse(v) } catch { return fallback } }
	return v ?? fallback
}

const initForm = () => {
	Object.assign(form, defaultForm())
	if (props.record) {
		// 后端可能把参数嵌在 rule JSON 里，先摊平到顶层再回填
		const rec = { ...props.record }
		const nested = parseJson(rec.rule, null)
		if (nested && typeof nested === 'object') {
			for (const k of Object.keys(form)) {
				if (nested[k] !== undefined && nested[k] !== null) rec[k] = nested[k]
			}
		}
		Object.keys(form).forEach((k) => {
			if (rec[k] !== undefined && rec[k] !== null) form[k] = rec[k]
		})
		form.type = rec.type || calcToType[rec.calc_type] || 'sales'
		form.calc_type = typeToCalc[form.type]
		const ids = rec.employee_ids?.length ? rec.employee_ids : (rec.apply_employees || [])
		form.employee_ids = [...ids]
		form.apply_employees = [...ids]
		form.scope = rec.scope || (ids.length ? 'specified' : 'all')
		const tiers = parseJson(rec.tier_data, [])
		form.tier_data = Array.isArray(tiers) && tiers.length ? tiers : [{ min: 0, max: null, rate: 0 }]
		// product_rules 后端为 {rate,amount,mode}，回填成表单 {value,mode}
		const prs = parseJson(rec.product_rules, [])
		form.product_rules = (Array.isArray(prs) ? prs : []).map((r) => {
			const isRate = r.mode ? r.mode === 'rate' : r.rate !== undefined && r.rate !== null
			return {
				product_id: r.product_id,
				mode: isRate ? 'rate' : 'amount',
				value: Number(isRate ? (r.rate ?? 0) : (r.amount ?? r.value ?? 0)),
			}
		})
	}
}

const loadOptions = async () => {
	try {
		const prodRes = await businessApi.product.list.get({ page: 1, page_size: 500 })
		if (prodRes?.code === 200) products.value = prodRes.data?.list || []
	} catch { /* ignore */ }
	try {
		const res = await businessApi.employee.list.get({ page: 1, page_size: 200 })
		if (res?.code === 200) employees.value = (res.data?.list || []).map((e) => ({ id: e.user_id || e.id, name: e.name, code: e.code }))
	} catch { /* ignore */ }
	try {
		const res = await businessApi.priceSystem.levelList.get()
		if (res?.code === 200) levels.value = res.data?.list || res.data || []
	} catch { /* ignore */ }
}

// 换类型时同步 calc_type 并清理不相关字段
watch(() => form.type, (t) => { form.calc_type = typeToCalc[t] || 'sale_rate' })

// 本地估算：月销 sampleAmount 元预计提成
const localEstimate = () => {
	const amt = Number(sampleAmount.value || 0)
	if (form.type === 'sales' || form.type === 'profit') return amt * (Number(form.rate || 0) / 100)
	if (form.type === 'piece') return Number(form.unit_amount || 0) // 单量未知，仅示意单件值
	if (form.type === 'ladder') {
		// 阶梯为分段（全额按档）：落入哪档按该档比例整额计算
		const tiers = [...(form.tier_data || [])].sort((a, b) => Number(a.min) - Number(b.min))
		for (const t of tiers) {
			const min = Number(t.min)
			const max = t.max === null || t.max === undefined || t.max === '' ? Infinity : Number(t.max)
			if (amt >= min && amt <= max) return amt * (Number(t.rate || 0) / 100)
		}
		return 0
	}
	if (form.type === 'single') {
		// 单品方案按命中商品比例估算取最高档（数量未知仅示意）
		const rates = (form.product_rules || []).filter((r) => r.mode === 'rate').map((r) => Number(r.value || 0))
		return rates.length ? amt * (Math.max(...rates) / 100) : 0
	}
	return 0
}

const calcEstimate = async () => {
	if (form.type === 'ladder' && tierError.value) { estimateText.value = localEstimate().toFixed(2); return }
	try {
		const params = {
			type: form.type, calc_type: form.calc_type,
			rate: form.rate, unit_amount: form.unit_amount, unit_base: form.unit_base,
			tier_data: form.tier_data,
			product_rules: (form.product_rules || []).map((r) => ({
				product_id: r.product_id,
				rate: r.mode === 'rate' ? Number(r.value) : undefined,
				amount: r.mode === 'amount' ? Number(r.value) : undefined,
			})),
			base_on_payment: form.base_on_payment, sample_amount: sampleAmount.value,
		}
		const res = await perfApi.rule.estimate.get(params)
		if (res.code === 200) estimateText.value = Number(res.data?.amount || 0).toFixed(2)
		else estimateText.value = localEstimate().toFixed(2)
	} catch { estimateText.value = localEstimate().toFixed(2) }
}

watch(() => [form.type, form.calc_type, form.rate, form.unit_amount, form.tier_data, form.product_rules, sampleAmount.value], calcEstimate, { deep: true })

const handleSave = async () => {
	try { await formRef.value.validate() } catch { return }
	if (tierError.value) { ElMessage.warning(tierError.value); return }
	saving.value = true
	try {
		const payload = {
			...form,
			type: form.type,
			calc_type: typeToCalc[form.type],
			scope: form.scope,
			employee_ids: form.scope === 'specified' ? form.employee_ids : [],
			apply_employees: form.scope === 'specified' ? form.employee_ids : [],
			rule: {
				rate: form.rate,
				unit_base: form.unit_base,
				unit_amount: form.unit_amount,
				tier_data: form.tier_data,
				product_rules: (form.product_rules || []).map((r) => ({
					product_id: r.product_id,
					rate: r.mode === 'rate' ? Number(r.value) : undefined,
					amount: r.mode === 'amount' ? Number(r.value) : undefined,
					mode: r.mode,
				})),
				base_on_payment: form.base_on_payment,
				deduct_return: form.deduct_return,
				exclude_baddebt: form.exclude_baddebt,
				cap_amount: form.cap_amount,
				floor_amount: form.floor_amount,
				allow_stack: form.allow_stack,
			},
		}
		// product_rules 扁平字段向后兼容：value 按模式拆成 rate/amount
		payload.product_rules = payload.rule.product_rules
		const res = props.record
			? await perfApi.rule.edit.put(props.record.id, payload)
			: await perfApi.rule.add.post(payload)
		if (res.code === 200) { ElMessage.success(res.message || '保存成功'); emit('success'); visible.value = false }
	} finally { saving.value = false }
}

onMounted(() => { loadOptions(); initForm(); calcEstimate() })
</script>

<style scoped>
/* 弹窗：宽 900、标题栏 50px、footer 60px、圆角 4px（scoped :deep 自身弹层） */
:deep(.perf-rule-dialog) { border-radius: 4px; }
:deep(.perf-rule-dialog .el-dialog__header) {
	height: 50px; margin: 0; padding: 0 20px; display: flex; align-items: center;
	border-bottom: 1px solid #DCDFE6; line-height: 50px;
}
:deep(.perf-rule-dialog .el-dialog__title) { font-size: 16px; font-weight: 700; color: #303133; }
:deep(.perf-rule-dialog .el-dialog__body) { padding: 16px 20px; }
:deep(.perf-rule-dialog .el-dialog__footer) {
	height: 60px; padding: 0 20px; display: flex; align-items: center; justify-content: flex-end;
	border-top: 1px solid #DCDFE6; box-sizing: border-box;
}
:deep(.perf-rule-dialog .el-dialog__headerbtn) { top: 10px; }
:deep(.perf-rule-dialog .el-dialog__body .el-form-item__label) { color: #606266; justify-content: flex-end; }
:deep(.perf-rule-dialog .el-dialog__body .el-input__inner),
:deep(.perf-rule-dialog .el-dialog__body .el-select__wrapper) { min-height: 36px; }
:deep(.perf-rule-dialog .el-dialog__body .el-input-number) { --el-input-height: 36px; }
:deep(.perf-rule-dialog .el-dialog__body .el-radio-group) { min-height: 36px; align-items: center; }

.section-title {
	font-size: 14px; font-weight: 700; color: #303133;
	padding: 4px 0 8px; margin-top: 4px; border-left: 3px solid #409EFF; padding-left: 8px;
}
.section-title:first-child { margin-top: 0; }
.section-body { background: #FAFAFA; border: 1px solid #DCDFE6; border-radius: 4px; padding: 14px 16px 6px; margin-bottom: 14px; }
.form-row { display: flex; flex-wrap: wrap; gap: 0 24px; }
.date-sep { margin: 0 8px; color: #909399; }

.calc-cards { margin-bottom: 16px; }
.calc-params { background: #FAFAFA; padding: 16px; border: 1px solid #DCDFE6; border-radius: 4px; margin-bottom: 12px; }
.hint { color: #909399; font-size: 12px; margin-left: 8px; }
.hint-block { color: #909399; font-size: 12px; padding: 4px 0 12px; }
.suffix { margin-left: 6px; color: #606266; }
.prefix { margin-right: 6px; color: #606266; }
.estimate-box { margin-top: 12px; padding: 10px 16px; background: #FAFAFA; border: 1px solid #DCDFE6; border-radius: 4px; color: #606266; font-size: 13px; }
.text-red { color: #F56C6C; font-size: 16px; margin-left: 8px; }
.tier-error { color: #F56C6C; font-size: 12px; margin-bottom: 8px; }
.tier-table { width: 100%; border-collapse: collapse; margin-bottom: 8px; border: 1px solid #DCDFE6; }
.tier-table th {
	background: #F5F7FA; color: #606266; font-size: 14px; font-weight: 500;
	height: 36px; text-align: left; padding: 0 8px; border: 1px solid #DCDFE6;
}
.tier-table td { padding: 4px 8px; border: 1px solid #DCDFE6; }
.adv-collapse { margin-bottom: 8px; border-top: 1px solid #DCDFE6; }
.adv-collapse :deep(.el-collapse-item__header) {
	height: 44px; line-height: 44px; font-size: 14px; font-weight: 700; color: #303133;
	background: #FAFAFA; padding: 0 12px; border: 1px solid #DCDFE6;
}
.adv-collapse :deep(.el-collapse-item__wrap) { border: 1px solid #DCDFE6; border-top: none; }
.adv-collapse :deep(.el-collapse-item__body) { padding: 14px 16px 6px; background: #fff; }
.adv-collapse :deep(.el-collapse-item__content) { color: #303133; }
.dialog-footer { text-align: right; width: 100%; }
/* Element 输入控件高度收敛到 36px（含 input-number 内部） */
:deep(.el-input-number__decrease),
:deep(.el-input-number__increase) { width: 32px; }
</style>
