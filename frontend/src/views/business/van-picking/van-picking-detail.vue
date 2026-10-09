<template>
	<el-dialog v-model="visible" :title="title" width="850px">
		<div v-if="detail.id">
			<el-descriptions :column="3" border size="small" style="margin-bottom:12px">
				<el-descriptions-item label="拣货单号">{{ detail.picking_no }}</el-descriptions-item>
				<el-descriptions-item label="要货单号">{{ detail.requisition?.requisition_no || '-' }}</el-descriptions-item>
				<el-descriptions-item label="车牌号">{{ detail.vehicle?.plate_no || '-' }}</el-descriptions-item>
				<el-descriptions-item label="源仓库">{{ detail.requisition?.warehouse?.name || '-' }}</el-descriptions-item>
				<el-descriptions-item label="库管员">{{ detail.picker_name }}</el-descriptions-item>
				<el-descriptions-item label="状态">{{ statusLabel(detail.status) }}{{ detail.checked ? ' / 已验货' : '' }}</el-descriptions-item>
			</el-descriptions>
			<el-table :data="detail.items" border size="small">
				<el-table-column prop="product_name" label="商品" min-width="160" />
				<el-table-column prop="spec" label="规格" width="100" />
				<el-table-column prop="apply_qty" label="申请" width="70" align="center" />
				<el-table-column prop="pick_qty" label="拣货" width="70" align="center" />
				<el-table-column v-if="canCheck" label="验货实收" width="120" align="center">
					<template #default="{ row }"><el-input-number v-model="checkMap[row.id]" :min="0" size="small" controls-position="right" style="width:100px" @input="calcDiff(row)" /></template>
				</el-table-column>
				<el-table-column v-else label="验货实收" width="90" align="center">
					<template #default="{ row }">{{ row.check_qty ?? '-' }}</template>
				</el-table-column>
				<el-table-column label="差异" width="70" align="center">
					<template #default="{ row }"><span :style="{ color: (row.diff_qty || (checkMap[row.id] !== undefined ? checkMap[row.id] - row.pick_qty : 0)) !== 0 ? '#f56c6c' : '#333' }">{{ row.diff_qty ?? (checkMap[row.id] !== undefined ? checkMap[row.id] - row.pick_qty : 0) }}</span></template>
				</el-table-column>
				<el-table-column v-if="canCheck" label="差异备注" width="160">
					<template #default="{ row }"><el-input v-model="diffRemarkMap[row.id]" size="small" placeholder="差异≠0必填" /></template>
				</el-table-column>
				<el-table-column v-else label="差异备注" width="160"><template #default="{ row }">{{ row.diff_remark || '-' }}</template></el-table-column>
			</el-table>
		</div>
		<template #footer>
			<el-button @click="visible = false">关闭</el-button>
			<el-button v-if="canCheck" type="primary" @click="onCheck(false)">确认验货</el-button>
			<el-button v-if="canCheck" type="danger" @click="onCheck(true)">异常退回</el-button>
		</template>
	</el-dialog>
</template>

<script setup>
import { ref, computed } from 'vue';
import { ElMessage } from 'element-plus';
import api from '@/api/business.js';
const emit = defineEmits(['saved']);
const visible = ref(false);
const detail = ref({});
const checkMap = ref({});
const diffRemarkMap = ref({});
const canCheck = computed(() => detail.value.status === 'approved' && !detail.value.checked);
const title = computed(() => canCheck.value ? '验货' : '拣货单详情');
const statusLabel = (s) => ({ draft: '草稿', pending: '待确认', approved: '已装车', cancelled: '已取消' }[s] || s);

const calcDiff = (row) => { /* diff 在模板里实时计算 */ };

const open = async (id) => {
	const res = await api.vanPicking.detail.get(id);
	detail.value = res.data;
	checkMap.value = {};
	diffRemarkMap.value = {};
	(detail.value.items || []).forEach(i => { checkMap.value[i.id] = i.pick_qty; });
	visible.value = true;
};
const onCheck = async (abnormal) => {
	const items = (detail.value.items || []).map(i => ({ product_id: i.product_id, check_qty: checkMap.value[i.id] ?? 0, diff_remark: diffRemarkMap.value[i.id] || '' }));
	try {
		await api.vanPicking.check.post(detail.value.id, { items, abnormal });
		ElMessage.success(abnormal ? '已退回重拣' : '验货通过');
		visible.value = false;
		emit('saved');
	} catch (e) { ElMessage.error(e.response?.data?.message || '验货失败'); }
};
defineExpose({ open });
</script>
