<template>
	<el-dialog v-model="visible" :title="form.id ? '编辑还货单' : '新增还货单'" width="1000px" @close="onClose">
		<el-form :model="form" label-width="100px">
			<el-row :gutter="16">
				<el-col :span="12"><el-form-item label="关联借货单" required><el-select v-model="form.borrow_order_id" placeholder="选择未还/部分还的借货单" filterable :disabled="!!form.id" style="width:100%" @change="onBorrowChange"><el-option v-for="b in borrowOrders" :key="b.id" :label="b.borrow_no + ' / ' + b.customer_name" :value="b.id" /></el-select></el-form-item></el-col>
				<el-col :span="8"><el-form-item label="还货日期"><el-date-picker v-model="form.return_date" type="date" value-format="YYYY-MM-DD" style="width:100%" /></el-form-item></el-col>
			</el-row>
			<el-form-item label="还货说明"><el-input v-model="form.return_reason" placeholder="如：客户退货" style="width:100%" /></el-form-item>
		</el-form>
		<el-table :data="form.items" border size="small" max-height="320">
			<el-table-column type="index" label="#" width="40" align="center" />
			<el-table-column prop="product_name" label="商品" min-width="150" />
			<el-table-column prop="spec" label="规格" width="90" />
			<el-table-column prop="unreturned_qty" label="未还数量" width="80" align="center" />
			<el-table-column label="还货数量" width="120" align="center"><template #default="{ row }"><el-input-number v-model="row.return_qty" :min="0" :max="row.unreturned_qty" size="small" controls-position="right" style="width:100px" @input="autoSplit(row)" /></template></el-table-column>
			<el-table-column label="完好数量" width="120" align="center"><template #default="{ row }"><el-input-number v-model="row.good_qty" :min="0" :max="row.return_qty" size="small" controls-position="right" style="width:100px" @input="syncBad(row)" /></template></el-table-column>
			<el-table-column label="破损数量" width="120" align="center"><template #default="{ row }"><el-input-number v-model="row.bad_qty" :min="0" :max="row.return_qty" size="small" controls-position="right" style="width:100px" @input="syncGood(row)" /></template></el-table-column>
			<el-table-column label="破损说明" width="160"><template #default="{ row }"><el-input v-model="row.bad_reason" placeholder="破损必填" size="small" /></template></el-table-column>
		</el-table>
		<div class="summary">合计：数量 <b>{{ totalQty }}</b> ｜ 完好 <b>{{ totalGood }}</b> ｜ 破损 <b style="color:#f56c6c">{{ totalBad }}</b></div>
		<template #footer>
			<el-button @click="visible = false">取消</el-button>
			<el-button type="primary" @click="onSave()" :loading="saving">保存待审核</el-button>
		</template>
	</el-dialog>
</template>

<script setup>
import { ref, computed } from 'vue';
import { ElMessage } from 'element-plus';
import api from '@/api/business.js';
const emit = defineEmits(['saved']);
const visible = ref(false); const saving = ref(false); const borrowOrders = ref([]);
const form = ref({ id: null, borrow_order_id: null, return_date: new Date().toISOString().slice(0, 10), return_reason: '', items: [] });
const totalQty = computed(() => form.value.items.reduce((s, i) => s + (Number(i.return_qty) || 0), 0));
const totalGood = computed(() => form.value.items.reduce((s, i) => s + (Number(i.good_qty) || 0), 0));
const totalBad = computed(() => form.value.items.reduce((s, i) => s + (Number(i.bad_qty) || 0), 0));
const autoSplit = (row) => { row.good_qty = row.return_qty; row.bad_qty = 0; };
const syncBad = (row) => { row.bad_qty = Math.max(0, (Number(row.return_qty) || 0) - (Number(row.good_qty) || 0)); };
const syncGood = (row) => { row.good_qty = Math.max(0, (Number(row.return_qty) || 0) - (Number(row.bad_qty) || 0)); };
const loadBasics = async () => {
	const [u, p] = await Promise.all([api.borrowOrder.list.get({ status: 'unreturned', page_size: 200 }), api.borrowOrder.list.get({ status: 'partial', page_size: 200 })]);
	const map = new Map();
	[...(u.data?.list || []), ...(p.data?.list || [])].forEach(b => { if (!map.has(b.id)) map.set(b.id, b); });
	borrowOrders.value = [...map.values()];
};
const onBorrowChange = async () => {
	if (!form.value.borrow_order_id) return;
	const res = await api.returnOrder.pendingBorrowItems.get({ borrow_order_id: form.value.borrow_order_id });
	form.value.items = (res.data?.items || []).map(i => ({ borrow_order_item_id: i.borrow_order_item_id, product_id: i.product_id, product_name: i.product_name, spec: i.spec, unreturned_qty: i.unreturned_qty, return_qty: i.unreturned_qty, good_qty: i.unreturned_qty, bad_qty: 0, bad_reason: '' }));
};
const open = async (row) => { visible.value = true; await loadBasics(); if (row && row.id) { const res = await api.returnOrder.detail.get(row.id); const d = res.data; form.value = { id: d.id, borrow_order_id: d.borrow_order_id, return_date: d.return_date, return_reason: d.return_reason, items: (d.items || []).map(i => ({ borrow_order_item_id: i.borrow_order_item_id, product_id: i.product_id, product_name: i.product_name, spec: i.spec, unreturned_qty: i.unreturned_qty, return_qty: i.return_qty, good_qty: i.good_qty, bad_qty: i.bad_qty, bad_reason: i.bad_reason })) }; } else { form.value = { id: null, borrow_order_id: null, return_date: new Date().toISOString().slice(0, 10), return_reason: '', items: [] }; } };
const onSave = async () => {
	if (!form.value.borrow_order_id) return ElMessage.warning('请选择关联借货单');
	if (!form.value.items.length) return ElMessage.warning('无可还货明细');
	for (const i of form.value.items) {
		if ((Number(i.good_qty) || 0) + (Number(i.bad_qty) || 0) !== (Number(i.return_qty) || 0)) return ElMessage.warning(`商品【${i.product_name}】完好与破损之和必须等于还货数量`);
		if ((Number(i.bad_qty) || 0) > 0 && !(i.bad_reason && i.bad_reason.trim())) return ElMessage.warning(`商品【${i.product_name}】有破损，请填写破损说明`);
	}
	const validItems = form.value.items.filter(i => (Number(i.return_qty) || 0) > 0).map(i => ({ borrow_order_item_id: i.borrow_order_item_id, product_id: i.product_id, return_qty: i.return_qty, good_qty: i.good_qty, bad_qty: i.bad_qty, bad_reason: i.bad_reason }));
	if (!validItems.length) return ElMessage.warning('请录入有效还货数量');
	const payload = { borrow_order_id: form.value.borrow_order_id, return_date: form.value.return_date, return_reason: form.value.return_reason, items: validItems };
	saving.value = true;
	try { if (form.value.id) { await api.returnOrder.update.put(form.value.id, payload); } else { await api.returnOrder.create.post(payload); } ElMessage.success('已保存，待审核'); visible.value = false; emit('saved'); }
	catch (e) { ElMessage.error(e.response?.data?.message || '保存失败'); } finally { saving.value = false; }
};
const onClose = () => { form.value = { id: null, borrow_order_id: null, return_date: new Date().toISOString().slice(0, 10), return_reason: '', items: [] }; };
defineExpose({ open });
</script>
<style scoped>.summary { margin-top: 12px; text-align: right; font-size: 14px; }</style>
