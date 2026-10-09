<template>
	<div class="page">
		<div class="header"><span class="title">换货单</span><el-button type="primary" @click="openForm()">新增换货单</el-button></div>
		<el-card shadow="never" class="card">
			<div class="filter">
				<el-input v-model="query.exchange_no" placeholder="换货单号" style="width:170px" clearable @keyup.enter="load" />
				<el-input v-model="query.customer_name" placeholder="客户" style="width:140px" clearable @keyup.enter="load" />
				<el-select v-model="query.status" placeholder="状态" style="width:120px" clearable>
					<el-option label="草稿" value="draft" />
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
				<el-table-column prop="exchange_no" label="换货单号" width="170">
					<template #default="{ row }"><a @click="openDetail(row)">{{ row.exchange_no }}</a></template>
				</el-table-column>
				<el-table-column prop="customer_name" label="客户" min-width="140" />
				<el-table-column prop="warehouse_name" label="仓库" width="140" />
				<el-table-column prop="salesman_name" label="业务员" width="90" />
				<el-table-column prop="exchange_date" label="换货日期" width="110" align="center" />
				<el-table-column label="换出/换入" width="100" align="center"><template #default="{ row }">{{ row.total_qty_out }}/{{ row.total_qty_in }}</template></el-table-column>
				<el-table-column label="差价" width="110" align="right"><template #default="{ row }"><span class="amt" :style="{ color: row.diff_amount > 0 ? '#f5222d' : (row.diff_amount < 0 ? '#67c23a' : '#606266') }">{{ row.diff_amount > 0 ? '+' : '' }}¥{{ row.diff_amount }}</span></template></el-table-column>
				<el-table-column label="结算" width="100" align="center"><template #default="{ row }">{{ settleLabel(row) }}</template></el-table-column>
				<el-table-column label="状态" width="90" align="center"><template #default="{ row }"><el-tag :type="statusType(row.status)" size="small">{{ statusLabel(row.status) }}</el-tag></template></el-table-column>
				<el-table-column label="操作" width="220" align="center" fixed="right">
					<template #default="{ row }">
						<template v-if="row.status === 'draft'">
							<el-button link type="primary" @click="openForm(row)">编辑</el-button>
							<el-button link type="success" @click="onSubmit(row)">提交审核</el-button>
							<el-button link type="danger" @click="onDelete(row)">删除</el-button>
						</template>
						<template v-else-if="row.status === 'pending'">
							<el-button link type="success" @click="onApprove(row)">审核换货</el-button>
							<el-button link type="warning" @click="onReject(row)">驳回</el-button>
							<el-button link type="danger" @click="onCancel(row)">取消</el-button>
							<el-button link type="info" @click="openDetail(row)">查看</el-button>
						</template>
						<template v-else><el-button link type="info" @click="openDetail(row)">查看</el-button></template>
					</template>
				</el-table-column>
			</el-table>
			<el-pagination class="pager" layout="total, sizes, prev, pager, next, jumper" :total="total" :page-sizes="[10, 20, 50]" :page-size="query.page_size" :current-page="query.page" @current-change="(p) => { query.page = p; load(); }" @size-change="(s) => { query.page_size = s; load(); }" />
		</el-card>
		<ExchangeOrderForm ref="formRef" @saved="load" />
		<ExchangeOrderDetail ref="detailRef" />
		<el-dialog v-model="submitVisible" title="提交审核 · 选择差价结算方式" width="420px">
			<div v-if="submitRow">
				<p>差价金额：<b :style="{ color: submitRow.diff_amount > 0 ? '#f5222d' : (submitRow.diff_amount < 0 ? '#67c23a' : '#606266') }">{{ submitRow.diff_amount > 0 ? '+' : '' }}¥{{ submitRow.diff_amount }}</b></p>
				<el-form label-width="90px" style="margin-top:12px">
					<el-form-item :label="submitRow.diff_amount > 0 ? '收款方式' : '退款方式'">
						<el-select v-model="submitMethod" style="width:100%">
							<template v-if="submitRow.diff_amount > 0">
								<el-option label="现金" value="cash" /><el-option label="微信" value="wechat" /><el-option label="支付宝" value="alipay" /><el-option label="银行卡" value="bank" /><el-option label="挂账" value="credit" />
							</template>
							<template v-else>
								<el-option label="现金退回" value="cash_return" /><el-option label="冲抵应收" value="offset" />
							</template>
						</el-select>
					</el-form-item>
				</el-form>
			</div>
			<template #footer>
				<el-button @click="submitVisible = false">取消</el-button>
				<el-button type="primary" @click="confirmSubmit" :loading="submitting">确定提交</el-button>
			</template>
		</el-dialog>
	</div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import api from '@/api/business.js';
import ExchangeOrderForm from './exchange-order-form.vue';
import ExchangeOrderDetail from './exchange-order-detail.vue';
const list = ref([]); const total = ref(0); const loading = ref(false);
const query = reactive({ exchange_no: '', customer_name: '', status: '', start_date: '', end_date: '', page: 1, page_size: 20 });
const dateRange = ref([]); const formRef = ref(null); const detailRef = ref(null);
const submitVisible = ref(false); const submitRow = ref(null); const submitMethod = ref(''); const submitting = ref(false);
const statusLabel = (s) => ({ draft: '草稿', pending: '待审核', approved: '已审核', cancelled: '已取消' }[s] || s);
const statusType = (s) => ({ draft: 'info', pending: 'warning', approved: 'success', cancelled: 'danger' }[s] || 'info');
const settleLabel = (r) => { if ((r.diff_amount || 0) > 0.01) return r.payment_method ? ({ cash: '现金收款', wechat: '微信收款', alipay: '支付宝收款', bank: '银行卡收款', credit: '挂账' }[r.payment_method] || '客户补款') : '客户补款'; if ((r.diff_amount || 0) < -0.01) return r.refund_method ? ({ cash_return: '现金退回', offset: '冲抵应收' }[r.refund_method] || '退款') : '退款'; return '等价交换'; };
const onDateChange = (val) => { query.start_date = val?.[0] || ''; query.end_date = val?.[1] || ''; };
const load = async () => { loading.value = true; try { const res = await api.exchangeOrder.list.get(query); list.value = res.data?.list || []; total.value = res.data?.total || 0; } finally { loading.value = false; } };
const reset = () => { Object.assign(query, { exchange_no: '', customer_name: '', status: '', start_date: '', end_date: '', page: 1 }); dateRange.value = []; load(); };
const onExport = async () => { try { const blob = await api.exchangeOrder.export.get(query); triggerDownload(blob, 'exchange_orders.csv'); } catch (e) { ElMessage.error('导出失败'); } };
const triggerDownload = (blob, name) => { const url = URL.createObjectURL(blob); const a = document.createElement('a'); a.href = url; a.download = name; a.click(); URL.revokeObjectURL(url); };
const openForm = (row) => formRef.value?.open(row);
const openDetail = (row) => detailRef.value?.open(row.id);
const onSubmit = (row) => { submitRow.value = row; submitMethod.value = ''; submitVisible.value = true; };
const confirmSubmit = async () => { const r = submitRow.value; if (!r) return; try { submitting.value = true; const payload = (r.diff_amount || 0) > 0.01 ? { payment_method: submitMethod.value || 'cash' } : (r.diff_amount || 0) < -0.01 ? { refund_method: submitMethod.value || 'cash_return' } : {}; await api.exchangeOrder.submit.post(r.id, payload); ElMessage.success('已提交，等待审核'); submitVisible.value = false; load(); } catch (e) { ElMessage.error(e.response?.data?.message || '提交失败'); } finally { submitting.value = false; } };
const onApprove = (row) => { ElMessageBox.confirm('审核换货？换出商品回到仓库，换入商品出库，差价按结算方式处理。', '审核换货', { type: 'warning' }).then(async () => { await api.exchangeOrder.approve.post(row.id); ElMessage.success('换货已审核'); load(); }).catch(() => {}); };
const onReject = (row) => { ElMessageBox.prompt('驳回原因', '驳回换货', { inputType: 'textarea', inputValidator: (v) => (v && v.trim() ? true : '请填写驳回原因') }).then(async ({ value }) => { await api.exchangeOrder.reject.post(row.id, { reject_reason: value }); ElMessage.success('已驳回'); load(); }).catch(() => {}); };
const onCancel = (row) => { ElMessageBox.prompt('取消原因', '取消换货', { inputType: 'textarea', inputValidator: (v) => (v && v.trim() ? true : '请填写取消原因') }).then(async ({ value }) => { await api.exchangeOrder.cancel.post(row.id, { cancel_reason: value }); ElMessage.success('已取消'); load(); }).catch(() => {}); };
const onDelete = (row) => { ElMessageBox.confirm('确认删除？', '提示', { type: 'warning' }).then(async () => { await api.exchangeOrder.delete.delete(row.id); ElMessage.success('已删除'); load(); }).catch(() => {}); };
onMounted(() => load());
</script>
<style scoped>
.page { background: #f0f2f5; min-height: 100vh; }
.header { height: 60px; background: #fff; border-bottom: 1px solid #e8e8e8; padding: 0 24px; display: flex; align-items: center; justify-content: space-between; }
.title { font-size: 18px; font-weight: 700; } .card { margin: 16px 24px; } .filter { margin-bottom: 16px; display: flex; gap: 12px; flex-wrap: wrap; }
.amt { font-weight: 700; } .pager { margin-top: 16px; justify-content: flex-end; } a { color: #409eff; cursor: pointer; }
</style>
