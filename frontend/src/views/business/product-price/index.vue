<template>
	<div class="page">
		<div class="header">
			<span class="title">商品价格</span>
		</div>
		<el-card shadow="never" class="card">
			<div class="filter">
				<el-input v-model="query.keyword" placeholder="商品名称/编码" style="width:220px" clearable @keyup.enter="load" />
				<el-button type="primary" @click="load">查询</el-button>
				<el-button @click="reset">重置</el-button>
			</div>
			<el-table :data="list" border stripe v-loading="loading">
				<el-table-column prop="code" label="编码" width="110" />
				<el-table-column prop="name" label="商品名称" min-width="200" show-overflow-tooltip />
				<el-table-column prop="spec" label="规格" width="100" />
				<el-table-column prop="unit" label="单位" width="70" align="center" />
				<el-table-column prop="cost_price" label="成本价" width="90" align="right">
					<template #default="{ row }"><span class="cost">¥{{ row.cost_price }}</span></template>
				</el-table-column>
				<el-table-column label="标准售价" width="110" align="right">
					<template #default="{ row }">¥{{ row.price_small }}</template>
				</el-table-column>
				<el-table-column v-for="lv in levels" :key="lv.level_id" :label="lv.name+'价'" width="120" align="right">
					<template #default="{ row }">
						<el-input-number size="small" :controls="false" style="width:90px"
							:model-value="priceOf(row, lv)" @change="(v) => onQuickSave(row, lv, v)" />
						<div class="sub">{{ discountOf(row, lv) }}</div>
					</template>
				</el-table-column>
				<el-table-column label="操作" width="150" align="center" fixed="right">
					<template #default="{ row }">
						<el-button link type="primary" @click="openEdit(row)">编辑</el-button>
						<el-button link type="info" @click="openHistory(row)">历史</el-button>
					</template>
				</el-table-column>
			</el-table>
			<el-pagination class="pager" layout="total, prev, pager, next" :total="total" :page-size="query.page_size" :current-page="query.page" @current-change="(p) => { query.page = p; load(); }" />
		</el-card>

		<!-- 编辑弹窗 -->
		<el-dialog v-model="editVisible" title="商品价格编辑" width="620px">
			<div class="pinfo" v-if="current.name">
				<b>{{ current.name }}</b>
				<span class="sub">{{ current.spec }} / {{ current.unit }}　成本价 ¥{{ current.cost_price }}</span>
			</div>
			<el-form label-width="110px" style="margin-top:12px">
				<el-form-item label="标准售价">
					<el-input-number v-model="editForm.price_small" :min="0" :precision="2" />
					<el-button link type="primary" @click="fillByDefault">按默认折扣填充</el-button>
				</el-form-item>
				<el-form-item v-for="lv in editForm.levels" :key="lv.level_id" :label="lv.name+'价'">
					<el-input-number v-model="lv.price" :min="0" :precision="2" />
					<span class="sub" style="margin-left:12px">{{ rowDiscount(editForm.price_small, lv.price) }}</span>
				</el-form-item>
			</el-form>
			<template #footer>
				<el-button @click="editVisible = false">取消</el-button>
				<el-button type="primary" @click="onEditSave">保存</el-button>
			</template>
		</el-dialog>

		<!-- 历史弹窗 -->
		<el-dialog v-model="historyVisible" title="价格变更历史" width="720px">
			<el-table :data="histList" border size="small">
				<el-table-column prop="created_at" label="时间" width="150" />
				<el-table-column prop="price_type_name" label="价格类型" width="110" />
				<el-table-column prop="old_price" label="变更前" width="90" align="right"><template #default="{ row }"><s>{{ row.old_price }}</s></template></el-table-column>
				<el-table-column prop="new_price" label="变更后" width="90" align="right" />
				<el-table-column label="幅度" width="90" align="center"><template #default="{ row }">{{ pct(row) }}</template></el-table-column>
				<el-table-column prop="operator_name" label="操作人" width="90" />
				<el-table-column prop="batch_rule" label="方式" />
			</el-table>
		</el-dialog>
	</div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import { ElMessage } from 'element-plus';
import api from '@/api/business.js';

const list = ref([]);
const levels = ref([]);
const total = ref(0);
const loading = ref(false);
const query = reactive({ keyword: '', page: 1, page_size: 20 });

const editVisible = ref(false);
const current = ref({});
const editForm = ref({ price_small: 0, levels: [] });

const historyVisible = ref(false);
const histList = ref([]);

const load = async () => {
	loading.value = true;
	try {
		const res = await api.priceSystem.productList.get(query);
		list.value = res.data.data.list || [];
		levels.value = res.data.data.levels || [];
		total.value = res.data.data.total || 0;
	} finally { loading.value = false; }
};

const priceOf = (row, lv) => {
	const hit = row.levels.find((x) => x.level_id === lv.level_id);
	return hit && hit.price != null ? Number(hit.price) : null;
};
const discountOf = (row, lv) => {
	const p = priceOf(row, lv);
	if (!p || !row.price_small) return '';
	return (p / row.price_small * 10).toFixed(1) + '折';
};
const rowDiscount = (std, price) => {
	if (!price || !std) return '';
	return '（' + (price / std * 10).toFixed(1) + '折）';
};

const onQuickSave = async (row, lv, v) => {
	if (v == null) return;
	const levels = row.levels.map((x) => ({ level_id: x.level_id, price: x.price }));
	levels.find((x) => x.level_id === lv.level_id).price = Number(v);
	await api.priceSystem.productSave.put(row.id, { price_small: Number(row.price_small), levels });
	ElMessage.success('已保存');
	load();
};

const openEdit = (row) => {
	current.value = row;
	editForm.value = {
		price_small: Number(row.price_small),
		levels: row.levels.map((x) => ({ level_id: x.level_id, name: x.name, price: x.price != null ? Number(x.price) : null })),
	};
	editVisible.value = true;
};

const fillByDefault = () => {
	editForm.value.levels.forEach((lv) => {
		const meta = levels.value.find((x) => x.id === lv.level_id);
		if (meta && editForm.value.price_small) {
			lv.price = Math.round(editForm.value.price_small * meta.default_discount / 10 * 100) / 100;
		}
	});
};

const onEditSave = async () => {
	await api.priceSystem.productSave.put(current.value.id, {
		price_small: Number(editForm.value.price_small),
		levels: editForm.value.levels.map((x) => ({ level_id: x.level_id, price: x.price })),
	});
	ElMessage.success('已保存');
	editVisible.value = false;
	load();
};

const openHistory = async (row) => {
	const res = await api.priceSystem.history.get({ product_id: row.id });
	histList.value = res.data.data.list || [];
	historyVisible.value = true;
};

const pct = (row) => {
	if (!row.old_price || row.old_price == row.new_price) return '-';
	const v = (row.new_price - row.old_price) / row.old_price * 100;
	return (v > 0 ? '+' : '') + v.toFixed(1) + '%';
};

const reset = () => { query.keyword = ''; query.page = 1; load(); };

onMounted(load);
</script>

<style scoped>
.page { background: #f0f2f5; min-height: 100vh; }
.header { height: 60px; background: #fff; border-bottom: 1px solid #e8e8e8; padding: 0 24px; display: flex; align-items: center; }
.title { font-size: 18px; font-weight: 700; color: #333; }
.card { margin: 16px 24px; border-radius: 4px; }
.filter { margin-bottom: 16px; display: flex; gap: 12px; }
.cost { color: #999; }
.sub { font-size: 12px; color: #999; }
.pinfo { display: flex; flex-direction: column; gap: 4px; }
.pager { margin-top: 16px; justify-content: flex-end; }
</style>
