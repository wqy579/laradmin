<template>
	<el-dialog v-model="visible" :title="form.id ? '编辑换货单' : '新增换货单'" width="1180px" @close="onClose">
		<el-form :model="form" label-width="100px">
			<el-row :gutter="16">
				<el-col :span="8"><el-form-item label="客户" required><el-select v-model="form.customer_id" placeholder="选择客户" filterable style="width:100%"><el-option v-for="c in customers" :key="c.id" :label="c.name" :value="c.id" /></el-select></el-form-item></el-col>
				<el-col :span="8"><el-form-item label="车辆" required><el-select v-model="form.vehicle_id" placeholder="选择车辆" filterable @change="onVehicleChange" style="width:100%"><el-option v-for="v in vehicles" :key="v.id" :label="v.plate_no + ' / ' + v.driver_name" :value="v.id" /></el-select></el-form-item></el-col>
				<el-col :span="8"><el-form-item label="换货日期"><el-date-picker v-model="form.exchange_date" type="date" value-format="YYYY-MM-DD" style="width:100%" /></el-form-item></el-col>
			</el-row>
			<el-row :gutter="16">
				<el-col :span="8"><el-form-item label="结算方式"><el-select v-model="form.settle_method" style="width:100%"><el-option label="现金" value="cash" /><el-option label="冲抵应收" value="offset" /><el-option label="挂账" value="credit" /></el-select></el-form-item></el-col>
			</el-row>
		</el-form>
		<div style="margin: 8px 0 12px"><el-button type="primary" size="small" @click="addEmptyRow" :disabled="!form.vehicle_warehouse_id">+ 从车上库存添加商品</el-button></div>
		<el-table :data="form.items" border size="small" max-height="320">
			<el-table-column type="index" label="#" width="40" align="center" />
			<el-table-column label="换出商品" width="150">
				<template #default="{ row }">
					<el-select v-model="row.product_id_out" placeholder="选择" filterable size="small" style="width:100%" @change="onOutChange(row)">
						<el-option v-for="p in outOptions" :key="p.id" :label="p.name + ' / ' + (p.spec || '')" :value="p.id" />
					</el-select>
				</template>
			</el-table-column>
			<el-table-column label="数量" width="90" align="center">
				<template #default="{ row }"><el-input-number v-model="row.qty" :min="0" size="small" controls-position="right" style="width:80px" @input="calcRow(row)" /></template>
			</el-table-column>
			<el-table-column label="换出单价" width="100" align="right">
				<template #default="{ row }"><el-input-number v-model="row.unit_price_out" :min="0" :precision="2" size="small" controls-position="right" style="width:90px" @input="calcRow(row)" /></template>
			</el-table-column>
			<el-table-column label="换出金额" width="100" align="right">
				<template #default="{ row }"><span class="amt">¥{{ (row.amount_out || 0).toFixed(2) }}</span></template>
			</el-table-column>
			<el-table-column label="换入商品" width="150">
				<template #default="{ row }">
					<el-select v-model="row.product_id_in" placeholder="选择" filterable size="small" style="width:100%" :disabled="!form.vehicle_warehouse_id" @change="onInChange(row)">
						<el-option v-for="p in inOptions" :key="p.product_id" :label="p.product_name + ' / ' + (p.spec || '')" :value="p.product_id" />
					</el-select>
				</template>
			</el-table-column>
			<el-table-column label="换入单价" width="100" align="right">
				<template #default="{ row }"><el-input-number v-model="row.unit_price_in" :min="0" :precision="2" size="small" controls-position="right" style="width:90px" @input="calcRow(row)" /></template>
			</el-table-column>
			<el-table-column label="换入金额" width="100" align="right">
				<template #default="{ row }"><span class="amt">¥{{ (row.amount_in || 0).toFixed(2) }}</span></template>
			</el-table-column>
			<el-table-column label="行差价" width="100" align="right">
				<template #default="{ row }"><span class="amt" :style="{ color: row.diff > 0 ? '#f5222d' : '#52c41a' }">{{ row.diff > 0 ? '+' : '' }}¥{{ (row.diff || 0).toFixed(2) }}</span></template>
			</el-table-column>
			<el-table-column label="" width="50" align="center">
				<template #default="{ $index }"><el-button link type="danger" @click="form.items.splice($index, 1)">删</el-button></template>
			</el-table-column>
		</el-table>
		<div class="summary">总差价：<b class="amt">¥{{ totalDiff }}</b></div>
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
const customers = ref([]); const vehicles = ref([]);
const inOptions = ref([]); const outOptions = ref([]);
const form = ref({ id: null, customer_id: null, vehicle_id: null, vehicle_warehouse_id: null, exchange_date: new Date().toISOString().slice(0, 10), settle_method: 'cash', items: [] });
const totalDiff = computed(() => form.value.items.reduce((s, i) => s + (Number(i.diff) || 0), 0).toFixed(2));
const calcRow = (row) => {
	row.amount_out = Math.round((Number(row.qty) || 0) * (Number(row.unit_price_out) || 0) * 100) / 100;
	row.amount_in = Math.round((Number(row.qty) || 0) * (Number(row.unit_price_in) || 0) * 100) / 100;
	row.diff = Math.round((((Number(row.amount_in) || 0) - (Number(row.amount_out) || 0))) * 100) / 100;
};
const loadBasics = async () => {
	const [cRes, vRes] = await Promise.all([api.customer.list.get({ page_size: 200 }), api.vehicle.list.get({ page_size: 200 })]);
	customers.value = cRes.data?.list || []; vehicles.value = vRes.data?.list || [];
	const pRes = await api.product.list.get({ page_size: 200 });
	outOptions.value = pRes.data?.list || [];
};
const onVehicleChange = async () => {
	form.value.items = []; form.value.vehicle_warehouse_id = null; inOptions.value = [];
	if (!form.value.vehicle_id) return;
	const wRes = await api.warehouse.list.get({ type: 'vehicle', page_size: 200 });
	const vw = (wRes.data?.list || []).find(w => w.vehicle_id === form.value.vehicle_id);
	form.value.vehicle_warehouse_id = vw?.id || null;
	if (form.value.vehicle_warehouse_id) {
		const res = await api.vanExchangeOrder.vehicleProducts.get({ vehicle_warehouse_id: form.value.vehicle_warehouse_id });
		inOptions.value = res.data?.list || [];
	}
};
const addEmptyRow = () => {
	form.value.items.push({ product_id_out: null, product_id_in: null, product_name_out: '', product_name_in: '', spec_out: '', spec_in: '', qty: 1, unit_price_out: 0, unit_price_in: 0, amount_out: 0, amount_in: 0, diff: 0 });
};
const onOutChange = (row, pid) => {
	const p = outOptions.value.find(o => o.id === pid);
	if (p) { row.product_name_out = p.name; row.spec_out = p.spec || ''; row.unit_price_out = Number(p.price_small) || 0; }
	calcRow(row);
};
const onInChange = (row, pid) => {
	const p = inOptions.value.find(o => o.product_id === pid);
	if (p) { row.product_name_in = p.product_name; row.spec_in = p.spec || ''; row.unit_price_in = Number(p.price_small) || 0; }
	calcRow(row);
};
const open = async (row) => {
	visible.value = true; await loadBasics();
	if (row && row.id) {
		const res = await api.vanExchangeOrder.detail.get(row.id); const d = res.data;
		form.value = { id: d.id, customer_id: d.customer_id, vehicle_id: d.vehicle_id, vehicle_warehouse_id: d.vehicle_warehouse_id, exchange_date: d.exchange_date, settle_method: d.settle_method, items: (d.items || []).map(i => ({ product_id_out: i.product_id_out, product_id_in: i.product_id_in, product_name_out: i.product_name_out || '', product_name_in: i.product_name_in || '', spec_out: i.spec_out || '', spec_in: i.spec_in || '', qty: i.qty, unit_price_out: i.unit_price_out, unit_price_in: i.unit_price_in, amount_out: Number(i.amount_out) || 0, amount_in: Number(i.amount_in) || 0, diff: Number(i.diff) || 0 })) };
		if (form.value.vehicle_warehouse_id) {
			const res2 = await api.vanExchangeOrder.vehicleProducts.get({ vehicle_warehouse_id: form.value.vehicle_warehouse_id });
			inOptions.value = res2.data?.list || [];
		}
	} else {
		form.value = { id: null, customer_id: null, vehicle_id: null, vehicle_warehouse_id: null, exchange_date: new Date().toISOString().slice(0, 10), settle_method: 'cash', items: [] };
	}
};
const onSave = async () => {
	if (!form.value.customer_id) return ElMessage.warning('请选择客户');
	if (!form.value.vehicle_warehouse_id) return ElMessage.warning('请选择车辆');
	if (!form.value.items.length) return ElMessage.warning('请添加商品');
	const validItems = form.value.items.filter(i => i.product_id_out && i.product_id_in && (Number(i.qty) || 0) > 0);
	if (!validItems.length) return ElMessage.warning('请录入有效换货明细（换入/换出商品均需选择）');
	const payload = { customer_id: form.value.customer_id, vehicle_id: form.value.vehicle_id, vehicle_warehouse_id: form.value.vehicle_warehouse_id, exchange_date: form.value.exchange_date, settle_method: form.value.settle_method, items: validItems.map(i => ({ product_id_out: i.product_id_out, product_id_in: i.product_id_in, qty: i.qty, unit_price_out: i.unit_price_out, unit_price_in: i.unit_price_in })) };
	saving.value = true;
	try { if (form.value.id) { await api.vanExchangeOrder.update.put(form.value.id, payload); } else { await api.vanExchangeOrder.create.post(payload); } ElMessage.success('草稿已保存'); visible.value = false; emit('saved'); }
	catch (e) { ElMessage.error(e.response?.data?.message || '保存失败'); } finally { saving.value = false; }
};
const onClose = () => { form.value = { id: null, customer_id: null, vehicle_id: null, vehicle_warehouse_id: null, exchange_date: new Date().toISOString().slice(0, 10), settle_method: 'cash', items: [] }; };
defineExpose({ open });
</script>
<style scoped>.amt { color: #f5222d; font-weight: 700; } .summary { margin-top: 12px; text-align: right; font-size: 14px; }</style>
