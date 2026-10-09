<template>
	<el-dialog :model-value="visible" @update:model-value="$emit('update:visible', $event)" title="新增上交" width="700px" :close-on-click-modal="false" destroy-on-close>
		<el-form :model="form" label-width="100px">
			<el-form-item label="配送员">
				<el-select v-model="form.delivery_person_id" filterable placeholder="请选择配送员" style="width: 200px" @change="loadSummary">
					<el-option v-for="e in employees" :key="e.id" :label="e.name" :value="e.user_id || e.id" />
				</el-select>
			</el-form-item>
			<el-form-item label="上交日期">
				<el-date-picker v-model="form.remit_date" type="date" value-format="YYYY-MM-DD" style="width: 180px" />
			</el-form-item>
		</el-form>
		<el-divider content-position="left">未上交汇总</el-divider>
		<el-descriptions :column="2" border size="small" v-if="summary">
			<el-descriptions-item label="现金待上交">¥{{ fmt(summary['现金']?.pending) }}</el-descriptions-item>
			<el-descriptions-item label="微信待上交">¥{{ fmt(summary['微信']?.pending) }}</el-descriptions-item>
			<el-descriptions-item label="支付宝待上交">¥{{ fmt(summary['支付宝']?.pending) }}</el-descriptions-item>
			<el-descriptions-item label="银行卡待上交">¥{{ fmt(summary['银行卡']?.pending) }}</el-descriptions-item>
		</el-descriptions>
		<el-divider content-position="left">本次上交</el-divider>
		<el-form :model="form" label-width="100px">
			<el-form-item label="现金金额">
				<el-input-number v-model="form.cash_amount" :min="0" :precision="2" controls-position="right" style="width: 180px" />
			</el-form-item>
			<el-form-item label="微信金额">
				<el-input-number v-model="form.wechat_amount" :min="0" :precision="2" controls-position="right" style="width: 180px" />
			</el-form-item>
			<el-form-item label="支付宝金额">
				<el-input-number v-model="form.alipay_amount" :min="0" :precision="2" controls-position="right" style="width: 180px" />
			</el-form-item>
			<el-form-item label="银行卡金额">
				<el-input-number v-model="form.bank_amount" :min="0" :precision="2" controls-position="right" style="width: 180px" />
			</el-form-item>
			<el-form-item label="合计">
				<span style="color: #F56C6C; font-size: 18px; font-weight: bold">¥{{ fmt(total) }}</span>
			</el-form-item>
			<el-form-item label="备注">
				<el-input v-model="form.remark" type="textarea" :rows="2" />
			</el-form-item>
		</el-form>
		<template #footer>
			<el-button @click="$emit('update:visible', false)">取消</el-button>
			<el-button type="primary" :loading="submitting" @click="handleSubmit">确认上交</el-button>
		</template>
	</el-dialog>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import businessApi from '@/api/business'

const props = defineProps({ visible: Boolean })
const emit = defineEmits(['update:visible', 'success'])

const employees = ref([])
const summary = ref(null)
const form = reactive({ delivery_person_id: null, remit_date: new Date().toISOString().slice(0, 10), cash_amount: 0, wechat_amount: 0, alipay_amount: 0, bank_amount: 0, remark: '' })
const submitting = ref(false)
const total = computed(() => Number(form.cash_amount) + Number(form.wechat_amount) + Number(form.alipay_amount) + Number(form.bank_amount))
const fmt = (n) => Number(n || 0).toFixed(2)

const loadSummary = async () => {
	if (!form.delivery_person_id) return
	const res = await businessApi.deliveryRemit.unremitSummary.get({ delivery_person_id: form.delivery_person_id })
	summary.value = res.data
	form.cash_amount = Number(res.data?.['现金']?.pending || 0)
	form.wechat_amount = Number(res.data?.['微信']?.pending || 0)
	form.alipay_amount = Number(res.data?.['支付宝']?.pending || 0)
	form.bank_amount = Number(res.data?.['银行卡']?.pending || 0)
}

const handleSubmit = async () => {
	if (!form.delivery_person_id) return ElMessage.warning('请选择配送员')
	if (total.value <= 0) return ElMessage.warning('上交金额必须大于0')
	submitting.value = true
	try {
		const payload = { ...form, cash_amount: form.cash_amount, wechat_amount: form.wechat_amount, alipay_amount: form.alipay_amount, bank_amount: form.bank_amount }
		const res = await businessApi.deliveryRemit.add.post(payload)
		if (res.code === 200) { ElMessage.success(res.message || '上交成功'); emit('success'); emit('update:visible', false) }
	} catch (e) { if (e && e.message) ElMessage.error(e.message) } finally { submitting.value = false }
}

onMounted(async () => {
	const er = await businessApi.employee.list.get({ page_size: 9999, is_active: 1 })
	employees.value = er.data?.list || er.data || []
})
</script>
