<template>
	<div class="page">
		<div class="header"><span class="title">车销销售单</span><el-button type="primary" @click="openForm()">新增销售单</el-button></div>
		<el-card shadow="never" class="card">
			<div class="filter">
				<el-input v-model="query.order_no" placeholder="销售单号" style="width:180px" clearable @keyup.enter="load" />
				<el-select v-model="query.status" placeholder="状态" style="width:120px" clearable>
					<el-option label="草稿" value="draft" /><el-option label="已确认" value="approved" /><el-option label="已取消" value="cancelled" />
				</el-select>
				<el-date-picker v-model="dateRange" type="daterange" value-format="YYYY-MM-DD" range-separator="至" start-placeholder="开始" end-placeholder="结束" style="width:240px" @change="onDateChange" />
				<el-button type="primary" @click="load">查询</el-button>
				<el-button @click="reset">重置</el-button>
			</div>
			<el-table :data="list" border stripe v-loading="loading">
				<el-table-column prop="order_no" label="销售单号" width="170">
					<template #default="{ row }"><a @click="openDetail(row)">{{ row.order_no }}</a></template>
				</el-table-column>
				<el-table-column prop="customer_name" label="客户" min-width="140" />
				<el-table-column prop="salesman_name" label="业务员" width="90" />
				<el-table-column prop="sale_date" label="销售日期" width="110" align="center" />
				<el-table-column prop="total_qty" label="数量" width="70" align="center" />
				<el-table-column label="总金额" width="110" align="right"><template #default="{ row }"><span class="amt">¥{{ row.total_amount }}</span></template></el-table-column>
				<el-table-column label="收款" width="100" align="right"><template #default="{ row }">¥{{ row.paid_amount }}</template></el-table-column>
				<el-table-column label="付款方式" width="90" align="center"><template #default="{ row }">{{ payLabel(row.payment_method) }}</template></el-table-column>
				<el-table-column label="状态" width="90" align="center"><template #default="{ row }"><el-tag :type="statusType(row.status)" size="small">{{ statusLabel(row.status) }}</el-tag></template></el-table-column>
				<el-table-column label="操作" width="150" align="center" fixed="right">
					<template #default="{ row }">
						<template v-if="row.status === 'draft'">
							<el-button link type="primary" @click="openForm(row)">编辑</el-button>
							<el-button link type="success" @click="onApprove(row)">确认销售</el-button>
							<el-button link type="danger" @click="onDelete(row)">删除</el-button>
						</template>
						<template v-else><el-button link type="info" @click="openDetail(row)">查看</el-button></template>
					</template>
				</el-table-column>
			</el-table>
			<el-pagination class="pager" layout="total, sizes, prev, pager, next, jumper" :total="total" :page-sizes="[10, 20, 50]" :page-size="query.page_size" :current-page="query.page" @current-change="(p) => { query.page = p; load(); }" @size-change="(s) => { query.page_size = s; load(); }" />
		</el-card>
		<VanSaleOrderForm ref="formRef" @saved="load" />
		<VanSaleOrderDetail ref="detailRef" />
	</div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import api from '@/api/business.js';
import VanSaleOrderForm from './van-sale-order-form.vue';
import VanSaleOrderDetail from './van-sale-order-detail.vue';
const list = ref([]); const total = ref(0); const loading = ref(false);
const query = reactive({ order_no: '', status: '', start_date: '', end_date: '', page: 1, page_size: 20 });
const dateRange = ref([]); const formRef = ref(null); const detailRef = ref(null);
const statusLabel = (s) => ({ draft: '草稿', approved: '已确认', cancelled: '已取消' }[s] || s);
const statusType = (s) => ({ draft: 'info', approved: 'success', cancelled: 'danger' }[s] || 'info');
const payLabel = (m) => ({ cash: '现金', wechat: '微信', alipay: '支付宝', card: '银行卡', credit: '挂账' }[m] || m);
const onDateChange = (val) => { query.start_date = val?.[0] || ''; query.end_date = val?.[1] || ''; };
const load = async () => { loading.value = true; try { const res = await api.vanSaleOrder.list.get(query); list.value = res.data?.list || []; total.value = res.data?.total || 0; } finally { loading.value = false; } };
const reset = () => { Object.assign(query, { order_no: '', status: '', start_date: '', end_date: '', page: 1 }); dateRange.value = []; load(); };
const openForm = (row) => formRef.value?.open(row);
const openDetail = (row) => detailRef.value?.open(row.id);
const onApprove = (row) => { ElMessageBox.confirm('确认销售？车上库存扣减并生成收款/挂账。', '确认销售', { type: 'warning' }).then(async () => { await api.vanSaleOrder.approve.post(row.id); ElMessage.success('销售已确认'); load(); }).catch(() => {}); };
const onDelete = (row) => { ElMessageBox.confirm('确认删除？', '提示', { type: 'warning' }).then(async () => { await api.vanSaleOrder.delete.delete(row.id); ElMessage.success('已删除'); load(); }).catch(() => {}); };
onMounted(() => load());
</script>
<style scoped>
.page { background: #f0f2f5; min-height: 100vh; }
.header { height: 60px; background: #fff; border-bottom: 1px solid #e8e8e8; padding: 0 24px; display: flex; align-items: center; justify-content: space-between; }
.title { font-size: 18px; font-weight: 700; } .card { margin: 16px 24px; } .filter { margin-bottom: 16px; display: flex; gap: 12px; flex-wrap: wrap; }
.amt { color: #f5222d; font-weight: 700; } .pager { margin-top: 16px; justify-content: flex-end; } a { color: #409eff; cursor: pointer; }
</style>
