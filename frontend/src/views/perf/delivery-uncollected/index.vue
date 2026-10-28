<template>
	<div class="perf-list perf-page">
		<div class="perf-filter">
			<span class="label">客户</span>
			<el-input v-model="search.customer_name" placeholder="客户" clearable size="small" style="width: 160px" @keyup.enter="refresh" @clear="refresh" />
			<span class="label">业务员</span>
			<el-input v-model="search.salesman_name" placeholder="业务员" clearable size="small" style="width: 140px" @keyup.enter="refresh" @clear="refresh" />
			<span class="label">发货日期</span>
			<el-date-picker v-model="dateRange" type="daterange" value-format="YYYY-MM-DD" start-placeholder="开始" end-placeholder="结束" size="small" style="width: 240px" @change="onDate" />
			<span class="label">天数区间</span>
			<el-input-number v-model="search.day_from" :min="0" :controls="false" size="small" style="width: 80px" placeholder="起" />
			<span style="color: var(--border-base)">~</span>
			<el-input-number v-model="search.day_to" :min="0" :controls="false" size="small" style="width: 80px" placeholder="止" />
			<el-button type="primary" size="small" @click="refresh">查询</el-button>
			<el-button size="small" @click="resetSearch">重置</el-button>
			<el-button size="small" :loading="exporting" @click="exportData">导出</el-button>
		</div>

		<div class="stat-cards" v-if="result?.cards">
			<div class="stat-card" v-for="c in cards" :key="c.key" :style="{ borderLeftColor: c.color }">
				<div class="stat-num">¥{{ fmtMoney(result.cards[c.key]) }}</div>
				<div class="stat-label">{{ c.label }}</div>
			</div>
		</div>

		<div class="perf-content">
			<el-table :data="pagedList" border stripe height="100%" v-loading="loading">
				<el-table-column prop="delivery_date" label="发货日期" width="120" align="center" />
				<el-table-column prop="order_no" label="单据号" min-width="160">
					<template #default="{ row }">
						<span class="link" @click="goOrder(row)">{{ row.order_no }}</span>
					</template>
				</el-table-column>
				<el-table-column prop="customer_name" label="客户" min-width="160" />
				<el-table-column prop="salesman_name" label="业务员" width="110" />
				<el-table-column prop="total_amount" label="发货金额" width="120" align="right">
					<template #default="{ row }"><span :class="negClass(row.total_amount)">¥{{ fmtMoney(row.total_amount) }}</span></template>
				</el-table-column>
				<el-table-column prop="paid_amount" label="已收金额" width="120" align="right">
					<template #default="{ row }"><span :class="negClass(row.paid_amount)">¥{{ fmtMoney(row.paid_amount) }}</span></template>
				</el-table-column>
				<el-table-column prop="uncollected" label="未收金额" width="120" align="right">
					<template #default="{ row }"><span class="text-red">¥{{ fmtMoney(row.uncollected) }}</span></template>
				</el-table-column>
				<el-table-column prop="age_days" label="账龄(天)" width="110" align="center">
					<template #default="{ row }">
						<span :style="{ color: ageColor(row), fontWeight: boldAge(row.age_days) }">{{ ageText(row.age_days) }}</span>
					</template>
				</el-table-column>
				<el-table-column prop="days_from_today" label="未收天数" width="110" align="center">
					<template #default="{ row }">
						<span :style="{ color: ageColor(row), fontWeight: boldAge(row.days_from_today ?? row.age_days) }">{{ ageText(row.days_from_today ?? row.age_days) }}</span>
					</template>
				</el-table-column>
				<el-table-column label="状态" width="90" align="center">
					<template #default="{ row }">
						<el-tag :type="statusType(row.status)" size="small">{{ statusLabel(row.status) }}</el-tag>
					</template>
				</el-table-column>
				<template #empty>
					<FinEmpty text="当前筛选条件下暂无数据" />
				</template>
			</el-table>
		</div>
		<div class="perf-pager">
			<el-pagination background layout="total, prev, pager, next" :total="total" :page-size="pageSize" :current-page="page" @current-change="onPage" />
		</div>
	</div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import { useRouter } from 'vue-router'
import perfApi from '@/api/perf'
import { saveBlob } from '@/utils/fileDownload'
import FinEmpty from '../../finance/_FinEmpty.vue'
import { toNum, isNilNum, fmtMoney } from '../_fmt.js'

// 负数标红（_fmt.js 归一后再比较，避免千分位串/NaN 漏判）
const negClass = (v) => (toNum(v) < 0 ? 'text-red' : '')
// 账龄天数：缺字段（null/undefined/空串）或非数值显示 "-"，绝不直出 NaN
const ageBlank = (v) => v === null || v === undefined || v === '' || isNilNum(v)
const ageText = (v) => (ageBlank(v) ? '-' : String(toNum(v)))
const boldAge = (v) => (!ageBlank(v) && toNum(v) > 30 ? 'bold' : 'normal')

const cards = [
	{ key: 'total_uncollected', label: '总未收金额', color: '#F56C6C' },
	{ key: 'b1_30', label: '1~30天', color: '#67C23A' },
	{ key: 'b31_60', label: '31~60天', color: '#E6A23E' },
	{ key: 'b61_90', label: '61~90天', color: '#F56C6C' },
	{ key: 'b90_plus', label: '90天以上', color: '#C0392B' },
]
const statusLabel = (s) => ({ normal: '正常', soon: '临期', overdue: '逾期' }[s] || s)
const statusType = (s) => ({ normal: 'success', soon: 'warning', overdue: 'danger' }[s] || 'info')
// 未收天数：>90 红 #F56C6C，>30 橙 #E6A23E，其余 #606266；优先后端 age_level/color
const ageColor = (row) => {
	if (row.color) return row.color
	const days = toNum(row.days_from_today ?? row.age_days)
	const lvl = row.age_level
	if (lvl === 'overdue' || lvl === 'high' || days > 90) return '#F56C6C'
	if (lvl === 'warning' || lvl === 'mid' || days > 30) return '#E6A23E'
	return '#606266'
}

const router = useRouter()
const dateRange = ref([])
const search = reactive({ customer_name: '', salesman_name: '', start: '', end: '', day_from: null, day_to: null })
const result = ref(null)
const loading = ref(false)
const exporting = ref(false)
const page = ref(1)
const pageSize = 30

// 筛选与分页由后端返回驱动
const buildParams = () => {
	const p = {
		customer_name: search.customer_name,
		salesman_name: search.salesman_name,
		start: search.start,
		end: search.end,
		day_from: search.day_from ?? '',
		day_to: search.day_to ?? '',
		page: page.value,
		page_size: pageSize,
	}
	return p
}
const list = computed(() => (result.value?.list || []))
const pagedList = computed(() => list.value) // 后端已分页（page/page_size/totals）
const total = computed(() => Number(result.value?.total ?? list.value.length))

const refresh = async () => {
	loading.value = true
	try {
		const res = await perfApi.delivery.uncollected.get(buildParams())
		if (res.code === 200) {
			result.value = res.data
		}
	} finally {
		loading.value = false
	}
}

const onDate = () => {
	search.start = dateRange.value?.[0] || ''
	search.end = dateRange.value?.[1] || ''
	page.value = 1
	refresh()
}
const onPage = (p) => {
	page.value = p
	refresh()
}
const resetSearch = () => {
	search.customer_name = ''
	search.salesman_name = ''
	search.start = ''
	search.end = ''
	search.day_from = null
	search.day_to = null
	dateRange.value = []
	page.value = 1
	refresh()
}

// 单号 → 销售单详情（禁止 /business/payment）
const goOrder = (row) => {
	router.push({ path: '/business/sales-order', query: { order_id: row.order_id || '', order_no: row.order_no } })
}

const exportData = async () => {
	if (!list.value.length) return ElMessage.warning('无数据')
	exporting.value = true
	try {
		const blob = await perfApi.delivery.export.get(buildParams())
		saveBlob(blob, `发货未收款_${search.start || ''}${search.end ? '_' + search.end : ''}.csv`)
		ElMessage.success('导出成功')
	} catch (e) {
		ElMessage.error(e?.message || '导出失败')
	} finally {
		exporting.value = false
	}
}

onMounted(refresh)
</script>

<style>
@import '../_shared.css';
</style>

<style scoped>
.perf-list { height: 100%; display: flex; flex-direction: column; overflow: auto; }
/* 排版（flex）留在本页，白底/内边距/下边框/标签字号由 _shared.css .perf-filter 提供 */
.perf-filter { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.stat-cards { display: flex; gap: 16px; padding: 12px 20px; }
.stat-card { flex: 1; background: var(--bg-card); height: 96px; border: 1px solid var(--border-base); border-left: 4px solid var(--color-danger); border-radius: var(--radius-md); padding: 16px 20px; display: flex; flex-direction: column; justify-content: center; }
.stat-num { font-size: var(--font-stat); font-weight: 600; color: var(--text-primary); font-variant-numeric: tabular-nums; font-family: var(--font-numeric); }
.stat-label { font-size: var(--font-base); color: var(--text-secondary); margin-top: 4px; }
.perf-content { flex: 1; overflow: auto; padding: 0 12px; }
.perf-pager { padding: 0 12px; }
.link { color: var(--color-primary); cursor: pointer; }
.text-red { color: var(--color-danger); font-weight: 600; }
/* 表头底色/字号/行高/斑马纹/hover 均由 _shared.css 提供，这里只留正文色与分隔线 */
:deep(.el-table) { color: var(--text-regular); }
:deep(.el-table td.el-table__cell),
:deep(.el-table th.el-table__cell) { border-bottom-color: var(--border-lighter); }
</style>
