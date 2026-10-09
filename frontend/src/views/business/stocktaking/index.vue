<template>
	<div class="stocktaking-page">
		<!-- 标题区 -->
		<div class="page-header">
			<span class="page-title">库存盘点</span>
			<div class="header-actions">
				<el-button type="primary" @click="openForm()">
					<i class="el-icon-plus"></i>新增盘点单
				</el-button>
				<el-button @click="openLedger()">库存台账</el-button>
			</div>
		</div>

		<!-- 筛选区 -->
		<div class="filter-bar">
			<el-select v-model="searchForm.warehouse_id" placeholder="全部仓库" clearable style="width:160px">
				<el-option v-for="w in warehouses" :key="w.id" :label="w.name" :value="w.id" />
			</el-select>
			<el-select v-model="searchForm.status" placeholder="全部状态" clearable style="width:140px">
				<el-option label="待盘点" value="draft" />
				<el-option label="盘点中" value="in_progress" />
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
			<el-input v-model="searchForm.check_no" placeholder="输入盘点单号" clearable style="width:180px" @keyup.enter="search" />
			<el-button type="primary" @click="search">查询</el-button>
			<el-button @click="resetSearch">重置</el-button>
		</div>

		<!-- 列表 -->
		<div class="table-card">
			<sTable
				ref="tableRef"
				tableName="stocktaking"
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
				emptyText="暂无盘点单，点击右上角新增盘点单开始盘点"
				@pageChange="onPageChange"
				@pageSizeChange="onPageSizeChange"
			>
				<template #check_no="{ row }">
					<el-link type="primary" @click="openDetail(row)">{{ row.check_no }}</el-link>
				</template>
				<template #status="{ row }">
					<el-tag :type="statusMeta[row.status]?.type" size="small" effect="light">
						{{ statusMeta[row.status]?.label || row.status }}
					</el-tag>
				</template>
				<template #profit_qty="{ row }"><span class="num-up">{{ row.profit_qty || 0 }}</span></template>
				<template #loss_qty="{ row }"><span class="num-down">{{ row.loss_qty || 0 }}</span></template>
				<template #profit_amount="{ row }"><span class="num-up">¥{{ fmtMoney(row.profit_amount) }}</span></template>
				<template #loss_amount="{ row }"><span class="num-down">¥{{ fmtMoney(row.loss_amount) }}</span></template>
				<template #approved_by="{ row }">{{ row.approver_name || '-' }}</template>
				<template #op="{ row }">
					<el-button v-if="['draft','in_progress'].includes(row.status)" link type="primary" @click="openForm(row)">继续盘点</el-button>
					<el-button v-if="['draft','in_progress'].includes(row.status)" link type="danger" @click="remove(row)">删除</el-button>
					<el-button v-if="row.status === 'pending'" link type="success" @click="openAudit(row)">审核</el-button>
					<el-button v-if="['pending','approved','cancelled'].includes(row.status)" link type="primary" @click="openDetail(row)">查看</el-button>
				</template>
			</sTable>
		</div>

		<!-- 新增/编辑盘点单 -->
		<stocktaking-form
			v-if="formVisible"
			:visible="formVisible"
			:edit-row="editingRow"
			:warehouses="warehouses"
			@close="formVisible = false"
			@saved="onSaved"
		/>

		<!-- 审核 -->
		<stocktaking-audit
			v-if="auditVisible"
			:visible="auditVisible"
			:row="auditingRow"
			@close="auditVisible = false"
			@done="onSaved"
		/>

		<!-- 详情查看 -->
		<el-dialog v-model="detailVisible" title="盘点单详情" width="900px" top="5vh">
			<stocktaking-detail v-if="detailVisible" :id="detailId" />
		</el-dialog>

		<!-- 库存台账 -->
		<stocktaking-ledger v-model="ledgerVisible" />
	</div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import businessApi from '@/api/business'
import sTable from '@/components/sTable/index.vue'
import StocktakingForm from './stocktaking-form.vue'
import StocktakingAudit from './stocktaking-audit.vue'
import StocktakingDetail from './stocktaking-detail.vue'
import StocktakingLedger from './stocktaking-ledger.vue'

const warehouses = ref([])
const data = ref([])
const total = ref(0)
const loading = ref(false)
const currentPage = ref(1)
const pageSize = ref(20)
const tableRef = ref(null)

const searchForm = reactive({ warehouse_id: '', status: '', check_no: '' })
const dateRange = ref([])

const statusMeta = {
	draft: { label: '待盘点', type: 'warning' },
	in_progress: { label: '盘点中', type: 'primary' },
	pending: { label: '待审核', type: 'warning' },
	approved: { label: '已审核', type: 'success' },
	cancelled: { label: '已取消', type: 'info' },
}

const columns = [
	{ type: 'checkbox', width: 48, fixed: 'left' },
	{ prop: 'check_no', title: '盘点单号', width: 180, fixed: 'left', slots: { default: 'check_no' } },
	{ prop: 'warehouse_name', title: '仓库', width: 120 },
	{ prop: 'check_date', title: '盘点日期', width: 120 },
	{ prop: 'status', title: '状态', width: 100, slots: { default: 'status' } },
	{ prop: 'total_skus', title: '商品种类', width: 90 },
	{ prop: 'profit_qty', title: '盘盈数量', width: 100, slots: { default: 'profit_qty' } },
	{ prop: 'loss_qty', title: '盘亏数量', width: 100, slots: { default: 'loss_qty' } },
	{ prop: 'profit_amount', title: '盘盈金额', width: 120, slots: { default: 'profit_amount' } },
	{ prop: 'loss_amount', title: '盘亏金额', width: 120, slots: { default: 'loss_amount' } },
	{ prop: 'creator_name', title: '制单人', width: 100 },
	{ prop: 'approved_by', title: '审核人', width: 100, slots: { default: 'approved_by' } },
	{ prop: 'op', title: '操作', width: 200, fixed: 'right', slots: { default: 'op' } },
]

function fmtMoney(v) {
	return Number(v ?? 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

async function fetchData() {
	loading.value = true
	try {
		const params = { page: currentPage.value, page_size: pageSize.value }
		;['warehouse_id', 'status', 'check_no'].forEach((k) => {
			if (searchForm[k] !== '' && searchForm[k] != null) params[k] = searchForm[k]
		})
		if (dateRange.value && dateRange.value.length === 2) {
			params.start_date = dateRange.value[0]
			params.end_date = dateRange.value[1]
		}
		const res = await businessApi.stocktaking.list.get(params)
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
		const res = await businessApi.warehouse.list.get({type:"normal"})
		warehouses.value = res.data?.list || res.data || []
	} catch (e) { /* 忽略 */ }
}

function search() { currentPage.value = 1; fetchData() }
function resetSearch() {
	searchForm.warehouse_id = ''; searchForm.status = ''; searchForm.check_no = ''
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

const ledgerVisible = ref(false)
function openLedger() { ledgerVisible.value = true }

function onSaved() {
	formVisible.value = false
	auditVisible.value = false
	fetchData()
}

async function remove(row) {
	try {
		await ElMessageBox.confirm(`确认删除盘点单 ${row.check_no}？`, '提示', { type: 'warning' })
		await businessApi.stocktaking.delete.delete(row.id)
		ElMessage.success('已删除')
		fetchData()
	} catch (e) {
		if (e !== 'cancel') ElMessage.error(e?.response?.data?.message || '删除失败')
	}
}

onMounted(() => { fetchWarehouses(); fetchData() })
</script>

<style scoped>
.stocktaking-page { display: flex; flex-direction: column; height: 100%; background: #f0f2f5; padding: 16px; box-sizing: border-box; }
.page-header { height: 56px; background: #fff; border: 1px solid #e8e8e8; border-radius: 4px; display: flex; align-items: center; justify-content: space-between; padding: 0 16px; }
.page-title { font-size: 18px; font-weight: bold; color: #333; }
.header-actions { display: flex; gap: 8px; }
.filter-bar { margin-top: 12px; background: #fff; border-radius: 4px; padding: 12px 16px; display: flex; align-items: center; flex-wrap: wrap; gap: 10px; }
.table-card { margin-top: 12px; flex: 1; background: #fff; border-radius: 4px; padding: 8px; overflow: hidden; display: flex; flex-direction: column; }
.num-up { color: #52c41a; font-weight: 500; }
.num-down { color: #f5222d; font-weight: 500; }
</style>
