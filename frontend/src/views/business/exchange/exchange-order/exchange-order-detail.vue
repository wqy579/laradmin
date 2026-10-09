<template>
	<el-dialog v-model="visible" title="换货单详情" width="950px">
		<div v-loading="loading">
			<el-descriptions :column="3" border size="small" v-if="data">
				<el-descriptions-item label="换货单号">{{ data.exchange_no }}</el-descriptions-item>
				<el-descriptions-item label="客户">{{ data.customer_name }}</el-descriptions-item>
				<el-descriptions-item label="仓库">{{ data.warehouse_name }}</el-descriptions-item>
				<el-descriptions-item label="业务员">{{ data.salesman_name }}</el-descriptions-item>
				<el-descriptions-item label="换货日期">{{ data.exchange_date }}</el-descriptions-item>
				<el-descriptions-item label="换货原因">{{ data.exchange_reason }}</el-descriptions-item>
				<el-descriptions-item label="状态"><el-tag :type="statusType(data.status)" size="small">{{ statusLabel(data.status) }}</el-tag></el-descriptions-item>
				<el-descriptions-item label="差价">¥{{ data.diff_amount }}</el-descriptions-item>
				<el-descriptions-item label="结算">{{ settleLabel(data) }}</el-descriptions-item>
			</el-descriptions>
			<el-table :data="data?.items || []" border size="small" style="margin-top:12px">
				<el-table-column type="index" label="#" width="40" align="center" />
				<el-table-column prop="product_name_out" label="换出商品" min-width="150" />
				<el-table-column prop="qty" label="数量" width="70" align="center" />
				<el-table-column prop="unit_price_out" label="换出单价" width="100" align="right" />
				<el-table-column prop="amount_out" label="换出金额" width="100" align="right" />
				<el-table-column prop="product_name_in" label="换入商品" min-width="150" />
				<el-table-column prop="unit_price_in" label="换入单价" width="100" align="right" />
				<el-table-column prop="amount_in" label="换入金额" width="100" align="right" />
				<el-table-column prop="diff_amount" label="行差价" width="100" align="right" />
			</el-table>
		</div>
		<template #footer><el-button @click="visible = false">关闭</el-button></template>
	</el-dialog>
</template>

<script setup>
import { ref } from 'vue';
import api from '@/api/business.js';
const visible = ref(false); const loading = ref(false); const data = ref(null);
const statusLabel = (s) => ({ draft: '草稿', pending: '待审核', approved: '已审核', cancelled: '已取消' }[s] || s);
const statusType = (s) => ({ draft: 'info', pending: 'warning', approved: 'success', cancelled: 'danger' }[s] || 'info');
const settleLabel = (r) => { if ((r.diff_amount || 0) > 0.01) return r.payment_method ? ({ cash: '现金收款', wechat: '微信收款', alipay: '支付宝收款', bank: '银行卡收款', credit: '挂账' }[r.payment_method] || '客户补款') : '客户补款'; if ((r.diff_amount || 0) < -0.01) return r.refund_method ? ({ cash_return: '现金退回', offset: '冲抵应收' }[r.refund_method] || '退款') : '退款'; return '等价交换'; };
const open = async (id) => { visible.value = true; loading.value = true; data.value = null; try { const res = await api.exchangeOrder.detail.get(id); data.value = res.data; } finally { loading.value = false; } };
defineExpose({ open });
</script>
