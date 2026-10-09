<template>
	<el-dialog v-model="visible" title="换货单详情" width="1100px">
		<el-descriptions v-if="detail.id" :column="3" border size="small">
			<el-descriptions-item label="换货单号">{{ detail.exchange_no }}</el-descriptions-item>
			<el-descriptions-item label="客户">{{ detail.customer_name }}</el-descriptions-item>
			<el-descriptions-item label="业务员">{{ detail.salesman_name }}</el-descriptions-item>
			<el-descriptions-item label="换货日期">{{ detail.exchange_date }}</el-descriptions-item>
			<el-descriptions-item label="差价金额">¥{{ detail.diff_amount }}</el-descriptions-item>
			<el-descriptions-item label="结算方式">{{ settleLabel(detail.settle_method) }}</el-descriptions-item>
			<el-descriptions-item label="状态">{{ statusLabel(detail.status) }}</el-descriptions-item>
		</el-descriptions>
		<el-table :data="detail.items" border size="small" style="margin-top:12px">
			<el-table-column prop="product_name_out" label="换出商品" min-width="140" />
			<el-table-column prop="spec_out" label="规格" width="90" />
			<el-table-column prop="qty" label="数量" width="60" align="center" />
			<el-table-column prop="unit_price_out" label="换出单价" width="90" align="right" />
			<el-table-column prop="amount_out" label="换出金额" width="100" align="right" />
			<el-table-column prop="product_name_in" label="换入商品" min-width="140" />
			<el-table-column prop="spec_in" label="规格" width="90" />
			<el-table-column prop="unit_price_in" label="换入单价" width="90" align="right" />
			<el-table-column prop="amount_in" label="换入金额" width="100" align="right" />
			<el-table-column prop="diff" label="行差价" width="100" align="right"><template #default="{ row }"><span class="amt" :style="{ color: row.diff > 0 ? '#f5222d' : '#52c41a' }">{{ row.diff > 0 ? '+' : '' }}¥{{ row.diff }}</span></template></el-table-column>
		</el-table>
	</el-dialog>
</template>
<script setup>
import { ref } from 'vue'; import api from '@/api/business.js';
const visible = ref(false); const detail = ref({});
const statusLabel = (s) => ({ draft: '草稿', approved: '已换货', cancelled: '已取消' }[s] || s);
const settleLabel = (m) => ({ cash: '现金', offset: '冲抵应收', credit: '挂账' }[m] || m);
const open = async (id) => { const res = await api.vanExchangeOrder.detail.get(id); detail.value = res.data; visible.value = true; };
defineExpose({ open });
</script>
<style scoped>.amt { color: #f5222d; font-weight: 700; }</style>
