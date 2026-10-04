<template>
	<div class="page">
		<div class="header">
			<span class="title">采购退货</span>
			<el-button type="warning" @click="openForm()">新增采购退货单</el-button>
		</div>
		<el-card shadow="never" class="card">
			<div class="filter">
				<el-input v-model="query.keyword" placeholder="退货单号/入库单号" style="width:200px" clearable @keyup.enter="load" />
				<el-select v-model="query.status" placeholder="状态" style="width:120px" clearable>
					<el-option label="草稿" value="draft" />
					<el-option label="待审核" value="pending" />
					<el-option label="已审核" value="approved" />
					<el-option label="已取消" value="cancelled" />
				</el-select>
				<el-button type="primary" @click="load">查询</el-button>
				<el-button @click="reset">重置</el-button>
			</div>
			<div class="toolbar">
				<el-button type="success" :disabled="selectedPending.length === 0" @click="openBatchAudit">批量审核（{{ selectedPending.length }}）</el-button>
				<el-button :loading="exporting" @click="onExport">导出</el-button>
			</div>
			<el-table :data="list" border stripe v-loading="loading" @selection-change="onSelectionChange">
				<el-table-column type="selection" width="40" :selectable="(row) => row.status === 'pending'" />
				<el-table-column prop="return_no" label="退货单号" width="170">
					<template #default="{ row }"><a @click="openDetail(row)">{{ row.return_no }}</a></template>
				</el-table-column>
				<el-table-column prop="supplier_name" label="供应商" min-width="160" />
				<el-table-column label="仓库" width="110" align="center">
					<template #default="{ row }">{{ row.warehouse?.name || '-' }}</template>
				</el-table-column>
				<el-table-column prop="total_qty" label="数量" width="80" align="center" />
				<el-table-column label="金额" width="110" align="right">
					<template #default="{ row }"><span class="amt">¥{{ row.total_amount }}</span></template>
				</el-table-column>
				<el-table-column prop="stock_in_no" label="原入库单" width="150" />
				<el-table-column label="状态" width="100" align="center">
					<template #default="{ row }">
						<el-tag :type="statusType(row.status)" size="small">{{ statusLabel(row.status) }}</el-tag>
					</template>
				</el-table-column>
				<el-table-column prop="created_at" label="制单时间" width="150" />
				<el-table-column label="操作" width="200" align="center">
					<template #default="{ row }">
						<template v-if="row.status === 'draft'">
							<el-button link type="primary" @click="openForm(row)">编辑</el-button>
							<el-button link type="warning" @click="onSubmit(row)">提交</el-button>
							<el-button link type="danger" @click="onDelete(row)">删除</el-button>
						</template>
						<template v-else-if="row.status === 'pending'">
							<el-button link type="success" @click="openAudit(row, true)">通过</el-button>
							<el-button link type="danger" @click="openAudit(row, false)">驳回</el-button>
							<el-button link info @click="openDetail(row)">查看</el-button>
						</template>
						<template v-else>
							<el-button link type="info" @click="openDetail(row)">查看</el-button>
						</template>
					</template>
				</el-table-column>
			</el-table>
			<el-pagination class="pager" layout="total, prev, pager, next" :total="total" :page-size="query.page_size" :current-page="query.page" @current-change="(p) => { query.page = p; load(); }" />
		</el-card>

		<!-- 新增/编辑 -->
		<el-dialog v-model="formVisible" :title="form.id ? '编辑采购退货单' : '新增采购退货单'" width="900px" top="5vh">
			<el-form :model="form" label-width="100px">
				<el-row :gutter="16">
					<el-col :span="8">
						<el-form-item label="供应商">
							<el-input v-model="form.supplier_name" />
						</el-form-item>
					</el-col>
					<el-col :span="8">
						<el-form-item label="仓库">
							<el-input v-model="form.warehouse_id" placeholder="仓库ID" />
						</el-form-item>
					</el-col>
					<el-col :span="8">
						<el-form-item label="退货日期">
							<el-date-picker v-model="form.return_date" value-format="YYYY-MM-DD" />
						</el-form-item>
					</el-col>
				</el-row>
				<el-row :gutter="16">
					<el-col :span="16">
						<el-form-item label="原入库单ID">
							<el-input v-model="form.stock_in_id" placeholder="选填，填后点加载商品" />
						</el-form-item>
					</el-col>
					<el-col :span="8">
						<el-button @click="loadStockInProducts">加载入库商品</el-button>
					</el-col>
				</el-row>
			</el-form>
			<el-table :data="form.items" border size="small">
				<el-table-column prop="product_code" label="编码" width="100" />
				<el-table-column prop="product_name" label="商品名称" min-width="160" />
				<el-table-column prop="unit" label="单位" width="60" align="center" />
				<el-table-column prop="original_qty" label="原入库" width="80" align="center" />
				<el-table-column prop="returned_qty" label="已退" width="70" align="center" />
				<el-table-column label="本次退货" width="120">
					<template #default="{ row }">
						<el-input-number v-model="row.return_qty" :min="1" size="small" style="width:90px" />
					</template>
				</el-table-column>
				<el-table-column label="退货单价" width="110">
					<template #default="{ row }">
						<el-input-number v-model="row.return_price" :min="0" :precision="2" size="small" style="width:100px" />
					</template>
				</el-table-column>
				<el-table-column label="金额" width="100" align="right">
					<template #default="{ row }">¥{{ (row.return_qty * row.return_price).toFixed(2) }}</template>
				</el-table-column>
				<el-table-column label="" width="50">
					<template #default="{ $index }">
						<el-button link type="danger" @click="form.items.splice($index, 1)">删</el-button>
					</template>
				</el-table-column>
			</el-table>
			<template #footer>
				<el-button @click="formVisible = false">取消</el-button>
				<el-button type="primary" @click="onSave(false)">保存草稿</el-button>
				<el-button type="warning" @click="onSave(true)">提交审核</el-button>
			</template>
		</el-dialog>

		<!-- 审核 -->
		<el-dialog v-model="auditVisible" title="审核采购退货单" width="500px">
			<el-form label-width="100px">
				<el-form-item label="审核意见">
					<el-input v-model="auditRemark" type="textarea" :rows="3" />
				</el-form-item>
			</el-form>
			<template #footer>
				<el-button @click="auditVisible = false">取消</el-button>
				<el-button type="danger" @click="onAudit(false)">驳回</el-button>
				<el-button type="success" @click="onAudit(true)">通过</el-button>
			</template>
		</el-dialog>

		<!-- 批量审核 -->
		<el-dialog v-model="batchAuditVisible" title="批量审核确认" width="450px">
			<p style="line-height:1.6;color:#666;font-size:14px">您已选择 {{ selectedPending.length }} 张待审核采购退货单，确认批量审核通过？审核后将自动执行退货出库并冲减应付，操作不可撤销。</p>
			<el-input v-model="batchRemark" type="textarea" :rows="3" placeholder="输入审核意见（可选）" style="margin-top:12px" />
			<template #footer>
				<el-button @click="batchAuditVisible = false">取消</el-button>
				<el-button type="success" :loading="batchLoading" @click="onBatchAudit">确认审核</el-button>
			</template>
		</el-dialog>

		<!-- 详情 -->
		<el-dialog v-model="detailVisible" title="采购退货单详情" width="900px">
			<el-descriptions :column="3" border size="small">
				<el-descriptions-item label="退货单号">{{ detail.return_no }}</el-descriptions-item>
				<el-descriptions-item label="供应商">{{ detail.supplier_name }}</el-descriptions-item>
				<el-descriptions-item label="状态">{{ statusLabel(detail.status) }}</el-descriptions-item>
				<el-descriptions-item label="退货日期">{{ detail.return_date }}</el-descriptions-item>
				<el-descriptions-item label="总数量">{{ detail.total_qty }}</el-descriptions-item>
				<el-descriptions-item label="总金额">¥{{ detail.total_amount }}</el-descriptions-item>
			</el-descriptions>
			<el-table :data="detail.items" border size="small" style="margin-top:12px">
				<el-table-column prop="product_name" label="商品" min-width="160" />
				<el-table-column prop="return_qty" label="退货数量" width="90" align="center" />
				<el-table-column prop="return_price" label="单价" width="90" align="right" />
				<el-table-column prop="return_amount" label="金额" width="100" align="right" />
			</el-table>
		</el-dialog>
	</div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import api from '@/api/business.js';

const list = ref([]);
const total = ref(0);
const loading = ref(false);
const query = reactive({ keyword: '', status: '', page: 1, page_size: 20 });

const formVisible = ref(false);
const form = ref({});
const auditVisible = ref(false);
const auditRow = ref(null);
const auditPass = ref(true);
const auditRemark = ref('');
const detailVisible = ref(false);
const detail = ref({ items: [] });

const selectedRows = ref([]);
const selectedPending = ref([]);
const batchAuditVisible = ref(false);
const batchRemark = ref('');
const batchLoading = ref(false);
const exporting = ref(false);

const onSelectionChange = (rows) => {
	selectedRows.value = rows;
	selectedPending.value = rows.filter((r) => r.status === 'pending');
};

const openBatchAudit = () => {
	if (selectedPending.value.length === 0) return ElMessage.warning('请选择待审核状态的单据');
	batchRemark.value = '';
	batchAuditVisible.value = true;
};

const onBatchAudit = async () => {
	batchLoading.value = true;
	try {
		const ids = selectedPending.value.map((r) => r.id);
		await api.purchaseReturn.batchApprove.post({ ids, remark: batchRemark.value });
		ElMessage.success(`成功审核 ${ids.length} 张采购退货单`);
		batchAuditVisible.value = false;
		load();
	} finally { batchLoading.value = false; }
};

const onExport = async () => {
	exporting.value = true;
	try {
		const res = await api.purchaseReturn.export(query);
		const blob = new Blob([res.data], { type: 'text/csv;charset=utf-8' });
		const url = window.URL.createObjectURL(blob);
		const a = document.createElement('a');
		a.href = url;
		a.download = `purchase_returns_${new Date().toISOString().slice(0, 10)}.csv`;
		a.click();
		window.URL.revokeObjectURL(url);
	} finally { exporting.value = false; }
};

const load = async () => {
	loading.value = true;
	try {
		const res = await api.purchaseReturn.list.get(query);
		list.value = res.data.data.data || [];
		total.value = res.data.data.total || 0;
	} finally { loading.value = false; }
};

const reset = () => { query.keyword = ''; query.status = ''; query.page = 1; load(); };

const openForm = (row) => {
	form.value = row && row.id
		? { ...row, items: row.items || [] }
		: { warehouse_id: '', return_date: new Date().toISOString().slice(0, 10), items: [] };
	formVisible.value = true;
};

const loadStockInProducts = async () => {
	if (!form.value.stock_in_id) return ElMessage.warning('请填原入库单ID');
	const res = await api.purchaseReturn.stockInProducts.get({ stock_in_id: form.value.stock_in_id });
	form.value.items = (res.data.data.items || []).map((x) => ({
		product_id: x.product_id, product_code: x.product_code, product_name: x.product_name,
		unit: x.unit, original_qty: x.original_qty, returned_qty: x.returned_qty,
		return_qty: 1, return_price: x.price,
	}));
};

const onSave = async (submit) => {
	if (!form.value.items.length) return ElMessage.warning('请添加商品');
	const payload = {
		warehouse_id: form.value.warehouse_id,
		return_date: form.value.return_date,
		supplier_id: form.value.supplier_id,
		stock_in_id: form.value.stock_in_id || null,
		items: form.value.items.map((x) => ({
			product_id: x.product_id, return_qty: x.return_qty, return_price: x.return_price,
		})),
		submit_for_approval: submit,
	};
	if (form.value.id) {
		await api.purchaseReturn.update.put(form.value.id, payload);
	} else {
		await api.purchaseReturn.create.post(payload);
	}
	ElMessage.success('已保存');
	formVisible.value = false;
	load();
};

const onSubmit = (row) => {
	ElMessageBox.confirm('确认提交审核？', '提示', { type: 'warning' })
		.then(async () => { await api.purchaseReturn.submit.post(row.id); ElMessage.success('已提交'); load(); })
		.catch(() => {});
};

const onDelete = (row) => {
	ElMessageBox.confirm('确认删除？', '提示', { type: 'warning' })
		.then(async () => { await api.purchaseReturn.delete.delete(row.id); ElMessage.success('已删除'); load(); })
		.catch(() => {});
};

const openAudit = (row, pass) => { auditRow.value = row; auditPass.value = pass; auditRemark.value = ''; auditVisible.value = true; };

const onAudit = async () => {
	const fn = auditPass.value ? api.purchaseReturn.approve : api.purchaseReturn.reject;
	await fn.post(auditRow.value.id, { approval_comment: auditRemark.value });
	ElMessage.success('已处理');
	auditVisible.value = false;
	load();
};

const openDetail = async (row) => {
	const res = await api.purchaseReturn.detail.get(row.id);
	detail.value = res.data.data;
	detailVisible.value = true;
};

const statusLabel = (s) => ({ draft: '草稿', pending: '待审核', approved: '已审核', cancelled: '已取消' }[s] || s);
const statusType = (s) => ({ draft: 'info', pending: 'warning', approved: 'success', cancelled: 'info' }[s] || 'info');

onMounted(load);
</script>

<style scoped>
.page { background: #f0f2f5; min-height: 100vh; }
.header { height: 60px; background: #fff; border-bottom: 1px solid #e8e8e8; padding: 0 24px; display: flex; align-items: center; justify-content: space-between; }
.title { font-size: 18px; font-weight: 700; color: #333; }
.card { margin: 16px 24px; border-radius: 4px; }
.filter { margin-bottom: 16px; display: flex; gap: 12px; }
.toolbar { margin-bottom: 12px; display: flex; gap: 12px; }
.amt { color: #f5222d; font-weight: 700; }
.pager { margin-top: 16px; justify-content: flex-end; }
</style>
