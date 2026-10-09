<template>
	<div class="biz-list">
		<!-- 筛选区 -->
		<div class="filter-bar">
			<el-date-picker v-model="filters.dateRange" type="daterange" range-separator="至" start-placeholder="开始日期" end-placeholder="结束日期" size="small" style="width:240px" value-format="YYYY-MM-DD" />
			<el-select v-model="filters.types" multiple collapse-tags collapse-tags-tooltip placeholder="单据类型" clearable size="small" style="width:200px">
				<el-option v-for="opt in typeOptions" :key="opt.value" :label="opt.label" :value="opt.value" />
			</el-select>
			<el-select v-model="filters.salesman_id" placeholder="业务员" clearable filterable size="small" style="width:120px">
				<el-option v-for="u in users" :key="u.id" :label="u.real_name || u.username" :value="u.id" />
			</el-select>
			<el-select v-model="filters.partner_id" placeholder="客户/供应商" clearable filterable size="small" style="width:160px">
				<el-option v-for="c in customers" :key="'c'+c.id" :label="c.name" :value="'c'+c.id" />
				<el-option v-for="s in suppliers" :key="'s'+s.id" :label="s.name" :value="'s'+s.id" />
			</el-select>
			<el-select v-model="filters.warehouse_id" placeholder="仓库" clearable size="small" style="width:120px">
				<el-option v-for="w in warehouses" :key="w.id" :label="w.name" :value="w.id" />
			</el-select>
			<el-input-number v-model="filters.min_amount" :controls="false" placeholder="最小金额" size="small" style="width:100px" />
			<el-input-number v-model="filters.max_amount" :controls="false" placeholder="最大金额" size="small" style="width:100px" />
			<el-input v-model="filters.order_no" placeholder="单据号" clearable size="small" style="width:140px" @keyup.enter="fetchData" />
			<el-button size="small" type="primary" @click="fetchData">查询</el-button>
			<el-button size="small" @click="handleReset">重置</el-button>
			<el-button size="small" type="success" @click="handleExport">导出</el-button>
		</div>

		<!-- 汇总栏 -->
		<div class="summary-bar">
			<span>共 {{ summary.total_count }} 条记录</span>
			<span class="spacer" />
			<span>收入合计 <b class="text-red">¥{{ Number(summary.total_income).toFixed(2) }}</b></span>
			<span class="sep">|</span>
			<span>支出合计 <b class="text-green">¥{{ Number(summary.total_expense).toFixed(2) }}</b></span>
			<span class="sep">|</span>
			<span>净额 <b class="text-blue">¥{{ Number(summary.net_amount).toFixed(2) }}</b></span>
		</div>

		<!-- 数据表格 -->
		<sTable ref="tableRef" tableName="business_history" :data="data" :columns="columns" :loading="loading" height="100%" stripe>
			<template #order_no="{ row }">
				<a class="link" @click="handleView(row)">{{ row.order_no }}</a>
			</template>
			<template #type_label="{ row }">
				<el-tag :type="row.type_color || 'info'" size="small">{{ row.type_label }}</el-tag>
			</template>
			<template #income="{ row }">
				<span v-if="row.income > 0" class="text-red">¥{{ Number(row.income).toFixed(2) }}</span>
				<span v-else>-</span>
			</template>
			<template #expense="{ row }">
				<span v-if="row.expense > 0" class="text-green">¥{{ Number(row.expense).toFixed(2) }}</span>
				<span v-else>-</span>
			</template>
		</sTable>

		<!-- 分页 -->
		<div class="pagination-bar">
			<el-pagination background layout="total, prev, pager, next, jumper" :total="pagination.total" :page-size="pagination.page_size" :current-page="pagination.page" @current-change="handlePageChange" />
		</div>
	</div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import businessApi from '@/api/business'
import authApi from '@/api/auth'
import sTable from '@/components/sTable/index.vue'

const data = ref([])
const loading = ref(false)
const users = ref([])
const customers = ref([])
const suppliers = ref([])
const warehouses = ref([])
const summary = ref({ total_income: 0, total_expense: 0, net_amount: 0, total_count: 0 })

const filters = reactive({
	dateRange: null,
	types: [],
	salesman_id: null,
	partner_id: null,
	warehouse_id: null,
	min_amount: null,
	max_amount: null,
	order_no: '',
})

const pagination = reactive({ page: 1, page_size: 20, total: 0 })

const typeOptions = [
	{ label: '销售订单', value: 'sales_order' },
	{ label: '销售出库', value: 'delivery' },
	{ label: '采购入库', value: 'stock_in' },
	{ label: '采购退货', value: 'purchase_return' },
	{ label: '销售退货', value: 'sales_return' },
	{ label: '收款单', value: 'receive' },
	{ label: '付款单', value: 'pay' },
	{ label: '现金费用', value: 'expense' },
	{ label: '库存盘点', value: 'stock_check' },
	{ label: '库存调整', value: 'stock_adjust' },
	{ label: '商品组装', value: 'assembly' },
	{ label: '商品拆分', value: 'split' },
	{ label: '采购申请', value: 'purchase_application' },
]

const columns = [
	{ prop: 'date', title: '单据日期', width: 120, align: 'center' },
	{ prop: 'order_no', title: '单据号', width: 180, slots: { default: 'order_no' } },
	{ prop: 'type_label', title: '单据类型', width: 100, align: 'center', slots: { default: 'type_label' } },
	{ prop: 'partner_name', title: '客户/供应商', width: 180, showOverflowTooltip: true },
	{ prop: 'warehouse_name', title: '仓库', width: 100, align: 'center' },
	{ prop: 'salesman_name', title: '业务员', width: 100, align: 'center' },
	{ prop: 'income', title: '收入金额', width: 120, align: 'right', slots: { default: 'income' } },
	{ prop: 'expense', title: '支出金额', width: 120, align: 'right', slots: { default: 'expense' } },
	{ prop: 'remark', title: '备注', width: 200, showOverflowTooltip: true },
]

function buildParams() {
	const p = {
		page: pagination.page,
		page_size: pagination.page_size,
	}
	if (filters.dateRange?.[0]) p.start_date = filters.dateRange[0]
	if (filters.dateRange?.[1]) p.end_date = filters.dateRange[1]
	if (filters.types.length) p.types = filters.types
	if (filters.salesman_id) p.salesman_id = filters.salesman_id
	if (filters.partner_id) {
		const pid = filters.partner_id
		if (pid.startsWith('c')) p.customer_id = parseInt(pid.slice(1))
		else p.supplier_id = parseInt(pid.slice(1))
	}
	if (filters.warehouse_id) p.warehouse_id = filters.warehouse_id
	if (filters.min_amount != null) p.min_amount = filters.min_amount
	if (filters.max_amount != null) p.max_amount = filters.max_amount
	if (filters.order_no) p.order_no = filters.order_no
	return p
}

async function fetchData() {
	loading.value = true
	try {
		const res = await businessApi.businessHistory.list.get(buildParams())
		if (res.code === 200) {
			data.value = res.data?.list || []
			pagination.total = res.data?.total || 0
		}
		// 同时拉汇总
		const sumRes = await businessApi.businessHistory.summary.get(buildParams())
		if (sumRes.code === 200) summary.value = sumRes.data
	} catch { ElMessage.error('加载失败') }
	finally { loading.value = false }
}

function handleReset() {
	Object.assign(filters, {
		dateRange: null, types: [], salesman_id: null, partner_id: null,
		warehouse_id: null, min_amount: null, max_amount: null, order_no: '',
	})
	pagination.page = 1
	fetchData()
}

function handlePageChange(p) {
	pagination.page = p
	fetchData()
}

function handleView(row) {
	// 根据 type_key 跳转到对应详情（路由内打开或弹窗）
	ElMessage.info(`查看 ${row.type_label}：${row.order_no}（详情待接入）`)
}

async function handleExport() {
	const params = new URLSearchParams(buildParams())
	const token = localStorage.getItem('laradmin_token') || localStorage.getItem('token')
	const res = await fetch(`/admin/business/history/export?${params}`, {
		headers: { Authorization: `Bearer ${token}` },
	})
	if (res.ok) {
		const blob = await res.blob()
		const url = URL.createObjectURL(blob)
		const a = document.createElement('a')
		a.href = url
		a.download = `经营历程_${new Date().toISOString().slice(0, 10)}.csv`
		a.click()
		URL.revokeObjectURL(url)
	} else {
		ElMessage.error('导出失败')
	}
}

async function loadUsers() {
	try {
		const res = await authApi.user.list.get({ page_size: 200 })
		users.value = res.data?.list || res.data?.data || []
	} catch {}
}

async function loadCustomers() {
	try {
		const res = await businessApi.customer.list.get({ page_size: 1000 })
		customers.value = res.data?.list || res.data?.data || []
	} catch {}
}

async function loadSuppliers() {
	try {
		const res = await businessApi.supplier.list.get({ page_size: 1000 })
		suppliers.value = res.data?.list || res.data?.data || []
	} catch {}
}

// 兼容：如果 supplier.list 不存在，用 supplier 默认 GET
if (!businessApi.supplier?.list) {
	businessApi.supplier.list = { get: (params) => Promise.resolve({ code: 200, data: { list: [] } }) }
}

async function loadWarehouses() {
	try {
		const res = await businessApi.warehouse.list.get({type:"normal",  page_size: 100 })
		warehouses.value = res.data?.list || res.data || []
	} catch {}
}

onMounted(() => {
	fetchData()
	loadUsers()
	loadCustomers()
	loadSuppliers()
	loadWarehouses()
})
</script>

<style scoped>
.biz-list { height: 100%; display: flex; flex-direction: column; }
.filter-bar { display: flex; align-items: center; gap: 8px; padding: 8px 12px; flex-wrap: wrap; background: #fff; border-bottom: 1px solid #ebeef5; }
.summary-bar { display: flex; align-items: center; gap: 8px; padding: 6px 12px; background: #f0f2f5; font-size: 13px; }
.summary-bar .spacer { flex: 1; }
.summary-bar .sep { color: #c0c4cc; margin: 0 4px; }
.pagination-bar { display: flex; justify-content: flex-end; padding: 8px 12px; background: #fff; border-top: 1px solid #ebeef5; }
.link { color: #409eff; cursor: pointer; }
.link:hover { text-decoration: underline; }
.text-red { color: #f56c6c; font-weight: 600; }
.text-green { color: #67c23a; font-weight: 600; }
.text-blue { color: #409eff; font-weight: 600; }
</style>
