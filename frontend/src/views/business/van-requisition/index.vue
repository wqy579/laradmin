<template>
	<div class="page">
		<div class="header">
			<span class="title">要货申请</span>
			<el-button type="primary" @click="openForm()">新增要货申请</el-button>
		</div>
		<el-card shadow="never" class="card">
			<div class="filter">
				<el-input v-model="query.requisition_no" placeholder="要货单号" style="width:180px" clearable @keyup.enter="load" />
				<el-input v-model="query.vehicle_id" placeholder="车辆ID" style="width:120px" clearable @keyup.enter="load" />
				<el-select v-model="query.status" placeholder="状态" style="width:120px" clearable>
					<el-option v-for="s in statusOpts" :key="s.value" :label="s.label" :value="s.value" />
				</el-select>
				<el-date-picker v-model="dateRange" type="daterange" value-format="YYYY-MM-DD" range-separator="至" start-placeholder="开始" end-placeholder="结束" style="width:240px" @change="onDateChange" />
				<el-button type="primary" @click="load">查询</el-button>
				<el-button @click="reset">重置</el-button>
			</div>
			<el-table :data="list" border stripe v-loading="loading">
				<el-table-column prop="requisition_no" label="要货单号" width="170">
					<template #default="{ row }"><a @click="openDetail(row)">{{ row.requisition_no }}</a></template>
				</el-table-column>
				<el-table-column prop="salesman_name" label="业务员" width="100" />
				<el-table-column label="车牌号" width="110">
					<template #default="{ row }">{{ row.vehicle?.plate_no || '-' }}</template>
				</el-table-column>
				<el-table-column label="源仓库" width="140">
					<template #default="{ row }">{{ row.warehouse?.name || '-' }}</template>
				</el-table-column>
				<el-table-column prop="apply_date" label="申请日期" width="110" align="center" />
				<el-table-column prop="total_qty" label="商品总数" width="90" align="center" />
				<el-table-column label="金额" width="110" align="right">
					<template #default="{ row }"><span class="amt">¥{{ row.total_amount }}</span></template>
				</el-table-column>
				<el-table-column label="状态" width="100" align="center">
					<template #default="{ row }">
						<el-tag :type="statusType(row.status)" size="small">{{ statusLabel(row.status) }}</el-tag>
					</template>
				</el-table-column>
				<el-table-column label="操作" width="230" align="center" fixed="right">
					<template #default="{ row }">
						<template v-if="['draft', 'rejected'].includes(row.status)">
							<el-button link type="primary" @click="openForm(row)">编辑</el-button>
							<el-button link type="warning" @click="onSubmit(row)">提交</el-button>
							<el-button link type="danger" @click="onDelete(row)">删除</el-button>
						</template>
						<template v-else-if="row.status === 'pending'">
							<el-button link type="success" @click="onApprove(row)">审核</el-button>
							<el-button link type="danger" @click="onReject(row)">驳回</el-button>
							<el-button link type="info" @click="openDetail(row)">查看</el-button>
						</template>
						<template v-else>
							<el-button link type="info" @click="openDetail(row)">查看</el-button>
						</template>
					</template>
				</el-table-column>
			</el-table>
			<el-pagination class="pager" layout="total, sizes, prev, pager, next, jumper" :total="total" :page-sizes="[10, 20, 50]" :page-size="query.page_size" :current-page="query.page" @current-change="(p) => { query.page = p; load(); }" @size-change="(s) => { query.page_size = s; load(); }" />
		</el-card>

		<VanRequisitionForm ref="formRef" @saved="load" />
		<VanRequisitionDetail ref="detailRef" />
	</div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import api from '@/api/business.js';
import VanRequisitionForm from './van-requisition-form.vue';
import VanRequisitionDetail from './van-requisition-detail.vue';

const list = ref([]);
const total = ref(0);
const loading = ref(false);
const query = reactive({ requisition_no: '', vehicle_id: '', status: '', start_date: '', end_date: '', page: 1, page_size: 20 });
const dateRange = ref([]);
const formRef = ref(null);
const detailRef = ref(null);

const statusOpts = [
	{ label: '草稿', value: 'draft' }, { label: '待审核', value: 'pending' },
	{ label: '已审核', value: 'approved' }, { label: '已拣货', value: 'picked' },
	{ label: '已驳回', value: 'rejected' }, { label: '已取消', value: 'cancelled' },
];
const statusLabel = (s) => ({ draft: '草稿', pending: '待审核', approved: '已审核', picked: '已拣货', rejected: '已驳回', cancelled: '已取消' }[s] || s);
const statusType = (s) => ({ draft: 'info', pending: 'warning', approved: 'success', picked: 'primary', rejected: 'danger', cancelled: 'info' }[s] || 'info');

const onDateChange = (val) => { query.start_date = val?.[0] || ''; query.end_date = val?.[1] || ''; };
const load = async () => {
	loading.value = true;
	try { const res = await api.vanRequisition.list.get(query); list.value = res.data?.list || []; total.value = res.data?.total || 0; }
	finally { loading.value = false; }
};
const reset = () => { Object.assign(query, { requisition_no: '', vehicle_id: '', status: '', start_date: '', end_date: '', page: 1 }); dateRange.value = []; load(); };
const openForm = (row) => formRef.value?.open(row);
const openDetail = (row) => detailRef.value?.open(row.id);
const onSubmit = (row) => {
	ElMessageBox.confirm('确认提交审核？', '提示', { type: 'warning' })
		.then(async () => { await api.vanRequisition.submit.post(row.id); ElMessage.success('已提交'); load(); })
		.catch(() => {});
};
const onApprove = (row) => {
	ElMessageBox.prompt('审核意见（可选）', '审核通过', { inputType: 'textarea', inputPlaceholder: '可选' })
		.then(async ({ value }) => { await api.vanRequisition.approve.post(row.id, { approval_comment: value }); ElMessage.success('审核通过，库存已冻结'); load(); })
		.catch(() => {});
};
const onReject = (row) => {
	ElMessageBox.prompt('驳回原因', '驳回', { inputType: 'textarea', inputValidator: (v) => !!v?.trim() || '请输入驳回原因' })
		.then(async ({ value }) => { await api.vanRequisition.reject.post(row.id, { approval_comment: value }); ElMessage.success('已驳回'); load(); })
		.catch(() => {});
};
const onDelete = (row) => {
	ElMessageBox.confirm('确认删除该要货申请？', '提示', { type: 'warning' })
		.then(async () => { await api.vanRequisition.delete.delete(row.id); ElMessage.success('已删除'); load(); })
		.catch(() => {});
};
onMounted(() => load());
</script>

<style scoped>
.page { background: #f0f2f5; min-height: 100vh; }
.header { height: 60px; background: #fff; border-bottom: 1px solid #e8e8e8; padding: 0 24px; display: flex; align-items: center; justify-content: space-between; }
.title { font-size: 18px; font-weight: 700; color: #333; }
.card { margin: 16px 24px; border-radius: 4px; }
.filter { margin-bottom: 16px; display: flex; gap: 12px; flex-wrap: wrap; }
.amt { color: #f5222d; font-weight: 700; }
.pager { margin-top: 16px; justify-content: flex-end; }
a { color: #409eff; cursor: pointer; }
</style>
