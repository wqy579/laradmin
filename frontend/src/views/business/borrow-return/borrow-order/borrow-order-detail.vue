<template>
	<el-dialog v-model="visible" title="借货单详情" width="900px">
		<div v-loading="loading">
			<el-descriptions :column="3" border size="small" v-if="data">
				<el-descriptions-item label="借货单号">{{ data.borrow_no }}</el-descriptions-item>
				<el-descriptions-item label="客户">{{ data.customer_name }}</el-descriptions-item>
				<el-descriptions-item label="仓库">{{ data.warehouse_name }}</el-descriptions-item>
				<el-descriptions-item label="业务员">{{ data.salesman_name }}</el-descriptions-item>
				<el-descriptions-item label="借货日期">{{ data.borrow_date }}</el-descriptions-item>
				<el-descriptions-item label="应还日期">{{ data.due_date || '—' }}</el-descriptions-item>
				<el-descriptions-item label="借货原因">{{ data.borrow_reason || '—' }}</el-descriptions-item>
				<el-descriptions-item label="状态"><el-tag :type="statusType(data.status)" size="small">{{ statusLabel(data.status) }}</el-tag></el-descriptions-item>
				<el-descriptions-item label="已还数量">{{ data.returned_qty }}</el-descriptions-item>
				<el-descriptions-item label="金额">¥{{ data.total_amount }}</el-descriptions-item>
			</el-descriptions>
			<el-table :data="data?.items || []" border size="small" style="margin-top:12px">
				<el-table-column type="index" label="#" width="40" align="center" />
				<el-table-column prop="product_name" label="商品" min-width="160" />
				<el-table-column prop="spec" label="规格" width="100" />
				<el-table-column prop="borrow_qty" label="借货数量" width="90" align="center" />
				<el-table-column prop="returned_qty" label="已还" width="80" align="center" />
				<el-table-column prop="unit_price" label="单价" width="100" align="right" />
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
const statusLabel = (s) => ({ draft: '未还', unreturned: '未还', partial: '部分还', cleared: '已还清', converted: '已转销售', cancelled: '已取消' }[s] || s);
const statusType = (s) => ({ draft: 'info', unreturned: 'warning', partial: 'primary', cleared: 'success', converted: 'success', cancelled: 'danger' }[s] || 'info');
const open = async (id) => { visible.value = true; loading.value = true; data.value = null; try { const res = await api.borrowOrder.detail.get(id); data.value = res.data; } finally { loading.value = false; } };
defineExpose({ open });
</script>
