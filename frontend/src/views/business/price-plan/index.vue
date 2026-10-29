<template>
	<div class="page">
		<div class="header">
			<span class="title">价格方案</span>
			<el-button type="primary" @click="openForm()">新增方案</el-button>
		</div>
		<el-card shadow="never" class="card">
			<el-table :data="list" border stripe v-loading="loading">
				<el-table-column prop="code" label="方案编码" width="130" align="center" />
				<el-table-column prop="name" label="方案名称" min-width="180" />
				<el-table-column label="定价档位" width="120" align="center">
					<template #default="{ row }">
						<el-tag :type="typeMeta[row.type]?.type || 'info'" size="small">{{ typeMeta[row.type]?.label || row.type }}</el-tag>
					</template>
				</el-table-column>
				<el-table-column label="折扣" width="100" align="center">
					<template #default="{ row }">
						<span v-if="row.discount != null">{{ row.discount }}折</span>
						<span v-else>-</span>
					</template>
				</el-table-column>
				<el-table-column label="适用等级" width="120" align="center">
					<template #default="{ row }">{{ row.level?.name || '-' }}</template>
				</el-table-column>
				<el-table-column label="生效期间" width="200" align="center">
					<template #default="{ row }">
						<span>{{ row.start_date || '不限' }} ~ {{ row.end_date || '不限' }}</span>
					</template>
				</el-table-column>
				<el-table-column label="状态" width="90" align="center">
					<template #default="{ row }">
						<el-tag :type="row.status ? 'success' : 'info'" size="small">{{ row.status ? '启用' : '停用' }}</el-tag>
					</template>
				</el-table-column>
				<el-table-column prop="remark" label="备注" min-width="160" show-overflow-tooltip />
				<el-table-column label="操作" width="150" align="center">
					<template #default="{ row }">
						<el-button link type="primary" @click="openForm(row)">编辑</el-button>
						<el-button link type="danger" @click="onDelete(row)">删除</el-button>
					</template>
				</el-table-column>
			</el-table>
		</el-card>

		<el-dialog v-model="formVisible" :title="form.id ? '编辑方案' : '新增方案'" width="560px" destroy-on-close>
			<el-form :model="form" label-width="100px">
				<el-form-item label="方案名称" required>
					<el-input v-model="form.name" maxlength="50" placeholder="如 国庆特价方案" />
				</el-form-item>
				<el-form-item label="方案编码" required>
					<el-input v-model="form.code" maxlength="30" placeholder="如 TP20261028" />
				</el-form-item>
				<el-form-item label="定价档位" required>
					<el-radio-group v-model="form.type">
						<el-radio-button value="special_price">特价</el-radio-button>
						<el-radio-button value="level_price">等级价</el-radio-button>
						<el-radio-button value="default_price">默认价</el-radio-button>
					</el-radio-group>
				</el-form-item>
				<el-form-item label="折扣率" v-if="form.type === 'level_price'">
					<el-input-number v-model="form.discount" :min="0.1" :max="9.9" :step="0.1" />
					<span class="unit">折</span>
				</el-form-item>
				<el-form-item label="适用等级">
					<el-select v-model="form.level_id" clearable placeholder="不限等级" style="width:200px">
						<el-option v-for="lv in levels" :key="lv.id" :label="lv.name" :value="lv.id" />
					</el-select>
				</el-form-item>
				<el-form-item label="生效期间">
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
				<el-form-item label="状态">
					<el-radio-group v-model="form.status">
						<el-radio :value="true">启用</el-radio>
						<el-radio :value="false">停用</el-radio>
					</el-radio-group>
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
import { ref, onMounted } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import api from '@/api/business.js';

const list = ref([]);
const loading = ref(false);
const saving = ref(false);
const formVisible = ref(false);
const form = ref({});
const levels = ref([]);

const typeMeta = {
	special_price: { label: '特价', type: 'danger' },
	level_price: { label: '等级价', type: 'warning' },
	default_price: { label: '默认价', type: 'info' },
};

const load = async () => {
	loading.value = true;
	try {
		const res = await api.customerSpecialPrice.plans.get({ page: 1, page_size: 100 });
		list.value = res.data?.list || [];
	} finally {
		loading.value = false;
	}
};

const loadLevels = async () => {
	const res = await api.customerSpecialPrice.options.get();
	levels.value = res.data?.levels || [];
};

const openForm = (row) => {
	form.value = row
		? { ...row, period: row.start_date && row.end_date ? [row.start_date, row.end_date] : [] }
		: { name: '', code: '', type: 'special_price', discount: 9.0, level_id: null, period: [], status: true, remark: '' };
	formVisible.value = true;
};

const onSave = async () => {
	if (!form.value.name || !form.value.code) return ElMessage.warning('请填写方案名称和编码');
	const payload = {
		name: form.value.name,
		code: form.value.code,
		type: form.value.type,
		discount: form.value.type === 'level_price' ? form.value.discount : null,
		level_id: form.value.level_id || null,
		start_date: form.value.period?.[0] || null,
		end_date: form.value.period?.[1] || null,
		status: form.value.status,
		remark: form.value.remark || '',
	};
	saving.value = true;
	try {
		if (form.value.id) {
			await api.customerSpecialPrice.planUpdate.put(form.value.id, payload);
		} else {
			await api.customerSpecialPrice.planCreate.post(payload);
		}
		ElMessage.success('已保存');
		formVisible.value = false;
		load();
	} finally {
		saving.value = false;
	}
};

const onDelete = (row) => {
	ElMessageBox.confirm(`确认删除方案「${row.name}」？删除后其下客户特价将解除关联。`, '提示', { type: 'warning' })
		.then(async () => { await api.customerSpecialPrice.planDelete.delete(row.id); ElMessage.success('已删除'); load(); })
		.catch(() => {});
};

onMounted(() => { load(); loadLevels(); });
</script>

<style scoped>
.page { background: #f0f2f5; min-height: 100vh; padding: 0; }
.header { height: 60px; background: #fff; border-bottom: 1px solid #e8e8e8; padding: 0 24px; display: flex; align-items: center; justify-content: space-between; }
.title { font-size: 18px; font-weight: 700; color: #333; }
.card { margin: 16px 24px; border-radius: 4px; }
.unit { margin-left: 8px; color: #666; }
</style>
