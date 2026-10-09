<template>
	<el-dialog v-model="visible" :title="form.id ? '编辑要货申请' : '新增要货申请'" width="900px" @close="onClose">
		<el-form :model="form" label-width="100px">
			<el-row :gutter="16">
				<el-col :span="8">
					<el-form-item label="源仓库" required>
						<el-select v-model="form.warehouse_id" placeholder="选择仓库" filterable @change="onWarehouseChange" style="width:100%">
							<el-option v-for="w in warehouses" :key="w.id" :label="w.name" :value="w.id" />
						</el-select>
					</el-form-item>
				</el-col>
				<el-col :span="8">
					<el-form-item label="车辆" required>
						<el-select v-model="form.vehicle_id" placeholder="选择车辆" filterable style="width:100%">
							<el-option v-for="v in vehicles" :key="v.id" :label="v.plate_no + ' / ' + v.driver_name" :value="v.id" />
						</el-select>
					</el-form-item>
				</el-col>
				<el-col :span="8">
					<el-form-item label="申请日期"><el-date-picker v-model="form.apply_date" type="date" value-format="YYYY-MM-DD" style="width:100%" /></el-form-item>
				</el-col>
			</el-row>
		</el-form>

		<div style="margin: 8px 0 12px">
			<el-button type="primary" size="small" @click="addProductDialog = true" :disabled="!form.warehouse_id">+ 从库存添加商品</el-button>
		</div>
		<el-table :data="form.items" border size="small" max-height="300">
			<el-table-column type="index" label="#" width="40" align="center" />
			<el-table-column prop="product_name" label="商品" min-width="160" />
			<el-table-column prop="spec" label="规格" width="100" />
			<el-table-column label="库存" width="80" align="center">
				<template #default="{ row }">{{ row.stock_qty }}</template>
			</el-table-column>
			<el-table-column label="申请数量" width="120" align="center">
				<template #default="{ row }">
					<el-input-number v-model="row.apply_qty" :min="0" :max="row.stock_qty" size="small" controls-position="right" style="width:100px" @input="calcItem(row)" />
				</template>
			</el-table-column>
			<el-table-column label="成本价" width="100" align="right">
				<template #default="{ row }">
					<el-input-number v-model="row.unit_cost" :min="0" :precision="2" size="small" controls-position="right" style="width:90px" @input="calcItem(row)" />
				</template>
			</el-table-column>
			<el-table-column label="金额" width="100" align="right">
				<template #default="{ row }"><span class="amt">¥{{ (row.amount || 0).toFixed(2) }}</span></template>
			</el-table-column>
			<el-table-column label="" width="50" align="center">
				<template #default="{ $index }"><el-button link type="danger" @click="form.items.splice($index, 1)">删</el-button></template>
			</el-table-column>
		</el-table>
		<div class="summary">合计：数量 <b>{{ totalQty }}</b> ｜ 金额 <b class="amt">¥{{ totalAmount }}</b></div>

		<template #footer>
			<el-button @click="visible = false">取消</el-button>
			<el-button type="primary" @click="onSave(false)" :loading="saving">保存草稿</el-button>
			<el-button type="warning" @click="onSave(true)" :loading="saving">提交审核</el-button>
		</template>

		<!-- 商品选择弹窗 -->
		<el-dialog v-model="addProductDialog" title="从库存添加商品" width="700px" append-to-body>
			<el-input v-model="prodKeyword" placeholder="搜索商品名/编码" clearable @keyup.enter="searchProducts" style="margin-bottom:12px;width:300px">
				<template #append><el-button @click="searchProducts">搜索</el-button></template>
			</el-input>
			<el-table :data="productOptions" border size="small" max-height="400">
				<el-table-column prop="product_name" label="商品" min-width="160" />
				<el-table-column prop="spec" label="规格" width="100" />
				<el-table-column prop="stock_qty" label="库存" width="80" align="center" />
				<el-table-column label="操作" width="80" align="center">
					<template #default="{ row }"><el-button link type="primary" @click="addProduct(row)">添加</el-button></template>
				</el-table-column>
			</el-table>
		</el-dialog>
	</el-dialog>
</template>

<script setup>
import { ref, reactive, computed } from 'vue';
import { ElMessage } from 'element-plus';
import api from '@/api/business.js';

const emit = defineEmits(['saved']);
const visible = ref(false);
const saving = ref(false);
const warehouses = ref([]);
const vehicles = ref([]);
const productOptions = ref([]);
const prodKeyword = ref('');
const addProductDialog = ref(false);
const form = ref({ id: null, warehouse_id: null, vehicle_id: null, apply_date: new Date().toISOString().slice(0, 10), remark: '', items: [] });

const totalQty = computed(() => form.value.items.reduce((s, i) => s + (Number(i.apply_qty) || 0), 0));
const totalAmount = computed(() => form.value.items.reduce((s, i) => s + (Number(i.amount) || 0), 0).toFixed(2));

const calcItem = (item) => { item.amount = Math.round((Number(item.apply_qty) || 0) * (Number(item.unit_cost) || 0) * 100) / 100; };

const loadBasics = async () => {
	const [wRes, vRes] = await Promise.all([
		api.warehouse.list.get({ type: 'normal', page_size: 200 }),
		api.vehicle.list.get({ page_size: 200 }),
	]);
	warehouses.value = wRes.data?.list || [];
	vehicles.value = vRes.data?.list || [];
};
const onWarehouseChange = () => { form.value.items = []; };
const searchProducts = async () => {
	if (!form.value.warehouse_id) return ElMessage.warning('请先选择源仓库');
	const res = await api.vanRequisition.warehouseProducts.get({ warehouse_id: form.value.warehouse_id });
	productOptions.value = res.data?.list || [];
};
const addProduct = (p) => {
	if (form.value.items.some(i => i.product_id === p.product_id)) return ElMessage.warning('该商品已在明细中');
	form.value.items.push({ product_id: p.product_id, product_name: p.product_name, spec: p.spec, unit: p.unit, stock_qty: p.stock_qty, apply_qty: 1, unit_cost: p.cost_price, amount: p.cost_price });
	addProductDialog.value = false;
};

const open = async (row) => {
	visible.value = true;
	await loadBasics();
	if (row && row.id) {
		const res = await api.vanRequisition.detail.get(row.id);
		const d = res.data;
		form.value = { id: d.id, warehouse_id: d.warehouse_id, vehicle_id: d.vehicle_id, apply_date: d.apply_date, remark: d.remark || '', items: (d.items || []).map(i => ({ product_id: i.product_id, product_name: i.product_name, spec: i.spec, unit: i.unit, stock_qty: i.stock_qty, apply_qty: i.apply_qty, unit_cost: i.unit_cost, amount: Number(i.amount) || 0 })) };
	} else {
		form.value = { id: null, warehouse_id: null, vehicle_id: null, apply_date: new Date().toISOString().slice(0, 10), remark: '', items: [] };
	}
};
const onSave = async (submit) => {
	if (!form.value.warehouse_id) return ElMessage.warning('请选择源仓库');
	if (!form.value.vehicle_id) return ElMessage.warning('请选择车辆');
	if (!form.value.items.length) return ElMessage.warning('请添加商品');
	const validItems = form.value.items.filter(i => i.product_id && (Number(i.apply_qty) || 0) > 0);
	if (!validItems.length) return ElMessage.warning('请录入有效数量');
	const payload = { warehouse_id: form.value.warehouse_id, vehicle_id: form.value.vehicle_id, apply_date: form.value.apply_date, remark: form.value.remark, items: validItems.map(i => ({ product_id: i.product_id, apply_qty: i.apply_qty, unit_cost: i.unit_cost })) };
	saving.value = true;
	try {
		if (form.value.id) { await api.vanRequisition.update.put(form.value.id, payload); }
		else { const res = await api.vanRequisition.create.post(payload); if (submit) { form.value.id = res.data?.id; } }
		if (submit && form.value.id) { await api.vanRequisition.submit.post(form.value.id); }
		ElMessage.success(submit ? '已提交审核' : '草稿已保存');
		visible.value = false;
		emit('saved');
	} catch (e) { ElMessage.error(e.response?.data?.message || '保存失败'); }
	finally { saving.value = false; }
};
const onClose = () => { form.value = { id: null, warehouse_id: null, vehicle_id: null, apply_date: new Date().toISOString().slice(0, 10), remark: '', items: [] }; };
defineExpose({ open });
</script>

<style scoped>
.summary { margin-top: 12px; text-align: right; font-size: 14px; }
.amt { color: #f5222d; font-weight: 700; }
</style>
