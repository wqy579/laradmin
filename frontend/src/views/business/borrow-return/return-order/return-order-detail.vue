<template>
	<el-dialog v-model="visible" title="还货单详情" width="900px">
		<div v-loading="loading">
			<el-descriptions :column="3" border size="small" v-if="data">
				<el-descriptions-item label="还货单号">{{ data.return_no }}</el-descriptions-item>
				<el-descriptions-item label="关联借货单">{{ data.borrow_order?.borrow_no || '—' }}</el-descriptions-item>
				<el-descriptions-item label="客户">{{ data.customer_name }}</el-descriptions-item>
				<el-descriptions-item label="仓库">{{ data.warehouse_name }}</el-descriptions-item>
				<el-descriptions-item label="业务员">{{ data.salesman_name }}</el-descriptions-item>
				<el-descriptions-item label="还货日期">{{ data.return_date }}</el-descriptions-item>
				<el-descriptions-item label="状态"><el-tag :type="statusType(data.status)" size="small">{{ statusLabel(data.status) }}</el-tag></el-descriptions-item>
				<el-descriptions-item label="完好/破损">{{ data.good_qty }}/{{ data.bad_qty }}</el-descriptions-item>
				<el-descriptions-item label="金额">¥{{ data.total_amount }}</el-descriptions-item>
			</el-descriptions>
			<el-table :data="data?.items || []" border size="small" style="margin-top:12px">
				<el-table-column type="index" label="#" width="40" align="center" />
				<el-table-column prop="product_name" label="商品" min-width="160" />
				<el-table-column prop="spec" label="规格" width="100" />
				<el-table-column prop="return_qty" label="还货数量" width="90" align="center" />
				<el-table-column prop="good_qty" label="完好" width="80" align="center" />
				<el-table-column prop="bad_qty" label="破损" width="80" align="center" />
				<el-table-column prop="bad_reason" label="破损说明" min-width="120" />
				<el-table-column prop="amount" label="金额" width="100" align="right" />
			</el-table>
		</div>
		<template #footer><el-button @click="visible = false">关闭</el-button></template>
	</el-dialog>
</template>

<script setup>
import { ref } from 'vue';
import api from '@/api/business.js';
const visible = ref(false); const loading = ref(false); const data = ref(null);
const statusLabel = (s) => ({ pending: '待审核', approved: '已审核', cancelled: '已取消' }[s] || s);
const statusType = (s) => ({ pending: 'warning', approved: 'success', cancelled: 'danger' }[s] || 'info');
const open = async (id) => { visible.value = true; loading.value = true; data.value = null; try { const res = await api.returnOrder.detail.get(id); data.value = res.data; } finally { loading.value = false; } };
defineExpose({ open });
</script>
