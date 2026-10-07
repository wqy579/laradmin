<template>
	<div class="stock-adjust-page">
		<!-- 标题区 -->
		<div class="page-header">
			<span class="page-title">库存调整</span>
			<div class="header-actions">
				<el-button type="success" :disabled="selectedPending.length === 0" @click="openBatchAudit">
					批量审核（{{ selectedPending.length }}）
				</el-button>
				<el-button :loading="exporting" @click="onExport">导出</el-button>
				<el-button type="primary" @click="openForm()">
					<i class="el-icon-plus"></i>新增调整单
				</el-button>
			</div>
		</div>

		<!-- 筛选区 -->
		<div class="filter-bar">
			<el-select v-model="searchForm.warehouse_id" placeholder="全部仓库" clearable style="width:160px">
				<el-option v-for="w in warehouses" :key="w.id" :label="w.name" :value="w.id" />
			</el-select>
			<el-select v-model="searchForm.adjust_type" placeholder="全部类型" clearable style="width:140px">
				<el-option label="库存损耗" value="stock_loss" />
				<el-option label="库存溢余" value="stock_gain" />
				<el-option label="其他" value="other" />
			</el-select>
			<el-select v-model="searchForm.status" placeholder="全部状态" clearable style="width:140px">
				<el-option label="草稿" value="draft" />
				<el-option label="待审核" value="pending" />
				<el-option label="已审核" value="approved" />
				<el-option label="已取消" value="cancelled" />
			</el-select>
			<el-date-picker
				v-model="dateRange"
				type="daterange"
				range-separator="至"
				start-placeholder="开始日期"
				end-placeholder="结束日期"
				value-format="YYYY-MM-DD"
				style="width:260px"
			/>
			<el-input v-model="searchForm.adjust_no" placeholder="输入调整单号" clearable style="width:180px" @keyup.enter="search" />
			<el-button type="primary" @click="search">查询</el-button>
			<el-button @click="resetSearch">重置</el-button>
		</div>

		<!-- 列表 -->
		<div class="table-card">
			<sTable
				ref="tableRef"
				tableName="stock_adjust"
				:data="data"
				:columns="columns"
				:loading="loading"
				:total="total"
				:currentPage="currentPage"
				:pageSize="pageSize"
				:pageSizes="[10,20,50,100]"
				height="100%"
				stripe
				show-pagination
				:checkbox-config="{ checkMethod: ({ row }) => row.status === 'pending' }"
				emptyText="暂无调整单，点击右上角新增调整单"
				@pageChange="onPageChange"
				@pageSizeChange="onPageSizeChange"
				@selectionChange="onSelectionChange"
			>
				<template #adjust_no="{ row }">
					<el-link type="primary" @click="openDetail(row)">{{ row.adjust_no }}</el-link>
				</template>
				<template #adjust_type="{ row }">
					<el-tag :type="typeMeta[row.adjust_type]?.type" size="small" effect="light">
						{{ typeMeta[row.adjust_type]?.label || row.adjust_type }}
					</el-tag>
				</template>
				<template #status="{ row }">
					<el-tag :type="statusMeta[row.status]?.type" size="small" effect="light">
						{{ statusMeta[row.status]?.label || row.status }}
					</el-tag>
				</template>
				<template #total_qty="{ row }">
					<span :class="qtyClass(row.total_qty)">{{ qtyFormat(row.total_qty) }}</span>
				</template>
				<template #total_amount="{ row }">
					<span :class="qtyClass(row.total_qty)">¥{{ fmtMoney(Math.abs(row.total_amount)) }}</span>
				</template>
				<template #op="{ row }">
					<el-button v-if="row.status === 'draft'" link type="primary" @click="openForm(row)">编辑</el-button>
					<el-button v-if="row.status === 'draft'" link type="danger" @click="remove(row)">删除</el-button>
					<el-button v-if="row.status === 'draft'" link type="success" @click="submit(row)">提交</el-button>
					<el-button v-if="row.status === 'pending'" link type="success" @click="openAudit(row)">审核</el-button>
					<el-button v-if="['draft','pending'].includes(row.status)" link type="warning" @click="docancel(row)">取消</el-button>
					<el-button v-if="['pending','approved','cancelled'].includes(row.status)" link type="primary" @click="openDetail(row)">查看</el-button>
				</template>
			</sTable>
		</div>

		<!-- 新增/编辑 -->
		<stock-adjust-form
			v-if="formVisible"
			:visible="formVisible"
			:edit-row="editingRow"
			:warehouses="warehouses"
			@close="formVisible = false"
			@saved="onSaved"
		/>

		<!-- 审核 -->
		<stock-adjust-audit
			v-if="auditVisible"
			:visible="auditVisible"
			:row="auditingRow"
			@close="auditVisible = false"
			@done="onSaved"
		/>

		<!-- 详情查看 -->
		<el-dialog v-model="detailVisible" title="库存调整单详情" width="900px" top="5vh">
			<stock-adjust-detail v-if="detailVisible" :id="detailId" />
		</el-dialog>

		<!-- 批量审核 -->
		<el-dialog v-model="batchVisible" title="批量审核确认" width="480px">
			<p style="line-height:1.6;color:#666;font-size:14px">
				您已选择 <b>{{ selectedPending.length }}</b> 张待审核调整单，确认批量审核通过？
				通过后将逐一调整库存并生成财务凭证，任一单据失败将整体回滚，操作不可撤销。
			</p>
			<el-input v-model="batchComment" type="textarea" :rows="3" placeholder="输入审核意见（可选）" style="margin-top:12px" />
			<template #footer>
				<el-button @click="batchVisible = false">取消</el-button>
				<el-button type="success" :loading="batchLoading" @click="doBatchApprove">确认审核</el-button>
			</template>
		</el-dialog>
	</div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import businessApi from '@/api/business'
import sTable from '@/components/sTable/index.vue'
import StockAdjustForm from './stock-adjust-form.vue'
import StockAdjustAudit from './stock-adjust-audit.vue'
import StockAdjustDetail from './stock-adjust-detail.vue'

const warehouses = ref([])
const data = ref([])
const total = ref(0)
const loading = ref(false)
const currentPage = ref(1)
const pageSize = ref(20)
const tableRef = ref(null)

const searchForm = reactive({ warehouse_id: '', adjust_type: '', status: '', adjust_no: '' })
const dateRange = ref([])

const typeMeta = {
	stock_loss: { label: '库存损耗', type: 'danger' },
	stock_gain: { label: '库存溢余', type: 'success' },
	other: { label: '其他', type: 'info' },
}

const statusMeta = {
	draft: { label: '草稿', type: 'info' },
	pending: { label: '待审核', type: 'warning' },
	approved: { label: '已审核', type: 'success' },
	cancelled: { label: '已取消', type: 'info' },
}

const columns = [
	{ type: 'checkbox', width: 50 },
	{ prop: 'adjust_no', title: '调整单号', width: 180, slots: { default: 'adjust_no' } },
	{ prop: 'warehouse_name', title: '仓库', width: 120 },
	{ prop: 'adjust_date', title: '调整日期', width: 120 },
	{ prop: 'adjust_type', title: '调整类型', width: 100, slots: { default: 'adjust_type' } },
	{ prop: 'total_qty', title: '调整数量', width: 120, align: 'right', slots: { default: 'total_qty' } },
	{ prop: 'total_amount', title: '调整金额', width: 120, align: 'right', slots: { default: 'total_amount' } },
	{ prop: 'reason', title: '调整原因', width: 200, showOverflowTooltip: true },
	{ prop: 'status', title: '状态', width: 100, slots: { default: 'status' } },
	{ prop: 'creator_name', title: '创建人', width: 100 },
	{ prop: 'approver_name', title: '审核人', width: 100 },
	{ prop: 'op', title: '操作', width: 200, fixed: 'right', slots: { default: 'op' } },
]

function qtyFormat(v) {
	const n = Number(v || 0)
	return n > 0 ? `+${n}` : `${n}`
}

function qtyClass(v) {
	const n = Number(v || 0)
	return n > 0 ? 'num-up' : n < 0 ? 'num-down' : 'num-zero'
}

function fmtMoney(v) {
	return Number(v ?? 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

async function fetchData() {
	loading.value = true
	try {
		const params = { page: currentPage.value, page_size: pageSize.value }
		;['warehouse_id', 'adjust_type', 'status', 'adjust_no'].forEach((k) => {
			if (searchForm[k] !== '' && searchForm[k] != null) params[k] = searchForm[k]
		})
		if (dateRange.value && dateRange.value.length === 2) {
			params.start_date = dateRange.value[0]
			params.end_date = dateRange.value[1]
		}
		const res = await businessApi.stockAdjust.list.get(params)
		const d = res.data || {}
		data.value = (d.list || []).map((r) => ({ ...r, warehouse_name: r.warehouse?.name || '-' }))
		total.value = d.total || 0
	} catch (e) {
		ElMessage.error(e?.response?.data?.message || '数据加载失败')
	} finally {
		loading.value = false
	}
}

async function fetchWarehouses() {
	try {
		const res = await businessApi.warehouse.list.get({})
		warehouses.value = res.data?.list || res.data || []
	} catch (e) { /* 忽略 */ }
}

function search() { currentPage.value = 1; fetchData() }
function resetSearch() {
	searchForm.warehouse_id = ''; searchForm.adjust_type = ''; searchForm.status = ''; searchForm.adjust_no = ''
	dateRange.value = []; search()
}
function onPageChange(p) { currentPage.value = p; fetchData() }
function onPageSizeChange(s) { pageSize.value = s; currentPage.value = 1; fetchData() }

// ---- 弹窗 ----
const formVisible = ref(false)
const editingRow = ref(null)
function openForm(row) {
	editingRow.value = row || null
	formVisible.value = true
}

const auditVisible = ref(false)
const auditingRow = ref(null)
function openAudit(row) {
	auditingRow.value = row
	auditVisible.value = true
}

const detailVisible = ref(false)
const detailId = ref(null)
function openDetail(row) {
	detailId.value = row.id
	detailVisible.value = true
}

function onSaved() {
	formVisible.value = false
	auditVisible.value = false
	fetchData()
}

// ---- 导出（沿用当前筛选条件）----
const exporting = ref(false)
async function onExport() {
	exporting.value = true
	try {
		const params = new URLSearchParams()
		;['warehouse_id', 'adjust_type', 'status', 'adjust_no'].forEach((k) => {
			if (searchForm[k] !== '' && searchForm[k] != null) params.set(k, searchForm[k])
		})
		if (dateRange.value && dateRange.value.length === 2) {
			params.set('start_date', dateRange.value[0])
			params.set('end_date', dateRange.value[1])
		}
		const token = localStorage.getItem('laradmin_token') || localStorage.getItem('token')
		const res = await fetch(`/admin/business/stock-adjust/export?${params}`, {
			headers: { Authorization: `Bearer ${token}` },
		})
		if (!res.ok) throw new Error(`HTTP ${res.status}`)
		const blob = await res.blob()
		const url = URL.createObjectURL(blob)
		const a = document.createElement('a')
		a.href = url
		a.download = `库存调整单_${new Date().toISOString().slice(0, 10)}.csv`
		a.click()
		URL.revokeObjectURL(url)
		ElMessage.success('导出成功')
	} catch (e) {
		ElMessage.error('导出失败')
	} finally {
		exporting.value = false
	}
}

// ---- 批量审核 ----
const selectedRows = ref([])
const selectedPending = computed(() => selectedRows.value.filter((r) => r.status === 'pending'))
function onSelectionChange(rows) {
	selectedRows.value = rows || []
}

const batchVisible = ref(false)
const batchComment = ref('')
const batchLoading = ref(false)
function openBatchAudit() {
	batchComment.value = ''
	batchVisible.value = true
}

async function doBatchApprove() {
	const ids = selectedPending.value.map((r) => r.id)
	if (!ids.length) {
		ElMessage.warning('请勾选待审核的调整单')
		return
	}
	batchLoading.value = true
	try {
		const res = await businessApi.stockAdjust.batchApprove.post({ ids, approval_comment: batchComment.value })
		const d = res.data || {}
		const skipped = d.skipped || []
		ElMessage.success(`批量审核完成：成功 ${d.approved || 0} 张${skipped.length ? `，跳过 ${skipped.length} 张` : ''}`)
		batchVisible.value = false
		selectedRows.value = []
		tableRef.value?.clearCheckboxRow?.()
		fetchData()
	} catch (e) {
		ElMessage.error(e?.response?.data?.message || '批量审核失败')
	} finally {
		batchLoading.value = false
	}
}

async function submit(row) {
	try {
		await ElMessageBox.confirm(`确认提交调整单 ${row.adjust_no}？`, '提示', { type: 'warning' })
		await businessApi.stockAdjust.submit.post(row.id)
		ElMessage.success('已提交审核')
		fetchData()
	} catch (e) {
		if (e !== 'cancel') ElMessage.error(e?.response?.data?.message || '提交失败')
	}
}

async function remove(row) {
	try {
		await ElMessageBox.confirm(`确认删除调整单 ${row.adjust_no}？`, '提示', { type: 'warning' })
		await businessApi.stockAdjust.delete.delete(row.id)
		ElMessage.success('已删除')
		fetchData()
	} catch (e) {
		if (e !== 'cancel') ElMessage.error(e?.response?.data?.message || '删除失败')
	}
}

async function docancel(row) {
	try {
		await ElMessageBox.confirm(`确认取消调整单 ${row.adjust_no}？`, '提示', { type: 'warning' })
		await businessApi.stockAdjust.cancel.post(row.id)
		ElMessage.success('已取消')
		fetchData()
	} catch (e) {
		if (e !== 'cancel') ElMessage.error(e?.response?.data?.message || '取消失败')
	}
}

onMounted(() => { fetchWarehouses(); fetchData() })
</script>

<style scoped>
.stock-adjust-page { display: flex; flex-direction: column; height: 100%; background: #f0f2f5; padding: 16px; box-sizing: border-box; }
.page-header { height: 56px; background: #fff; border: 1px solid #e8e8e8; border-radius: 4px; display: flex; align-items: center; justify-content: space-between; padding: 0 16px; }
.page-title { font-size: 18px; font-weight: bold; color: #333; }
.header-actions { display: flex; gap: 8px; }
.filter-bar { margin-top: 12px; background: #fff; border-radius: 4px; padding: 12px 16px; display: flex; align-items: center; flex-wrap: wrap; gap: 10px; }
.table-card { margin-top: 12px; flex: 1; background: #fff; border-radius: 4px; padding: 8px; overflow: hidden; display: flex; flex-direction: column; }
.num-up { color: #52c41a; font-weight: 500; }
.num-down { color: #f5222d; font-weight: 500; }
.num-zero { color: #999; }
</style>
