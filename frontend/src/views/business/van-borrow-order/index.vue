<template>
	<div class="page">
		<div class="header"><span class="title">车销借货单</span><div class="btn-group"><el-button @click="openBalances()">借货余额</el-button><el-button type="primary" @click="openForm()">新增借货单</el-button></div></div>
		<el-card shadow="never" class="card">
			<div class="filter">
				<el-input v-model="query.borrow_no" placeholder="借货单号" style="width:180px" clearable @keyup.enter="load" />
				<el-input v-model="query.customer_name" placeholder="客户" style="width:140px" clearable @keyup.enter="load" />
				<el-select v-model="query.status" placeholder="状态" style="width:120px" clearable>
					<el-option label="草稿" value="draft" /><el-option label="已借出" value="approved" /><el-option label="已取消" value="cancelled" />
				</el-select>
				<el-date-picker v-model="dateRange" type="daterange" value-format="YYYY-MM-DD" range-separator="至" start-placeholder="开始" end-placeholder="结束" style="width:240px" @change="onDateChange" />
				<el-button type="primary" @click="load">查询</el-button>
				<el-button @click="reset">重置</el-button>
			</div>
			<el-table :data="list" border stripe v-loading="loading">
				<el-table-column prop="borrow_no" label="借货单号" width="170">
					<template #default="{ row }"><a @click="openDetail(row)">{{ row.borrow_no }}</a></template>
				</el-table-column>
				<el-table-column prop="customer_name" label="客户" min-width="140" />
				<el-table-column prop="salesman_name" label="业务员" width="90" />
				<el-table-column prop="borrow_date" label="借货日期" width="110" align="center" />
				<el-table-column prop="due_date" label="应还日期" width="110" align="center" />
				<el-table-column prop="total_qty" label="数量" width="70" align="center" />
				<el-table-column label="总金额" width="110" align="right"><template #default="{ row }"><span class="amt">¥{{ row.total_amount }}</span></template></el-table-column>
				<el-table-column label="状态" width="90" align="center"><template #default="{ row }"><el-tag :type="statusType(row.status)" size="small">{{ statusLabel(row.status) }}</el-tag></template></el-table-column>
				<el-table-column label="操作" width="170" align="center" fixed="right">
					<template #default="{ row }">
						<template v-if="row.status === 'draft'">
							<el-button link type="primary" @click="openForm(row)">编辑</el-button>
							<el-button link type="success" @click="onApprove(row)">审核借货</el-button>
							<el-button link type="danger" @click="onDelete(row)">删除</el-button>
						</template>
						<template v-else><el-button link type="info" @click="openDetail(row)">查看</el-button></template>
					</template>
				</el-table-column>
			</el-table>
			<el-pagination class="pager" layout="total, sizes, prev, pager, next, jumper" :total="total" :page-sizes="[10, 20, 50]" :page-size="query.page_size" :current-page="query.page" @current-change="(p) => { query.page = p; load(); }" @size-change="(s) => { query.page_size = s; load(); }" />
		</el-card>
		<VanBorrowOrderForm ref="formRef" @saved="load" />
		<VanBorrowOrderDetail ref="detailRef" />
		<el-dialog v-model="balVisible" title="借货余额" width="700px">
			<el-input v-model="balCustomerId" placeholder="客户ID" clearable style="width:160px;margin-bottom:12px" @keyup.enter="loadBalances" />
			<el-button type="primary" size="small" @click="loadBalances" style="margin-bottom:12px">查询</el-button>
			<el-table :data="balances" border size="small" max-height="400" v-loading="balLoading">
				<el-table-column prop="customer_id" label="客户ID" width="120" align="center" />
				<el-table-column prop="product_name" label="商品" min-width="180" />
				<el-table-column prop="qty" label="借货数量" width="120" align="center" />
			</el-table>
		</el-dialog>
	</div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import api from '@/api/business.js';
import VanBorrowOrderForm from './van-borrow-order-form.vue';
import VanBorrowOrderDetail from './van-borrow-order-detail.vue';
const list = ref([]); const total = ref(0); const loading = ref(false);
const query = reactive({ borrow_no: '', customer_name: '', status: '', start_date: '', end_date: '', page: 1, page_size: 20 });
const dateRange = ref([]); const formRef = ref(null); const detailRef = ref(null);
const balVisible = ref(false); const balLoading = ref(false); const balances = ref([]); const balCustomerId = ref('');
const statusLabel = (s) => ({ draft: '草稿', approved: '已借出', cancelled: '已取消' }[s] || s);
const statusType = (s) => ({ draft: 'info', approved: 'success', cancelled: 'danger' }[s] || 'info');
const onDateChange = (val) => { query.start_date = val?.[0] || ''; query.end_date = val?.[1] || ''; };
const load = async () => { loading.value = true; try { const res = await api.vanBorrowOrder.list.get(query); list.value = res.data?.list || []; total.value = res.data?.total || 0; } finally { loading.value = false; } };
const loadBalances = async () => { balLoading.value = true; try { const res = await api.vanBorrowOrder.balances.get(balCustomerId ? { customer_id: balCustomerId } : {}); balances.value = res.data?.list || []; } finally { balLoading.value = false; } };
const openBalances = () => { balVisible.value = true; loadBalances(); };
const reset = () => { Object.assign(query, { borrow_no: '', customer_name: '', status: '', start_date: '', end_date: '', page: 1 }); dateRange.value = []; load(); };
const openForm = (row) => formRef.value?.open(row);
const openDetail = (row) => detailRef.value?.open(row.id);
const onApprove = (row) => { ElMessageBox.confirm('审核借货？借出商品将扣减车上库存，并登记客户借货余额。', '审核借货', { type: 'warning' }).then(async () => { await api.vanBorrowOrder.approve.post(row.id); ElMessage.success('借货已审核'); load(); }).catch(() => {}); };
const onDelete = (row) => { ElMessageBox.confirm('确认删除？', '提示', { type: 'warning' }).then(async () => { await api.vanBorrowOrder.delete.delete(row.id); ElMessage.success('已删除'); load(); }).catch(() => {}); };
onMounted(() => load());
</script>
<style scoped>
.page { background: #f0f2f5; min-height: 100vh; }
.header { height: 60px; background: #fff; border-bottom: 1px solid #e8e8e8; padding: 0 24px; display: flex; align-items: center; justify-content: space-between; }
.title { font-size: 18px; font-weight: 700; } .btn-group { display: flex; gap: 8px; }
.card { margin: 16px 24px; } .filter { margin-bottom: 16px; display: flex; gap: 12px; flex-wrap: wrap; }
.amt { color: #f5222d; font-weight: 700; } .pager { margin-top: 16px; justify-content: flex-end; } a { color: #409eff; cursor: pointer; }
</style>
