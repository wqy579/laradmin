<template>
	<div class="perf-list perf-page">
		<div class="perf-filter">
			<el-radio-group v-model="dimension" size="small" @change="onDimension">
				<el-radio-button label="customer">按客户</el-radio-button>
				<el-radio-button label="salesman">按业务员</el-radio-button>
			</el-radio-group>
			<span class="label">客户</span>
			<el-input v-model="search.customer_name" placeholder="客户名称" clearable size="small" style="width: 150px" @keyup.enter="refresh" @clear="refresh" />
			<span class="label">业务员</span>
			<el-input v-model="search.salesman_name" placeholder="业务员" clearable size="small" style="width: 130px" @keyup.enter="refresh" @clear="refresh" />
			<span class="label">截止日期</span>
			<el-date-picker v-model="search.as_of_date" type="date" value-format="YYYY-MM-DD" size="small" style="width: 150px" @change="refresh" />
			<el-button type="primary" size="small" @click="refresh">查询</el-button>
			<el-button size="small" @click="resetSearch">重置</el-button>
			<el-button size="small" :loading="exporting" @click="exportData">导出</el-button>
		</div>

		<!-- 4 统计卡 -->
		<div class="stat-cards" v-if="result?.cards">
			<div class="stat-card" v-for="c in cards" :key="c.key" :style="{ borderLeftColor: c.color }">
				<div class="stat-num">¥{{ fmtMoney(result.cards[c.key]) }}</div>
				<div class="stat-label">{{ c.label }}</div>
			</div>
		</div>

		<div class="perf-content">
			<el-table :data="pagedList" border stripe height="100%" v-loading="loading" show-summary :summary-method="summaryMethod" @expand-change="(row) => loadDetails(row)">
				<el-table-column type="expand">
					<template #default="{ row }">
						<div class="detail-box" v-loading="row._loading">
							<el-table v-if="detailMap[row[dimKey]]?.length" :data="detailMap[row[dimKey]]" border size="small" class="perf-table--dense">
								<el-table-column prop="order_no" label="单据号" min-width="150">
									<template #default="{ row: o }">
										<span class="link" @click="goOrder(o)">{{ o.order_no }}</span>
									</template>
								</el-table-column>
								<el-table-column v-if="dimension === 'salesman'" prop="customer_name" label="客户" min-width="150" />
								<el-table-column prop="order_date" label="日期" width="120" align="center" />
								<el-table-column prop="total_amount" label="金额" width="130" align="right">
									<template #default="{ row: o }"><span :class="negClass(o.total_amount)">¥{{ fmtMoney(o.total_amount) }}</span></template>
								</el-table-column>
								<el-table-column prop="paid_amount" label="已收" width="130" align="right">
									<template #default="{ row: o }"><span :class="negClass(o.paid_amount)">¥{{ fmtMoney(o.paid_amount) }}</span></template>
								</el-table-column>
								<el-table-column prop="outstanding" label="未收" width="130" align="right">
									<template #default="{ row: o }"><span class="text-red">¥{{ fmtMoney(o.outstanding) }}</span></template>
								</el-table-column>
								<el-table-column prop="age_days" label="账龄(天)" width="100" align="center" />
								<template #empty>
									<span class="perf-empty">当前筛选条件下暂无数据</span>
								</template>
							</el-table>
							<span v-else class="perf-empty">当前筛选条件下暂无数据</span>
						</div>
					</template>
				</el-table-column>

				<!-- 客户维度 -->
				<template v-if="dimension === 'customer'">
					<el-table-column prop="customer_code" label="客户编号" width="120" />
					<el-table-column prop="customer_name" label="客户名称" min-width="190" />
					<el-table-column prop="salesman_name" label="业务员" width="110" />
					<el-table-column prop="outstanding" label="应收余额" width="130" align="right">
						<template #default="{ row }"><span :class="negClass(row.outstanding)">¥{{ fmtMoney(row.outstanding) }}</span></template>
					</el-table-column>
					<el-table-column label="额度占用%" width="130" align="right">
						<template #default="{ row }">{{ fmtRate(row.credit_usage_rate) }}%</template>
					</el-table-column>
					<el-table-column prop="unmatured" label="未到期" width="120" align="right">
						<template #default="{ row }"><span :class="negClass(row.unmatured)">¥{{ fmtMoney(row.unmatured) }}</span></template>
					</el-table-column>
					<el-table-column prop="b1_30" label="1~30天" width="120" align="right">
						<template #default="{ row }"><span :style="{ color: agingColor(30) }">¥{{ fmtMoney(row.b1_30) }}</span></template>
					</el-table-column>
					<el-table-column prop="b31_60" label="31~60天" width="120" align="right">
						<template #default="{ row }"><span :style="{ color: agingColor(60) }">¥{{ fmtMoney(row.b31_60) }}</span></template>
					</el-table-column>
					<el-table-column prop="b61_90" label="61~90天" width="120" align="right">
						<template #default="{ row }"><span :style="{ color: agingColor(90) }">¥{{ fmtMoney(row.b61_90) }}</span></template>
					</el-table-column>
					<el-table-column prop="b91_180" label="91~180天" width="130" align="right">
						<template #default="{ row }"><span :style="{ color: agingColor(180), fontWeight: 'bold' }">¥{{ fmtMoney(row.b91_180) }}</span></template>
					</el-table-column>
					<el-table-column prop="b180_plus" label="180天以上" width="130" align="right">
						<template #default="{ row }"><span :style="{ color: agingColor(999), fontWeight: 'bold' }">¥{{ fmtMoney(row.b180_plus) }}</span></template>
					</el-table-column>
					<el-table-column prop="max_age" label="最长逾期" width="100" align="center">
						<template #default="{ row }"><span class="text-red">{{ row.max_age }}天</span></template>
					</el-table-column>
					<el-table-column label="状态" width="90" align="center">
						<template #default="{ row }">
							<el-tag :type="riskType(row.status)" size="small">{{ riskLabel(row.status) }}</el-tag>
						</template>
					</el-table-column>
				</template>

				<!-- 业务员维度 -->
				<template v-else>
					<el-table-column prop="salesman_name" label="业务员" min-width="170" />
					<el-table-column prop="customer_count" label="客户数" width="90" align="center" />
					<el-table-column prop="outstanding" label="应收余额" width="130" align="right">
						<template #default="{ row }"><span :class="negClass(row.outstanding)">¥{{ fmtMoney(row.outstanding) }}</span></template>
					</el-table-column>
					<el-table-column prop="unmatured" label="未到期" width="120" align="right">
						<template #default="{ row }"><span :class="negClass(row.unmatured)">¥{{ fmtMoney(row.unmatured) }}</span></template>
					</el-table-column>
					<el-table-column prop="b1_30" label="1~30天" width="120" align="right">
						<template #default="{ row }"><span :style="{ color: agingColor(30) }">¥{{ fmtMoney(row.b1_30) }}</span></template>
					</el-table-column>
					<el-table-column prop="b31_60" label="31~60天" width="120" align="right">
						<template #default="{ row }"><span :style="{ color: agingColor(60) }">¥{{ fmtMoney(row.b31_60) }}</span></template>
					</el-table-column>
					<el-table-column prop="b61_90" label="61~90天" width="120" align="right">
						<template #default="{ row }"><span :style="{ color: agingColor(90) }">¥{{ fmtMoney(row.b61_90) }}</span></template>
					</el-table-column>
					<el-table-column prop="b91_180" label="91~180天" width="130" align="right">
						<template #default="{ row }"><span :style="{ color: agingColor(180), fontWeight: 'bold' }">¥{{ fmtMoney(row.b91_180) }}</span></template>
					</el-table-column>
					<el-table-column prop="b180_plus" label="180天以上" width="130" align="right">
						<template #default="{ row }"><span :style="{ color: agingColor(999), fontWeight: 'bold' }">¥{{ fmtMoney(row.b180_plus) }}</span></template>
					</el-table-column>
					<el-table-column prop="max_age" label="最长逾期" width="100" align="center">
						<template #default="{ row }"><span class="text-red">{{ row.max_age }}天</span></template>
					</el-table-column>
					<el-table-column label="状态" width="90" align="center">
						<template #default="{ row }">
							<el-tag :type="riskType(row.status)" size="small">{{ riskLabel(row.status) }}</el-tag>
						</template>
					</el-table-column>
				</template>

				<template #empty>
					<FinEmpty text="当前筛选条件下暂无数据" />
				</template>
			</el-table>
		</div>
		<div class="perf-pager">
			<el-pagination background layout="total, prev, pager, next" :total="total" :page-size="pageSize" :current-page="page" @current-change="(p) => (page = p)" />
		</div>
	</div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, watch } from 'vue'
import { ElMessage } from 'element-plus'
import { useRouter } from 'vue-router'
import perfApi from '@/api/perf'
import { saveBlob } from '@/utils/fileDownload'
import FinEmpty from '../../finance/_FinEmpty.vue'
import { fmtMoney, fmtRate } from '../_fmt.js'

// 金额千分位 + 两位小数（_fmt.js 统一兜底非有限值）；负数 #F56C6C
const negClass = (v) => (Number(v) < 0 ? 'text-red' : '')

const cards = [
	{ key: 'total', label: '应收总额', color: '#409EFF' },
	{ key: 'unmatured', label: '未逾期', color: '#67C23A' },
	{ key: 'overdue', label: '逾期金额', color: '#E6A23E' },
	{ key: 'overdue_90', label: '逾期超90天', color: '#F56C6C' },
]
const agingColor = (days) => (days <= 30 ? '#67C23A' : days <= 60 ? '#E6A23E' : days <= 90 ? '#F56C6C' : '#C0392B')
const riskLabel = (s) => ({ normal: '正常', warning: '预警', high_risk: '高风险' }[s] || s)
const riskType = (s) => ({ normal: 'success', warning: 'warning', high_risk: 'danger' }[s] || 'info')

const router = useRouter()
const dimension = ref('customer')
const search = reactive({ customer_name: '', salesman_name: '', as_of_date: new Date().toISOString().slice(0, 10) })
const result = ref(null)
const loading = ref(false)
const exporting = ref(false)
const page = ref(1)
const pageSize = 30
const detailMap = reactive({}) // 维度 id -> 下钻明细（优先 details 接口）

// 展开列按下钻维度取 id 键（客户维度 customer_id，业务员维度 salesman_id）
const dimKey = computed(() => (dimension.value === 'salesman' ? 'salesman_id' : 'customer_id'))

const buildParams = () => ({ ...search, dimension: dimension.value })

// 前端筛选 + 分页（后端一次返回全量 list）
const filteredList = computed(() => {
	if (!result.value?.list) return []
	return result.value.list.filter((r) => {
		if (dimension.value === 'salesman') {
			if (search.salesman_name && !(r.salesman_name || '').includes(search.salesman_name)) return false
			return true
		}
		if (search.customer_name && !(r.customer_name || '').includes(search.customer_name)) return false
		if (search.salesman_name && !(r.salesman_name || '').includes(search.salesman_name)) return false
		return true
	})
})
const total = computed(() => filteredList.value.length)
const pagedList = computed(() => {
	const start = (page.value - 1) * pageSize
	return filteredList.value.slice(start, start + pageSize)
})

const refresh = async () => {
	loading.value = true
	try {
		const res = await perfApi.ar.aging.get(buildParams())
		if (res.code === 200) {
			result.value = res.data
			page.value = 1
		}
	} finally {
		loading.value = false
	}
}

const onDimension = () => {
	page.value = 1
	for (const k of Object.keys(detailMap)) delete detailMap[k]
	refresh()
}

const resetSearch = () => {
	search.customer_name = ''
	search.salesman_name = ''
	search.as_of_date = new Date().toISOString().slice(0, 10)
	refresh()
}

// 行下钻：优先 details 接口，回退 row.orders
const loadDetails = async (row) => {
	const key = row[dimKey.value]
	if (!key || detailMap[key]) return
	row._loading = true
	try {
		const res = await perfApi.ar.details(key, { dimension: dimension.value, as_of_date: search.as_of_date })
		if (res.code === 200 && res.data?.list) {
			detailMap[key] = res.data.list
		} else if (row.orders?.length) {
			detailMap[key] = row.orders
		} else {
			detailMap[key] = []
		}
	} catch (e) {
		detailMap[key] = row.orders || []
	} finally {
		row._loading = false
	}
}
// 单号蓝色可点 → 销售单详情
const goOrder = (o) => {
	if (o.order_id || o.id) {
		router.push({ path: '/business/sales-order', query: { order_id: o.order_id || o.id, order_no: o.order_no } })
	} else {
		router.push({ path: '/business/sales-order', query: { order_no: o.order_no } })
	}
}

// 合计行：用后端全量 totals（按维度合计），非当前页小计
const summaryMethod = ({ columns }) => {
	const t = result.value?.totals || {}
	const moneyKeys = { outstanding: 1, unmatured: 1, b1_30: 1, b31_60: 1, b61_90: 1, b91_180: 1, b180_plus: 1 }
	return columns.map((col, i) => {
		if (i === 0) return '合计'
		if (col.property && moneyKeys[col.property]) return '¥' + fmtMoney(t[col.property])
		return ''
	})
}

const exportData = async () => {
	if (!filteredList.value.length) return ElMessage.warning('无数据')
	exporting.value = true
	try {
		const blob = await perfApi.ar.export.get(buildParams())
		saveBlob(blob, `应收账龄分析_${search.as_of_date || ''}.csv`)
		ElMessage.success('导出成功')
	} catch (e) {
		ElMessage.error(e?.message || '导出失败')
	} finally {
		exporting.value = false
	}
}

// 筛选变化时回到第一页
watch(() => search.as_of_date, () => {
	page.value = 1
})

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
.stat-card { flex: 1; background: var(--bg-card); height: 96px; border: 1px solid var(--border-base); border-left: 4px solid var(--color-primary); border-radius: var(--radius-md); padding: 16px 20px; display: flex; flex-direction: column; justify-content: center; }
.stat-num { font-size: var(--font-stat); font-weight: 600; color: var(--text-primary); font-variant-numeric: tabular-nums; font-family: var(--font-numeric); }
.stat-label { font-size: var(--font-base); color: var(--text-secondary); margin-top: 4px; }
.perf-content { flex: 1; overflow: auto; padding: 0 12px; }
.perf-pager { padding: 0 12px; }
.detail-box { padding: 8px 12px 8px 48px; }
.link { color: var(--color-primary); cursor: pointer; }
.text-red { color: var(--color-danger); font-weight: 600; }
/* 表头底色/字号/行高/斑马纹/hover 均由 _shared.css 提供，这里只留正文色与分隔线 */
:deep(.el-table) { color: var(--text-regular); }
:deep(.el-table td.el-table__cell),
:deep(.el-table th.el-table__cell) { border-bottom-color: var(--border-lighter); }
</style>