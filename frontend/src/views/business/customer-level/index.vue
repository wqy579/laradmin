<template>
	<div class="page">
		<div class="header">
			<span class="title">客户等级</span>
			<el-button type="primary" @click="openForm()">新增等级</el-button>
		</div>
		<el-card shadow="never" class="card">
			<el-table :data="list" border stripe>
				<el-table-column prop="name" label="等级名称" min-width="180" />
				<el-table-column prop="code" label="等级编码" width="120" align="center" />
				<el-table-column label="默认折扣" width="120" align="center">
					<template #default="{ row }">
						<span class="discount">{{ row.default_discount }}折</span>
					</template>
				</el-table-column>
				<el-table-column prop="customer_count" label="客户数量" width="100" align="center" />
				<el-table-column prop="sort" label="排序" width="80" align="center" />
				<el-table-column label="状态" width="90" align="center">
					<template #default="{ row }">
						<el-tag :type="row.status ? 'success' : 'info'" size="small">{{ row.status ? '启用' : '停用' }}</el-tag>
					</template>
				</el-table-column>
				<el-table-column label="操作" width="150" align="center">
					<template #default="{ row }">
						<el-button link type="primary" @click="openForm(row)">编辑</el-button>
						<el-button link type="danger" :disabled="row.is_system" @click="onDelete(row)">删除</el-button>
					</template>
				</el-table-column>
			</el-table>
		</el-card>

		<el-dialog v-model="formVisible" :title="form.id ? '编辑等级' : '新增等级'" width="500px">
			<el-form :model="form" label-width="100px">
				<el-form-item label="等级名称" required>
					<el-input v-model="form.name" maxlength="20" />
				</el-form-item>
				<el-form-item label="等级编码" required>
					<el-input v-model="form.code" placeholder="如 LV1" />
				</el-form-item>
				<el-form-item label="默认折扣率">
					<el-input-number v-model="form.default_discount" :min="0.1" :max="9.9" :step="0.5" />
					<span class="unit">折</span>
				</el-form-item>
				<el-form-item label="排序">
					<el-input-number v-model="form.sort" :min="0" />
				</el-form-item>
				<el-form-item label="状态">
					<el-radio-group v-model="form.status">
						<el-radio :value="true">启用</el-radio>
						<el-radio :value="false">停用</el-radio>
					</el-radio-group>
				</el-form-item>
			</el-form>
			<template #footer>
				<el-button @click="formVisible = false">取消</el-button>
				<el-button type="primary" @click="onSave">确定</el-button>
			</template>
		</el-dialog>
	</div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import api from '@/api/business.js';

const list = ref([]);
const formVisible = ref(false);
const form = ref({});

const load = async () => {
	const res = await api.priceSystem.levelList.get();
	list.value = res.data.data.list || [];
};

const openForm = (row) => {
	form.value = row ? { ...row } : { name: '', code: '', default_discount: 9.0, sort: 1, status: true };
	formVisible.value = true;
};

const onSave = async () => {
	if (!form.value.name || !form.value.code) return ElMessage.warning('请填写名称和编码');
	if (form.value.id) {
		await api.priceSystem.levelUpdate.put(form.value.id, form.value);
	} else {
		await api.priceSystem.levelCreate.post(form.value);
	}
	ElMessage.success('已保存');
	formVisible.value = false;
	load();
};

const onDelete = (row) => {
	ElMessageBox.confirm(`确认删除等级「${row.name}」？`, '提示', { type: 'warning' })
		.then(async () => { await api.priceSystem.levelDelete.delete(row.id); ElMessage.success('已删除'); load(); })
		.catch(() => {});
};

onMounted(load);
</script>

<style scoped>
.page { background: #f0f2f5; min-height: 100vh; padding: 0; }
.header { height: 60px; background: #fff; border-bottom: 1px solid #e8e8e8; padding: 0 24px; display: flex; align-items: center; justify-content: space-between; }
.title { font-size: 18px; font-weight: 700; color: #333; }
.card { margin: 16px 24px; border-radius: 4px; }
.discount { color: #1890ff; font-weight: 700; }
.unit { margin-left: 8px; color: #666; }
</style>
