<template>
	<el-dialog :model-value="visible" @update:model-value="$emit('update:visible', $event)" title="新增收款" width="700px" :close-on-click-modal="false" destroy-on-close>
		<el-form :model="form" label-width="100px">
			<el-form-item label="选择配送单">
				<el-select v-model="form.task_id" filterable placeholder="请选择已送达配送单" style="width: 100%" @change="onTaskChange">
					<el-option v-for="t in tasks" :key="t.id" :label="`${t.task_no} - ${t.customer_name} (应收¥${Number(t.unpaid_amount||0).toFixed(2)})`" :value="t.id" />
				</el-select>
			</el-form-item>
			<el-form-item label="应收金额">
				<span style="color: #F56C6C; font-size: 18px; font-weight: bold">¥{{ fmt(form.receivable_amount) }}</span>
			</el-form-item>
			<el-form-item label="本次收款">
				<el-input-number v-model="form.received_amount" :min="0.01" :max="form.receivable_amount" :precision="2" controls-position="right" style="width: 200px" />
			</el-form-item>
			<el-form-item label="收款方式">
				<el-radio-group v-model="form.payment_method">
					<el-radio label="现金">现金</el-radio>
					<el-radio label="微信">微信</el-radio>
					<el-radio label="支付宝">支付宝</el-radio>
					<el-radio label="银行卡">银行卡</el-radio>
					<el-radio label="挂账">挂账</el-radio>
				</el-radio-group>
			</el-form-item>
			<el-form-item label="收款日期">
				<el-date-picker v-model="form.collect_date" type="date" value-format="YYYY-MM-DD" style="width: 180px" />
			</el-form-item>
			<el-form-item label="备注">
				<el-input v-model="form.remark" type="textarea" :rows="2" />
			</el-form-item>
		</el-form>
		<template #footer>
			<el-button @click="$emit('update:visible', false)">取消</el-button>
			<el-button type="primary" :loading="submitting" @click="handleSubmit">确认收款</el-button>
		</template>
	</el-dialog>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import businessApi from '@/api/business'

const props = defineProps({ visible: Boolean })
const emit = defineEmits(['update:visible', 'success'])

const tasks = ref([])
const form = reactive({ task_id: null, receivable_amount: 0, received_amount: 0, payment_method: '现金', collect_date: new Date().toISOString().slice(0, 10), remark: '' })
const submitting = ref(false)
const fmt = (n) => Number(n || 0).toFixed(2)

const onTaskChange = (id) => {
	const t = tasks.value.find(x => x.id === id)
	form.receivable_amount = Number(t?.unpaid_amount || 0)
	form.received_amount = form.receivable_amount
}

const handleSubmit = async () => {
	if (!form.task_id) return ElMessage.warning('请选择配送单')
	if (!form.received_amount) return ElMessage.warning('请输入收款金额')
	submitting.value = true
	try {
		const res = await businessApi.deliveryCollection.add.post(form)
		if (res.code === 200) { ElMessage.success(res.message || '收款成功'); emit('success'); emit('update:visible', false) }
	} catch (e) { if (e && e.message) ElMessage.error(e.message) } finally { submitting.value = false }
}

onMounted(async () => {
	const res = await businessApi.deliveryCollection.pendingTasks.get()
	tasks.value = res.data || []
})
</script>
