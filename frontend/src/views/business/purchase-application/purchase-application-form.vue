<template>
	<el-dialog v-model="visible" :title="form.id ? '编辑采购申请' : '新增采购申请'" width="98%" top="2vh" destroy-on-close class="pa-dialog">
		<el-form :model="form" label-width="80px" size="small">
			<el-row :gutter="16">
				<el-col :span="6">
					<el-form-item label="供应商" required>
						<el-select v-model="form.supplier_id" placeholder="选择供应商" filterable clearable style="width:100%">
							<el-option v-for="s in suppliers" :key="s.id" :label="s.name" :value="s.id" />
						</el-select>
					</el-form-item>
				</el-col>
				<el-col :span="6">
					<el-form-item label="仓库">
						<el-select v-model="form.warehouse_id" placeholder="转入库仓库" filterable clearable style="width:100%">
							<el-option v-for="w in warehouses" :key="w.id" :label="w.name" :value="w.id" />
						</el-select>
					</el-form-item>
				</el-col>
				<el-col :span="4">
					<el-form-item label="付款类型">
						<el-select v-model="form.payment_type" style="width:100%">
							<el-option label="现金" value="cash" />
							<el-option label="转账" value="transfer" />
							<el-option label="月结" value="monthly" />
							<el-option label="其他" value="other" />
						</el-select>
					</el-form-item>
				</el-col>
				<el-col :span="4">
					<el-form-item label="审批人" required>
						<el-select v-model="form.approver_id" placeholder="请选择审批人" filterable clearable style="width:100%">
							<el-option v-for="e in employees" :key="e.id" :label="e.name" :value="e.id" />
						</el-select>
					</el-form-item>
				</el-col>
				<el-col :span="4">
					<el-form-item label="需用日期">
						<el-date-picker v-model="form.expected_date" type="date" value-format="YYYY-MM-DD" style="width:100%" />
					</el-form-item>
				</el-col>
			</el-row>
			<el-row :gutter="16">
				<el-col :span="20">
					<el-form-item label="备注">
						<el-input v-model="form.remark" placeholder="请输入备注" clearable />
					</el-form-item>
				</el-col>
				<el-col :span="4">
					<el-form-item label="附件">
						<sUpload :max-count="5" list-type="text" @success="onUploadSuccess" />
					</el-form-item>
				</el-col>
			</el-row>
		</el-form>

		<!-- 三栏：主分类 / 子分类 / 商品表格 -->
		<div class="cat-picker">
			<div class="cat-col">
				<div class="cat-col-title">主分类</div>
				<div class="cat-col-body">
					<div :class="['cat-item', { active: !picker.mainId }]" @click="selectPickerAllMain">
						<span class="cat-name">全部</span>
						<span class="cat-count">{{ totalProductCount }}</span>
					</div>
					<div v-for="m in categories" :key="m.id" :class="['cat-item', { active: picker.mainId === m.id }]" @click="selectPickerMain(m)">
						<span class="cat-name">{{ m.name }}</span>
						<span class="cat-count">{{ m.product_count || 0 }}</span>
					</div>
				</div>
			</div>
			<div class="cat-col">
				<div class="cat-col-title">
					子分类
					<span v-if="picker.mainName" class="cat-col-sub">{{ picker.mainName }}</span>
				</div>
				<div class="cat-col-body">
					<template v-if="picker.mainId">
						<div :class="['cat-item', { active: !picker.subId }]" @click="selectPickerAllSub">
							<span class="cat-name">全部</span>
							<span class="cat-count">{{ currentMainProductCount }}</span>
						</div>
						<div v-for="s in pickerSubs" :key="s.id" :class="['cat-item', { active: picker.subId === s.id }]" @click="selectPickerSub(s)">
							<span class="cat-name">{{ s.name }}</span>
							<span class="cat-count">{{ s.product_count || 0 }}</span>
						</div>
					</template>
					<el-empty v-else :image-size="28" description="先选主分类" />
				</div>
			</div>
			<div class="prod-col">
				<!-- 商品搜索行 -->
				<div class="prod-search-row">
					<el-input v-model="prodKeyword" placeholder="搜索商品名/编码/规格" clearable @keyup.enter="searchProducts" style="flex:1">
						<template #append>
							<el-button @click="searchProducts">搜索</el-button>
						</template>
					</el-input>
				</div>
				<!-- 商品候选列表 -->
				<div class="prod-list">
					<div v-for="p in productOptions" :key="p.id" class="prod-item" @click="addProduct(p)">
						<span class="prod-name">{{ p.name }}</span>
						<span class="prod-spec">{{ p.spec_display || p.spec || '-' }}</span>
						<span class="prod-stock">库存:{{ p.stock_qty ?? 0 }}</span>
					</div>
					<el-empty v-if="!productOptions.length" :image-size="40" description="无商品" />
				</div>
			</div>
		</div>

		<!-- 明细表格 -->
		<el-table :data="form.items" border size="small" style="margin-top:12px" max-height="300">
			<el-table-column type="index" label="#" width="40" align="center" />
			<el-table-column prop="product_name" label="商品" min-width="160">
				<template #default="{ row }">
					<span v-if="row.product_id">{{ row.product_name }}</span>
					<span v-else style="color:#999">未选择</span>
				</template>
			</el-table-column>
			<el-table-column prop="spec" label="规格" width="100" />
			<el-table-column v-if="hasLargeUnit" label="大" width="120">
				<template #header>大<span class="u-h">{{ firstLargeUnit }}</span></template>
				<template #default="{ row }">
					<el-input v-model="row.qty_large" size="small" style="width:50px" @input="calcAmount(row)" />
					<span class="u-sep">×</span>
					<el-input v-model="row.price_large" size="small" style="width:55px" @input="onLargePriceChange(row)" />
				</template>
			</el-table-column>
			<el-table-column v-if="hasMediumUnit" label="中" width="120">
				<template #header>中<span class="u-h">{{ firstMediumUnit }}</span></template>
				<template #default="{ row }">
					<el-input v-model="row.qty_medium" size="small" style="width:50px" @input="calcAmount(row)" />
					<span class="u-sep">×</span>
					<el-input v-model="row.price_medium" size="small" style="width:55px" @input="onMediumPriceChange(row)" />
				</template>
			</el-table-column>
			<el-table-column label="小" width="120">
				<template #header>小<span class="u-h">{{ firstSmallUnit }}</span></template>
				<template #default="{ row }">
					<el-input v-model="row.qty_small" size="small" style="width:50px" @input="calcAmount(row)" />
					<span class="u-sep">×</span>
					<el-input v-model="row.price_small" size="small" style="width:55px" @input="onSmallPriceChange(row)" />
				</template>
			</el-table-column>
			<el-table-column label="金额" width="100" align="right">
				<template #default="{ row }"><span class="amt">¥{{ row.amount.toFixed(2) }}</span></template>
			</el-table-column>
			<el-table-column label="备注" width="120">
				<template #default="{ row }">
					<el-input v-model="row.remark" size="small" />
				</template>
			</el-table-column>
			<el-table-column label="" width="50" align="center">
				<template #default="{ $index }">
					<el-button link type="danger" @click="form.items.splice($index, 1)">删</el-button>
				</template>
			</el-table-column>
		</el-table>

		<div class="summary-bar">
			<span>合计：大 <b>{{ totalLg }}</b> ｜ 中 <b>{{ totalMd }}</b> ｜ 小 <b>{{ totalSm }}</b> ｜ 总金额 <b class="amt">¥{{ totalAmount }}</b></span>
		</div>

		<template #footer>
			<el-button @click="visible = false">取消</el-button>
			<el-button type="primary" @click="onSave(false)" :loading="saving">保存草稿</el-button>
			<el-button type="warning" @click="onSave(true)" :loading="saving">提交审批</el-button>
		</template>
	</el-dialog>
</template>

<script setup>
import { ref, reactive, computed } from 'vue';
import { ElMessage } from 'element-plus';
import api from '@/api/business.js';
import sUpload from '@/components/sUpload/index.vue';

const emit = defineEmits(['saved']);
const visible = ref(false);
const saving = ref(false);

const suppliers = ref([]);
const warehouses = ref([]);
const employees = ref([]);
const categories = ref([]);

const form = ref({
	supplier_id: null, warehouse_id: null, payment_type: 'cash',
	approver_id: null, expected_date: tomorrowStr(), remark: '', attachment: [],
	items: [],
});

function tomorrowStr() {
	const d = new Date(); d.setDate(d.getDate() + 1);
	return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

const blankRow = () => ({
	product_id: null, product_name: '', spec: '',
	qty_large: 0, qty_medium: 0, qty_small: 0,
	price_large: 0, price_medium: 0, price_small: 0,
	unit_large: '', unit_medium: '', unit_small: '',
	unit_conversion: 0, unit_conversion_medium: 0,
	amount: 0, remark: '',
});

// 分类面板
const picker = reactive({ mainId: null, mainName: '', subId: null, subName: '' });
const pickerSubs = computed(() => categories.value.find(m => m.id === picker.mainId)?.children || []);
const totalProductCount = computed(() => categories.value.reduce((s, m) => s + (m.product_count || 0), 0));
const currentMainProductCount = computed(() => categories.value.find(m => m.id === picker.mainId)?.product_count || 0);

const selectPickerMain = (m) => { picker.mainId = m.id; picker.mainName = m.name; picker.subId = null; picker.subName = ''; prodKeyword.value = ''; searchProducts(); };
const selectPickerSub = (s) => { picker.subId = s.id; picker.subName = s.name; prodKeyword.value = ''; searchProducts(); };
const selectPickerAllMain = () => { picker.mainId = null; picker.mainName = ''; picker.subId = null; picker.subName = ''; prodKeyword.value = ''; searchProducts(); };
const selectPickerAllSub = () => { picker.subId = null; picker.subName = ''; prodKeyword.value = ''; searchProducts(); };

// 商品搜索
const prodKeyword = ref('');
const productOptions = ref([]);
const searchProducts = async () => {
	const res = await api.product.list.get({
		keyword: prodKeyword.value || '', per_page: 30, is_active: 1,
		main_category_id: picker.mainId || undefined, sub_category_id: picker.subId || undefined,
	});
	productOptions.value = res.code === 200 ? (res.data?.list || []) : [];
};

// 添加商品到明细
const addProduct = (p) => {
	if (!p) return;
	// 已存在则跳过
	if (form.value.items.some(i => i.product_id === p.id)) {
		ElMessage.warning('该商品已在明细中');
		return;
	}
	const row = blankRow();
	applyProduct(row, p);
	form.value.items.push(row);
};
const applyProduct = (item, p) => {
	item.product_id = p.id;
	item.product_name = p.name;
	item.spec = p.spec_display || p.spec || '-';
	item.unit_large = p.price_unit || '';
	item.unit_medium = p.barcode_medium_unit || '';
	item.unit_small = p.price_unit_small || '';
	item.unit_conversion = Number(p.unit_conversion) || 0;
	item.unit_conversion_medium = Number(p.unit_conversion_medium) || 0;
	// 默认带成本价
	const csm = Number(p.cost_price_small ?? p.price_small ?? 0) || 0;
	const clg = Number(p.cost_price_large ?? p.price_large ?? 0) || 0;
	item.price_small = csm;
	item.price_large = clg;
	item.price_medium = Number(p.cost_price_large ?? p.price_medium ?? 0) || 0;
	const mc = item.unit_conversion_medium;
	const c = item.unit_conversion;
	if (!item.price_medium && csm > 0 && mc > 0) item.price_medium = Math.round(csm * mc * 100) / 100;
	if (!item.price_large && csm > 0 && c > 0) item.price_large = Math.round(csm * c * 100) / 100;
	calcAmount(item);
};

// 数量/单价换算（复用 stock-in-dialog 逻辑）
const calcAmount = (item) => {
	item.amount = Math.round(
		((Number(item.qty_large) || 0) * (Number(item.price_large) || 0)
			+ (Number(item.qty_medium) || 0) * (Number(item.price_medium) || 0)
			+ (Number(item.qty_small) || 0) * (Number(item.price_small) || 0)) * 100
	) / 100;
};
const onSmallPriceChange = (item) => {
	const sm = Number(item.price_small) || 0;
	const mc = Number(item.unit_conversion_medium) || 0;
	const c = Number(item.unit_conversion) || 0;
	if (c > 0 && mc > 0) { item.price_medium = Math.round(sm * mc * 100) / 100; item.price_large = Math.round(sm * c * 100) / 100; }
	else if (mc > 0) { item.price_medium = Math.round(sm * mc * 100) / 100; item.price_large = 0; }
	else if (c > 0) { item.price_large = Math.round(sm * c * 100) / 100; item.price_medium = 0; }
	else { item.price_medium = 0; item.price_large = 0; }
	calcAmount(item);
};
const onMediumPriceChange = (item) => {
	const md = Number(item.price_medium) || 0;
	const mc = Number(item.unit_conversion_medium) || 0;
	const c = Number(item.unit_conversion) || 0;
	if (c > 0 && mc > 0) { item.price_small = Math.round(md / mc * 100) / 100; item.price_large = Math.round((md / mc) * c * 100) / 100; }
	else if (mc > 0) { item.price_small = Math.round(md / mc * 100) / 100; item.price_large = 0; }
	else { item.price_small = md; item.price_large = 0; }
	calcAmount(item);
};
const onLargePriceChange = (item) => {
	const lg = Number(item.price_large) || 0;
	const mc = Number(item.unit_conversion_medium) || 0;
	const c = Number(item.unit_conversion) || 0;
	if (c > 0 && mc > 0) { item.price_small = Math.round(lg / c * 100) / 100; item.price_medium = Math.round((lg / c) * mc * 100) / 100; }
	else if (c > 0) { item.price_small = Math.round(lg / c * 100) / 100; item.price_medium = 0; }
	else { item.price_small = lg; item.price_medium = 0; }
	calcAmount(item);
};

// 汇总
const totalLg = computed(() => form.value.items.reduce((s, i) => s + (Number(i.qty_large) || 0), 0));
const totalMd = computed(() => form.value.items.reduce((s, i) => s + (Number(i.qty_medium) || 0), 0));
const totalSm = computed(() => form.value.items.reduce((s, i) => s + (Number(i.qty_small) || 0), 0));
const totalAmount = computed(() => form.value.items.reduce((s, i) => s + (Number(i.amount) || 0), 0).toFixed(2));

// 单位列显隐：取第一个商品的单位名作为表头提示
const hasLargeUnit = computed(() => form.value.items.some(i => i.unit_conversion > 0));
const hasMediumUnit = computed(() => form.value.items.some(i => i.unit_conversion_medium > 0));
const firstLargeUnit = computed(() => form.value.items.find(i => i.unit_large)?.unit_large || '');
const firstMediumUnit = computed(() => form.value.items.find(i => i.unit_medium)?.unit_medium || '');
const firstSmallUnit = computed(() => form.value.items.find(i => i.unit_small)?.unit_small || '');

const onUploadSuccess = (file) => {
	if (!form.value.attachment) form.value.attachment = [];
	form.value.attachment.push(file);
};

// 加载基础数据
const loadBasics = async () => {
	const [sRes, wRes, eRes, cRes] = await Promise.all([
		api.supplier.list.get({ page_size: 200 }),
		api.warehouse.list.get({ page_size: 200 }),
		api.employee.list.get({ page_size: 200 }),
		api.product.categories.get(),
	]);
	suppliers.value = sRes.data?.list || [];
	warehouses.value = wRes.data?.list || [];
	employees.value = eRes.data?.list || [];
	categories.value = cRes.code === 200 ? (cRes.data || cRes.data?.data || []) : [];
};

// 打开（供父组件 ref 调用）
const open = async (row) => {
	visible.value = true;
	await loadBasics();
	if (row && row.id) {
		// 编辑：拉详情回填
		const res = await api.purchaseApplication.detail.get(row.id);
		const d = res.data;
		form.value = {
			id: d.id,
			supplier_id: d.supplier_id, warehouse_id: d.warehouse_id,
			payment_type: d.payment_type || 'cash',
			approver_id: d.approver_id, expected_date: d.expected_date,
			remark: d.remark || '', attachment: d.attachment || [],
			items: (d.items || []).map(i => ({
				product_id: i.product_id, product_name: i.product_name, spec: i.spec,
				qty_large: i.qty_large, qty_medium: i.qty_medium, qty_small: i.qty_small,
				price_large: i.price_large, price_medium: i.price_medium, price_small: i.price_small,
				unit_large: i.unit_large, unit_medium: i.unit_medium, unit_small: i.unit_small,
				unit_conversion: i.unit_conversion, unit_conversion_medium: i.unit_conversion_medium,
				amount: Number(i.amount) || 0, remark: i.remark || '',
			})),
		};
	} else {
		form.value = {
			supplier_id: null, warehouse_id: null, payment_type: 'cash',
			approver_id: null, expected_date: tomorrowStr(), remark: '', attachment: [],
			items: [],
		};
	}
	selectPickerAllMain();
	searchProducts();
};

const onSave = async (submit) => {
	if (!form.value.supplier_id) return ElMessage.warning('请选择供应商');
	if (!form.value.approver_id) return ElMessage.warning('请选择审批人');
	if (!form.value.items.length) return ElMessage.warning('请添加商品');
	const validItems = form.value.items.filter(i => i.product_id && ((Number(i.qty_large) || 0) + (Number(i.qty_medium) || 0) + (Number(i.qty_small) || 0) > 0));
	if (!validItems.length) return ElMessage.warning('请录入有效的商品数量');

	const payload = {
		supplier_id: form.value.supplier_id,
		warehouse_id: form.value.warehouse_id,
		payment_type: form.value.payment_type,
		approver_id: form.value.approver_id,
		expected_date: form.value.expected_date,
		remark: form.value.remark,
		attachment: form.value.attachment,
		submit_for_approval: submit,
		items: validItems.map(i => ({
			product_id: i.product_id,
			qty_large: i.qty_large, qty_medium: i.qty_medium, qty_small: i.qty_small,
			price_large: i.price_large, price_medium: i.price_medium, price_small: i.price_small,
			remark: i.remark,
		})),
	};

	saving.value = true;
	try {
		if (form.value.id) {
			await api.purchaseApplication.update.put(form.value.id, payload);
		} else {
			await api.purchaseApplication.create.post(payload);
		}
		ElMessage.success(submit ? '已提交审批' : '草稿已保存');
		visible.value = false;
		emit('saved');
	} catch (e) {
		ElMessage.error(e.response?.data?.message || '保存失败');
	} finally { saving.value = false; }
};

defineExpose({ open });
</script>

<style scoped>
.pa-dialog :deep(.el-dialog__body) { padding: 12px 20px; }
.cat-picker { display: flex; gap: 8px; height: 320px; }
.cat-col { width: 180px; background: #fff; border: 1px solid #ebeef5; border-radius: 4px; display: flex; flex-direction: column; }
.cat-col-title { height: 32px; line-height: 32px; padding: 0 10px; font-size: 13px; font-weight: 700; border-bottom: 1px solid #ebeef5; background: #f5f7fa; }
.cat-col-sub { font-size: 12px; color: #999; margin-left: 6px; font-weight: 400; }
.cat-col-body { flex: 1; overflow-y: auto; }
.cat-item { height: 36px; line-height: 36px; padding: 0 10px; font-size: 13px; cursor: pointer; display: flex; justify-content: space-between; }
.cat-item:hover { background: #f5f7fa; }
.cat-item.active { background: #ecf5ff; color: #409eff; }
.cat-count { color: #999; font-size: 12px; }
.prod-col { flex: 1; display: flex; flex-direction: column; border: 1px solid #ebeef5; border-radius: 4px; }
.prod-search-row { padding: 8px; border-bottom: 1px solid #ebeef5; display: flex; gap: 8px; }
.prod-list { flex: 1; overflow-y: auto; }
.prod-item { padding: 8px 12px; font-size: 13px; cursor: pointer; display: flex; gap: 12px; border-bottom: 1px solid #f5f5f5; }
.prod-item:hover { background: #f5f7fa; }
.prod-name { flex: 1; }
.prod-spec { color: #999; }
.prod-stock { color: #67c23a; font-size: 12px; }
.amt { color: #f5222d; font-weight: 700; }
.u-h { font-size: 12px; color: #999; font-weight: 400; margin-left: 4px; }
.u-sep { color: #ccc; margin: 0 2px; }
.summary-bar { margin-top: 12px; padding: 8px 16px; background: #f5f7fa; border-radius: 4px; font-size: 14px; }
.summary-bar b { color: #409eff; }
</style>
