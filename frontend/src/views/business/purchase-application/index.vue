<template>
	<div class="page">
		<div class="header">
			<span class="title">采购申请</span>
			<el-button type="warning" @click="openForm()">新增采购申请</el-button>
		</div>
		<el-card shadow="never" class="card">
			<div class="filter">
				<el-input v-model="query.apply_no" placeholder="申请单号" style="width:180px" clearable @keyup.enter="load" />
				<el-input v-model="query.keyword" placeholder="供应商/单号" style="width:180px" clearable @keyup.enter="load" />
				<el-select v-model="query.supplier_id" placeholder="供应商" style="width:160px" filterable clearable>
					<el-option v-for="s in suppliers" :key="s.id" :label="s.name" :value="s.id" />
				</el-select>
				<el-select v-model="query.status" placeholder="状态" style="width:120px" clearable>
					<el-option label="草稿" value="draft" />
					<el-option label="待审批" value="pending" />
					<el-option label="已审批" value="approved" />
					<el-option label="已驳回" value="rejected" />
					<el-option label="已取消" value="cancelled" />
					<el-option label="已转入库" value="transferred" />
				</el-select>
				<el-date-picker v-model="dateRange" type="daterange" value-format="YYYY-MM-DD" range-separator="至" start-placeholder="开始" end-placeholder="结束" style="width:240px" @change="onDateChange" />
				<el-button type="primary" @click="load">查询</el-button>
				<el-button @click="reset">重置</el-button>
			</div>
			<div class="toolbar">
				<el-button type="warning" :disabled="selectedDraft.length === 0" @click="onBatchSubmit">批量提交（{{ selectedDraft.length }}）</el-button>
				<el-button type="success" :disabled="selectedPending.length === 0" @click="openBatchAudit">批量审批（{{ selectedPending.length }}）</el-button>
				<el-button :loading="exporting" @click="onExport">导出</el-button>
			</div>
			<el-table :data="list" border stripe v-loading="loading" @selection-change="onSelectionChange">
				<el-table-column type="selection" width="40" :selectable="(row) => ['draft', 'pending'].includes(row.status)" />
				<el-table-column prop="apply_no" label="申请单号" width="170">
					<template #default="{ row }"><a @click="openDetail(row)">{{ row.apply_no }}</a></template>
				</el-table-column>
				<el-table-column prop="supplier_name" label="供应商" min-width="140" />
				<el-table-column label="付款类型" width="90" align="center">
					<template #default="{ row }">{{ paymentLabel(row.payment_type) }}</template>
				</el-table-column>
				<el-table-column prop="expected_date" label="需用日期" width="110" align="center" />
				<el-table-column label="金额" width="110" align="right">
					<template #default="{ row }"><span class="amt">¥{{ row.total_amount }}</span></template>
				</el-table-column>
				<el-table-column label="状态" width="100" align="center">
					<template #default="{ row }">
						<el-tag :type="statusType(row.status)" size="small">{{ statusLabel(row.status) }}</el-tag>
					</template>
				</el-table-column>
				<el-table-column prop="creator_name" label="制单人" width="90" align="center" />
				<el-table-column prop="approver_name" label="审批人" width="90" align="center" />
				<el-table-column prop="created_at" label="制单时间" width="150" />
				<el-table-column label="操作" width="230" align="center" fixed="right">
					<template #default="{ row }">
						<template v-if="row.status === 'draft'">
							<el-button link type="primary" @click="openForm(row)">编辑</el-button>
							<el-button link type="warning" @click="onSubmit(row)">提交</el-button>
							<el-button link type="info" @click="onCancel(row)">取消</el-button>
							<el-button link type="danger" @click="onDelete(row)">删除</el-button>
						</template>
						<template v-else-if="row.status === 'pending'">
							<el-button link type="success" @click="openAudit(row, true)">通过</el-button>
							<el-button link type="danger" @click="openAudit(row, false)">驳回</el-button>
							<el-button link type="info" @click="onCancel(row)">取消</el-button>
							<el-button link type="info" @click="openDetail(row)">查看</el-button>
						</template>
						<template v-else-if="row.status === 'approved'">
							<el-button link type="primary" @click="onTransfer(row)">转入库</el-button>
							<el-button link type="info" @click="openDetail(row)">查看</el-button>
						</template>
						<template v-else-if="row.status === 'rejected'">
							<el-button link type="primary" @click="openForm(row)">编辑</el-button>
							<el-button link type="info" @click="openDetail(row)">查看</el-button>
						</template>
						<template v-else>
							<el-button link type="info" @click="openDetail(row)">查看</el-button>
						</template>
					</template>
				</el-table-column>
			</el-table>
			<el-pagination class="pager" layout="total, sizes, prev, pager, next, jumper" :total="total" :page-sizes="[10, 20, 50, 100]" :page-size="query.page_size" :current-page="query.page" @current-change="(p) => { query.page = p; load(); }" @size-change="(s) => { query.page_size = s; load(); }" />
		</el-card>

		<!-- 新增/编辑表单 -->
		<PurchaseApplicationForm ref="formRef" @saved="onSaved" />

		<!-- 审核 -->
		<el-dialog v-model="auditVisible" :title="auditPass ? '审批通过' : '驳回采购申请'" width="500px">
			<el-form label-width="100px">
				<el-form-item label="单号">{{ auditRow?.apply_no }}</el-form-item>
				<el-form-item label="供应商">{{ auditRow?.supplier_name }}</el-form-item>
				<el-form-item label="金额">¥{{ auditRow?.total_amount }}</el-form-item>
				<el-form-item :label="auditPass ? '审批意见' : '驳回原因'">
					<el-input v-model="auditRemark" type="textarea" :rows="3" :placeholder="auditPass ? '可选' : '必填'" />
				</el-form-item>
			</el-form>
			<template #footer>
				<el-button @click="auditVisible = false">取消</el-button>
				<el-button v-if="auditPass" type="success" @click="onAudit">通过</el-button>
				<el-button v-else type="danger" @click="onAudit">驳回</el-button>
			</template>
		</el-dialog>

		<!-- 批量审批 -->
		<el-dialog v-model="batchAuditVisible" title="批量审批确认" width="450px">
			<p style="line-height:1.6;color:#666;font-size:14px">您已选择 {{ selectedPending.length }} 张待审批采购申请，确认批量审批通过？审批通过后可转采购入库。</p>
			<el-input v-model="batchRemark" type="textarea" :rows="3" placeholder="输入审批意见（可选）" style="margin-top:12px" />
			<template #footer>
				<el-button @click="batchAuditVisible = false">取消</el-button>
				<el-button type="success" :loading="batchLoading" @click="onBatchAudit">确认审批</el-button>
			</template>
		</el-dialog>

		<!-- 详情 -->
		<el-dialog v-model="detailVisible" title="采购申请详情" width="900px">
			<el-descriptions :column="3" border size="small">
				<el-descriptions-item label="申请单号">{{ detail.apply_no }}</el-descriptions-item>
				<el-descriptions-item label="供应商">{{ detail.supplier_name }}</el-descriptions-item>
				<el-descriptions-item label="状态">{{ statusLabel(detail.status) }}</el-descriptions-item>
				<el-descriptions-item label="制单日期">{{ detail.apply_date }}</el-descriptions-item>
				<el-descriptions-item label="需用日期">{{ detail.expected_date }}</el-descriptions-item>
				<el-descriptions-item label="付款类型">{{ paymentLabel(detail.payment_type) }}</el-descriptions-item>
				<el-descriptions-item label="总数量">{{ detail.total_quantity }}</el-descriptions-item>
				<el-descriptions-item label="总金额">¥{{ detail.total_amount }}</el-descriptions-item>
				<el-descriptions-item label="审批人">{{ detail.approver_name }}</el-descriptions-item>
				<el-descriptions-item v-if="detail.transferred_at" label="转入库时间">{{ detail.transferred_at }}</el-descriptions-item>
				<el-descriptions-item v-if="detail.payable_amount" label="应付金额">¥{{ detail.payable_amount }}</el-descriptions-item>
				<el-descriptions-item v-if="detail.approval_comment" label="审批意见">{{ detail.approval_comment }}</el-descriptions-item>
			</el-descriptions>
			<el-table :data="detail.items" border size="small" style="margin-top:12px">
				<el-table-column prop="product_name" label="商品" min-width="160" />
				<el-table-column prop="spec" label="规格" width="100" />
				<el-table-column label="大" width="60" align="center">
					<template #default="{ row }">{{ row.qty_large || '-' }}</template>
				</el-table-column>
				<el-table-column label="中" width="60" align="center">
					<template #default="{ row }">{{ row.qty_medium || '-' }}</template>
				</el-table-column>
				<el-table-column label="小" width="60" align="center">
					<template #default="{ row }">{{ row.qty_small || '-' }}</template>
				</el-table-column>
				<el-table-column prop="quantity" label="折算数量" width="90" align="center" />
				<el-table-column prop="amount" label="金额" width="100" align="right" />
			</el-table>
			<div v-if="detail.operation_logs?.length" style="margin-top:12px">
				<div style="font-weight:700;margin-bottom:8px">操作记录</div>
				<el-timeline>
					<el-timeline-item v-for="log in detail.operation_logs" :key="log.id" :timestamp="log.created_at" placement="top">
						<b>{{ log.action_label }}</b> — {{ log.operator_name }} <span v-if="log.detail" style="color:#999;margin-left:8px">{{ log.detail }}</span>
					</el-timeline-item>
				</el-timeline>
			</div>
		</el-dialog>
	</div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import api from '@/api/business.js';
import PurchaseApplicationForm from './purchase-application-form.vue';

const list = ref([]);
const total = ref(0);
const loading = ref(false);
const query = reactive({ apply_no: '', keyword: '', supplier_id: '', status: '', start_date: '', end_date: '', page: 1, page_size: 20 });
const dateRange = ref([]);
const suppliers = ref([]);

const formRef = ref(null);
const auditVisible = ref(false);
const auditRow = ref(null);
const auditPass = ref(true);
const auditRemark = ref('');
const detailVisible = ref(false);
const detail = ref({ items: [] });

const selectedRows = ref([]);
const selectedDraft = ref([]);
const selectedPending = ref([]);
const batchAuditVisible = ref(false);
const batchRemark = ref('');
const batchLoading = ref(false);
const exporting = ref(false);

const onSelectionChange = (rows) => {
	selectedRows.value = rows;
	selectedDraft.value = rows.filter((r) => r.status === 'draft');
	selectedPending.value = rows.filter((r) => r.status === 'pending');
};

const onDateChange = (val) => {
	query.start_date = val?.[0] || '';
	query.end_date = val?.[1] || '';
};

const openBatchAudit = () => {
	if (selectedPending.value.length === 0) return ElMessage.warning('请选择待审批状态的单据');
	batchRemark.value = '';
	batchAuditVisible.value = true;
};

const onBatchAudit = async () => {
	batchLoading.value = true;
	try {
		const ids = selectedPending.value.map((r) => r.id);
		const res = await api.purchaseApplication.batchApprove.post({ ids, remark: batchRemark.value });
		ElMessage.success(res.message || `成功审批 ${ids.length} 张`);
		batchAuditVisible.value = false;
		load();
	} catch (e) {
		ElMessage.error(e.response?.data?.message || e.message || '批量审批失败');
	} finally { batchLoading.value = false; }
};

const onBatchSubmit = async () => {
	const ids = selectedDraft.value.map((r) => r.id);
	try {
		const res = await api.purchaseApplication.batchSubmit.post({ ids });
		ElMessage.success(res.message || `成功提交 ${ids.length} 张`);
		load();
	} catch (e) {
		ElMessage.error(e.response?.data?.message || e.message || '批量提交失败');
	}
};

const onExport = async () => {
	exporting.value = true;
	try {
		const res = await api.purchaseApplication.export(query);
		const blob = new Blob([res.data], { type: 'text/csv;charset=utf-8' });
		const url = window.URL.createObjectURL(blob);
		const a = document.createElement('a');
		a.href = url;
		a.download = `purchase_applications_${new Date().toISOString().slice(0, 10)}.csv`;
		a.click();
		window.URL.revokeObjectURL(url);
	} finally { exporting.value = false; }
};

const load = async () => {
	loading.value = true;
	try {
		const res = await api.purchaseApplication.list.get(query);
		list.value = res.data?.list || [];
		total.value = res.data?.total || 0;
	} finally { loading.value = false; }
};

const loadSuppliers = async () => {
	const res = await api.supplier.list.get({ page_size: 200 });
	suppliers.value = res.data?.list || [];
};

const reset = () => {
	query.apply_no = ''; query.keyword = ''; query.supplier_id = ''; query.status = '';
	query.start_date = ''; query.end_date = ''; query.page = 1;
	dateRange.value = [];
	load();
};

const openForm = (row) => {
	formRef.value?.open(row);
};

const onSaved = () => { load(); };

const onSubmit = (row) => {
	if (!row.total_quantity || Number(row.total_quantity) <= 0) return ElMessage.warning('明细数量不能为空，请先编辑补充商品');
	ElMessageBox.confirm('确认提交审批？', '提示', { type: 'warning' })
		.then(async () => { await api.purchaseApplication.submit.post(row.id); ElMessage.success('已提交'); load(); })
		.catch(() => {});
};

const onDelete = (row) => {
	ElMessageBox.confirm('确认删除该采购申请？', '提示', { type: 'warning' })
		.then(async () => { await api.purchaseApplication.delete.delete(row.id); ElMessage.success('已删除'); load(); })
		.catch(() => {});
};

const onCancel = (row) => {
	ElMessageBox.confirm(`确认取消采购申请 ${row.apply_no}？取消后不可恢复。`, '取消确认', { type: 'warning', confirmButtonText: '确认取消', cancelButtonText: '返回' })
		.then(async () => { await api.purchaseApplication.cancel.post(row.id); ElMessage.success('已取消'); load(); })
		.catch(() => {});
};

const openAudit = (row, pass) => {
	auditRow.value = row; auditPass.value = pass; auditRemark.value = '';
	auditVisible.value = true;
};

const onAudit = async () => {
	if (!auditPass.value && !auditRemark.value.trim()) return ElMessage.warning('驳回必须填写原因');
	const fn = auditPass.value ? api.purchaseApplication.approve : api.purchaseApplication.reject;
	await fn.post(auditRow.value.id, { approval_comment: auditRemark.value });
	ElMessage.success('已处理');
	auditVisible.value = false;
	load();
};

const onTransfer = (row) => {
	ElMessageBox.confirm(`确认将采购申请 ${row.apply_no} 转采购入库？转入库后库存增加，供应商应付增加 ¥${row.total_amount}，操作不可撤销。`, '转采购入库确认', { type: 'warning', confirmButtonText: '确认转入库', cancelButtonText: '取消' })
		.then(async () => {
			const res = await api.purchaseApplication.transfer.post(row.id);
			ElMessage.success(res.data.message || '已转入库');
			load();
		})
		.catch(() => {});
};

const openDetail = async (row) => {
	const res = await api.purchaseApplication.detail.get(row.id);
	detail.value = res.data;
	detailVisible.value = true;
};

const statusLabel = (s) => ({ draft: '草稿', pending: '待审批', approved: '已审批', rejected: '已驳回', cancelled: '已取消', transferred: '已转入库' }[s] || s);
const statusType = (s) => ({ draft: 'info', pending: 'warning', approved: 'success', rejected: 'danger', cancelled: 'info', transferred: 'primary' }[s] || 'info');
const paymentLabel = (t) => ({ cash: '现金', transfer: '转账', monthly: '月结', other: '其他' }[t] || t);

onMounted(() => { load(); loadSuppliers(); });
</script>

<style scoped>
.page { background: #f0f2f5; min-height: 100vh; }
.header { height: 60px; background: #fff; border-bottom: 1px solid #e8e8e8; padding: 0 24px; display: flex; align-items: center; justify-content: space-between; }
.title { font-size: 18px; font-weight: 700; color: #333; }
.card { margin: 16px 24px; border-radius: 4px; }
.filter { margin-bottom: 16px; display: flex; gap: 12px; flex-wrap: wrap; }
.toolbar { margin-bottom: 12px; display: flex; gap: 12px; }
.amt { color: #f5222d; font-weight: 700; }
.pager { margin-top: 16px; justify-content: flex-end; }
</style>
