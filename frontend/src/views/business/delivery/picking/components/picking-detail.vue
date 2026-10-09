<template>
	<el-dialog :model-value="visible" @update:model-value="$emit('update:visible', $event)" title="配货单详情" width="800px" destroy-on-close>
		<el-descriptions :column="3" border size="small" v-if="detail">
			<el-descriptions-item label="配货单号">{{ detail.picking_no }}</el-descriptions-item>
			<el-descriptions-item label="订单号">{{ detail.order_no }}</el-descriptions-item>
			<el-descriptions-item label="客户">{{ detail.customer_name }}</el-descriptions-item>
			<el-descriptions-item label="配货日期">{{ detail.picking_date }}</el-descriptions-item>
			<el-descriptions-item label="金额">¥{{ Number(detail.total_amount || 0).toFixed(2) }}</el-descriptions-item>
			<el-descriptions-item label="状态">
				<el-tag :type="statusType(detail.status)" size="small">{{ statusLabel(detail.status) }}</el-tag>
			</el-descriptions-item>
		</el-descriptions>
		<el-table :data="detail?.items || []" border size="small" style="margin-top: 12px">
			<el-table-column prop="product_code" label="商品编码" width="120" />
			<el-table-column prop="product_name" label="商品名称" width="180" />
			<el-table-column prop="spec" label="规格" width="100" />
			<el-table-column prop="unit" label="单位" width="70" />
			<el-table-column prop="quantity" label="配货数量" width="90" align="center" />
			<el-table-column prop="price" label="单价" width="90" align="right" />
			<el-table-column prop="amount" label="金额" width="100" align="right" />
		</el-table>
		<template #footer>
			<el-button @click="$emit('update:visible', false)">关闭</el-button>
		</template>
	</el-dialog>
</template>

<script setup>
import { ref, watch } from 'vue'
import businessApi from '@/api/business'

const props = defineProps({ visible: Boolean, record: Object })
defineEmits(['update:visible', 'success'])

const detail = ref(null)
const statusLabel = (s) => ({ pending: '待配货', picked: '已配货', cancelled: '已取消' }[s] || s)
const statusType = (s) => ({ pending: 'warning', picked: 'success', cancelled: 'info' }[s] || 'info')

watch(() => props.visible, async (v) => {
	if (v && props.record) {
		const res = await businessApi.deliveryPicking.detail.get(props.record.id)
		detail.value = res.data
	}
}, { immediate: true })
</script>
