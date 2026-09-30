<template>
	<div class="sales-order-page">
		<aside class="summary-aside">
			<OrderSummary :summary="summary" :activeFilter="activeFilter" @filter="onSummaryFilter" />
		</aside>
		<div class="biz-list">
			<!-- 顶部状态 tab + 快捷筛选 -->
			<div class="status-tabs">
				<div v-for="t in statusTabs" :key="t.value" :class="['status-tab', { active: searchForm.status_tab === t.value }]" @click="pickStatusTab(t.value)">
					{{ t.label }}
					<span v-if="t.count > 0" class="tab-badge">{{ t.count }}</span>
				</div>
				<div class="quick-filters">
					<label v-for="q in quickFilters" :key="q.value" :class="['quick-label', { active: searchForm.quick_filter === q.value }]">
						<input type="radio" :value="q.value" v-model="searchForm.quick_filter" @change="doSearch" />{{ q.label }}{{ q.count > 0 ? q.count : '' }}
					</label>
				</div>
			</div>
			<div class="toolbar"><div class="right-panel"><el-button type="primary" @click="handleAdd">新增订单</el-button></div></div>
			<sTable ref="tableRef" tableName="business_sales_order" :data="data" :columns="columns" :searchForm="searchForm"
				:loading="loading" :total="total" :currentPage="paginationProps.currentPage" :pageSize="paginationProps.pageSize"
				:pageSizes="paginationProps.pageSizes" rowKey="id" height="100%" stripe
				@refresh="refresh" @search="doSearch" @pageChange="handlePageChange" @pageSizeChange="handlePageSizeChange">
				<template #order_no_default="{ row }">
					<span :title="row.order_no">{{ (row.order_no || '').slice(-6) }}</span>
				</template>
				<template #customer_default="{ row }">
					<div class="customer-cell" @mouseenter="row._hover = true" @mouseleave="row._hover = false">
						<div class="customer-name-row">
							<el-tag :type="row.is_return ? 'danger' : 'primary'" size="small" effect="plain">{{ row.is_return ? '退货' : '普通' }}</el-tag>
							<span class="customer-name">{{ row.customer_name || row.customer?.name }}</span>
						</div>
						<!-- 地址区：hover 时被操作按钮覆盖 -->
						<div v-if="row._hover" class="customer-actions">
							<el-button type="primary" link size="small" @click="handleEdit(row)">编辑</el-button>
							<el-button type="primary" link size="small" @click="handlePrint(row)">直接打印</el-button>
							<el-button v-if="nextStatus(row.status)" type="success" link size="small" @click="handleAdvance(row)">{{ nextStatus(row.status) }}</el-button>
							<el-button v-if="row.status==='配送中'" type="warning" link size="small" @click="handleAdvance(row, '待收款')">待收款</el-button>
							<el-button v-if="row.status==='pending'" type="danger" link size="small" @click="handleCancel(row)">作废</el-button>
						</div>
						<div v-else class="customer-addr-row">
							<span v-if="row.has_gift" class="tag-gift" title="含赠品">赠</span>
							<span v-if="row.has_special" class="tag-special" title="变价">变</span>
							<span v-if="row.customer?.address" class="customer-addr">{{ row.customer.address }}</span>
							<span class="print-status" :class="{ unprinted: !row.print_count }">{{ row.print_count ? '打印' + row.print_count + '次' : '未打印' }}</span>
						</div>
					</div>
				</template>
				<template #customer_category_default="{ row }">
					<span>{{ row.customer?.category || row.customer?.route_label || '--' }}</span>
				</template>
				<template #status_default="{ row }">
					<el-tag :type="statusType(row.status)" size="small">{{ statusLabel(row.status) }}</el-tag>
				</template>
				<template #total_amount_default="{ row }">
					<span>¥{{ Number(row.total_amount || 0).toFixed(2) }}</span>
				</template>
			</sTable>
		</div>
	</div>
	<SalesOrderDialog v-if="dialog.order" v-model:visible="dialog.order" :orderType="orderType" :record="currentOrder" :customers="customers" :suppliers="suppliers" :warehouses="warehouses" :salesmen="salesmen" @success="doRefresh" />
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { useTable } from '@/hooks/useTable'
import businessApi from '@/api/business'
import SalesOrderDialog from '../components/sales-order-dialog.vue'
import OrderSummary from './components/order-summary.vue'

const searchForm = ref({ keyword: '', status: null, customer_id: null, salesman_id: null, vehicle_id: null, route_id: null, status_tab: '5', quick_filter: -1 })

// 顶部状态 tab（新系统只有 pending，1-4 都映射 pending，5 全部）
const statusTabs = ref([
	{ value: '1', label: '待配货', count: 0 },
	{ value: '2', label: '待调度', count: 0 },
	{ value: '3', label: '待配送', count: 0 },
	{ value: '4', label: '已发货收款', count: 0 },
	{ value: '5', label: '全部单据', count: 0 },
])
// 快捷筛选
const quickFilters = ref([
	{ value: -1, label: '全部 ', count: 0 },
	{ value: 0, label: '未打印', count: 0 },
	{ value: 1, label: '变价', count: 0 },
	{ value: 2, label: '含赠品', count: 0 },
	{ value: 3, label: '含备注', count: 0 },
])
const pickStatusTab = (v) => { searchForm.value.status_tab = v; doSearch() }
const { tableRef, data, total, loading, paginationProps, refresh, search, handlePageChange, handlePageSizeChange } = useTable({
	apiObj: { get: (params) => businessApi.salesOrder.list.get(params) },
	searchForm: searchForm.value,
})

const columns = [
	{ type: 'checkbox', width: 48, fixed: 'left' },
	{ prop: 'order_no', title: '订单编号', width: 100, slots: { default: 'order_no_default' } },
	{ prop: 'customer_name', title: '客户', width: 200, slots: { default: 'customer_default' } },
	{ prop: 'salesman_name', title: '业务员', width: 90 },
	{ prop: 'customer_category', title: '客户类别', width: 100, slots: { default: 'customer_category_default' } },
	{ prop: 'operator_name', title: '操作人', width: 90 },
	{ prop: 'warehouse_name', title: '仓库', width: 100 },
	{ prop: 'order_date', title: '日期', width: 100 },
	{ prop: 'total_qty', title: '数量', width: 70, align: 'center' },
	{ prop: 'total_amount', title: '金额', width: 90, align: 'right', slots: { default: 'total_amount_default' } },
	{ prop: 'status', title: '状态', width: 90, align: 'center', slots: { default: 'status_default' } },
]

const dialog = reactive({ order: false })
const currentOrder = ref(null)
const customers = ref([])
const suppliers = ref([])
const warehouses = ref([])
const salesmen = ref([])
const orderType = ref('normal')

// 左侧汇总面板：跟随右侧搜索条件统计，点项联动过滤
const summary = ref({ totals: {}, bySalesman: [], byVehicle: [], byRoute: [] })
const activeFilter = ref({ salesman: null, vehicle: null, route: null })
const loadSummary = async () => {
	const res = await businessApi.salesOrder.summary.get(searchForm.value)
	if (res.code === 200) summary.value = res.data || {}
}
const statusCounts = ref({})
const loadStatusCounts = async () => {
	// 跟随当前搜索条件（除 status_tab）统计各状态数
	const params = { ...searchForm.value, status_tab: undefined }
	const res = await businessApi.salesOrder.list.get({ ...params, page: 1, page_size: 1 })
	if (res.code === 200) {
		const c = res.data?.status_counts || {}
		statusCounts.value = c
		statusTabs.value[0].count = c.pending || 0
		statusTabs.value[1].count = c['配货中'] || 0
		statusTabs.value[2].count = c['待配送'] || 0
		statusTabs.value[3].count = c['配送中'] || 0
		statusTabs.value[4].count = c.all || 0
	}
}
const doSearch = (...args) => { search(...args); loadSummary(); loadStatusCounts() }
const doRefresh = () => { refresh(); loadSummary(); loadStatusCounts() }
const onSummaryFilter = ({ type, id }) => {
	const field = type === 'salesman' ? 'salesman_id' : type === 'vehicle' ? 'vehicle_id' : 'route_id'
	searchForm.value[field] = id
	activeFilter.value[type] = id
	search()
	loadSummary()
}

const statusType = (s) => ({ pending: 'warning', '配货中': 'primary', '待配送': 'info', '配送中': 'primary', '已收款': 'success', '待收款': 'danger', cancelled: 'info' }[s] || 'info')
const statusLabel = (s) => ({ pending: '待配货', '配货中': '配货中', '待配送': '待配送', '配送中': '配送中', '已收款': '已收款', '待收款': '待收款', cancelled: '已取消' }[s] || s)
// 下一状态：用于显示流转按钮
const nextStatus = (s) => ({ pending: '配货中', '配货中': '待配送', '待配送': '配送中', '配送中': '已收款' }[s] || null)

const handleAdd = () => { currentOrder.value = null; orderType.value = 'normal'; dialog.order = true }
const handleEdit = (row) => { currentOrder.value = row; dialog.order = true }
const handleDelete = async (row) => {
	const res = await businessApi.salesOrder.delete.delete(row.id)
	if (res.code === 200) { ElMessage.success('删除成功'); doRefresh() }
}
const handleAdvance = async (row, target) => {
	try {
		const res = await businessApi.salesOrder.approve.post(row.id, target ? { target } : {})
		if (res.code === 200) { ElMessage.success(res.message || '状态已更新'); doRefresh() }
		else ElMessage.error(res.message || '操作失败')
	} catch (e) {
		ElMessage.error(e?.message || '操作失败')
	}
}
const handleCancel = async (row) => {
	try {
		await ElMessageBox.confirm('确定作废该订单吗？', '提示', { type: 'warning' })
	} catch { return }
	const res = await businessApi.salesOrder.cancel.post(row.id)
	if (res.code === 200) { ElMessage.success('作废成功'); doRefresh() }
}
const handlePrint = async (row) => {
	const res = await businessApi.salesOrder.print.post(row.id)
	if (res.code === 200) {
		ElMessage.success('已记录打印')
		// 触发浏览器打印
		window.open('about:blank', '_blank')
		doRefresh()
	}
}

onMounted(() => {
	Promise.all([
		businessApi.customer.list.get({ page_size: 9999 }).then(r => { if (r.code === 200) customers.value = r.data?.list || [] }),
		businessApi.warehouse.list.get({ page_size: 9999 }).then(r => { if (r.code === 200) warehouses.value = r.data?.list || [] }),
		businessApi.employee.list.get({ is_active: 1, page_size: 9999 }).then(r => { if (r.code === 200) salesmen.value = r.data?.list || [] }),
		businessApi.supplier.list.get({ page_size: 9999 }).then(r => { if (r.code === 200) suppliers.value = r.data?.list || [] }),
	]).finally(() => { refresh(); loadSummary(); loadStatusCounts() })
})
</script>

<style scoped>
.sales-order-page { display: flex; height: 100%; min-height: 0; }
.summary-aside { width: 300px; flex-shrink: 0; border-right: 1px solid var(--el-border-color); overflow: hidden; }

/* 顶部状态 tab + 快捷筛选 */
.status-tabs { display: flex; align-items: center; border-bottom: 1px solid var(--el-border-color); padding: 0 8px; flex-wrap: wrap; gap: 4px; }
.status-tab { position: relative; padding: 6px 12px; cursor: pointer; font-size: 13px; color: var(--el-text-color-regular); border: 1px solid transparent; border-bottom: none; }
.status-tab.active { color: var(--el-color-primary); font-weight: 600; border-color: var(--el-border-color); border-bottom-color: var(--el-bg-color); background: var(--el-bg-color); margin-bottom: -1px; }
.tab-badge { position: absolute; top: 2px; right: 2px; background: #FF6400; color: #fff; font-size: 10px; font-weight: bold; border-radius: 8px; padding: 0 4px; line-height: 14px; }
.quick-filters { display: flex; gap: 8px; margin-left: 20px; }
.quick-label { font-size: 12px; cursor: pointer; padding: 2px 6px; border-radius: 3px; }
.quick-label.active { background: var(--el-color-primary-light-9); color: var(--el-color-primary); }
.quick-label input { display: none; }

/* 客户列：名称+地址，hover 显示操作覆盖 */
.customer-cell { position: relative; }
.customer-name-row { display: flex; align-items: center; gap: 4px; }
.customer-name { font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.customer-addr { font-size: 11px; color: var(--el-text-color-secondary); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.customer-addr-empty { color: var(--el-text-color-placeholder); }
.customer-actions { display: flex; align-items: center; gap: 2px; flex-wrap: wrap; }
.customer-addr-row { display: flex; align-items: center; gap: 3px; }
.tag-gift { background: #ec971f; color: #fff; font-size: 10px; padding: 0 3px; border-radius: 2px; }
.tag-special { background: #f56c6c; color: #fff; font-size: 10px; padding: 0 3px; border-radius: 2px; }
.print-status { font-size: 10px; color: var(--el-text-color-secondary); margin-left: 4px; white-space: nowrap; }
.print-status.unprinted { color: var(--el-color-danger); }
</style>
