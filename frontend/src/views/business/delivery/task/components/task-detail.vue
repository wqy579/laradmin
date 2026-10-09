<template>
	<el-dialog :model-value="visible" @update:model-value="$emit('update:visible', $event)" title="配送任务详情" width="1000px" destroy-on-close>
		<el-card shadow="never" v-if="detail">
			<el-descriptions :column="3" border size="small">
				<el-descriptions-item label="任务单号">{{ detail.task.task_no }}</el-descriptions-item>
				<el-descriptions-item label="配送员">{{ detail.task.delivery_person_name }}</el-descriptions-item>
				<el-descriptions-item label="车牌号">{{ detail.task.plate_no }}</el-descriptions-item>
				<el-descriptions-item label="客户">{{ detail.task.customer_name }}</el-descriptions-item>
				<el-descriptions-item label="地址">{{ detail.task.address }}</el-descriptions-item>
				<el-descriptions-item label="电话">{{ detail.task.phone }}</el-descriptions-item>
				<el-descriptions-item label="订单金额"><span style="color:#F56C6C">¥{{ Number(detail.task.order_amount||0).toFixed(2) }}</span></el-descriptions-item>
				<el-descriptions-item label="已收">¥{{ Number(detail.task.paid_amount||0).toFixed(2) }}</el-descriptions-item>
				<el-descriptions-item label="状态"><el-tag :type="statusType(detail.task.status)" size="small">{{ statusLabel(detail.task.status) }}</el-tag></el-descriptions-item>
			</el-descriptions>
		</el-card>
		<el-divider content-position="left">商品明细</el-divider>
		<el-table :data="detail.items || []" border size="small">
			<el-table-column prop="product_code" label="商品编码" width="120" />
			<el-table-column prop="product_name" label="商品名称" width="180" />
			<el-table-column prop="spec" label="规格" width="90" />
			<el-table-column prop="unit" label="单位" width="60" />
			<el-table-column prop="quantity" label="数量" width="80" align="center" />
			<el-table-column prop="price" label="单价" width="90" align="right" />
			<el-table-column prop="amount" label="金额" width="100" align="right" />
		</el-table>
		<el-divider content-position="left">配送进度</el-divider>
		<el-steps :active="stepIndex" finish-status="success" align-center>
			<el-step title="已配货" />
			<el-step title="已拣货" />
			<el-step title="已验货" />
			<el-step title="已装车" />
			<el-step title="配送中" />
			<el-step title="已送达" />
			<el-step title="已收款" />
		</el-steps>

		<template v-if="detail.task.status === 'delivering' || detail.task.status === 'delivered'">
			<el-divider content-position="left">异常登记</el-divider>
			<el-form :model="exForm" label-width="90px" size="small">
				<el-form-item label="异常类型">
					<el-radio-group v-model="exForm.exception_type">
						<el-radio label="reject">客户拒收</el-radio>
						<el-radio label="damaged">商品破损</el-radio>
						<el-radio label="address">地址错误</el-radio>
					</el-radio-group>
				</el-form-item>
				<el-form-item label="异常说明">
					<el-input v-model="exForm.exception_remark" type="textarea" :rows="2" placeholder="请描述异常情况" />
				</el-form-item>
				<el-form-item>
					<el-button type="danger" @click="handleException">提交异常登记</el-button>
				</el-form-item>
			</el-form>
		</template>

		<template #footer>
			<el-button @click="$emit('update:visible', false)">关闭</el-button>
		</template>
	</el-dialog>
</template>

<script setup>
import { ref, reactive, computed, watch } from 'vue'
import { ElMessage } from 'element-plus'
import businessApi from '@/api/business'

const props = defineProps({ visible: Boolean, record: Object })
const emit = defineEmits(['update:visible', 'success'])

const detail = ref(null)
const exForm = reactive({ exception_type: 'reject', exception_remark: '' })

const statusLabel = (s) => ({ pending: '待配送', delivering: '配送中', delivered: '已送达', paid: '已收款', exception: '异常' }[s] || s)
const statusType = (s) => ({ pending: 'info', delivering: 'primary', delivered: 'warning', paid: 'success', exception: 'danger' }[s] || 'info')
const stepIndex = computed(() => {
	const s = detail.value?.task?.status
	return ({ pending: 4, delivering: 5, delivered: 6, paid: 7, exception: 5 }[s] ?? 4)
})

const handleException = async () => {
	if (!exForm.exception_remark) return ElMessage.warning('请填写异常说明')
	const res = await businessApi.deliveryTask.exception.post(props.record.id, exForm)
	if (res.code === 200) { ElMessage.success(res.message || '异常已登记'); emit('success'); emit('update:visible', false) }
}

watch(() => props.visible, async (v) => {
	if (v && props.record) {
		const res = await businessApi.deliveryTask.detail.get(props.record.id)
		detail.value = res.data
	}
}, { immediate: true })
</script>
