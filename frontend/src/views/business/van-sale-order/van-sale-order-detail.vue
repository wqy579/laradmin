<template>
	<el-dialog v-model="visible" title="销售单详情" width="850px">
		<el-descriptions v-if="detail.id" :column="3" border size="small">
			<el-descriptions-item label="销售单号">{{ detail.order_no }}</el-descriptions-item>
			<el-descriptions-item label="客户">{{ detail.customer_name }}</el-descriptions-item>
			<el-descriptions-item label="业务员">{{ detail.salesman_name }}</el-descriptions-item>
			<el-descriptions-item label="销售日期">{{ detail.sale_date }}</el-descriptions-item>
			<el-descriptions-item label="总金额">¥{{ detail.total_amount }}</el-descriptions-item>
			<el-descriptions-item label="收款">¥{{ detail.paid_amount }}</el-descriptions-item>
			<el-descriptions-item label="付款方式">{{ payLabel(detail.payment_method) }}</el-descriptions-item>
			<el-descriptions-item label="状态">{{ statusLabel(detail.status) }}</el-descriptions-item>
		</el-descriptions>
		<el-table :data="detail.items" border size="small" style="margin-top:12px">
			<el-table-column prop="product_name" label="商品" min-width="160" />
			<el-table-column prop="spec" label="规格" width="100" />
			<el-table-column prop="sale_qty" label="数量" width="70" align="center" />
			<el-table-column prop="unit_price" label="单价" width="90" align="right" />
			<el-table-column prop="amount" label="金额" width="100" align="right" />
			<el-table-column prop="price_source" label="价格来源" width="100" align="center" />
		</el-table>
	</el-dialog>
</template>
<script setup>
import { ref } from 'vue'; import api from '@/api/business.js';
const visible = ref(false); const detail = ref({});
const statusLabel = (s) => ({ draft: '草稿', approved: '已确认', cancelled: '已取消' }[s] || s);
const payLabel = (m) => ({ cash: '现金', wechat: '微信', alipay: '支付宝', card: '银行卡', credit: '挂账' }[m] || m);
const open = async (id) => { const res = await api.vanSaleOrder.detail.get(id); detail.value = res.data; visible.value = true; };
defineExpose({ open });
</script>
