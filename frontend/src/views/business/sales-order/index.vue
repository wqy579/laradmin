<template>
	<div class="so-page" tabindex="0" @keydown="handlePageKeydown">
		<!-- 左侧：业务员 / 车辆 / 客户 维度面板 -->
		<aside class="so-aside">
			<div class="aside-tabs">
				<div v-for="t in dimTabs" :key="t.type" :class="['aside-tab', { active: dimTab === t.type }]" @click="dimTab = t.type">
					{{ t.label }}
				</div>
			</div>
			<div class="aside-scroll">
				<!-- 合计行 -->
				<div :class="['aside-item', 'aside-total', { active: activeFilter[dimTab] == null }]" @click="onAsidePick(null)">
					<div class="aside-top">
						<span class="aside-name">{{ dimTabs.find(t => t.type === dimTab)?.totalLabel }}</span>
						<span class="aside-amount">¥{{ fmt(summary.totals?.total_amount) }}<i v-if="summary.totals?.order_count > 0" class="aside-badge">{{ summary.totals.order_count }}</i></span>
					</div>
					<div class="aside-sub">
						<span>普<span class="sub-num">{{ summary.totals?.order_count || '--' }}</span></span>
						<span>退<span class="sub-num">--</span></span>
					</div>
					<div class="aside-sub">
						<span>大<span class="sub-num">{{ summary.totals?.qty_large || '--' }}</span></span>
						<span>中<span class="sub-num">{{ summary.totals?.qty_medium || '--' }}</span></span>
						<span>小<span class="sub-num">{{ summary.totals?.qty_small || '--' }}</span></span>
					</div>
				</div>
				<!-- 明细项 -->
				<div v-for="(r, i) in asideRows" :key="dimTab + i" :class="['aside-item', { active: eq(activeFilter[dimTab], r[dimIdKey]) }]" @click="onAsidePick(r[dimIdKey])">
					<div class="aside-top">
						<span class="aside-name">{{ r.name || dimTabs.find(t => t.type === dimTab)?.emptyName }}</span>
						<span class="aside-amount">¥{{ fmt(r.total_amount) }}<i v-if="(r.order_count || 0) > 0" class="aside-badge">{{ r.order_count }}</i></span>
					</div>
					<div class="aside-sub">
						<span>普<span class="sub-num">{{ (r.order_count || 0) > 0 ? r.order_count : '--' }}</span></span>
						<span>退<span class="sub-num">--</span></span>
					</div>
					<div class="aside-sub">
						<span>大<span class="sub-num">{{ (r.qty_large || 0) > 0 ? r.qty_large : '--' }}</span></span>
						<span>中<span class="sub-num">{{ (r.qty_medium || 0) > 0 ? r.qty_medium : '--' }}</span></span>
						<span>小<span class="sub-num">{{ (r.qty_small || 0) > 0 ? r.qty_small : '--' }}</span></span>
					</div>
				</div>
			</div>
		</aside>

		<!-- 右侧：主内容区 -->
		<div class="so-main">
			<!-- ① 状态标签页 -->
			<div class="status-tabs">
				<div v-for="t in statusTabs" :key="t.value" :class="['status-tab', { active: searchForm.status_tab === t.value }]" @click="pickStatusTab(t.value)">
					{{ t.label }}<span v-if="t.count > 0" class="tab-badge">{{ t.count }}</span>
				</div>
			</div>

			<!-- ② 快捷筛选栏 -->
			<div class="quick-filters">
				<label v-for="q in quickFilters" :key="q.value" :class="['quick-label', { active: searchForm.quick_filter === q.value }]" @click="toggleQuickFilter(q.value)">
					<input type="checkbox" :checked="searchForm.quick_filter === q.value" @click.stop />
					<span>{{ q.label }}<b v-if="q.count > 0">{{ q.count }}</b></span>
				</label>
				<a class="help-link" @click="showHelp">?<span>帮助视频</span></a>
			</div>

			<!-- ③ 筛选条件区 -->
			<div class="toolbar">
				<el-select v-model="searchForm.quick_date" size="default" style="width: 100px" @change="onQuickDate">
					<el-option v-for="d in datePresets" :key="d.value" :label="d.label" :value="d.value" />
				</el-select>
				<el-date-picker v-model="dateRange" type="daterange" range-separator="至" start-placeholder="开始" end-placeholder="结束" size="default" style="width: 180px" value-format="YYYY-MM-DD" :disabled="searchForm.quick_date !== 'custom'" @change="onDateRange" />
				<el-input v-model="searchForm.keyword" placeholder="单号、助记码等" clearable size="default" style="width: 200px" @keyup.enter="doSearch" @clear="doSearch">
					<template #prefix><el-icon><Search /></el-icon></template>
				</el-input>
				<el-button type="primary" size="default" @click="doSearch">查询<sub>C</sub></el-button>
				<el-dropdown @command="onAdvanced" size="default">
					<el-button size="default">高级查询<el-icon class="el-icon--right"><ArrowDown /></el-icon></el-button>
					<template #dropdown>
						<el-dropdown-menu>
							<el-dropdown-item command="range" :disabled="searchForm.quick_date === 'custom'">按日期范围</el-dropdown-item>
							<el-dropdown-item command="amount">按金额范围</el-dropdown-item>
							<el-dropdown-item command="clear">清除筛选</el-dropdown-item>
						</el-dropdown-menu>
					</template>
				</el-dropdown>
				<div style="flex: 1"></div>
				<el-button type="primary" size="default" @click="handleAdd">新增订单</el-button>
			</div>

			<!-- ④ 订单类型标签 -->
			<div class="type-tabs">
				<div v-for="t in typeTabs" :key="t.value" :class="['type-tab', { active: searchForm.order_type === t.value }]" @click="searchForm.order_type = t.value; doSearch()">
					{{ t.label }}<span v-if="t.count > 0">({{ t.count }})</span>
				</div>
				<span class="type-hint">订单列表支持键盘（↑↓）键查看明细</span>
			</div>

			<!-- ⑤ 批量操作栏 -->
			<div v-show="selectedRows.length" class="batch-bar">
				<div class="batch-actions">
					<el-select v-model="batchForm.vehicle_id" size="default" placeholder="配送车" style="width: 120px" filterable clearable>
						<el-option v-for="v in vehicles" :key="v.id" :label="`${v.plate_no} (${v.driver_name})`" :value="v.id" />
					</el-select>
					<el-select v-model="batchForm.person_id" size="default" placeholder="配送员" style="width: 120px" filterable clearable>
						<el-option v-for="s in salesmen" :key="s.id" :label="s.name" :value="s.id" />
					</el-select>
					<el-date-picker v-model="batchForm.dispatch_date" type="date" size="default" style="width: 120px" value-format="YYYY-MM-DD" />
					<template v-if="searchForm.status_tab === '1'">
						<el-button type="primary" size="default" @click="batchAdvance('配货中')">配货</el-button>
						<el-button type="primary" size="default" @click="batchPrint">打印</el-button>
						<el-button type="danger" size="default" @click="batchRemark">说明</el-button>
						<el-button type="primary" size="default" link @click="batchCopy">复制为销售单</el-button>
					</template>
					<template v-else-if="searchForm.status_tab === '6'">
						<el-button type="primary" size="default" @click="batchDispatch">调度</el-button>
						<el-button type="primary" size="default" @click="batchPrint">打印</el-button>
						<el-button type="danger" size="default" @click="batchRemark">说明</el-button>
					</template>
					<template v-else-if="searchForm.status_tab === '3' || searchForm.status_tab === '7'">
						<el-button type="primary" size="default" @click="batchAdvance(searchForm.status_tab === '3' ? '配送中' : '已收款')">发货</el-button>
						<el-button type="primary" size="default" @click="batchPrint">打印</el-button>
						<el-button type="danger" size="default" @click="batchRemark">说明</el-button>
					</template>
					<template v-else-if="searchForm.status_tab === '4'">
						<el-button type="danger" size="default" @click="batchRedFlush">红冲</el-button>
						<el-button type="primary" size="default" @click="batchPrint">打印</el-button>
						<el-button type="danger" size="default" @click="batchRemark">说明</el-button>
					</template>
					<template v-else>
						<el-button type="primary" size="default" @click="batchPrint">打印</el-button>
						<el-button type="danger" size="default" @click="batchRemark">说明</el-button>
					</template>
				</div>
				<div class="batch-summary">
					<b>已选择 {{ selectedRows.length }} 条</b>
					<i>|</i>
					<span>总金额：<b style="color: #303133">{{ fmt(selectionTotal) }}</b>（销售-退货）</span>
					<i>|</i>
					<span style="color: #67c23a">销售：{{ fmt(selectionSale) }}</span>
					<i>|</i>
					<span style="color: #f56c6c">退货：{{ fmt(selectionReturn) }}</span>
					<i>|</i>
					<span>大数量：{{ selectionQty.lg }}</span>
					<i>|</i>
					<span>中数量：{{ selectionQty.md }}</span>
					<i>|</i>
					<span>小数量：{{ selectionQty.sm }}</span>
					<i>|</i>
					<span style="color: #909399">重量：{{ Number(summary.totals?.total_weight || 0).toFixed(3) }}(t)</span>
					<i>|</i>
					<span style="color: #909399">体积：{{ Number(summary.totals?.total_volume || 0).toFixed(3) }}(m³)</span>
				</div>
			</div>

			<!-- ⑥ 数据表格 -->
			<sTable
				ref="tableRef"
				tableName="business_sales_order"
				:data="data"
				:columns="columns"
				:searchForm="searchForm"
				:loading="loading"
				:total="total"
				:currentPage="paginationProps.currentPage"
				:pageSize="paginationProps.pageSize"
				:pageSizes="paginationProps.pageSizes"
				:paginationLayout="paginationLayout"
				rowKey="id"
				height="100%"
				stripe
				@refresh="doRefresh"
				@search="doSearch"
				@pageChange="handlePageChange"
				@pageSizeChange="handlePageSizeChange"
				@selectionChange="selectedRows = $event"
			>
				<template #order_no_default="{ row }">
					<span class="order-no-link" @click="handleDetail(row)">{{ (row.order_no || '').slice(-6) }}</span>
				</template>
				<template #customer_default="{ row }">
					<div class="customer-cell">
						<div class="customer-name-row">
							<span class="customer-name" :title="row.customer_name" @mouseenter="onCustomerEnter(row, $event)" @mouseleave="onCustomerLeave">{{ row.customer_name || row.customer?.name }}</span>
							<!-- 退货单存在 sales_returns 独立表，不在此列表；此处区分的是红冲单（继承原单类型，靠 original_order_id 识别） -->
							<el-tag v-if="row.is_flush" size="small" type="danger" effect="plain" class="order-tag">红冲</el-tag>
							<el-tag v-else-if="Number(row.total_amount || 0) < 0" size="small" type="warning" effect="plain" class="order-tag">负值</el-tag>
							<el-tag v-else size="small" type="primary" effect="plain" class="order-tag">普通</el-tag>
							<el-tag v-if="row.has_gift" size="small" type="warning" effect="plain" class="order-tag">赠品</el-tag>
							<el-tag v-if="row.has_special" size="small" effect="plain" class="order-tag" style="background:#f3e8f5;color:#9c27b0">变价</el-tag>
						</div>
						<div class="customer-addr-row">
							<span class="customer-addr" :title="row.customer?.address">{{ row.customer?.address || '' }}</span>
							<span v-if="!row.print_count" class="print-unprinted">未打印</span>
						</div>
					</div>
				</template>
				<template #customer_category_default="{ row }">
					<span>{{ row.customer?.category || row.customer?.route_label || '--' }}</span>
				</template>
				<template #warehouse_default="{ row }">
					<a v-if="row.warehouse_name" class="warehouse-link" @click="handleEdit(row)">{{ row.warehouse_name }}</a>
					<span v-else style="color: #909399">-请选择仓库-</span>
				</template>
				<template #total_qty_default="{ row }">
					<span>{{ Number(row.total_qty || 0).toFixed(3) }}</span>
				</template>
				<template #total_amount_default="{ row }">
					<span :style="{ color: Number(row.total_amount) < 0 ? '#f56c6c' : '#303133' }">{{ fmt(row.total_amount) }}元</span>
				</template>
				<template #order_time_default="{ row }">
					<div class="order-time">
						<div>{{ fmtDate(row.order_date) }}</div>
						<div>{{ fmtTime(row.order_date) }}</div>
					</div>
				</template>
				<template #operation_default="{ row }">
					<div class="row-ops">
						<a class="op op-primary" @click="handleDetail(row)">查看</a>
						<a v-if="canEdit(row)" class="op op-primary" @click="handleEdit(row)">编辑</a>
						<a v-if="row.status === 'pending'" class="op op-success" @click="handleAdvance(row, '配货中')">配货</a>
						<a v-else-if="row.status === '配货中'" class="op op-success" @click="handleAdvance(row, '待调度')">完成配货</a>
						<a v-else-if="row.status === '待调度'" class="op op-success" @click="handleAdvance(row, '待配送')">调度</a>
						<a v-else-if="row.status === '待配送'" class="op op-success" @click="handleAdvance(row, '配送中')">配送</a>
						<a v-else-if="row.status === '配送中'" class="op op-success" @click="handleAdvance(row, '已收款')">已收款</a>
						<el-dropdown @command="onRowCommand(row, $event)">
							<a class="op op-muted">更多<el-icon><ArrowDown /></el-icon></a>
							<template #dropdown>
								<el-dropdown-menu>
									<el-dropdown-item command="print">打印订单明细</el-dropdown-item>
									<el-dropdown-item command="print-delivery">打印送货单</el-dropdown-item>
									<el-dropdown-item command="copy">复制为销售单</el-dropdown-item>
									<el-dropdown-item command="history">查看经营历程</el-dropdown-item>
									<el-dropdown-item v-if="row.status === 'pending'" command="delete" divided>删除</el-dropdown-item>
								</el-dropdown-menu>
							</template>
						</el-dropdown>
					</div>
				</template>
			</sTable>
		</div>

		<!-- ⑦ 客户 hover 卡片 -->
		<div
			v-if="hoverRow"
			v-show="hoverCard.visible"
			class="customer-card"
			:style="hoverCard.style"
			@mouseenter="onCardEnter"
			@mouseleave="onCustomerLeave"
		>
			<div class="card-name">
				{{ hoverRow.customer?.name }}
				<el-tag v-if="hoverRow.customer?.category" size="small" type="primary" effect="plain" class="order-tag">{{ hoverRow.customer.category }}</el-tag>
			</div>
			<div class="card-row">📞 联系电话：{{ hoverRow.customer?.phone || '-' }}</div>
			<div class="card-row card-wrap">📍 详细地址：{{ hoverRow.customer?.address || '-' }}</div>
			<div class="card-row" :style="{ color: '#f56c6c', fontWeight: 'bold' }">💰 欠款余额：{{ fmt(hoverRow.customer?.balance) }}元</div>
			<div class="card-row" style="color: #909399">⚠️ 信用额度：{{ fmt(hoverRow.customer?.credit_limit) }}元</div>
			<div class="card-row">📅 最近下单：{{ lastOrderDate }}</div>
			<hr class="card-divider" />
			<div class="card-actions">
				<a @click="onCardClick('view')">查看客户档案</a>
				<a v-if="canEdit(hoverRow)" @click="onCardClick('edit')">编辑订单</a>
				<a v-if="hoverRow.status === '已收款'" @click="onCardClick('redflush')">红冲</a>
			</div>
		</div>

		<!-- 订单编辑/新增弹窗 -->
		<SalesOrderDialog v-if="dialog.order" v-model:visible="dialog.order" :orderType="orderType" :record="currentOrder" :customers="customers" :suppliers="suppliers" :warehouses="warehouses" :salesmen="salesmen" @success="doRefresh" />

		<!-- 详情弹窗 -->
		<el-dialog v-model="dialog.detail" title="订单详情" width="800px" top="5vh" destroy-on-close>
			<el-tabs v-model="detailTab">
				<el-tab-pane label="订单信息" name="info">
					<el-descriptions :column="2" border size="small" v-if="detailData">
						<el-descriptions-item label="订单编号">{{ detailData.order_no }}</el-descriptions-item>
						<el-descriptions-item label="状态"><el-tag :type="statusType(detailData.status)" size="small">{{ statusLabel(detailData.status) }}</el-tag></el-descriptions-item>
						<el-descriptions-item label="客户">{{ detailData.customer?.name }}</el-descriptions-item>
						<el-descriptions-item label="业务员">{{ detailData.salesman_name }}</el-descriptions-item>
						<el-descriptions-item label="仓库">{{ detailData.warehouse?.name }}</el-descriptions-item>
						<el-descriptions-item label="日期">{{ detailData.order_date }}</el-descriptions-item>
						<el-descriptions-item label="总金额"><b style="color: #e6a23c">¥{{ fmt(detailData.total_amount) }}</b></el-descriptions-item>
						<el-descriptions-item label="总数量">{{ detailData.total_qty }}</el-descriptions-item>
						<el-descriptions-item v-if="detailData.red_flush_reason" label="红冲原因" :span="2">{{ detailData.red_flush_reason }}</el-descriptions-item>
						<el-descriptions-item label="备注" :span="2">{{ detailData.remark || '-' }}</el-descriptions-item>
					</el-descriptions>
					<el-table :data="detailData?.items || []" size="small" style="margin-top: 12px" border>
						<el-table-column prop="product.name" label="商品" width="200" />
						<el-table-column prop="qty_large" label="大" width="50" />
						<el-table-column prop="qty_medium" label="中" width="50" />
						<el-table-column prop="qty_small" label="小" width="50" />
						<el-table-column prop="amount" label="金额" width="90" align="right" />
						<el-table-column prop="sale_mode" label="模式" width="80" />
					</el-table>
				</el-tab-pane>
				<el-tab-pane label="订单历程" name="history">
					<el-timeline>
						<el-timeline-item v-for="log in (detailData?.operation_logs || [])" :key="log.id" :timestamp="log.created_at" :type="log.to_status === '已红冲' ? 'danger' : 'primary'">
							<b>{{ log.action }}</b> <span style="color: #909399">— {{ log.operator_name }}</span>
							<div v-if="log.detail">{{ log.detail }}</div>
						</el-timeline-item>
					</el-timeline>
					<el-empty v-if="!detailData?.operation_logs?.length" description="无操作记录" />
				</el-tab-pane>
			</el-tabs>
		</el-dialog>
	</div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, onBeforeUnmount, nextTick, watch } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Search, ArrowDown } from '@element-plus/icons-vue'
import businessApi from '@/api/business'
import SalesOrderDialog from '../components/sales-order-dialog.vue'

// ===== 状态标签 =====
const statusTabs = ref([
	{ value: '1', label: '待配货', count: 0 },
	{ value: '2', label: '待调度', count: 0 },
	{ value: '3', label: '待配送', count: 0 },
	{ value: '4', label: '已发货收款', count: 0 },
	{ value: '5', label: '全部单据', count: 0 },
])
const statusLabelMap = { pending: '待配货', '配货中': '配货中', '待调度': '待调度', '待配送': '待配送', '配送中': '配送中', '已收款': '已收款', '待收款': '待收款', '已红冲': '已红冲', cancelled: '已取消' }
const statusTypeMap = { pending: 'warning', '配货中': 'primary', '待调度': 'info', '待配送': 'info', '配送中': 'primary', '已收款': 'success', '待收款': 'danger', '已红冲': 'info', cancelled: 'info' }
const statusLabel = (s) => statusLabelMap[s] || s
const statusType = (s) => statusTypeMap[s] || 'info'

// ===== 订单类型标签 =====
// 值来自 sales_orders.order_type 列的实际取值（后端分组原样返回），此处只做中文映射；
// 字典缺项时兜底显示 `${值}订单`，新增类型不会显示成裸英文。
const typeLabelMap = {
	normal: '普通订单',
	process: '处理订单',
	exchange: '换货订单',
	give_back: '还货订单',
	sales_return: '退货订单',
}
const typeTabs = ref([
	{ value: 'all', label: '全部订单', count: 0 },
	{ value: 'normal', label: '普通订单', count: 0 },
	{ value: 'return', label: '退货订单', count: 0 },
])

// ===== 快捷筛选 =====
const quickFilters = ref([
	{ value: -1, label: '全部 ', count: 0 },
	{ value: 0, label: '未打印', count: 0 },
	{ value: 1, label: '变价', count: 0 },
	{ value: 2, label: '含赠品', count: 0 },
	{ value: 3, label: '含备注', count: 0 },
])

// ===== 左侧维度 =====
const dimTab = ref('salesman')
const dimTabs = [
	{ type: 'salesman', label: '业务员', idKey: 'salesman_id', emptyName: '未分配业务员', totalLabel: '合计' },
	{ type: 'vehicle', label: '车辆', idKey: 'vehicle_id', emptyName: '未分配车辆', totalLabel: '合计' },
	{ type: 'route', label: '客户', idKey: 'route_id', emptyName: '未分线路', totalLabel: '全部' },
]
const activeFilter = reactive({ salesman: null, vehicle: null, route: null })
const summary = ref({ totals: {}, bySalesman: [], byVehicle: [], byRoute: [] })
const asideRows = computed(() => {
	const key = { salesman: 'bySalesman', vehicle: 'byVehicle', route: 'byRoute' }[dimTab.value]
	return summary.value?.[key] || []
})
const dimIdKey = computed(() => dimTabs.find((t) => t.type === dimTab.value).idKey)

// ===== 日期筛选 =====
const datePresets = [
	{ value: 'today', label: '今天' },
	{ value: 'yesterday', label: '昨天' },
	{ value: 'week', label: '近7天' },
	{ value: 'month', label: '本月' },
	{ value: 'last_month', label: '上月' },
	{ value: 'custom', label: '自定义' },
]
const dateRange = ref(null)

// ===== 表格核心状态 =====
const searchForm = reactive({
	keyword: '',
	status_tab: '1',
	order_type: 'all',
	quick_filter: -1,
	quick_date: 'today',
	start_date: null,
	end_date: null,
	salesman_id: null,
	vehicle_id: null,
	route_id: null,
})
const data = ref([])
const total = ref(0)
const loading = ref(false)
const paginationProps = reactive({ currentPage: 1, pageSize: 20, pageSizes: [10, 20, 50, 100] })
const paginationLayout = 'total, sizes, prev, pager, next, jumper'
const selectedRows = ref([])
const tableRef = ref(null)

const fetchData = async () => {
	loading.value = true
	try {
		const res = await businessApi.salesOrder.list.get({
			page: paginationProps.currentPage,
			page_size: paginationProps.pageSize,
			...searchForm,
		})
		if (res.code === 200) {
			const d = res.data || {}
			data.value = d.list || []
			total.value = d.total || 0
			// 状态 tab 计数
			const c = d.status_counts || {}
			statusTabs.value[0].count = c.pending || 0
			statusTabs.value[1].count = (c['配货中'] || 0) + (c['待调度'] || 0)
			statusTabs.value[2].count = c['待配送'] || 0
			statusTabs.value[3].count = (c['已收款'] || 0) + (c['待收款'] || 0)
			statusTabs.value[4].count = c.all || 0
			// 订单类型 tab：后端按 order_type 列实际取值分组返回，前端用字典转中文标签
			const rawTypeCounts = d.type_counts?.raw || {}
			typeTabs.value = [
				{ value: 'all', label: '全部订单', count: d.type_counts?.all || 0 },
				...Object.entries(rawTypeCounts).map(([k, v]) => ({ value: k, label: typeLabelMap[k] || `${k}订单`, count: v })),
			]
			// 快捷筛选计数
			if (d.quick_counts) {
				quickFilters.value[0].count = d.quick_counts.all || 0
				quickFilters.value[1].count = d.quick_counts.unprinted || 0
				quickFilters.value[2].count = d.quick_counts.special || 0
				quickFilters.value[3].count = d.quick_counts.gift || 0
				quickFilters.value[4].count = d.quick_counts.remark || 0
			}
			// 注意：列表接口返回的 customers/warehouses/salesmen 是精简 shape
			// （Customer 只 select id,name，缺 is_active；对话框按 is_active 过滤客户
			// 会把这些行全丢掉，导致客户下拉为空）。下拉数据统一由 onMounted 的
			// 独立请求加载，这里不再用列表响应覆盖它。
		}
	} finally {
		loading.value = false
	}
}

const loadSummary = async () => {
	const { salesman_id, vehicle_id, route_id, ...rest } = searchForm
	const res = await businessApi.salesOrder.summary.get(rest)
	if (res.code === 200) summary.value = res.data || {}
}

const doSearch = async () => {
	await Promise.all([fetchData(), loadSummary()])
}
const doRefresh = async () => {
	await Promise.all([fetchData(), loadSummary()])
}

const onQuickDate = (v) => {
	if (v !== 'custom') {
		dateRange.value = null
		searchForm.start_date = null
		searchForm.end_date = null
	}
	doSearch()
}
const onDateRange = (v) => {
	searchForm.quick_date = 'custom'
	searchForm.start_date = v?.[0] || null
	searchForm.end_date = v?.[1] || null
	doSearch()
}
const pickStatusTab = (v) => {
	searchForm.status_tab = v
	selectedRows.value = []
	tableRef.value?.clearCheckboxRow?.()
	doSearch()
}
const toggleQuickFilter = (v) => {
	searchForm.quick_filter = v
	doSearch()
}
const onAdvanced = (cmd) => {
	if (cmd === 'range') {
		searchForm.quick_date = 'custom'
	} else if (cmd === 'amount') {
		ElMessage.info('金额范围筛选开发中')
	} else if (cmd === 'clear') {
		Object.assign(searchForm, { keyword: '', status_tab: '1', order_type: 'all', quick_filter: -1, quick_date: 'today', start_date: null, end_date: null, salesman_id: null, vehicle_id: null, route_id: null })
		Object.assign(activeFilter, { salesman: null, vehicle: null, route: null })
		dateRange.value = null
		doSearch()
	}
}

// ===== 左侧面板联动 =====
const onAsidePick = (id) => {
	const field = dimTab.value === 'salesman' ? 'salesman_id' : dimTab.value === 'vehicle' ? 'vehicle_id' : 'route_id'
	searchForm.salesman_id = dimTab.value === 'salesman' ? id : null
	searchForm.vehicle_id = dimTab.value === 'vehicle' ? id : null
	searchForm.route_id = dimTab.value === 'route' ? id : null
	activeFilter.value = { salesman: null, vehicle: null, route: null, [dimTab.value]: id }
	selectedRows.value = []
	tableRef.value?.clearCheckboxRow?.()
	doSearch()
}

// ===== 多选统计 =====
// 选中汇总：列表只含 sales_orders（退货单在 sales_returns 独立表，不在此列表），
// 因此没有「销售/退货」的类型来源；改用金额正负拆分——负金额即红冲/冲销单。
const selectionTotal = computed(() => selectedRows.value.reduce((s, r) => s + Number(r.total_amount || 0), 0))
const selectionSale = computed(() => selectedRows.value.filter((r) => Number(r.total_amount || 0) >= 0).reduce((s, r) => s + Number(r.total_amount || 0), 0))
const selectionReturn = computed(() => selectedRows.value.filter((r) => Number(r.total_amount || 0) < 0).reduce((s, r) => s + Number(r.total_amount || 0), 0))
const selectionQty = computed(() => {
	const acc = { lg: 0, md: 0, sm: 0 }
	for (const r of selectedRows.value) {
		const items = r.items || []
		acc.lg += items.reduce((a, i) => a + Number(i.qty_large || 0), 0)
		acc.md += items.reduce((a, i) => a + Number(i.qty_medium || 0), 0)
		acc.sm += items.reduce((a, i) => a + Number(i.qty_small || 0), 0)
	}
	return acc
})
const clearSelection = () => {
	selectedRows.value = []
	tableRef.value?.clearCheckboxRow?.()
}

// ===== 批量操作 =====
const batchForm = reactive({ vehicle_id: null, person_id: null, dispatch_date: new Date().toISOString().slice(0, 10) })

const batchAdvance = async (target) => {
	if (!selectedRows.value.length) return
	try {
		await ElMessageBox.confirm(`确定将选中的 ${selectedRows.value.length} 条订单标记为${target}吗？`, '批量操作', { type: 'warning' })
	} catch {
		return
	}
	for (const row of selectedRows.value) {
		try {
			const res = await businessApi.salesOrder.approve.post(row.id, {
				target,
				vehicle_id: batchForm.vehicle_id || undefined,
				delivery_person_id: batchForm.person_id || undefined,
			})
			if (res.code !== 200) ElMessage.error(`订单${row.order_no}：${res.message}`)
		} catch {}
	}
	ElMessage.success(`已批量${target} ${selectedRows.value.length} 单`)
	clearSelection()
	doRefresh()
}

const batchDispatch = async () => {
	if (!batchForm.vehicle_id && !batchForm.person_id) {
		ElMessage.warning('请先选择车辆或配送员')
		return
	}
	await batchAdvance('待配送')
}

const batchPrint = async () => {
	const orders = []
	for (const row of selectedRows.value) {
		try {
			const res = await businessApi.salesOrder.print.post(row.id)
			if (res.code === 200 && res.data) orders.push({ ...res.data, print_user: 'admin' })
		} catch {}
	}
	if (orders.length) {
		window.open(`/print-order.html?data=${encodeURIComponent(JSON.stringify(orders))}`, '_blank')
		ElMessage.success(`已打印 ${orders.length} 单`)
	}
	clearSelection()
	doRefresh()
}

const batchRemark = async () => {
	try {
		const { value } = await ElMessageBox.prompt('批量备注', '说明', { inputType: 'textarea', inputPlaceholder: '批量备注内容' })
		const ids = selectedRows.value.map((r) => r.id)
		const res = await businessApi.salesOrder.batchUpdate.post({ ids, remark: value })
		if (res.code === 200) {
			ElMessage.success(`已为 ${ids.length} 单添加备注`)
			clearSelection()
			doRefresh()
		} else ElMessage.error(res.message || '操作失败')
	} catch {}
}

const batchCopy = async () => {
	for (const row of selectedRows.value) {
		await businessApi.salesOrder.add.post({
			customer_id: row.customer_id,
			warehouse_id: row.warehouse_id,
			salesman_id: row.salesman_id,
			remark: `复制自 ${row.order_no}`,
			items: (row.items || []).map((i) => ({
				product_id: i.product_id,
				quantity: i.quantity,
				qty_large: i.qty_large,
				qty_medium: i.qty_medium,
				qty_small: i.qty_small,
				price_large: i.price_large,
				price_medium: i.price_medium,
				price_small: i.price_small,
				sale_mode: i.sale_mode,
			})),
		})
	}
	ElMessage.success(`已复制 ${selectedRows.value.length} 单`)
	clearSelection()
	doRefresh()
}

const batchRedFlush = async () => {
	try {
		const { value } = await ElMessageBox.prompt('请输入红冲原因', '红冲确认', {
			inputType: 'textarea',
			inputValidator: (v) => (v && v.trim() ? true : '请输入红冲原因'),
		})
		const ids = selectedRows.value.map((r) => r.id)
		const res = await businessApi.salesOrder.batchRedFlush.post({ ids, type: 'cancel', reason: value })
		if (res.code === 200) {
			ElMessage.success(`红冲撤单 ${ids.length} 单成功`)
			clearSelection()
			doRefresh()
		} else ElMessage.error(res.message || '红冲失败')
	} catch {}
}

// ===== 单条操作 =====
const canEdit = (row) => ['pending', '配货中', '待调度'].includes(row.status)

const handleAdd = () => {
	currentOrder.value = null
	orderType.value = 'normal'
	dialog.order = true
}
const handleEdit = (row) => {
	currentOrder.value = row
	dialog.order = true
}
const handleDetail = async (row) => {
	try {
		const res = await businessApi.salesOrder.detail.get(row.id)
		if (res.code === 200) {
			detailData.value = res.data
			detailTab.value = 'info'
			dialog.detail = true
		}
	} catch {
		ElMessage.error('加载失败')
	}
}
const handleAdvance = async (row, target) => {
	try {
		const res = await businessApi.salesOrder.approve.post(row.id, { target })
		if (res.code === 200) {
			ElMessage.success(res.message || '状态已更新')
			doRefresh()
		} else ElMessage.error(res.message || '操作失败')
	} catch (e) {
		ElMessage.error(e?.message || '操作失败')
	}
}
const handleDelete = async (row) => {
	try {
		await ElMessageBox.confirm(`确定删除订单 ${row.order_no}？`, '删除确认', { type: 'warning' })
		const res = await businessApi.salesOrder.delete.delete(row.id)
		if (res.code === 200) {
			ElMessage.success('删除成功')
			doRefresh()
		}
	} catch {}
}

const onRowCommand = (row, cmd) => {
	if (cmd === 'print') handlePrint(row)
	else if (cmd === 'print-delivery') handlePrint(row, 'delivery')
	else if (cmd === 'copy') {
		currentOrder.value = { ...row, order_no: undefined }
		orderType.value = 'normal'
		dialog.order = true
	} else if (cmd === 'history') {
		handleDetail(row)
		detailTab.value = 'history'
	} else if (cmd === 'delete') handleDelete(row)
}

const handlePrint = async (row, mode) => {
	if (row.status === 'cancelled') {
		ElMessage.warning('已作废的订单不能打印')
		return
	}
	const res = await businessApi.salesOrder.print.post(row.id)
	if (res.code === 200) {
		window.open(`/print-order.html?data=${encodeURIComponent(JSON.stringify([{ ...res.data, print_user: 'admin', mode }]))}`, '_blank')
		doRefresh()
	}
}

const showHelp = () => ElMessage.info('帮助视频：订单列表使用教程开发中')

// ===== 客户 hover 卡片 =====
// 定位跟随触发的客户名元素（右下方 8px），并做边界收敛避免超出视口。
// 鼠标从名称移到卡片上时卡片保持显示：卡片自身也绑定 enter/leave，
// 只有「既不在名称上、也不在卡片上」才延时隐藏。
const hoverRow = ref(null)
const hoverCard = reactive({ visible: false, style: {} })
let enterTimer = null
let leaveTimer = null
const lastOrderDate = ref('')

const positionCard = (el) => {
	const rect = el.getBoundingClientRect()
	const CARD_W = 300
	const CARD_H = 240
	let left = rect.right + 8
	let top = rect.bottom + 8
	// 右侧空间不足则翻到左侧
	if (left + CARD_W > window.innerWidth - 8) {
		left = Math.max(8, rect.left - CARD_W - 8)
	}
	// 下方空间不足则上翻
	if (top + CARD_H > window.innerHeight - 8) {
		top = Math.max(8, window.innerHeight - CARD_H - 8)
	}
	hoverCard.style = { left: `${left}px`, top: `${top}px` }
}

const onCustomerEnter = (row, event) => {
	clearTimeout(enterTimer)
	clearTimeout(leaveTimer)
	const el = event?.currentTarget
	enterTimer = setTimeout(async () => {
		hoverRow.value = row
		lastOrderDate.value = row.order_date || '-'
		positionCard(el)
		hoverCard.visible = true
		// 客户档案补充电话/地址/欠款（列表只带了 name/address/category）
		const cust = await loadCustomerInfo(row.customer_id)
		if (cust && hoverRow.value?.id === row.id) {
			hoverRow.value = { ...row, customer: { ...row.customer, ...cust } }
		}
	}, 200)
}

const onCustomerLeave = () => {
	clearTimeout(enterTimer)
	clearTimeout(leaveTimer)
	leaveTimer = setTimeout(() => {
		hoverCard.visible = false
		hoverRow.value = null
	}, 300)
}

// 鼠标进入卡片：取消隐藏
const onCardEnter = () => {
	clearTimeout(leaveTimer)
	clearTimeout(enterTimer)
}

const loadCustomerInfo = async (id) => {
	if (!id) return null
	try {
		const res = await businessApi.customer.detail.get(id)
		if (res.code === 200) return res.data
	} catch {}
	return null
}

const formatRecent = (row) => row.order_date || '-'

const onCardClick = (cmd) => {
	const row = hoverRow.value
	if (!row) return
	if (cmd === 'view') {
		ElMessage.info('查看客户档案功能开发中')
	} else if (cmd === 'edit') {
		handleEdit(row)
	} else if (cmd === 'redflush') {
		ElMessageBox.prompt('红冲原因', '红冲确认', { inputType: 'textarea' })
			.then(({ value }) => businessApi.salesOrder.batchRedFlush.post({ ids: [row.id], type: 'cancel', reason: value }))
			.then((res) => res.code === 200 && (ElMessage.success('红冲成功'), doRefresh()))
			.catch(() => {})
	}
	hoverCard.visible = false
}

// ===== 键盘快捷键 =====
// _cursor 是临时标记（不写回 data，避免脏化），用于「当前高亮行」。
// 首次按 ↑↓ 时找不到 _cursor → 默认落在首/尾行。
const currentPageRows = computed(() => data.value)

const setCursor = (row) => {
	const rows = currentPageRows.value
	for (const r of rows) delete r._cursor
	if (row) row._cursor = true
}

const handlePageKeydown = (e) => {
	if (!currentPageRows.value.length) return
	const grid = tableRef.value?.getGridInstance?.()
	if (!grid) return
	// 输入框内不拦截
	if (['INPUT', 'TEXTAREA', 'SELECT'].includes(e.target.tagName) || e.target.isContentEditable) return

	if (e.key === 'ArrowDown') {
		e.preventDefault()
		const rows = currentPageRows.value
		const idx = rows.findIndex((r) => r._cursor)
		const next = rows[idx === -1 ? 0 : Math.min(idx + 1, rows.length - 1)]
		setCursor(next)
		grid.setCurrentRow?.(next)
	} else if (e.key === 'ArrowUp') {
		e.preventDefault()
		const rows = currentPageRows.value
		const idx = rows.findIndex((r) => r._cursor)
		const prev = rows[idx === -1 ? rows.length - 1 : Math.max(idx - 1, 0)]
		setCursor(prev)
		grid.setCurrentRow?.(prev)
	} else if (e.key === ' ') {
		e.preventDefault()
		const rows = currentPageRows.value
		const cur = rows.find((r) => r._cursor)
		if (cur) {
			grid.toggleCheckboxRow?.(cur, !grid.isCheckedRow?.(cur))
		}
	} else if (e.key === 'Enter') {
		e.preventDefault()
		const cur = currentPageRows.value.find((r) => r._cursor)
		if (cur) handleDetail(cur)
	} else if (e.key === 'Delete') {
		const cur = currentPageRows.value.find((r) => r._cursor)
		if (cur && cur.status === 'pending') handleDelete(cur)
	} else if (e.altKey && (e.key === 'c' || e.key === 'C')) {
		e.preventDefault()
		doSearch()
	}
}

// ===== 表格列配置 =====
const columns = [
	{ type: 'checkbox', width: 48, fixed: 'left' },
	{ prop: 'order_no', label: '单号', width: 100, slots: { default: 'order_no_default' } },
	{ prop: 'customer', label: '客户', width: 220, slots: { default: 'customer_default' } },
	{ prop: 'customer_category', label: '客户类别', width: 100, align: 'center', slots: { default: 'customer_category_default' } },
	{ prop: 'salesman_name', label: '业务员', width: 80, align: 'center' },
	{ prop: 'warehouse', label: '发货仓库', width: 100, align: 'center', slots: { default: 'warehouse_default' } },
	{ prop: 'total_qty', label: '数量', width: 80, align: 'right', slots: { default: 'total_qty_default' } },
	{ prop: 'total_amount', label: '金额', width: 100, align: 'right', slots: { default: 'total_amount_default' } },
	{ prop: 'stocker_name', label: '备货人', width: 80, align: 'center' },
	{ prop: 'operator_name', label: '操作人', width: 80, align: 'center' },
	{ prop: 'order_date', label: '时间', width: 140, align: 'center', slots: { default: 'order_time_default' } },
	{ prop: 'reconcile_date', label: '对账日期', width: 100, align: 'center' },
	{ prop: 'operation', label: '操作', width: 160, align: 'center', fixed: 'right', slots: { default: 'operation_default' } },
]

// ===== 弹窗 =====
const dialog = reactive({ order: false, detail: false })
const detailData = ref(null)
const detailTab = ref('info')
const currentOrder = ref(null)
const customers = ref([])
const suppliers = ref([])
const warehouses = ref([])
const salesmen = ref([])
const vehicles = ref([])
const orderType = ref('normal')

// ===== 工具 =====
const fmt = (n) => Number(n || 0).toFixed(2)
const fmtDate = (s) => {
	if (!s) return ''
	const d = new Date(s)
	return `${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}
const fmtTime = (s) => {
	if (!s) return ''
	const d = new Date(s)
	return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`
}
const eq = (a, b) => a != null && String(a) === String(b ?? '')

// ===== 生命周期 =====
onMounted(async () => {
	// 加载下拉数据（车辆、业务员等，独立于列表接口）
	try {
		await Promise.all([
			businessApi.vehicle.list.get({ is_active: 1, page_size: 9999 }).then((r) => (r.code === 200 && (vehicles.value = r.data?.list || []))),
			businessApi.employee.list.get({ is_active: 1, page_size: 9999 }).then((r) => (r.code === 200 && (salesmen.value = r.data?.list || []))),
			businessApi.customer.list.get({ page_size: 9999 }).then((r) => (r.code === 200 && (customers.value = r.data?.list || []))),
			businessApi.supplier.list.get({ page_size: 9999 }).then((r) => (r.code === 200 && (suppliers.value = r.data?.list || []))),
			businessApi.warehouse.list.get({ page_size: 9999 }).then((r) => (r.code === 200 && (warehouses.value = r.data?.list || []))),
		])
	} catch {}
	await doSearch()
	// 聚焦表格容器，让键盘事件生效
	nextTick(() => {
		document.querySelector('.so-page')?.focus()
	})
})

onBeforeUnmount(() => {
	enterTimer && clearTimeout(enterTimer)
	leaveTimer && clearTimeout(leaveTimer)
})

// ===== 分页 =====
const handlePageChange = (p) => {
	paginationProps.currentPage = p
	selectedRows.value = []
	fetchData()
}
const handlePageSizeChange = (s) => {
	paginationProps.pageSize = s
	paginationProps.currentPage = 1
	selectedRows.value = []
	fetchData()
}

watch([() => searchForm.status_tab, () => searchForm.order_type, () => searchForm.quick_date], () => {
	paginationProps.currentPage = 1
})
</script>

<style scoped>
.so-page {
	display: flex;
	height: 100%;
	background: #f0f2f5;
	outline: none;
}

/* ===== 左侧维度面板 ===== */
.so-aside {
	width: 180px;
	flex-shrink: 0;
	background: #f5f7fa;
	border-right: 1px solid #ebeef5;
	display: flex;
	flex-direction: column;
	overflow: hidden;
}

.aside-tabs {
	display: flex;
	flex-shrink: 0;
	border-bottom: 1px solid #ebeef5;
}

.aside-tab {
	flex: 1;
	text-align: center;
	height: 32px;
	line-height: 32px;
	font-size: 14px;
	color: #606266;
	cursor: pointer;
	border-bottom: 2px solid transparent;
}

.aside-tab.active {
	color: #409eff;
	border-bottom-color: #409eff;
}

.aside-scroll {
	flex: 1;
	overflow-y: auto;
}

.aside-item {
	height: 56px;
	padding: 8px 12px;
	cursor: pointer;
	border-bottom: 1px solid #ebeef5;
	border-left: 3px solid transparent;
}

.aside-item:hover {
	background: #e8f4fd;
}

.aside-item.active {
	background: #ecf5ff;
	border-left-color: #409eff;
}

.aside-total {
	height: 40px;
	background: #ebeef5;
	border-bottom: 1px solid #ebeef5;
}

.aside-top {
	display: flex;
	justify-content: space-between;
	align-items: center;
}

.aside-name {
	font-size: 14px;
	font-weight: bold;
	color: #303133;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	max-width: 70px;
}

.aside-amount {
	font-size: 14px;
	font-weight: bold;
	color: #e6a23c;
	display: flex;
	align-items: center;
	gap: 4px;
}

.aside-badge {
	min-width: 18px;
	height: 18px;
	line-height: 18px;
	padding: 0 4px;
	background: #e6a23c;
	color: #fff;
	border-radius: 9px;
	font-size: 12px;
	font-style: normal;
	text-align: center;
}

.aside-sub {
	display: flex;
	gap: 4px;
	font-size: 12px;
	color: #909399;
	line-height: 14px;
}

.sub-num {
	margin-left: 2px;
}

/* ===== 右侧主内容 ===== */
.so-main {
	flex: 1;
	background: #fff;
	display: flex;
	flex-direction: column;
	overflow: hidden;
}

/* ① 状态标签 */
.status-tabs {
	display: flex;
	height: 36px;
	border-bottom: 1px solid #ebeef5;
	flex-shrink: 0;
}

.status-tab {
	padding: 0 16px;
	line-height: 36px;
	font-size: 14px;
	color: #606266;
	cursor: pointer;
	position: relative;
}

.status-tab:hover {
	background: #f5f7fa;
}

.status-tab.active {
	color: #e6a23c;
	font-weight: bold;
	border-bottom: 2px solid #e6a23c;
}

.tab-badge {
	display: inline-block;
	min-width: 18px;
	height: 18px;
	line-height: 18px;
	padding: 0 4px;
	background: #e6a23c;
	color: #fff;
	border-radius: 9px;
	font-size: 12px;
	text-align: center;
	margin-left: 4px;
}

/* ② 快捷筛选 */
.quick-filters {
	height: 32px;
	display: flex;
	align-items: center;
	justify-content: flex-end;
	padding: 0 12px;
	gap: 12px;
	flex-shrink: 0;
	font-size: 12px;
	color: #606266;
}

.quick-label {
	display: flex;
	align-items: center;
	gap: 3px;
	cursor: pointer;
}

.quick-label input {
	width: 16px;
	height: 16px;
	accent-color: #409eff;
}

.quick-label b {
	margin-left: 3px;
	color: #e6a23c;
	font-weight: bold;
}

.help-link {
	color: #409eff;
	cursor: pointer;
	font-size: 12px;
	margin-left: 4px;
}

.help-link span {
	margin-left: 2px;
}

/* ③ 筛选区 */
.toolbar {
	height: 44px;
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 0 12px;
	border-bottom: 1px solid #ebeef5;
	flex-shrink: 0;
}

.toolbar .el-button--primary sub {
	text-decoration: underline;
	margin-left: 1px;
}

/* ④ 订单类型 */
.type-tabs {
	height: 32px;
	display: flex;
	align-items: center;
	padding: 0 12px;
	gap: 16px;
	border-bottom: 1px solid #ebeef5;
	flex-shrink: 0;
}

.type-tab {
	height: 32px;
	line-height: 32px;
	padding: 0 12px;
	font-size: 14px;
	color: #606266;
	cursor: pointer;
	border-bottom: 2px solid transparent;
}

.type-tab:hover {
	color: #409eff;
}

.type-tab.active {
	color: #409eff;
	border-bottom-color: #409eff;
}

.type-hint {
	margin-left: auto;
	font-size: 12px;
	color: #909399;
}

/* ⑤ 批量操作栏 */
.batch-bar {
	background: #fff;
	border: 1px solid #ebeef5;
	border-radius: 4px;
	margin: 8px 12px 0;
	flex-shrink: 0;
	overflow: hidden;
}

.batch-actions {
	height: 40px;
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 0 12px;
	border-bottom: 1px solid #ebeef5;
}

.batch-summary {
	height: 32px;
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 0 12px;
	background: #f0f9eb;
	font-size: 14px;
	color: #303133;
	flex-wrap: wrap;
}

.batch-summary i {
	color: #c0c4cc;
	font-style: normal;
}

/* ⑥ 表格容器 */
.so-main :deep(.sTable) {
	flex: 1;
	min-height: 0;
}

/* 行内样式 */
.order-no-link {
	color: #409eff;
	cursor: pointer;
	text-decoration: underline;
}

.customer-cell {
	overflow: hidden;
}

.customer-name-row {
	display: flex;
	align-items: center;
	gap: 4px;
	height: 22px;
	overflow: hidden;
}

.customer-name {
	font-weight: bold;
	color: #303133;
	max-width: 120px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	cursor: pointer;
}

.order-tag {
	height: 18px;
	padding: 0 6px;
	border-radius: 3px;
	font-size: 12px;
}

.customer-addr-row {
	display: flex;
	align-items: center;
	gap: 4px;
	height: 18px;
	overflow: hidden;
}

.customer-addr {
	font-size: 12px;
	color: #909399;
	max-width: 200px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.print-unprinted {
	font-size: 12px;
	color: #f56c6c;
	margin-left: auto;
}

.warehouse-link {
	color: #409eff;
	cursor: pointer;
	text-decoration: underline;
}

.order-time {
	font-size: 12px;
	color: #606266;
	line-height: 16px;
}

.row-ops {
	display: flex;
	align-items: center;
	gap: 8px;
	justify-content: center;
	flex-wrap: wrap;
}

.op {
	font-size: 14px;
	cursor: pointer;
	white-space: nowrap;
}

.op-primary {
	color: #409eff;
}

.op-success {
	color: #67c23a;
}

.op-danger {
	color: #f56c6c;
}

.op-muted {
	color: #909399;
}

.op:hover {
	text-decoration: underline;
}

/* 键盘选中行高亮（vxe） */
.so-main :deep(.vxe-body--row.is-current) {
	background: #fdf6ec !important;
}

/* 客户 hover 卡片 */
.customer-card {
	position: fixed;
	width: 300px;
	background: #fff;
	border: 1px solid #ebeef5;
	border-radius: 4px;
	box-shadow: 0 2px 12px rgba(0, 0, 0, 0.15);
	padding: 16px;
	z-index: 9999;
	opacity: 0;
	transition: opacity 0.2s;
	pointer-events: none;
}

.customer-card[style*='top:'] {
	opacity: 1;
	pointer-events: auto;
}

.card-name {
	font-size: 16px;
	font-weight: bold;
	color: #303133;
	margin-bottom: 8px;
	display: flex;
	align-items: center;
	gap: 6px;
}

.card-row {
	font-size: 14px;
	color: #606266;
	margin-bottom: 8px;
	line-height: 1.4;
}

.card-wrap {
	word-break: break-all;
}

.card-divider {
	height: 1px;
	background: #ebeef5;
	margin: 4px 0 12px;
	border: none;
}

.card-actions {
	display: flex;
	gap: 16px;
}

.card-actions a {
	color: #409eff;
	font-size: 14px;
	cursor: pointer;
}

.card-actions a:last-child {
	color: #f56c6c;
}

.card-actions a:hover {
	text-decoration: underline;
}
</style>
