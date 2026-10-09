<template>
	<div class="page">
		<div class="header"><span class="title">还货单</span><el-button type="primary" @click="openForm()">新增还货单</el-button></div>
		<el-card shadow="never" class="card">
			<div class="filter">
				<el-input v-model="query.return_no" placeholder="还货单号" style="width:170px" clearable @keyup.enter="load" />
				<el-input v-model="query.borrow_no" placeholder="关联借货单号" style="width:170px" clearable @keyup.enter="load" />
				<el-input v-model="query.customer_name" placeholder="客户" style="width:140px" clearable @keyup.enter="load" />
				<el-select v-model="query.status" placeholder="状态" style="width:120px" clearable>
					<el-option label="待审核" value="pending" />
					<el-option label="已审核" value="approved" />
					<el-option label="已取消" value="cancelled" />
				</el-select>
				<el-date-picker v-model="dateRange" type="daterange" value-format="YYYY-MM-DD" range-separator="至" start-placeholder="开始" end-placeholder="结束" style="width:240px" @change="onDateChange" />
				<el-button type="primary" @click="load">查询</el-button>
				<el-button @click="reset">重置</el-button>
				<el-button @click="onExport">导出</el-button>
			</div>
			<el-table :data="list" border stripe v-loading="loading">
				<el-table-column prop="return_no" label="还货单号" width="170">
					<template #default="{ row }"><a @click="openDetail(row)">{{ row.return_no }}</a></template>
				</el-table-column>
				<el-table-column prop="borrow_no" label="关联借货单" width="170">
					<template #default="{ row }">{{ row.borrow_order?.borrow_no || '—' }}</template>
				</el-table-column>
				<el-table-column prop="customer_name" label="客户" min-width="140" />
				<el-table-column prop="warehouse_name" label="仓库" width="140" />
				<el-table-column prop="salesman_name" label="业务员" width="90" />
				<el-table-column prop="return_date" label="还货日期" width="110" align="center" />
				<el-table-column prop="total_qty" label="数量" width="70" align="center" />
				<el-table-column label="完好/破损" width="100" align="center"><template #default="{ row }"><span>{{ row.good_qty }}/<span style="color:#f56c6c">{{ row.bad_qty }}</span></span></template></el-table-column>
				<el-table-column label="状态" width="90" align="center"><template #default="{ row }"><el-tag :type="statusType(row.status)" size="small">{{ statusLabel(row.status) }}</el-tag></template></el-table-column>
				<el-table-column label="操作" width="220" align="center" fixed="right">
					<template #default="{ row }">
						<template v-if="row.status === 'pending'">
							<el-button link type="primary" @click="openForm(row)">编辑</el-button>
							<el-button link type="success" @click="onApprove(row)">审核还货</el-button>
							<el-button link type="danger" @click="onDelete(row)">删除</el-button>
							<el-button link type="warning" @click="onCancel(row)">取消</el-button>
						</template>
						<template v-else><el-button link type="info" @click="openDetail(row)">查看</el-button></template>
					</template>
				</el-table-column>
			</el-table>
			<el-pagination class="pager" layout="total, sizes, prev, pager, next, jumper" :total="total" :page-sizes="[10, 20, 50]" :page-size="query.page_size" :current-page="query.page" @current-change="(p) => { query.page = p; load(); }" @size-change="(s) => { query.page_size = s; load(); }" />
		</el-card>
		<ReturnOrderForm ref="formRef" @saved="load" />
		<ReturnOrderDetail ref="detailRef" />
	</div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import api from '@/api/business.js';
import ReturnOrderForm from './return-order-form.vue';
import ReturnOrderDetail from './return-order-detail.vue';
const list = ref([]); const total = ref(0); const loading = ref(false);
const query = reactive({ return_no: '', borrow_no: '', customer_name: '', status: '', start_date: '', end_date: '', page: 1, page_size: 20 });
const dateRange = ref([]); const formRef = ref(null); const detailRef = ref(null);
const statusLabel = (s) => ({ pending: '待审核', approved: '已审核', cancelled: '已取消' }[s] || s);
const statusType = (s) => ({ pending: 'warning', approved: 'success', cancelled: 'danger' }[s] || 'info');
const onDateChange = (val) => { query.start_date = val?.[0] || ''; query.end_date = val?.[1] || ''; };
const load = async () => { loading.value = true; try { const res = await api.returnOrder.list.get(query); list.value = res.data?.list || []; total.value = res.data?.total || 0; } finally { loading.value = false; } };
const reset = () => { Object.assign(query, { return_no: '', borrow_no: '', customer_name: '', status: '', start_date: '', end_date: '', page: 1 }); dateRange.value = []; load(); };
const onExport = async () => { try { const blob = await api.returnOrder.export.get(query); triggerDownload(blob, 'borrow_return_orders.csv'); } catch (e) { ElMessage.error('导出失败'); } };
const triggerDownload = (blob, name) => { const url = URL.createObjectURL(blob); const a = document.createElement('a'); a.href = url; a.download = name; a.click(); URL.revokeObjectURL(url); };
const openForm = (row) => formRef.value?.open(row);
const openDetail = (row) => detailRef.value?.open(row.id);
const onApprove = (row) => { ElMessageBox.confirm('审核还货？完好商品回到仓库库存，客户借货余额冲减，破损将生成报损单。', '审核还货', { type: 'warning' }).then(async () => { await api.returnOrder.approve.post(row.id); ElMessage.success('还货已审核'); load(); }).catch(() => {}); };
const onCancel = (row) => { ElMessageBox.prompt('取消原因', '取消还货', { inputType: 'textarea', inputValidator: (v) => (v && v.trim() ? true : '请填写取消原因') }).then(async ({ value }) => { await api.returnOrder.cancel.post(row.id, { cancel_reason: value }); ElMessage.success('已取消'); load(); }).catch(() => {}); };
const onDelete = (row) => { ElMessageBox.confirm('确认删除？', '提示', { type: 'warning' }).then(async () => { await api.returnOrder.delete.delete(row.id); ElMessage.success('已删除'); load(); }).catch(() => {}); };
onMounted(() => load());
</script>
<style scoped>
.page { background: #f0f2f5; min-height: 100vh; }
.header { height: 60px; background: #fff; border-bottom: 1px solid #e8e8e8; padding: 0 24px; display: flex; align-items: center; justify-content: space-between; }
.title { font-size: 18px; font-weight: 700; } .card { margin: 16px 24px; } .filter { margin-bottom: 16px; display: flex; gap: 12px; flex-wrap: wrap; }
.pager { margin-top: 16px; justify-content: flex-end; } a { color: #409eff; cursor: pointer; }
</style>
