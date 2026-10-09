<template>
	<div class="page">
		<div class="header"><span class="title">拣货验货</span></div>
		<el-card shadow="never" class="card">
			<div class="filter">
				<el-input v-model="query.picking_no" placeholder="拣货单号" style="width:180px" clearable @keyup.enter="load" />
				<el-select v-model="query.status" placeholder="状态" style="width:120px" clearable>
					<el-option label="草稿" value="draft" /><el-option label="待确认" value="pending" />
					<el-option label="已装车" value="approved" /><el-option label="已取消" value="cancelled" />
				</el-select>
				<el-date-picker v-model="dateRange" type="daterange" value-format="YYYY-MM-DD" range-separator="至" start-placeholder="开始" end-placeholder="结束" style="width:240px" @change="onDateChange" />
				<el-button type="primary" @click="load">查询</el-button>
				<el-button @click="reset">重置</el-button>
			</div>
			<el-table :data="list" border stripe v-loading="loading">
				<el-table-column prop="picking_no" label="拣货单号" width="170">
					<template #default="{ row }"><a @click="openDetail(row)">{{ row.picking_no }}</a></template>
				</el-table-column>
				<el-table-column label="要货单号" width="170">
					<template #default="{ row }">{{ row.requisition?.requisition_no || '-' }}</template>
				</el-table-column>
				<el-table-column label="车牌号" width="110">
					<template #default="{ row }">{{ row.vehicle?.plate_no || '-' }}</template>
				</el-table-column>
				<el-table-column prop="picker_name" label="库管员" width="100" />
				<el-table-column prop="pick_date" label="拣货日期" width="110" align="center" />
				<el-table-column prop="total_qty" label="拣货总数" width="90" align="center" />
				<el-table-column label="状态" width="100" align="center">
					<template #default="{ row }">
						<el-tag :type="statusType(row.status)" size="small">{{ statusLabel(row.status) }}</el-tag>
						<el-tag v-if="row.checked" type="success" size="small" style="margin-left:4px">已验货</el-tag>
					</template>
				</el-table-column>
				<el-table-column label="操作" width="200" align="center" fixed="right">
					<template #default="{ row }">
						<template v-if="row.status === 'draft'">
							<el-button link type="warning" @click="onSubmit(row)">提交</el-button>
							<el-button link type="danger" @click="onDelete(row)">删除</el-button>
						</template>
						<template v-else-if="row.status === 'pending'">
							<el-button link type="success" @click="onApprove(row)">确认装车</el-button>
							<el-button link type="info" @click="openDetail(row)">查看</el-button>
						</template>
						<template v-else-if="row.status === 'approved' && !row.checked">
							<el-button link type="primary" @click="openDetail(row)">验货</el-button>
						</template>
						<template v-else>
							<el-button link type="info" @click="openDetail(row)">查看</el-button>
						</template>
					</template>
				</el-table-column>
			</el-table>
			<el-pagination class="pager" layout="total, sizes, prev, pager, next, jumper" :total="total" :page-sizes="[10, 20, 50]" :page-size="query.page_size" :current-page="query.page" @current-change="(p) => { query.page = p; load(); }" @size-change="(s) => { query.page_size = s; load(); }" />
		</el-card>

		<VanPickingDetail ref="detailRef" @saved="load" />
	</div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import api from '@/api/business.js';
import VanPickingDetail from './van-picking-detail.vue';

const list = ref([]);
const total = ref(0);
const loading = ref(false);
const query = reactive({ picking_no: '', status: '', start_date: '', end_date: '', page: 1, page_size: 20 });
const dateRange = ref([]);
const detailRef = ref(null);

const statusLabel = (s) => ({ draft: '草稿', pending: '待确认', approved: '已装车', cancelled: '已取消' }[s] || s);
const statusType = (s) => ({ draft: 'info', pending: 'warning', approved: 'success', cancelled: 'info' }[s] || 'info');
const onDateChange = (val) => { query.start_date = val?.[0] || ''; query.end_date = val?.[1] || ''; };
const load = async () => { loading.value = true; try { const res = await api.vanPicking.list.get(query); list.value = res.data?.list || []; total.value = res.data?.total || 0; } finally { loading.value = false; } };
const reset = () => { Object.assign(query, { picking_no: '', status: '', start_date: '', end_date: '', page: 1 }); dateRange.value = []; load(); };
const openDetail = (row) => detailRef.value?.open(row.id);
const onSubmit = (row) => { ElMessageBox.confirm('确认提交？', '提示', { type: 'warning' }).then(async () => { await api.vanPicking.submit.post(row.id); ElMessage.success('已提交'); load(); }).catch(() => {}); };
const onDelete = (row) => { ElMessageBox.confirm('确认删除？', '提示', { type: 'warning' }).then(async () => { await api.vanPicking.delete.delete(row.id); ElMessage.success('已删除'); load(); }).catch(() => {}); };
const onApprove = (row) => { ElMessageBox.confirm('确认装车？装车后源仓出库、车上仓入库。', '确认装车', { type: 'warning' }).then(async () => { await api.vanPicking.approve.post(row.id); ElMessage.success('装车完成'); load(); }).catch(() => {}); };
onMounted(() => load());
</script>

<style scoped>
.page { background: #f0f2f5; min-height: 100vh; }
.header { height: 60px; background: #fff; border-bottom: 1px solid #e8e8e8; padding: 0 24px; display: flex; align-items: center; }
.title { font-size: 18px; font-weight: 700; }
.card { margin: 16px 24px; }
.filter { margin-bottom: 16px; display: flex; gap: 12px; flex-wrap: wrap; }
.pager { margin-top: 16px; justify-content: flex-end; }
a { color: #409eff; cursor: pointer; }
</style>
