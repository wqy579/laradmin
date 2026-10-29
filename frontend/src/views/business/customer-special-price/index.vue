<template>
	<div class="page">
		<div class="header">
			<span class="title">客户特价</span>
			<el-radio-group v-model="tab" size="small">
				<el-radio-button value="manage">特价管理</el-radio-button>
				<el-radio-button value="resolve">价格解析</el-radio-button>
			</el-radio-group>
		</div>

		<!-- ① 特价管理 -->
		<el-card shadow="never" class="card" v-show="tab === 'manage'">
			<div class="toolbar">
				<el-select v-model="searchForm.customer_id" placeholder="客户" clearable filterable remote :remote-method="searchCustomers" style="width:220px">
					<el-option v-for="c in customerOptions" :key="c.id" :label="c.name" :value="c.id" />
				</el-select>
				<el-select v-model="searchForm.product_id" placeholder="商品" clearable filterable remote :remote-method="searchProducts" style="width:220px">
					<el-option v-for="p in productOptions" :key="p.id" :label="p.name" :value="p.id" />
				</el-select>
				<el-select v-model="searchForm.price_plan_id" placeholder="价格方案" clearable style="width:180px">
					<el-option v-for="pl in plans" :key="pl.id" :label="pl.name" :value="pl.id" />
				</el-select>
				<el-select v-model="searchForm.status" placeholder="状态" clearable style="width:110px">
					<el-option label="启用" :value="1" />
					<el-option label="停用" :value="0" />
				</el-select>
				<el-button type="primary" @click="search">查询</el-button>
				<el-button @click="resetSearch">重置</el-button>
				<el-button type="success" @click="openForm()">新增特价</el-button>
			</div>

			<el-table :data="list" border stripe v-loading="loading" class="mt12">
				<el-table-column label="客户" min-width="160">
					<template #default="{ row }">{{ row.customer?.name || '-' }}<span class="sub">{{ row.customer?.code }}</span></template>
				</el-table-column>
				<el-table-column label="商品" min-width="180">
					<template #default="{ row }">
						{{ row.product?.name || '-' }}
						<span class="sub">{{ row.product?.code }}<template v-if="row.product?.spec"> / {{ row.product.spec }}</template></span>
					</template>
				</el-table-column>
				<el-table-column label="原价" width="100" align="right">
					<template #default="{ row }">
						<span class="money">{{ fmtMoney(row.product?.price_small) }}</span>
					</template>
				</el-table-column>
				<el-table-column label="特价" width="100" align="right">
					<template #default="{ row }">
						<span class="money sp">{{ fmtMoney(row.price) }}</span>
					</template>
				</el-table-column>
				<el-table-column label="来源方案" width="130" align="center">
					<template #default="{ row }">{{ row.plan?.name || '-' }}</template>
				</el-table-column>
				<el-table-column label="特价期间" width="200" align="center">
					<template #default="{ row }">
						<span>{{ row.start_date || '不限' }} ~ {{ row.end_date || '不限' }}</span>
					</template>
				</el-table-column>
				<el-table-column label="状态" width="90" align="center">
					<template #default="{ row }">
						<el-tag :type="row.status ? 'success' : 'info'" size="small">{{ row.status ? '启用' : '停用' }}</el-tag>
					</template>
				</el-table-column>
				<el-table-column label="操作" width="140" align="center">
					<template #default="{ row }">
						<el-button link type="primary" @click="openForm(row)">编辑</el-button>
						<el-button link type="danger" @click="onDelete(row)">删除</el-button>
					</template>
				</el-table-column>
			</el-table>

			<div class="pager">
				<el-pagination
					background
					layout="total, prev, pager, next"
					:total="total"
					:page-size="pageSize"
					:current-page="page"
					@current-change="onPageChange"
				/>
			</div>
		</el-card>

		<!-- ② 价格解析（复用 E4 ReportTable：price_tag 列 + rule_hit 整行高亮） -->
		<el-card shadow="never" class="card" v-show="tab === 'resolve'">
			<div class="toolbar">
				<el-select v-model="resolveForm.customer_ids" placeholder="客户" clearable multiple filterable remote :remote-method="searchCustomers" style="width:240px">
					<el-option v-for="c in customerOptions" :key="c.id" :label="c.name" :value="c.id" />
				</el-select>
				<el-select v-model="resolveForm.product_ids" placeholder="商品" clearable multiple filterable remote :remote-method="searchProducts" style="width:240px">
					<el-option v-for="p in productOptions" :key="p.id" :label="p.name" :value="p.id" />
				</el-select>
				<el-button type="primary" @click="doResolve">解析</el-button>
			</div>
			<ReportTable
				class="mt12"
				report="customer_special_price_resolve"
				:columns="resolveColumns"
				:data="resolveData"
				:loading="resolving"
				:total="resolveData.length"
				:page-size="50"
				:summary="resolveSummary"
			/>
		</el-card>

		<!-- 新增/编辑特价 -->
		<el-dialog v-model="formVisible" :title="form.id ? '编辑特价' : '新增特价'" width="620px" destroy-on-close>
			<el-form :model="form" label-width="100px">
				<el-form-item label="客户" required>
					<el-select v-model="form.customer_id" filterable remote :remote-method="searchCustomers" style="width:100%">
						<el-option v-for="c in customerOptions" :key="c.id" :label="`${c.name}（${c.code}）`" :value="c.id" />
					</el-select>
				</el-form-item>
				<el-form-item label="商品" required>
					<el-select v-model="form.product_id" filterable remote :remote-method="searchProducts" style="width:100%">
						<el-option v-for="p in productOptions" :key="p.id" :label="`${p.name}（${p.code}）`" :value="p.id" />
					</el-select>
				</el-form-item>
				<el-form-item label="特价" required>
					<el-input-number v-model="form.price" :min="0" :precision="2" :step="0.5" />
					<span class="sub" v-if="selectedProduct">原价 {{ fmtMoney(selectedProduct.price_small) }}</span>
				</el-form-item>
				<el-form-item label="来源方案">
					<el-select v-model="form.price_plan_id" clearable placeholder="关联价格方案" style="width:100%">
						<el-option v-for="pl in plans" :key="pl.id" :label="pl.name" :value="pl.id" />
					</el-select>
				</el-form-item>
				<el-form-item label="特价期间">
					<el-date-picker
						v-model="form.period"
						type="daterange"
						range-separator="至"
						start-placeholder="开始"
						end-placeholder="结束"
						value-format="YYYY-MM-DD"
						style="width:260px"
					/>
				</el-form-item>
				<el-form-item label="备注">
					<el-input v-model="form.remark" maxlength="255" type="textarea" :rows="2" />
				</el-form-item>
			</el-form>
			<template #footer>
				<el-button @click="formVisible = false">取消</el-button>
				<el-button type="primary" :loading="saving" @click="onSave">确定</el-button>
			</template>
		</el-dialog>
	</div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import api from '@/api/business.js';
import ReportTable from '@/views/business/report/components/ReportTable.vue';

const tab = ref('manage');

// ---------------- 特价管理 ----------------
const list = ref([]);
const loading = ref(false);
const total = ref(0);
const page = ref(1);
const pageSize = ref(20);
const searchForm = ref({ customer_id: null, product_id: null, price_plan_id: null, status: null });
const plans = ref([]);

const customerOptions = ref([]);
const productOptions = ref([]);

const searchCustomers = async (kw) => {
	try {
		const res = await api.customer.list.get({ keyword: kw, page: 1, page_size: 20 });
		customerOptions.value = res?.data?.list || res?.data || [];
	} catch { /* 忽略 */ }
};
const searchProducts = async (kw) => {
	try {
		const res = await api.product.list.get({ keyword: kw, page: 1, page_size: 20 });
		productOptions.value = res?.data?.list || res?.data || [];
	} catch { /* 忽略 */ }
};

const load = async () => {
	loading.value = true;
	try {
		const params = { page: page.value, page_size: pageSize.value };
		Object.keys(searchForm.value).forEach((k) => {
			if (searchForm.value[k] !== null && searchForm.value[k] !== '' && searchForm.value[k] !== undefined) params[k] = searchForm.value[k];
		});
		const res = await api.customerSpecialPrice.list.get(params);
		list.value = res.data?.list || [];
		total.value = res.data?.total || 0;
	} finally {
		loading.value = false;
	}
};

const loadPlans = async () => {
	try {
		const res = await api.customerSpecialPrice.plans.get({ page: 1, page_size: 100 });
		plans.value = res.data?.list || [];
	} catch { /* 忽略 */ }
};

const search = () => { page.value = 1; load(); };
const resetSearch = () => { searchForm.value = { customer_id: null, product_id: null, price_plan_id: null, status: null }; search(); };
const onPageChange = (p) => { page.value = p; load(); };

// ---------------- 新增/编辑 ----------------
const formVisible = ref(false);
const saving = ref(false);
const form = ref({});

const selectedProduct = computed(() => productOptions.value.find((p) => p.id === form.value.product_id) || null);

const openForm = (row) => {
	form.value = row
		? { ...row, period: row.start_date && row.end_date ? [row.start_date, row.end_date] : [] }
		: { customer_id: null, product_id: null, price: null, price_plan_id: null, period: [], remark: '', status: true };
	if (row?.customer) { customerOptions.value = [row.customer]; }
	if (row?.product) { productOptions.value = [row.product]; }
	formVisible.value = true;
};

const onSave = async () => {
	if (!form.value.customer_id || !form.value.product_id || form.value.price == null) return ElMessage.warning('请填写客户、商品和特价');
	const payload = {
		price_plan_id: form.value.price_plan_id || null,
		items: [{
			customer_id: form.value.customer_id,
			product_id: form.value.product_id,
			price: form.value.price,
			start_date: form.value.period?.[0] || null,
			end_date: form.value.period?.[1] || null,
			remark: form.value.remark || '',
		}],
	};
	saving.value = true;
	try {
		if (form.value.id) {
			await api.customerSpecialPrice.update.put(form.value.id, {
				price: form.value.price,
				price_plan_id: form.value.price_plan_id || null,
				start_date: form.value.period?.[0] || null,
				end_date: form.value.period?.[1] || null,
				status: form.value.status,
				remark: form.value.remark || '',
			});
		} else {
			await api.customerSpecialPrice.create.post(payload);
		}
		ElMessage.success('已保存');
		formVisible.value = false;
		load();
	} finally {
		saving.value = false;
	}
};

const onDelete = (row) => {
	ElMessageBox.confirm(`确认删除「${row.customer?.name || ''}」对「${row.product?.name || ''}」的特价？`, '提示', { type: 'warning' })
		.then(async () => { await api.customerSpecialPrice.delete.delete(row.id); ElMessage.success('已删除'); load(); })
		.catch(() => {});
};

// ---------------- 价格解析（E4 规则组件复用） ----------------
const resolveForm = ref({ customer_ids: [], product_ids: [] });
const resolveData = ref([]);
const resolveColumns = ref([]);
const resolveSummary = ref(null);
const resolving = ref(false);

const doResolve = async () => {
	resolving.value = true;
	try {
		const params = {};
		if (resolveForm.value.customer_ids?.length) params.customer_ids = resolveForm.value.customer_ids;
		if (resolveForm.value.product_ids?.length) params.product_ids = resolveForm.value.product_ids;
		const res = await api.customerSpecialPrice.resolve.get(params);
		resolveData.value = res.data?.list || [];
		resolveColumns.value = res.data?.columns || [];
		const s = {};
		resolveData.value.forEach((r) => {
			['standard_price', 'level_price', 'special_price', 'effective_price'].forEach((k) => { s[k] = (s[k] || 0) + Number(r[k] || 0); });
		});
		resolveSummary.value = s;
	} finally {
		resolving.value = false;
	}
};

const fmtMoney = (v) => Number(v ?? 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

onMounted(() => { load(); loadPlans(); });
</script>

<style scoped>
.page { background: #f0f2f5; min-height: 100vh; padding: 0; }
.header { height: 60px; background: #fff; border-bottom: 1px solid #e8e8e8; padding: 0 24px; display: flex; align-items: center; justify-content: space-between; }
.title { font-size: 18px; font-weight: 700; color: #333; }
.card { margin: 16px 24px; border-radius: 4px; }
.toolbar { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
.mt12 { margin-top: 12px; }
.pager { display: flex; justify-content: flex-end; margin-top: 12px; }
.money { font-variant-numeric: tabular-nums; font-weight: 600; }
.money.sp { color: #f56c6c; }
.sub { margin-left: 6px; color: #909399; font-size: 12px; }
</style>
