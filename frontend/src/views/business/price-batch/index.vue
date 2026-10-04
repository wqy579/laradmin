<template>
	<div class="page">
		<div class="header"><span class="title">批量调价</span></div>
		<el-card shadow="never" class="card">
			<div class="filter">
				<el-input v-model="query.keyword" placeholder="商品名称/编码" style="width:220px" clearable @keyup.enter="load" />
				<el-button type="primary" @click="load">查询</el-button>
				<el-button @click="reset">重置</el-button>
			</div>
			<el-table :data="list" border stripe v-loading="loading" @selection-change="onSel">
				<el-table-column type="selection" width="45" />
				<el-table-column prop="code" label="编码" width="110" />
				<el-table-column prop="name" label="商品名称" min-width="200" show-overflow-tooltip />
				<el-table-column prop="spec" label="规格" width="100" />
				<el-table-column prop="price_small" label="标准售价" width="100" align="right" />
			</el-table>
			<div class="step1bar">
				<span>已选 {{ selected.length }} 件商品</span>
				<el-button type="primary" :disabled="!selected.length" @click="step = 2">下一步：设置调价规则</el-button>
			</div>
		</el-card>

		<el-card v-if="step === 2" shadow="never" class="card">
			<div class="sum">已选 {{ selected.length }} 件商品将进行批量调价　<el-button link type="primary" @click="step = 1">上一步</el-button></div>
			<el-form label-width="100px">
				<el-form-item label="调价方式">
					<el-radio-group v-model="form.mode">
						<el-radio-button value="ratio">按比例</el-radio-button>
						<el-radio-button value="amount">固定金额</el-radio-button>
						<el-radio-button value="set">固定价格</el-radio-button>
					</el-radio-group>
				</el-form-item>
				<el-form-item :label="form.mode === 'ratio' ? '调价比例' : form.mode === 'amount' ? '调整金额' : '固定价格'">
					<el-input-number v-model="form.value" :precision="2" :step="form.mode === 'ratio' ? 0.1 : 1" />
					<span class="unit">{{ form.mode === 'ratio' ? '倍（0.9=9折）' : '元' }}</span>
				</el-form-item>
				<el-form-item label="调价范围">
					<el-checkbox v-model="form.scopes" value="standard">标准售价</el-checkbox>
					<el-checkbox v-for="lv in levels" :key="lv.id" :value="lv.id">{{ lv.name }}</el-checkbox>
				</el-form-item>
			</el-form>
			<div class="bar">
				<el-button @click="step = 1">上一步</el-button>
				<el-button type="warning" @click="confirm">确认调价</el-button>
			</div>
		</el-card>
	</div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import api from '@/api/business.js';

const list = ref([]);
const levels = ref([]);
const selected = ref([]);
const loading = ref(false);
const step = ref(1);
const query = reactive({ keyword: '', page: 1, page_size: 20 });
const form = reactive({ mode: 'ratio', value: 0.9, scopes: ['standard'] });

const load = async () => {
	loading.value = true;
	try {
		const res = await api.priceSystem.productList.get({ keyword: query.keyword, page: 1, page_size: 50 });
		list.value = res.data.data.list || [];
		levels.value = res.data.data.levels || [];
	} finally { loading.value = false; }
};
const onSel = (rows) => { selected.value = rows; };
const reset = () => { query.keyword = ''; load(); };

const confirm = () => {
	ElMessageBox.confirm(`确认批量调价？将修改 ${selected.value.length} 件商品的价格（记录历史）`, '提示', { type: 'warning' })
		.then(async () => {
			await api.priceSystem.batch.post({
				product_ids: selected.value.map((r) => r.id),
				mode: form.mode, value: form.value, scopes: form.scopes,
			});
			ElMessage.success('调价完成');
			step.value = 1;
			load();
		}).catch(() => {});
};

onMounted(load);
</script>

<style scoped>
.page { background: #f0f2f5; min-height: 100vh; }
.header { height: 60px; background: #fff; border-bottom: 1px solid #e8e8e8; padding: 0 24px; display: flex; align-items: center; }
.title { font-size: 18px; font-weight: 700; color: #333; }
.card { margin: 16px 24px; border-radius: 4px; }
.filter { margin-bottom: 16px; display: flex; gap: 12px; }
.step1bar { margin-top: 16px; display: flex; justify-content: flex-end; align-items: center; gap: 16px; }
.sum { background: #f6ffed; border: 1px solid #b7eb8f; padding: 10px 16px; border-radius: 4px; margin-bottom: 16px; color: #52c41a; }
.bar { display: flex; justify-content: flex-end; }
.unit { margin-left: 8px; color: #999; font-size: 12px; }
</style>
