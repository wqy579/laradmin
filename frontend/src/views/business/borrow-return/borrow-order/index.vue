<template>
	<div class="page">
		<div class="header"><span class="title">借货单</span><el-button type="primary" @click="openForm()">新增借货单</el-button></div>
		<el-card shadow="never" class="card">
			<div class="filter">
				<el-input v-model="query.borrow_no" placeholder="借货单号" style="width:180px" clearable @keyup.enter="load" />
				<el-input v-model="query.customer_name" placeholder="客户" style="width:140px" clearable @keyup.enter="load" />
				<el-select v-model="query.status" placeholder="状态" style="width:120px" clearable>
					<el-option label="未还" value="unreturned" />
					<el-option label="部分还" value="partial" />
					<el-option label="已还清" value="cleared" />
					<el-option label="已转销售" value="converted" />
					<el-option label="已取消" value="cancelled" />
				</el-select>
				<el-date-picker v-model="dateRange" type="daterange" value-format="YYYY-MM-DD" range-separator="至" start-placeholder="开始" end-placeholder="结束" style="width:240px" @change="onDateChange" />
				<el-button type="primary" @click="load">查询</el-button>
				<el-button @click="reset">重置</el-button>
				<el-button @click="onExport">导出</el-button>
			</div>
			<el-table :data="list" border stripe v-loading="loading">
				<el-table-column prop="borrow_no" label="借货单号" width="170">
					<template #default="{ row }"><a @click="openDetail(row)">{{ row.borrow_no }}</a></template>
				</el-table-column>
				<el-table-column prop="customer_name" label="客户" min-width="140" />
				<el-table-column prop="warehouse_name" label="仓库" width="140" />
				<el-table-column prop="salesman_name" label="业务员" width="90" />
				<el-table-column prop="borrow_date" label="借货日期" width="110" align="center" />
				<el-table-column prop="due_date" label="应还日期" width="110" align="center" />
				<el-table-column prop="total_qty" label="数量" width="70" align="center" />
				<el-table-column label="金额" width="110" align="right"><template #default="{ row }"><span class="amt">¥{{ row.total_amount }}</span></template></el-table-column>
				<el-table-column prop="returned_qty" label="已还" width="70" align="center" />
				<el-table-column label="状态" width="90" align="center"><template #default="{ row }"><el-tag :type="statusType(row.status)" size="small">{{ statusLabel(row.status) }}</el-tag></template></el-table-column>
				<el-table-column label="操作" width="220" align="center" fixed="right">
					<template #default="{ row }">
						<template v-if="row.status === 'draft'">
							<el-button link type="primary" @click="openForm(row)">编辑</el-button>
							<el-button link type="success" @click="onConfirm(row)">确认借货</el-button>
							<el-button link type="danger" @click="onDelete(row)">删除</el-button>
						</template>
						<template v-else-if="row.status === 'unreturned'">
							<el-button link type="warning" @click="onCancel(row)">取消</el-button>
							<el-button link type="success" @click="onConvert(row)">转销售</el-button>
							<el-button link type="info" @click="openDetail(row)">查看</el-button>
						</template>
						<template v-else><el-button link type="info" @click="openDetail(row)">查看</el-button></template>
					</template>
				</el-table-column>
			</el-table>
			<el-pagination class="pager" layout="total, sizes, prev, pager, next, jumper" :total="total" :page-sizes="[10, 20, 50]" :page-size="query.page_size" :current-page="query.page" @current-change="(p) => { query.page = p; load(); }" @size-change="(s) => { query.page_size = s; load(); }" />
		</el-card>
		<BorrowOrderForm ref="formRef" @saved="load" />
		<BorrowOrderDetail ref="detailRef" />
	</div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import api from '@/api/business.js';
import BorrowOrderForm from './borrow-order-form.vue';
import BorrowOrderDetail from './borrow-order-detail.vue';
const list = ref([]); const total = ref(0); const loading = ref(false);
const query = reactive({ borrow_no: '', customer_name: '', status: '', start_date: '', end_date: '', page: 1, page_size: 20 });
const dateRange = ref([]); const formRef = ref(null); const detailRef = ref(null);
const statusLabel = (s) => ({ draft: '未还', unreturned: '未还', partial: '部分还', cleared: '已还清', converted: '已转销售', cancelled: '已取消' }[s] || s);
const statusType = (s) => ({ draft: 'info', unreturned: 'warning', partial: 'primary', cleared: 'success', converted: 'success', cancelled: 'danger' }[s] || 'info');
const onDateChange = (val) => { query.start_date = val?.[0] || ''; query.end_date = val?.[1] || ''; };
const load = async () => { loading.value = true; try { const res = await api.borrowOrder.list.get(query); list.value = res.data?.list || []; total.value = res.data?.total || 0; } finally { loading.value = false; } };
const reset = () => { Object.assign(query, { borrow_no: '', customer_name: '', status: '', start_date: '', end_date: '', page: 1 }); dateRange.value = []; load(); };
const onExport = async () => { try { const blob = await api.borrowOrder.export.get(query); triggerDownload(blob, 'borrow_orders.csv'); } catch (e) { ElMessage.error('导出失败'); } };
const triggerDownload = (blob, name) => { const url = URL.createObjectURL(blob); const a = document.createElement('a'); a.href = url; a.download = name; a.click(); URL.revokeObjectURL(url); };
const openForm = (row) => formRef.value?.open(row);
const openDetail = (row) => detailRef.value?.open(row.id);
const onConfirm = (row) => { ElMessageBox.confirm('确认借货？借出商品将扣减仓库库存，并登记客户借货余额。', '确认借货', { type: 'warning' }).then(async () => { await api.borrowOrder.confirm.post(row.id); ElMessage.success('借货已确认'); load(); }).catch(() => {}); };
const onConvert = (row) => { ElMessageBox.confirm('转销售？将生成销售单并增加客户应收账款，借货余额清零。', '转销售', { type: 'warning' }).then(async () => { await api.borrowOrder.convert.post(row.id); ElMessage.success('已转销售'); load(); }).catch(() => {}); };
const onCancel = (row) => { ElMessageBox.prompt('取消原因', '取消借货', { inputType: 'textarea', inputValidator: (v) => (v && v.trim() ? true : '请填写取消原因') }).then(async ({ value }) => { await api.borrowOrder.cancel.post(row.id, { cancel_reason: value }); ElMessage.success('已取消，库存已恢复'); load(); }).catch(() => {}); };
const onDelete = (row) => { ElMessageBox.confirm('确认删除？', '提示', { type: 'warning' }).then(async () => { await api.borrowOrder.delete.delete(row.id); ElMessage.success('已删除'); load(); }).catch(() => {}); };
onMounted(() => load());
</script>
<style scoped>
.page { background: #f0f2f5; min-height: 100vh; }
.header { height: 60px; background: #fff; border-bottom: 1px solid #e8e8e8; padding: 0 24px; display: flex; align-items: center; justify-content: space-between; }
.title { font-size: 18px; font-weight: 700; } .card { margin: 16px 24px; } .filter { margin-bottom: 16px; display: flex; gap: 12px; flex-wrap: wrap; }
.amt { color: #f5222d; font-weight: 700; } .pager { margin-top: 16px; justify-content: flex-end; } a { color: #409eff; cursor: pointer; }
</style>
