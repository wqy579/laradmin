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
			<div class="toolbar">
				<div class="left-panel">
					<!-- 日期筛选 -->
					<el-radio-group v-model="searchForm.quick_date" size="small" @change="doSearch">
						<el-radio-button value="">全部</el-radio-button>
						<el-radio-button value="today">今天</el-radio-button>
						<el-radio-button value="yesterday">昨天</el-radio-button>
						<el-radio-button value="week">近7天</el-radio-button>
					</el-radio-group>
					<el-date-picker v-model="dateRange" type="daterange" range-separator="至" start-placeholder="开始" end-placeholder="结束" size="small" style="width:220px" value-format="YYYY-MM-DD" @change="onDateRange" />
					<el-input v-model="searchForm.keyword" placeholder="单号/客户/业务员" clearable size="small" style="width:180px" @keyup.enter="doSearch" @clear="doSearch" />
					<el-button size="small" type="primary" @click="doSearch">查询</el-button>
				</div>
				<div class="right-panel"><el-button type="primary" @click="handleAdd">新增订单</el-button></div>
			</div>
			<!-- 多选统计条 -->
			<div v-if="selectedRows.length" class="select-bar">
				<span>已选 {{ selectedRows.length }} 条</span>
				<span class="sep">|</span>
				<span>总金额: <b style="color:#FF6400;font-size:14px">{{ selectedTotal }}</b> 元</span>
				<span class="sep">|</span>
				<span>大: <b>{{ selectedQty.lg }}</b> 中: <b>{{ selectedQty.md }}</b> 小: <b>{{ selectedQty.sm }}</b></span>
				<span class="sep">|</span>
				<!-- 按 status_tab 显示不同操作 -->
				<template v-if="searchForm.status_tab === '1'">
					<el-button size="small" type="primary" @click="batchAdvance('配货中')">配货</el-button>
					<el-button size="small" @click="batchPrint">打印</el-button>
				</template>
				<template v-else-if="searchForm.status_tab === '2'">
					<el-button size="small" type="primary" @click="batchAdvance('待调度')">完成配货</el-button>
					<el-button size="small" @click="batchPrint">打印</el-button>
				</template>
				<template v-else-if="searchForm.status_tab === '6'">
					<el-button size="small" type="primary" @click="batchDispatch">调度</el-button>
					<el-button size="small" @click="batchPrint">打印备货单</el-button>
					<el-button size="small" @click="batchUnfreeze">撤销冻结</el-button>
				</template>
				<template v-else-if="searchForm.status_tab === '3'">
					<el-button size="small" type="success" @click="batchReceive('现金')">现金收款</el-button>
					<el-button size="small" type="warning" @click="batchReceive('应收')">应收收款</el-button>
					<el-button size="small" @click="batchReverse">撤销配送</el-button>
					<el-button size="small" @click="batchPrint">打印配送单</el-button>
					<el-button size="small" @click="batchPrint">打印装车单</el-button>
				</template>
				<template v-else-if="searchForm.status_tab === '7'">
					<el-button size="small" type="primary" @click="batchAdvance('已收款')">完成配送</el-button>
					<el-button size="small" @click="batchPrint">打印</el-button>
				</template>
				<template v-else-if="searchForm.status_tab === '4'">
					<el-button size="small" type="danger" @click="batchRedFlush('撤单')">红冲撤单</el-button>
					<el-button size="small" type="danger" @click="batchRedFlush('改单')">红冲改单</el-button>
				</template>
				<template v-else-if="searchForm.status_tab === '5'">
					<el-button size="small" @click="batchPrint">批量打印</el-button>
					<el-button size="small" @click="batchPrintPreview">打印预览</el-button>
				</template>
				<el-button size="small" link @click="clearSelection">取消选择</el-button>
			</div>
			<sTable ref="tableRef" tableName="business_sales_order" :data="data" :columns="columns" :searchForm="searchForm"
				:loading="loading" :total="total" :currentPage="paginationProps.currentPage" :pageSize="paginationProps.pageSize"
				:pageSizes="paginationProps.pageSizes" rowKey="id" height="100%" stripe
				@refresh="refresh" @search="doSearch" @pageChange="handlePageChange" @pageSizeChange="handlePageSizeChange"
				@selectionChange="selectedRows = $event">
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
							<el-button v-if="nextStatus(row.status)" type="success" link size="small" @click="handleAdvance(row)">{{ nextStatusShort(row.status) }}</el-button>
							<el-button v-if="prevStatus(row.status)" type="warning" link size="small" @click="handleReverse(row)">回{{ prevStatusShort(row.status) }}</el-button>
							<el-button v-if="row.status==='配送中'" type="warning" link size="small" @click="handleAdvance(row, '待收款')">待收款</el-button>
							<el-button v-if="row.status==='待收款'" type="warning" link size="small" @click="handleAdvance(row, '配送中')">回配送</el-button>
							<el-button v-if="row.status !== 'cancelled'" type="primary" link size="small" @click="handlePrint(row)">直接打印</el-button>
							<el-button v-if="row.status==='pending'" type="danger" link size="small" @click="handleCancel(row)">作废</el-button>
						</div>
						<div v-else class="customer-addr-row">
							<span v-if="row.has_gift" class="tag-gift" title="含赠品">赠品</span>
							<span v-if="row.has_special" class="tag-special" title="变价">变价</span>
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

	<!-- 配送选择弹框 -->
	<el-dialog v-model="dialog.delivery" title="调度——选择配送员和车辆" width="400px" destroy-on-close>
		<el-form label-width="80px" size="small">
			<el-form-item label="配送员">
				<el-select v-model="deliveryForm.delivery_person_id" placeholder="请选择配送员" filterable style="width:100%">
					<el-option v-for="s in salesmen" :key="s.id" :label="s.name" :value="s.id" />
				</el-select>
			</el-form-item>
			<el-form-item label="车辆">
				<el-select v-model="deliveryForm.vehicle_id" placeholder="请选择车辆" filterable clearable style="width:100%">
					<el-option v-for="v in vehicles" :key="v.id" :label="v.plate_no" :value="v.id" />
				</el-select>
			</el-form-item>
		</el-form>
		<template #footer>
			<el-button @click="dialog.delivery = false">取消</el-button>
			<el-button type="primary" :loading="submitting" @click="confirmDelivery">确认调度</el-button>
		</template>
	</el-dialog>
</template>

<script setup>
import { ref, reactive, onMounted, computed } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { useTable } from '@/hooks/useTable'
import businessApi from '@/api/business'
import SalesOrderDialog from '../components/sales-order-dialog.vue'
import OrderSummary from './components/order-summary.vue'

const { tableRef, data, total, loading, paginationProps, refresh, search, handlePageChange, handlePageSizeChange, searchForm } = useTable({
	apiObj: { get: (params) => businessApi.salesOrder.list.get(params) },
	searchForm: { keyword: '', status: null, customer_id: null, salesman_id: null, vehicle_id: null, route_id: null, status_tab: '5', quick_filter: -1, quick_date: '', start_date: null, end_date: null },
})
const dateRange = ref(null)
const onDateRange = (v) => {
	searchForm.start_date = v?.[0] || null
	searchForm.end_date = v?.[1] || null
	doSearch()
}
// 多选统计
const selectedRows = ref([])
const selectedTotal = computed(() => selectedRows.value.reduce((s, r) => s + Number(r.total_amount || 0), 0).toFixed(2))
const selectedQty = computed(() => {
	const lg = selectedRows.value.reduce((s, r) => s + (Number(r.items?.reduce((a, i) => a + Number(i.qty_large || 0), 0)) || 0), 0)
	const md = selectedRows.value.reduce((s, r) => s + (Number(r.items?.reduce((a, i) => a + Number(i.qty_medium || 0), 0)) || 0), 0)
	const sm = selectedRows.value.reduce((s, r) => s + (Number(r.items?.reduce((a, i) => a + Number(i.qty_small || 0), 0)) || 0), 0)
	return { lg, md, sm }
})
const clearSelection = () => { selectedRows.value = []; tableRef.value?.clearCheckboxRow?.() }

// 批量操作
const batchAdvance = async (target) => {
	for (const row of selectedRows.value) {
		try { await businessApi.salesOrder.approve.post(row.id, { target }) } catch {}
	}
	ElMessage.success(`已批量${target} ${selectedRows.value.length} 单`); clearSelection(); doRefresh()
}
const batchDispatch = async () => {
	// 批量调度：选第一个配送员+车辆
	ElMessage.info('批量调度需逐单选配送员车辆，请逐单操作')
}
const batchUnfreeze = async () => {
	for (const row of selectedRows.value) {
		try { await businessApi.salesOrder.cancel.post(row.id) } catch {}
	}
	ElMessage.success(`已撤销冻结 ${selectedRows.value.length} 单`); clearSelection(); doRefresh()
}
const batchReverse = async () => { batchAdvance('待调度') }
const batchReceive = async (method) => {
	for (const row of selectedRows.value) {
		try { await businessApi.receive.create.post({ customer_id: row.customer_id, amount: row.total_amount, receive_date: row.order_date, payment_method: method === '现金' ? '现金' : '应收', sales_order_id: row.id }) } catch {}
	}
	ElMessage.success(`已${method}收款 ${selectedRows.value.length} 单`); clearSelection(); doRefresh()
}
const batchPrint = () => {
	for (const row of selectedRows.value) { businessApi.salesOrder.print.post(row.id) }
	ElMessage.success(`已打印 ${selectedRows.value.length} 单`); clearSelection(); doRefresh()
}
const batchPrintPreview = () => { ElMessage.info('打印预览功能开发中') }
const batchRedFlush = async (type) => {
	try {
		const { value } = await ElMessageBox.prompt(`请输入红冲原因（${type === 'cancel' ? '撤单' : '改单'}）`, '红冲确认', {
			inputType: 'textarea',
			inputPlaceholder: '红冲原因（必填，财务审计需要）',
			inputValidator: (v) => v && v.trim() ? true : '请输入红冲原因',
		})
		const ids = selectedRows.value.map(r => r.id)
		const res = await businessApi.salesOrder.batchRedFlush.post({ ids, type: type === '撤单' ? 'cancel' : 'modify', reason: value })
		if (res.code === 200) {
			ElMessage.success(`红冲${type} ${ids.length} 单完成`)
			clearSelection(); doRefresh()
		} else ElMessage.error(res.message || '红冲失败')
	} catch (e) {
		if (e !== 'cancel' && e !== 'close') ElMessage.error(e?.message || '红冲失败')
	}
}

// 顶部状态 tab（新系统只有 pending，1-4 都映射 pending，5 全部）
const statusTabs = ref([
	{ value: '5', label: '全部单据', count: 0 },
	{ value: '1', label: '待配货', count: 0 },
	{ value: '2', label: '配货中', count: 0 },
	{ value: '6', label: '待调度', count: 0 },
	{ value: '3', label: '待配送', count: 0 },
	{ value: '7', label: '配送中', count: 0 },
	{ value: '4', label: '已发货收款', count: 0 },
])
// 快捷筛选
const quickFilters = ref([
	{ value: -1, label: '全部 ', count: 0 },
	{ value: 0, label: '未打印', count: 0 },
	{ value: 1, label: '变价', count: 0 },
	{ value: 2, label: '含赠品', count: 0 },
	{ value: 3, label: '含备注', count: 0 },
])
const pickStatusTab = (v) => { searchForm.status_tab = v; doSearch() }

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

const dialog = reactive({ order: false, delivery: false })
const currentOrder = ref(null)
const customers = ref([])
const suppliers = ref([])
const warehouses = ref([])
const salesmen = ref([])
const vehicles = ref([])
const orderType = ref('normal')
const submitting = ref(false)
const deliveryForm = reactive({ delivery_person_id: null, vehicle_id: null })
let pendingDeliveryRow = null

const confirmDelivery = async () => {
	if (!deliveryForm.delivery_person_id) { ElMessage.error('请选择配送员'); return }
	submitting.value = true
	try {
		const res = await businessApi.salesOrder.approve.post(pendingDeliveryRow.id, { target: '待配送', delivery_person_id: deliveryForm.delivery_person_id, vehicle_id: deliveryForm.vehicle_id || null })
		if (res.code === 200) { ElMessage.success(res.message || '调度完成'); dialog.delivery = false; doRefresh() }
		else ElMessage.error(res.message || '操作失败')
	} catch (e) { ElMessage.error(e?.message || '操作失败') }
	finally { submitting.value = false }
}

// 左侧汇总面板：跟随右侧搜索条件统计，点项联动过滤
const summary = ref({ totals: {}, bySalesman: [], byVehicle: [], byRoute: [] })
const activeFilter = ref({ salesman: null, vehicle: null, route: null })
const loadSummary = async () => {
	// 汇总剔除 salesman/vehicle/route 维度筛选，否则选中一项后汇总只剩该项
	const { salesman_id, vehicle_id, route_id, ...rest } = searchForm
	const res = await businessApi.salesOrder.summary.get(rest)
	if (res.code === 200) summary.value = res.data || {}
}
const statusCounts = ref({})
// status_counts 从 list 响应取（useTable 不暴露响应，用 onLoaded 回调或单独轻量请求）
// 简化：loadSummary 已含 totals.order_count（=all），状态 tab count 用 summary 的各分组 sum 近似
// 但精确各状态数需要 list 的 status_counts——用独立轻量请求（只 count，不分页）
const loadStatusCounts = async () => {
	// 剔除维度筛选 + status_tab（status_counts 要统计所有状态，不能被 tab 过滤）
	const { salesman_id, vehicle_id, route_id, status_tab, ...rest } = searchForm
	const res = await businessApi.salesOrder.list.get({ ...rest, page: 1, page_size: 1 })
	if (res.code === 200) {
		const c = res.data?.status_counts || {}
		statusTabs.value[0].count = c.all || 0
		statusTabs.value[1].count = c.pending || 0
		statusTabs.value[2].count = c['配货中'] || 0
		statusTabs.value[3].count = c['待调度'] || 0
		statusTabs.value[4].count = c['待配送'] || 0
		statusTabs.value[5].count = c['配送中'] || 0
		statusTabs.value[6].count = (c['已收款'] || 0) + (c['待收款'] || 0)
	}
}
const doSearch = (...args) => {
	// list + summary + statusCounts 并行
	Promise.all([Promise.resolve(search(...args)), loadSummary(), loadStatusCounts()])
}
const doRefresh = () => {
	Promise.all([Promise.resolve(refresh()), loadSummary(), loadStatusCounts()])
}
const onSummaryFilter = ({ type, id }) => {
	const field = type === 'salesman' ? 'salesman_id' : type === 'vehicle' ? 'vehicle_id' : 'route_id'
	// 三个维度互斥：选一个时清掉另外两个，避免叠加过滤
	searchForm.salesman_id = type === 'salesman' ? id : null
	searchForm.vehicle_id = type === 'vehicle' ? id : null
	searchForm.route_id = type === 'route' ? id : null
	activeFilter.value = { salesman: null, vehicle: null, route: null, [type]: id }
	// 汇总始终按全量（不带 salesman/vehicle/route 过滤），否则选中后列表只剩一项
	search()
	loadSummary()
}

const statusType = (s) => ({ pending: 'warning', '配货中': 'primary', '待调度': 'info', '待配送': 'info', '配送中': 'primary', '已收款': 'success', '待收款': 'danger', '已红冲': 'info', cancelled: 'info' }[s] || 'info')
const statusLabel = (s) => ({ pending: '待配货', '配货中': '配货中', '待调度': '待调度', '待配送': '待配送', '配送中': '配送中', '已收款': '已收款', '待收款': '待收款', '已红冲': '已红冲', cancelled: '已取消' }[s] || s)
// 流程：待配货→配货中→待调度→待配送→配送中→已收款/待收款
const nextStatus = (s) => ({ pending: '配货中', '配货中': '待调度', '待调度': '待配送', '待配送': '配送中', '配送中': '已收款' }[s] || null)
const nextStatusShort = (s) => ({ pending: '配货', '配货中': '待调度', '待调度': '调度', '待配送': '配送', '配送中': '已收款' }[s] || nextStatus(s))
const prevStatus = (s) => ({ '已收款': '待收款', '待收款': '配送中', '配送中': '待配送', '待配送': '待调度', '待调度': '配货中', '配货中': '待配货' }[s] || null)
// 逆向按钮文案：撤销当前步骤（如配货中→撤销配货=回待配货）
const prevStatusShort = (s) => ({ '已收款': '撤销收款', '待收款': '撤销待收款', '配送中': '撤销配送', '待配送': '撤销调度', '待调度': '撤销调度', '配货中': '撤销配货' }[s] || prevStatus(s))

const handleAdd = () => { currentOrder.value = null; orderType.value = 'normal'; dialog.order = true }
const handleEdit = (row) => { currentOrder.value = row; dialog.order = true }
const handleDelete = async (row) => {
	const res = await businessApi.salesOrder.delete.delete(row.id)
	if (res.code === 200) { ElMessage.success('删除成功'); doRefresh() }
}
const handleAdvance = async (row, target) => {
	const realTarget = target || nextStatus(row.status)
	// 待调度→待配送：调度时选配送员+车辆
	if (realTarget === '待配送' && row.status === '待调度') {
		pendingDeliveryRow = row
		deliveryForm.delivery_person_id = null
		deliveryForm.vehicle_id = null
		dialog.delivery = true
		return
	}
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
	if (row.status === 'cancelled') { ElMessage.warning('已作废的订单不能打印'); return }
	const res = await businessApi.salesOrder.print.post(row.id)
	if (res.code === 200) {
		ElMessage.success('已记录打印')
		window.open('about:blank', '_blank')
		doRefresh()
	}
}
// 逆向操作：回退到上一状态
const handleReverse = async (row) => {
	const prev = prevStatus(row.status)
	if (!prev) return
	try {
		const res = await businessApi.salesOrder.approve.post(row.id, { target: prev })
		if (res.code === 200) { ElMessage.success(res.message || '已回退'); doRefresh() }
		else ElMessage.error(res.message || '操作失败')
	} catch (e) {
		ElMessage.error(e?.message || '操作失败')
	}
}

onMounted(() => {
	Promise.all([
		businessApi.customer.list.get({ page_size: 9999 }).then(r => { if (r.code === 200) customers.value = r.data?.list || [] }),
		businessApi.warehouse.list.get({ page_size: 9999 }).then(r => { if (r.code === 200) warehouses.value = r.data?.list || [] }),
		businessApi.employee.list.get({ is_active: 1, page_size: 9999 }).then(r => { if (r.code === 200) salesmen.value = r.data?.list || [] }),
		businessApi.supplier.list.get({ page_size: 9999 }).then(r => { if (r.code === 200) suppliers.value = r.data?.list || [] }),
		businessApi.vehicle.list.get({ is_active: 1, page_size: 9999 }).then(r => { if (r.code === 200) vehicles.value = r.data?.list || [] }),
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

/* toolbar 日期筛选 */
.toolbar { display: flex; justify-content: space-between; align-items: center; padding: 6px 8px; gap: 8px; flex-wrap: wrap; }
.toolbar .left-panel { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.toolbar .right-panel { display: flex; align-items: center; gap: 4px; }

/* 多选统计条 */
.select-bar { display: flex; align-items: center; gap: 8px; padding: 6px 12px; background: var(--el-color-primary-light-9); border-bottom: 1px solid var(--el-border-color); font-size: 12px; }
.select-bar b { font-weight: 600; }
.select-bar .sep { color: var(--el-text-color-placeholder); }
</style>
