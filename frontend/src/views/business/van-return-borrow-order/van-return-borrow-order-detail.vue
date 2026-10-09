<template>
	<el-dialog v-model="visible" title="还货单详情" width="850px">
		<el-descriptions v-if="detail.id" :column="3" border size="small">
			<el-descriptions-item label="还货单号">{{ detail.return_no }}</el-descriptions-item>
			<el-descriptions-item label="客户">{{ detail.customer_name }}</el-descriptions-item>
			<el-descriptions-item label="业务员">{{ detail.salesman_name }}</el-descriptions-item>
			<el-descriptions-item label="还货日期">{{ detail.return_date }}</el-descriptions-item>
			<el-descriptions-item label="总金额">¥{{ detail.total_amount }}</el-descriptions-item>
			<el-descriptions-item label="总数量">{{ detail.total_qty }}</el-descriptions-item>
			<el-descriptions-item label="状态">{{ statusLabel(detail.status) }}</el-descriptions-item>
		</el-descriptions>
		<el-table :data="detail.items" border size="small" style="margin-top:12px">
			<el-table-column prop="product_name" label="商品" min-width="160" />
			<el-table-column prop="spec" label="规格" width="100" />
			<el-table-column prop="return_qty" label="数量" width="70" align="center" />
			<el-table-column prop="unit_price" label="单价" width="90" align="right" />
			<el-table-column prop="amount" label="金额" width="100" align="right" />
		</el-table>
	</el-dialog>
</template>
<script setup>
import { ref } from 'vue'; import api from '@/api/business.js';
const visible = ref(false); const detail = ref({});
const statusLabel = (s) => ({ draft: '草稿', approved: '已还入', cancelled: '已取消' }[s] || s);
const open = async (id) => { const res = await api.vanReturnBorrowOrder.detail.get(id); detail.value = res.data; visible.value = true; };
defineExpose({ open });
</script>
