<template>
	<div class="perf-list">
		<div class="perf-head">
			<div class="perf-title">退货汇总单</div>
			<div class="perf-filter">
				<span class="label">退货类型</span>
				<el-select v-model="returnType" placeholder="全部" clearable style="width: 130px" @change="refresh">
					<el-option label="客户退货" value="customer" />
					<el-option label="车辆退货" value="van" />
				</el-select>
				<span class="label">日期范围</span>
				<el-date-picker v-model="dateRange" type="daterange" value-format="YYYY-MM-DD" start-placeholder="开始" end-placeholder="结束" style="width: 260px" @change="refresh" />
				<span class="label">仓库</span>
				<el-select v-model="warehouseId" placeholder="全部仓库" clearable style="width: 150px" @change="refresh">
					<el-option v-for="w in warehouses" :key="w.id" :label="w.name" :value="w.id" />
				</el-select>
				<span class="label">渠道大类</span>
				<el-input v-model="channelCategory" placeholder="渠道大类" clearable style="width: 140px" @keyup.enter="refresh" />
				<span class="label">渠道小类</span>
				<el-input v-model="channelSubCategory" placeholder="渠道小类" clearable style="width: 140px" @keyup.enter="refresh" />
				<el-button type="primary" @click="refresh">查询</el-button>
				<el-button @click="onReset">重置</el-button>
			</div>
		</div>

		<div class="perf-table">
			<el-table :data="pagedList" border stripe height="100%" v-loading="loading" show-summary :summary-method="summaryMethod" empty-text="暂无数据">
				<el-table-column prop="return_type" label="退货类型" width="110">
					<template #default="{ row }">{{ returnTypeText(row) }}</template>
				</el-table-column>
				<el-table-column prop="channel_category" label="渠道大类" width="120" show-overflow-tooltip />
				<el-table-column prop="channel_sub_category" label="渠道小类" width="120" show-overflow-tooltip />
				<el-table-column prop="warehouse" label="仓库" min-width="140" show-overflow-tooltip>
					<template #default="{ row }">{{ row.warehouse || row.warehouse_name || '—' }}</template>
				</el-table-column>
				<el-table-column prop="date" label="日期" width="110">
					<template #default="{ row }">{{ fmtDay(row.date ?? row.return_date) }}</template>
				</el-table-column>
				<el-table-column prop="return_count" label="退货单数" width="100" align="right">
					<template #default="{ row }">{{ fmtCount(row.return_count) }}</template>
				</el-table-column>
				<el-table-column prop="return_qty" label="退货数量" width="120" align="right">
					<template #default="{ row }">{{ fmtQty(row.return_qty) }}</template>
				</el-table-column>
				<el-table-column prop="return_amount" label="退货金额" width="140" align="right">
					<template #default="{ row }">
						<span :class="moneyCls(row.return_amount)">{{ fmtAmount(row.return_amount) }}</span>
					</template>
				</el-table-column>
			</el-table>
		</div>

		<div class="perf-toolbar">
			<span class="pager-total">共 {{ total }} 条</span>
			<el-pagination
				layout="sizes, prev, pager, next, jumper"
				:page-sizes="[10, 20, 30, 50]"
				:page-size="pageSize"
				:total="total"
				:current-page="page"
				@current-change="onPage"
				@size-change="onSizeChange"
			/>
		</div>
	</div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import perfApi from '@/api/perf'
import businessApi from '@/api/business'

// 退货类型展示（后端已返回中文则原样显示）
const returnTypeText = (row) => row?.return_type_label || ({ customer: '客户退货', van: '车辆退货' }[row?.return_type] || row?.return_type || '—')
// 退货单数整数千分位
const fmtCount = (v) => {
	const n = Number(v || 0)
	const neg = n < 0
	const s = Math.abs(n).toFixed(0).replace(/\B(?=(\d{3})+(?!\d))/g, ',')
	return (neg ? '-' : '') + s
}
// 退货数量三位小数千分位
const fmtQty = (v) => {
	const n = Number(v || 0)
	const neg = n < 0
	const s = Math.abs(n).toFixed(3).replace(/\B(?=(\d{3})+(?!\d))/g, ',')
	return (neg ? '-' : '') + s
}
// 退货金额两位小数千分位，正数 #303133、负数红字
const fmtAmount = (v) => {
	const n = Number(v || 0)
	const neg = n < 0
	const s = Math.abs(n).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',')
	return (neg ? '-' : '') + s
}
const moneyCls = (v) => (Number(v || 0) < 0 ? 'text-red' : 'text-amount')
const fmtDay = (v) => (v ? String(v).slice(0, 10) : '—')

const returnType = ref('')
const dateRange = ref([])
const warehouseId = ref(null)
const channelCategory = ref('')
const channelSubCategory = ref('')
const warehouses = ref([])
const list = ref([])
const totals = ref(null)
const loading = ref(false)
const page = ref(1)
const pageSize = ref(30)
const total = computed(() => list.value.length)

const pagedList = computed(() => {
	const start = (page.value - 1) * pageSize.value
	return list.value.slice(start, start + pageSize.value)
})

const buildParams = () => {
	const p = { date_start: dateRange.value?.[0] || '', date_end: dateRange.value?.[1] || '' }
	if (returnType.value) p.return_type = returnType.value
	if (warehouseId.value !== null && warehouseId.value !== '') p.warehouse_id = warehouseId.value
	const c1 = channelCategory.value.trim()
	const c2 = channelSubCategory.value.trim()
	if (c1) p.channel_category = c1
	if (c2) p.channel_sub_category = c2
	return p
}

const refresh = async () => {
	if (!dateRange.value?.[0] || !dateRange.value?.[1]) return ElMessage.warning('请选择完整的日期范围')
	loading.value = true
	page.value = 1
	try {
		const res = await perfApi.reports.returnSummary.get(buildParams())
		if (res.code === 200) {
			list.value = res.data?.list || []
			totals.value = res.data?.totals || null
		}
	} finally {
		loading.value = false
	}
}

const onReset = () => {
	returnType.value = ''
	warehouseId.value = null
	channelCategory.value = ''
	channelSubCategory.value = ''
	const now = new Date()
	const first = new Date(now.getFullYear(), now.getMonth(), 1)
	const f = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
	dateRange.value = [f(first), f(now)]
	refresh()
}
const onPage = (p) => { page.value = p }
const onSizeChange = (s) => { pageSize.value = s; page.value = 1 }

// 底部合计行：优先用后端 totals（return_count/return_qty/return_amount），缺省按当前列表汇总
const summaryMethod = ({ columns: cols }) => {
	const sum = (key) => {
		if (totals.value && totals.value[key] !== undefined && totals.value[key] !== null) return Number(totals.value[key])
		return list.value.reduce((s, r) => s + Number(r[key] || 0), 0)
	}
	return cols.map((c, i) => {
		if (i === 0) return '合计'
		if (c.prop === 'return_count') return fmtCount(sum('return_count'))
		if (c.prop === 'return_qty') return fmtQty(sum('return_qty'))
		if (c.prop === 'return_amount') return fmtAmount(sum('return_amount'))
		return ''
	})
}

const fetchWarehouses = async () => {
	try {
		const res = await businessApi.warehouse.list.get({ page_size: 200 })
		warehouses.value = res.data?.list || res.data || []
	} catch {}
}

onMounted(() => {
	const now = new Date()
	const first = new Date(now.getFullYear(), now.getMonth(), 1)
	const f = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
	dateRange.value = [f(first), f(now)]
	fetchWarehouses()
	refresh()
})
</script>

<style scoped>
.perf-list { height: 100%; display: flex; flex-direction: column; overflow: auto; }
/* 白底查询区：标题 18px 加粗 #303133，下边框 1px #EBEEF5，字段间距 12px，标签 14px #606266 */
.perf-head { background: #fff; border-bottom: 1px solid #EBEEF5; }
.perf-title { padding: 16px 20px 0; font-size: 18px; font-weight: 700; color: #303133; }
.perf-filter { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; padding: 16px 20px; }
.perf-filter .label { font-size: 14px; color: #606266; }
/* 列表页按钮：主 36px 蓝底 #409EFF 白字 14px 圆角 4px；次要白底 1px #DCDFE6 文字 #606266 */
.perf-filter .el-button { height: 36px; padding: 0 20px; font-size: 14px; border-radius: 4px; }
.perf-filter .el-button--primary { background: #409EFF; border-color: #409EFF; color: #fff; }
.perf-filter .el-button:not(.el-button--primary) { background: #fff; border: 1px solid #DCDFE6; color: #606266; }

.perf-table { flex: 1; min-height: 0; }
/* 表头 #F5F7FA 14px 500 #606266 行高 48px；表体 14px #606266 行高 48px；斑马偶数 #FAFAFA；悬停 #F5F7FA */
:deep(.el-table th.el-table__cell) { background: #F5F7FA !important; height: 48px; padding-top: 0; padding-bottom: 0; }
:deep(.el-table th.el-table__cell .cell) { color: #606266; font-size: 14px; font-weight: 500; line-height: 24px; padding-top: 0; padding-bottom: 0; }
:deep(.el-table td.el-table__cell) { height: 48px; padding-top: 0; padding-bottom: 0; }
:deep(.el-table td.el-table__cell .cell) { color: #606266; font-size: 14px; line-height: 24px; padding-top: 0; padding-bottom: 0; }
:deep(.el-table .el-table__body tr.el-table__row--striped td.el-table__cell) { background: #FAFAFA; }
:deep(.el-table--enable-row-hover .el-table__body tr:hover > td.el-table__cell) { background: #F5F7FA !important; }
/* 合计行加粗 */
:deep(.el-table__footer td.el-table__cell .cell) { font-weight: 700; }
.text-red { color: #F56C6C; }
.text-amount { color: #303133; }

.perf-toolbar { display: flex; align-items: center; justify-content: flex-end; gap: 12px; margin: 12px 20px; }
.pager-total { font-size: 14px; color: #606266; }
:deep(.perf-toolbar .el-pagination) { font-size: 14px; color: #606266; --el-pagination-text-color: #606266; --el-pagination-button-color: #606266; }
</style>
