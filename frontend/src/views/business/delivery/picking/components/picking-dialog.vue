<template>
	<el-dialog :model-value="visible" @update:model-value="$emit('update:visible', $event)" title="新增配货单" width="900px" :close-on-click-modal="false" destroy-on-close>
		<el-form :model="form" label-width="90px">
			<el-form-item label="选择订单">
				<el-select v-model="form.sales_order_id" filterable placeholder="请选择已审核订单" style="width: 100%" @change="onOrderChange">
					<el-option v-for="o in orders" :key="o.id" :label="`${o.order_no} - ${o.customer_name}`" :value="o.id" />
				</el-select>
			</el-form-item>
			<el-form-item label="配货日期">
				<el-date-picker v-model="form.picking_date" type="date" value-format="YYYY-MM-DD" placeholder="配货日期" style="width: 200px" />
			</el-form-item>
		</el-form>
		<el-table v-if="form.items.length" :data="form.items" border size="small" style="margin-top: 8px">
			<el-table-column prop="product_code" label="商品编码" width="120" :formatter="(row) => row.product_code || '-'" />
			<el-table-column prop="product_name" label="商品名称" width="180" />
			<el-table-column prop="spec" label="规格" width="100" :formatter="(row) => row.spec || '-'" />
			<el-table-column prop="unit" label="单位" width="70" />
			<el-table-column prop="order_qty" label="订单数量" width="90" align="center" />
			<el-table-column label="配货数量" width="120">
				<template #default="{ row }">
					<el-input-number v-model="row.quantity" :min="1" :max="row.order_qty" size="small" controls-position="right" style="width: 110px" />
				</template>
			</el-table-column>
			<el-table-column prop="price" label="单价" width="90" align="right">
				<template #default="{ row }">¥{{ Number(row.price || 0).toFixed(2) }}</template>
			</el-table-column>
			<el-table-column label="金额" width="100" align="right">
				<template #default="{ row }">¥{{ (Number(row.price || 0) * Number(row.quantity || 0)).toFixed(2) }}</template>
			</el-table-column>
		</el-table>
		<el-empty v-else description="请先选择订单" />
		<template #footer>
			<el-button @click="$emit('update:visible', false)">取消</el-button>
			<el-button type="primary" :loading="submitting" @click="handleSubmit">确认配货</el-button>
		</template>
	</el-dialog>
</template>

<script setup>
import { ref, reactive, onMounted, watch } from 'vue'
import { ElMessage } from 'element-plus'
import businessApi from '@/api/business'

const props = defineProps({ visible: Boolean })
const emit = defineEmits(['update:visible', 'success'])

const orders = ref([])
const orderItemsMap = ref({})
const form = reactive({ sales_order_id: null, picking_date: new Date().toISOString().slice(0, 10), items: [] })
const submitting = ref(false)

const onOrderChange = (orderId) => {
	form.items = (orderItemsMap.value[orderId] || []).map(it => ({ ...it, quantity: it.order_qty }))
}

const handleSubmit = async () => {
	if (!form.sales_order_id) return ElMessage.warning('请选择订单')
	if (!form.items.length) return ElMessage.warning('订单无商品明细')
	submitting.value = true
	try {
		const payload = {
			sales_order_id: form.sales_order_id,
			picking_date: form.picking_date,
			items: form.items.map(it => ({ item_id: it.item_id, product_id: it.product_id, quantity: it.quantity, price: it.price, amount: Number((Number(it.price || 0) * Number(it.quantity || 0)).toFixed(2)) })),
		}
		const res = await businessApi.deliveryPicking.add.post(payload)
		if (res.code === 200) { ElMessage.success(res.message || '创建成功'); emit('success'); emit('update:visible', false) }
	} catch (e) { /* 拦截器已弹错误提示，此处静默吞掉，避免重复 toast */ } finally { submitting.value = false }
}

onMounted(async () => {
	const res = await businessApi.deliveryPicking.pendingOrders.get()
	orders.value = res.data?.orders || []
	orderItemsMap.value = res.data?.items || {}
})
</script>
