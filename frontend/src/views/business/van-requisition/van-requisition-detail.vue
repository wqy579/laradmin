<template>
	<el-dialog v-model="visible" title="要货申请详情" width="900px">
		<el-descriptions :column="3" border size="small">
			<el-descriptions-item label="要货单号">{{ detail.requisition_no }}</el-descriptions-item>
			<el-descriptions-item label="业务员">{{ detail.salesman_name }}</el-descriptions-item>
			<el-descriptions-item label="车牌号">{{ detail.vehicle?.plate_no || '-' }}</el-descriptions-item>
			<el-descriptions-item label="源仓库">{{ detail.warehouse?.name || '-' }}</el-descriptions-item>
			<el-descriptions-item label="申请日期">{{ detail.apply_date }}</el-descriptions-item>
			<el-descriptions-item label="状态">{{ statusLabel(detail.status) }}</el-descriptions-item>
			<el-descriptions-item label="商品总数">{{ detail.total_qty }}</el-descriptions-item>
			<el-descriptions-item label="总金额">¥{{ detail.total_amount }}</el-descriptions-item>
			<el-descriptions-item label="审核人">{{ detail.approver_name || '-' }}</el-descriptions-item>
		</el-descriptions>
		<el-table :data="detail.items" border size="small" style="margin-top:12px">
			<el-table-column prop="product_name" label="商品" min-width="160" />
			<el-table-column prop="spec" label="规格" width="100" />
			<el-table-column prop="stock_qty" label="库存" width="80" align="center" />
			<el-table-column prop="apply_qty" label="申请数量" width="90" align="center" />
			<el-table-column prop="unit_cost" label="成本价" width="90" align="right" />
			<el-table-column prop="amount" label="金额" width="100" align="right" />
		</el-table>
	</el-dialog>
</template>

<script setup>
import { ref } from 'vue';
import api from '@/api/business.js';
const visible = ref(false);
const detail = ref({ items: [] });
const statusLabel = (s) => ({ draft: '草稿', pending: '待审核', approved: '已审核', picked: '已拣货', rejected: '已驳回', cancelled: '已取消' }[s] || s);
const open = async (id) => {
	const res = await api.vanRequisition.detail.get(id);
	detail.value = res.data;
	visible.value = true;
};
defineExpose({ open });
</script>
