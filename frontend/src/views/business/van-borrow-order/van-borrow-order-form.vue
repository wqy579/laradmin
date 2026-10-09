<template>
	<el-dialog v-model="visible" :title="form.id ? '编辑借货单' : '新增借货单'" width="950px" @close="onClose">
		<el-form :model="form" label-width="100px">
			<el-row :gutter="16">
				<el-col :span="8"><el-form-item label="客户" required><el-select v-model="form.customer_id" placeholder="选择客户" filterable style="width:100%"><el-option v-for="c in customers" :key="c.id" :label="c.name" :value="c.id" /></el-select></el-form-item></el-col>
				<el-col :span="8"><el-form-item label="车辆" required><el-select v-model="form.vehicle_id" placeholder="选择车辆" filterable @change="onVehicleChange" style="width:100%"><el-option v-for="v in vehicles" :key="v.id" :label="v.plate_no + ' / ' + v.driver_name" :value="v.id" /></el-select></el-form-item></el-col>
				<el-col :span="8"><el-form-item label="借货日期"><el-date-picker v-model="form.borrow_date" type="date" value-format="YYYY-MM-DD" style="width:100%" /></el-form-item></el-col>
			</el-row>
			<el-row :gutter="16">
				<el-col :span="8"><el-form-item label="应还日期"><el-date-picker v-model="form.due_date" type="date" value-format="YYYY-MM-DD" style="width:100%" /></el-form-item></el-col>
			</el-row>
		</el-form>
		<div style="margin: 8px 0 12px"><el-button type="primary" size="small" @click="addDialog = true" :disabled="!form.vehicle_warehouse_id">+ 从车上库存添加商品</el-button></div>
		<el-table :data="form.items" border size="small" max-height="280">
			<el-table-column type="index" label="#" width="40" align="center" />
			<el-table-column prop="product_name" label="商品" min-width="160" />
			<el-table-column prop="spec" label="规格" width="100" />
			<el-table-column prop="stock_qty" label="车上库存" width="80" align="center" />
			<el-table-column label="借货数量" width="120" align="center"><template #default="{ row }"><el-input-number v-model="row.borrow_qty" :min="0" :max="row.stock_qty" size="small" controls-position="right" style="width:100px" @input="calcItem(row)" /></template></el-table-column>
			<el-table-column label="单价" width="100" align="right"><template #default="{ row }"><el-input-number v-model="row.unit_price" :min="0" :precision="2" size="small" controls-position="right" style="width:90px" @input="calcItem(row)" /></template></el-table-column>
			<el-table-column label="金额" width="100" align="right"><template #default="{ row }"><span class="amt">¥{{ (row.amount || 0).toFixed(2) }}</span></template></el-table-column>
			<el-table-column label="" width="50" align="center"><template #default="{ $index }"><el-button link type="danger" @click="form.items.splice($index, 1)">删</el-button></template></el-table-column>
		</el-table>
		<div class="summary">合计：数量 <b>{{ totalQty }}</b> ｜ 金额 <b class="amt">¥{{ totalAmount }}</b></div>
		<template #footer>
			<el-button @click="visible = false">取消</el-button>
			<el-button type="primary" @click="onSave()" :loading="saving">保存草稿</el-button>
		</template>
		<el-dialog v-model="addDialog" title="从车上库存添加商品" width="700px" append-to-body>
			<el-input v-model="prodKeyword" placeholder="搜索商品" clearable @keyup.enter="searchProducts" style="margin-bottom:12px;width:300px"><template #append><el-button @click="searchProducts">搜索</el-button></template></el-input>
			<el-table :data="productOptions" border size="small" max-height="400">
				<el-table-column prop="product_name" label="商品" min-width="160" /><el-table-column prop="spec" label="规格" width="100" /><el-table-column prop="stock_qty" label="车上库存" width="80" align="center" />
				<el-table-column label="操作" width="80" align="center"><template #default="{ row }"><el-button link type="primary" @click="addProduct(row)">添加</el-button></template></el-table-column>
			</el-table>
		</el-dialog>
	</el-dialog>
</template>

<script setup>
import { ref, computed } from 'vue';
import { ElMessage } from 'element-plus';
import api from '@/api/business.js';
const emit = defineEmits(['saved']);
const visible = ref(false); const saving = ref(false);
const customers = ref([]); const vehicles = ref([]); const productOptions = ref([]);
const prodKeyword = ref(''); const addDialog = ref(false);
const form = ref({ id: null, customer_id: null, vehicle_id: null, vehicle_warehouse_id: null, borrow_date: new Date().toISOString().slice(0, 10), due_date: '', items: [] });
const totalQty = computed(() => form.value.items.reduce((s, i) => s + (Number(i.borrow_qty) || 0), 0));
const totalAmount = computed(() => form.value.items.reduce((s, i) => s + (Number(i.amount) || 0), 0).toFixed(2));
const calcItem = (item) => { item.amount = Math.round((Number(item.borrow_qty) || 0) * (Number(item.unit_price) || 0) * 100) / 100; };
const loadBasics = async () => {
	const [cRes, vRes] = await Promise.all([api.customer.list.get({ page_size: 200 }), api.vehicle.list.get({ page_size: 200 })]);
	customers.value = cRes.data?.list || []; vehicles.value = vRes.data?.list || [];
};
const onVehicleChange = async () => {
	form.value.items = []; form.value.vehicle_warehouse_id = null;
	if (!form.value.vehicle_id) return;
	const wRes = await api.warehouse.list.get({ type: 'vehicle', page_size: 200 });
	const vw = (wRes.data?.list || []).find(w => w.vehicle_id === form.value.vehicle_id);
	form.value.vehicle_warehouse_id = vw?.id || null;
};
const searchProducts = async () => {
	if (!form.value.vehicle_warehouse_id) return ElMessage.warning('请先选择车辆');
	const res = await api.vanBorrowOrder.vehicleProducts.get({ vehicle_warehouse_id: form.value.vehicle_warehouse_id });
	productOptions.value = res.data?.list || [];
};
const addProduct = (p) => {
	if (form.value.items.some(i => i.product_id === p.product_id)) return ElMessage.warning('该商品已在明细中');
	form.value.items.push({ product_id: p.product_id, product_name: p.product_name, spec: p.spec, unit: p.unit, stock_qty: p.stock_qty, borrow_qty: 1, unit_price: p.price_small, amount: p.price_small });
	addDialog.value = false;
};
const open = async (row) => { visible.value = true; await loadBasics(); if (row && row.id) { const res = await api.vanBorrowOrder.detail.get(row.id); const d = res.data; form.value = { id: d.id, customer_id: d.customer_id, vehicle_id: d.vehicle_id, vehicle_warehouse_id: d.vehicle_warehouse_id, borrow_date: d.borrow_date, due_date: d.due_date, items: (d.items || []).map(i => ({ product_id: i.product_id, product_name: i.product_name, spec: i.spec, stock_qty: i.stock_qty, borrow_qty: i.borrow_qty, unit_price: i.unit_price, amount: Number(i.amount) || 0 })) }; } else { form.value = { id: null, customer_id: null, vehicle_id: null, vehicle_warehouse_id: null, borrow_date: new Date().toISOString().slice(0, 10), due_date: '', items: [] }; } };
const onSave = async () => {
	if (!form.value.customer_id) return ElMessage.warning('请选择客户');
	if (!form.value.vehicle_warehouse_id) return ElMessage.warning('请选择车辆');
	if (!form.value.items.length) return ElMessage.warning('请添加商品');
	const validItems = form.value.items.filter(i => i.product_id && (Number(i.borrow_qty) || 0) > 0);
	if (!validItems.length) return ElMessage.warning('请录入有效数量');
	const payload = { customer_id: form.value.customer_id, vehicle_id: form.value.vehicle_id, vehicle_warehouse_id: form.value.vehicle_warehouse_id, borrow_date: form.value.borrow_date, due_date: form.value.due_date, items: validItems.map(i => ({ product_id: i.product_id, borrow_qty: i.borrow_qty, unit_price: i.unit_price })) };
	saving.value = true;
	try { if (form.value.id) { await api.vanBorrowOrder.update.put(form.value.id, payload); } else { await api.vanBorrowOrder.create.post(payload); } ElMessage.success('草稿已保存'); visible.value = false; emit('saved'); }
	catch (e) { ElMessage.error(e.response?.data?.message || '保存失败'); } finally { saving.value = false; }
};
const onClose = () => { form.value = { id: null, customer_id: null, vehicle_id: null, vehicle_warehouse_id: null, borrow_date: new Date().toISOString().slice(0, 10), due_date: '', items: [] }; };
defineExpose({ open });
</script>
<style scoped>.amt { color: #f5222d; font-weight: 700; } .summary { margin-top: 12px; text-align: right; font-size: 14px; }</style>
