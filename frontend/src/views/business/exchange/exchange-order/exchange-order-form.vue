<template>
	<el-dialog v-model="visible" :title="form.id ? '编辑换货单' : '新增换货单'" width="1200px" @close="onClose">
		<el-form :model="form" label-width="100px">
			<el-row :gutter="16">
				<el-col :span="8"><el-form-item label="客户" required><el-select v-model="form.customer_id" placeholder="选择客户" filterable style="width:100%"><el-option v-for="c in customers" :key="c.id" :label="c.name" :value="c.id" /></el-select></el-form-item></el-col>
				<el-col :span="8"><el-form-item label="仓库" required><el-select v-model="form.warehouse_id" placeholder="选择仓库" filterable @change="onWarehouseChange" style="width:100%"><el-option v-for="w in warehouses" :key="w.id" :label="w.name" :value="w.id" /></el-select></el-form-item></el-col>
				<el-col :span="8"><el-form-item label="换货日期"><el-date-picker v-model="form.exchange_date" type="date" value-format="YYYY-MM-DD" style="width:100%" /></el-form-item></el-col>
			</el-row>
			<el-row :gutter="16">
				<el-col :span="12"><el-form-item label="原销售单号"><el-select v-model="form.sales_order_id" placeholder="输入销售单号搜索（可留空）" filterable remote clearable reserve-keyword :remote-method="searchSalesOrder" :loading="soLoading" style="width:100%" @change="onSalesOrderChange" @clear="form.sales_order_no = ''"><el-option v-for="o in salesOrderOptions" :key="o.id" :label="`${o.order_no} / ${o.customer_name || ''}`" :value="o.id" /></el-select></el-form-item></el-col>
				<el-col :span="12"><el-form-item label="换货原因" required><el-input v-model="form.exchange_reason" placeholder="如：包装破损、口味不符" style="width:100%" /></el-form-item></el-col>
			</el-row>
		</el-form>
		<div style="margin: 8px 0 12px"><el-button type="primary" size="small" @click="addRow" :disabled="!form.warehouse_id">+ 添加换货明细</el-button></div>
		<el-table :data="form.items" border size="small" max-height="340">
			<el-table-column type="index" label="#" width="40" align="center" />
			<el-table-column label="换出商品" min-width="170">
				<template #default="{ row }"><el-select v-model="row.product_id_out" placeholder="选择" filterable size="small" style="width:100%" @change="(v) => onOutChange(row, v)"><el-option v-for="p in outOptions" :key="p.id" :label="p.name + ' / ' + (p.spec || '')" :value="p.id" /></el-select></template>
			</el-table-column>
			<el-table-column label="数量" width="100" align="center"><template #default="{ row }"><el-input-number v-model="row.qty" :min="0" size="small" controls-position="right" style="width:90px" @input="calcRow(row)" /></template></el-table-column>
			<el-table-column label="换出单价" width="110" align="right"><template #default="{ row }"><el-input-number v-model="row.unit_price_out" :min="0" :precision="2" size="small" controls-position="right" style="width:100px" @input="calcRow(row)" /></template></el-table-column>
			<el-table-column label="换出金额" width="100" align="right"><template #default="{ row }"><span class="amt">¥{{ (row.amount_out || 0).toFixed(2) }}</span></template></el-table-column>
			<el-table-column label="换入商品" min-width="170">
				<template #default="{ row }"><el-select v-model="row.product_id_in" placeholder="选择" filterable size="small" style="width:100%" :disabled="!form.warehouse_id" @change="(v) => onInChange(row, v)"><el-option v-for="p in inOptions" :key="p.product_id" :label="p.product_name + ' / ' + (p.spec || '')" :value="p.product_id" /></el-select></template>
			</el-table-column>
			<el-table-column label="换入单价" width="110" align="right"><template #default="{ row }"><el-input-number v-model="row.unit_price_in" :min="0" :precision="2" size="small" controls-position="right" style="width:100px" @input="calcRow(row)" /></template></el-table-column>
			<el-table-column label="换入金额" width="100" align="right"><template #default="{ row }"><span class="amt">¥{{ (row.amount_in || 0).toFixed(2) }}</span></template></el-table-column>
			<el-table-column label="行差价" width="110" align="right"><template #default="{ row }"><span :style="{ color: row.diff > 0 ? '#f5222d' : (row.diff < 0 ? '#67c23a' : '#606266'), fontWeight: 700 }">{{ row.diff > 0 ? '+' : '' }}¥{{ (row.diff || 0).toFixed(2) }}</span></template></el-table-column>
			<el-table-column label="" width="50" align="center"><template #default="{ $index }"><el-button link type="danger" @click="form.items.splice($index, 1)">删</el-button></template></el-table-column>
		</el-table>
		<div class="summary">换出合计：<b class="amt">¥{{ totalOut }}</b> ｜ 换入合计：<b class="amt">¥{{ totalIn }}</b> ｜ 总差价：<b :style="{ color: totalDiff > 0 ? '#f5222d' : (totalDiff < 0 ? '#67c23a' : '#606266') }">¥{{ totalDiff }}</b></div>
		<template #footer>
			<el-button @click="visible = false">取消</el-button>
			<el-button type="primary" @click="onSave()" :loading="saving">保存草稿</el-button>
		</template>
	</el-dialog>
</template>

<script setup>
import { ref, computed } from 'vue';
import { ElMessage } from 'element-plus';
import api from '@/api/business.js';
const emit = defineEmits(['saved']);
const visible = ref(false); const saving = ref(false);
const customers = ref([]); const warehouses = ref([]); const outOptions = ref([]); const inOptions = ref([]);
const salesOrderOptions = ref([]); const soLoading = ref(false);
const form = ref({ id: null, customer_id: null, warehouse_id: null, sales_order_id: null, sales_order_no: '', exchange_date: new Date().toISOString().slice(0, 10), exchange_reason: '', items: [] });
const totalOut = computed(() => form.value.items.reduce((s, i) => s + (Number(i.amount_out) || 0), 0).toFixed(2));
const totalIn = computed(() => form.value.items.reduce((s, i) => s + (Number(i.amount_in) || 0), 0).toFixed(2));
const totalDiff = computed(() => (Number(totalIn.value) - Number(totalOut.value)).toFixed(2));
const calcRow = (row) => { row.amount_out = Math.round((Number(row.qty) || 0) * (Number(row.unit_price_out) || 0) * 100) / 100; row.amount_in = Math.round((Number(row.qty) || 0) * (Number(row.unit_price_in) || 0) * 100) / 100; row.diff = Math.round((Number(row.amount_in) - Number(row.amount_out)) * 100) / 100; };
const loadBasics = async () => {
	const [cRes, wRes, pRes] = await Promise.all([api.customer.list.get({ page_size: 200 }), api.warehouse.list.get({ page_size: 200 }), api.product.list.get({ page_size: 200 })]);
	customers.value = cRes.data?.list || []; warehouses.value = wRes.data?.list || []; outOptions.value = pRes.data?.list || [];
};
// 原销售单号远程搜索（用于带出原商品明细）
const searchSalesOrder = async (kw) => {
	if (!kw) { salesOrderOptions.value = []; return; }
	soLoading.value = true;
	try {
		const res = await api.salesOrder.list.get({ order_no: kw, page_size: 20 });
		salesOrderOptions.value = res.data?.list || [];
	} finally { soLoading.value = false; }
};
// 选中原销售单：自动带出客户/仓库，并把原商品明细填进换出侧（客户退回的商品）
const onSalesOrderChange = async (id) => {
	const picked = salesOrderOptions.value.find((o) => o.id === id);
	form.value.sales_order_no = picked?.order_no || '';
	if (!id) return;
	try {
		const res = await api.exchangeOrder.salesOrderItems.get({ sales_order_id: id });
		const data = res.data;
		if (!data) return;
		if (!form.value.customer_id && data.order?.customer_id) form.value.customer_id = data.order.customer_id;
		// onWarehouseChange 会清空明细，必须先于填充明细调用
		if (!form.value.warehouse_id && data.order?.warehouse_id) {
			form.value.warehouse_id = data.order.warehouse_id;
			await onWarehouseChange();
		}
		form.value.items = (data.items || []).map((i) => ({
			product_id_out: i.product_id,
			product_id_in: null,
			qty: Number(i.quantity) || 1,
			unit_price_out: Number(i.price) || 0,
			unit_price_in: 0,
			amount_out: Number(i.amount) || 0,
			amount_in: 0,
			diff: -(Number(i.amount) || 0),
		}));
		ElMessage.success(`已带出原销售单 ${data.order?.order_no || ''} 的 ${form.value.items.length} 条商品明细`);
	} catch (e) {
		ElMessage.error('带出原销售单明细失败');
	}
};
const onWarehouseChange = async () => { form.value.items = []; inOptions.value = []; if (form.value.warehouse_id) { const res = await api.exchangeOrder.warehouseProducts.get({ warehouse_id: form.value.warehouse_id }); inOptions.value = res.data?.list || []; } };
const addRow = () => { form.value.items.push({ product_id_out: null, product_id_in: null, qty: 1, unit_price_out: 0, unit_price_in: 0, amount_out: 0, amount_in: 0, diff: 0 }); };
const onOutChange = (row, pid) => { const p = outOptions.value.find(o => o.id === pid); if (p) { row.unit_price_out = Number(p.price_small) || 0; } calcRow(row); };
const onInChange = (row, pid) => { const p = inOptions.value.find(o => o.product_id === pid); if (p) { row.unit_price_in = Number(p.price_small) || 0; } calcRow(row); };
const open = async (row) => { visible.value = true; await loadBasics(); if (row && row.id) { const res = await api.exchangeOrder.detail.get(row.id); const d = res.data; form.value = { id: d.id, customer_id: d.customer_id, warehouse_id: d.warehouse_id, sales_order_id: d.sales_order_id || null, sales_order_no: d.sales_order_no || '', exchange_date: d.exchange_date, exchange_reason: d.exchange_reason, items: (d.items || []).map(i => ({ product_id_out: i.product_id_out, product_id_in: i.product_id_in, qty: i.qty, unit_price_out: i.unit_price_out, unit_price_in: i.unit_price_in, amount_out: Number(i.amount_out) || 0, amount_in: Number(i.amount_in) || 0, diff: Number(i.diff_amount) || 0 })) }; if (form.value.warehouse_id) { const r2 = await api.exchangeOrder.warehouseProducts.get({ warehouse_id: form.value.warehouse_id }); inOptions.value = r2.data?.list || []; } } else { form.value = { id: null, customer_id: null, warehouse_id: null, sales_order_id: null, sales_order_no: '', exchange_date: new Date().toISOString().slice(0, 10), exchange_reason: '', items: [] }; } };
const onSave = async () => {
	if (!form.value.customer_id) return ElMessage.warning('请选择客户');
	if (!form.value.warehouse_id) return ElMessage.warning('请选择仓库');
	if (!form.value.exchange_reason || !form.value.exchange_reason.trim()) return ElMessage.warning('请填写换货原因');
	if (!form.value.items.length) return ElMessage.warning('请添加换货明细');
	const validItems = form.value.items.filter(i => i.product_id_out && i.product_id_in && (Number(i.qty) || 0) > 0);
	if (!validItems.length) return ElMessage.warning('请录入有效换货明细（换入/换出商品均须选择）');
	const payload = { customer_id: form.value.customer_id, warehouse_id: form.value.warehouse_id, sales_order_id: form.value.sales_order_id || null, sales_order_no: form.value.sales_order_no || '', exchange_date: form.value.exchange_date, exchange_reason: form.value.exchange_reason, items: validItems.map(i => ({ product_id_out: i.product_id_out, product_id_in: i.product_id_in, qty: i.qty, unit_price_out: i.unit_price_out, unit_price_in: i.unit_price_in })) };
	saving.value = true;
	try { if (form.value.id) { await api.exchangeOrder.update.put(form.value.id, payload); } else { await api.exchangeOrder.create.post(payload); } ElMessage.success('草稿已保存'); visible.value = false; emit('saved'); }
	catch (e) { ElMessage.error(e.response?.data?.message || '保存失败'); } finally { saving.value = false; }
};
const onClose = () => { form.value = { id: null, customer_id: null, warehouse_id: null, sales_order_id: null, sales_order_no: '', exchange_date: new Date().toISOString().slice(0, 10), exchange_reason: '', items: [] }; };
defineExpose({ open });
</script>
<style scoped>.amt { color: #f5222d; font-weight: 700; } .summary { margin-top: 12px; text-align: right; font-size: 14px; }</style>
