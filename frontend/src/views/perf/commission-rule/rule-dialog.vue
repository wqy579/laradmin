<template>
	<el-dialog v-model="visible" :title="record ? '编辑方案' : '新增方案'" width="900px" :close-on-click-modal="false" @open="initForm">
		<el-form ref="formRef" :model="form" :rules="rules" label-width="120px" size="default">
			<!-- 第一块：基础信息 -->
			<el-collapse v-model="activeNames">
				<el-collapse-item name="base" title="基础信息">
					<el-form-item label="方案名称" prop="name">
						<el-input v-model="form.name" placeholder="请输入方案名称" style="width: 320px" />
					</el-form-item>
					<el-form-item label="生效日期">
						<el-date-picker v-model="form.start_date" type="date" value-format="YYYY-MM-DD" placeholder="生效日期" style="width: 160px" />
						<span style="margin: 0 8px; color: #909399">~</span>
						<el-date-picker v-model="form.end_date" type="date" value-format="YYYY-MM-DD" placeholder="失效日期" style="width: 160px" />
					</el-form-item>
					<el-form-item label="适用业务员">
						<el-select v-model="form.apply_employees" multiple placeholder="默认全部" style="width: 360px">
							<el-option v-for="e in employees" :key="e.id" :label="e.name" :value="e.id" />
						</el-select>
					</el-form-item>
					<el-form-item label="适用客户等级">
						<el-select v-model="form.apply_customer_levels" multiple placeholder="默认全部" style="width: 360px">
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
				</el-collapse-item>

				<!-- 第二块：提成方式 -->
				<el-collapse-item name="calc" title="提成方式">
					<el-radio-group v-model="form.calc_type" class="calc-cards">
						<el-radio-button v-for="t in calcTypes" :key="t.value" :value="t.value">{{ t.label }}</el-radio-button>
					</el-radio-group>

					<!-- 按销售额比例 -->
					<div v-if="form.calc_type === 'sale_rate'" class="calc-params">
						<el-form-item label="提成比例">
							<el-input-number v-model="form.rate" :min="0" :max="100" :precision="2" :step="0.1" controls-position="right" />
							<span class="suffix">%</span>
						</el-form-item>
						<el-form-item label="按回款金额计算">
							<el-switch v-model="form.base_on_payment" /> <span class="hint">默认按实际回款而非订单额</span>
						</el-form-item>
					</div>

					<!-- 按毛利比例 -->
					<div v-if="form.calc_type === 'profit_rate'" class="calc-params">
						<el-form-item label="提成比例">
							<el-input-number v-model="form.rate" :min="0" :max="100" :precision="2" :step="0.1" controls-position="right" />
							<span class="suffix">%</span>
						</el-form-item>
						<div class="hint-block">毛利 = 销售金额 − 成本金额 − 退货 − 分摊费用</div>
						<el-form-item label="按回款金额计算"><el-switch v-model="form.base_on_payment" /></el-form-item>
					</div>

					<!-- 固定每件/每箱 -->
					<div v-if="form.calc_type === 'fixed_per_unit'" class="calc-params">
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
					<div v-if="form.calc_type === 'tiered'" class="calc-params">
						<table class="tier-table">
							<thead><tr><th>下限</th><th>上限</th><th>比例(%)</th><th>操作</th></tr></thead>
							<tbody>
								<tr v-for="(row, i) in form.tier_data" :key="i">
									<td><el-input-number v-model="row.min" :min="0" :precision="2" :controls="false" size="small" /></td>
									<td><el-input-number v-model="row.max" :min="0" :precision="2" :controls="false" size="small" placeholder="空=无上限" /></td>
									<td><el-input-number v-model="row.rate" :min="0" :max="100" :precision="2" :controls="false" size="small" /></td>
									<td><el-button type="danger" link size="small" @click="form.tier_data.splice(i, 1)">删除</el-button></td>
								</tr>
							</tbody>
						</table>
						<el-button type="primary" link size="small" @click="form.tier_data.push({ min: 0, max: null, rate: 0 })">+ 增加阶梯</el-button>
					</div>

					<!-- 单品专属 -->
					<div v-if="form.calc_type === 'product_specific'" class="calc-params">
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
						<b class="text-red">¥ {{ estimateText }}</b>
					</div>
				</el-collapse-item>

				<!-- 第三块：高级规则 -->
				<el-collapse-item name="adv" title="高级规则">
					<el-form-item label="退货扣减提成"><el-switch v-model="form.deduct_return" /></el-form-item>
					<el-form-item label="未回款不计提"><el-switch v-model="form.exclude_baddebt" /></el-form-item>
					<el-form-item label="封顶提成">
						<span class="prefix">¥</span>
						<el-input-number v-model="form.cap_amount" :min="0" :precision="2" controls-position="right" /> <span class="hint">0 为不封顶</span>
					</el-form-item>
					<el-form-item label="保底提成">
						<span class="prefix">¥</span>
						<el-input-number v-model="form.floor_amount" :min="0" :precision="2" controls-position="right" />
					</el-form-item>
					<el-form-item label="允许叠加方案"><el-switch v-model="form.allow_stack" /> <span class="hint">默认取最高不重复计</span></el-form-item>
					<el-form-item label="备注"><el-input v-model="form.remark" type="textarea" :rows="2" style="width: 400px" /></el-form-item>
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
import { ref, reactive, watch, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import perfApi from '@/api/perf'
import businessApi from '@/api/business'

const props = defineProps({ visible: Boolean, record: Object })
const emit = defineEmits(['update:visible', 'success'])

const visible = ref(props.visible)
watch(() => props.visible, (v) => { visible.value = v })
watch(visible, (v) => emit('update:visible', v))

const calcTypes = [
	{ value: 'sale_rate', label: '按销售额比例' },
	{ value: 'profit_rate', label: '按毛利比例' },
	{ value: 'fixed_per_unit', label: '固定每件/每箱' },
	{ value: 'tiered', label: '阶梯提成' },
	{ value: 'product_specific', label: '单品专属' },
]

const employees = ref([])
const levels = ref([])
const products = ref([])
const sampleAmount = ref(100000)
const estimateText = ref('0.00')
const saving = ref(false)

const defaultForm = () => ({
	name: '', calc_type: 'sale_rate',
	apply_employees: [], apply_customer_levels: [],
	product_scope_type: 'all', product_scope_ids: [],
	rate: 0, unit_base: '件', unit_amount: 0,
	tier_data: [{ min: 0, max: null, rate: 0 }],
	product_rules: [],
	base_on_payment: true, deduct_return: true, exclude_baddebt: true,
	cap_amount: 0, floor_amount: 0, allow_stack: false,
	start_date: null, end_date: null, status: 'active', remark: '',
})

const form = reactive(defaultForm())
const rules = { name: [{ required: true, message: '请输入方案名称', trigger: 'blur' }] }
const formRef = ref(null)
const activeNames = ref(['base', 'calc', 'adv'])

const initForm = () => {
	Object.assign(form, defaultForm())
	if (props.record) {
		Object.keys(form).forEach((k) => {
			if (props.record[k] !== undefined && props.record[k] !== null) form[k] = props.record[k]
		})
		form.tier_data = props.record.tier_data?.length ? props.record.tier_data : [{ min: 0, max: null, rate: 0 }]
		form.product_rules = props.record.product_rules || []
	}
}

const loadOptions = async () => {
	try {
		const prodRes = await businessApi.product.list.get({ page: 1, page_size: 500 })
		if (prodRes?.code === 200) products.value = prodRes.data?.list || []
	} catch { /* ignore */ }
	// 员工档案（user_id 关联业务员）
	try {
		const res = await businessApi.employee.list.get({ page: 1, page_size: 200 })
		if (res?.code === 200) employees.value = (res.data?.list || []).map((e) => ({ id: e.user_id || e.id, name: e.name, code: e.code }))
	} catch { /* ignore */ }
	// 客户等级
	try {
		const res = await businessApi.customer.levelList.get()
		if (res?.code === 200) levels.value = res.data?.list || res.data || []
	} catch { /* ignore */ }
}

const calcEstimate = async () => {
	try {
		const params = {
			calc_type: form.calc_type,
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
	} catch { /* ignore */ }
}

watch(() => [form.calc_type, form.rate, form.unit_amount, form.tier_data, form.product_rules, sampleAmount.value], calcEstimate, { deep: true })

const handleSave = async () => {
	try { await formRef.value.validate() } catch { return }
	saving.value = true
	try {
		const payload = { ...form }
		// product_rules 的 value 按模式拆成后端期望的 rate/amount
		payload.product_rules = (form.product_rules || []).map((r) => ({
			product_id: r.product_id,
			rate: r.mode === 'rate' ? Number(r.value) : undefined,
			amount: r.mode === 'amount' ? Number(r.value) : undefined,
			mode: r.mode,
		}))
		const res = props.record
			? await perfApi.rule.edit.put(props.record.id, payload)
			: await perfApi.rule.add.post(payload)
		if (res.code === 200) { ElMessage.success('保存成功'); emit('success'); visible.value = false }
	} finally { saving.value = false }
}

onMounted(() => { loadOptions(); initForm() })
</script>

<style scoped>
.calc-cards { margin-bottom: 16px; }
.calc-params { background: #f5f7fa; padding: 16px; border-radius: 4px; margin-bottom: 12px; }
.hint { color: #909399; font-size: 12px; margin-left: 8px; }
.hint-block { color: #909399; font-size: 12px; padding: 4px 0 12px; }
.suffix { margin-left: 6px; color: #606266; }
.prefix { margin-right: 6px; color: #606266; }
.estimate-box { margin-top: 12px; padding: 10px 16px; background: #f4f4f5; border-radius: 4px; color: #606266; font-size: 13px; }
.text-red { color: #f56c6c; font-size: 16px; margin-left: 8px; }
.tier-table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
.tier-table th { background: #f5f7fa; color: #909399; font-size: 13px; font-weight: 500; height: 36px; text-align: left; padding: 0 8px; }
.tier-table td { padding: 4px 8px; }
.dialog-footer { text-align: right; }
</style>
