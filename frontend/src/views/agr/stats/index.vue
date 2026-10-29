<template>
	<div class="agr-stats agr-page">
		<!-- 查询区：类型固定陈列协议、状态由服务端全量统计，故不提供类型/状态下拉 -->
		<div class="agr-filter">
			<span class="label">关键字</span>
			<el-input v-model="search.keyword" placeholder="编号 / 名称 / 客户" clearable size="small" style="width: 190px" @keyup.enter="refresh" @clear="refresh" />
			<span class="label">客户</span>
			<el-select v-model="search.customer_id" placeholder="全部" clearable filterable size="small" style="width: 180px" @change="refresh">
				<el-option v-for="c in customers" :key="c.id" :label="c.name" :value="c.id" />
			</el-select>
			<span class="label">有效期</span>
			<el-date-picker
				v-model="dateRange"
				type="daterange"
				value-format="YYYY-MM-DD"
				range-separator="~"
				start-placeholder="开始日"
				end-placeholder="结束日"
				size="small"
				style="width: 230px"
				@change="refresh"
			/>
			<el-button type="primary" size="small" @click="refresh">查询</el-button>
			<el-button size="small" @click="resetSearch">重置</el-button>
		</div>

		<!-- 汇总卡片 -->
		<div class="stat-cards">
			<div class="card">
				<div class="k">协议额合计</div>
				<div class="v amt">¥{{ fmtMoney(stat.amount) }}</div>
				<div class="s">陈列协议 {{ stat.count }} 份</div>
			</div>
			<div class="card">
				<div class="k">已兑现合计</div>
				<div class="v amt is-ok">¥{{ fmtMoney(stat.redeemed_amount) }}</div>
				<div class="s">待兑现 <span class="amt">¥{{ fmtMoney(pendingTotal) }}</span></div>
			</div>
			<div class="card">
				<div class="k">总兑现率</div>
				<div class="v amt" :class="rateClass(stat.redemption_rate)">{{ fmtRate(stat.redemption_rate) }}%</div>
				<el-progress :percentage="clampedRate(stat.redemption_rate)" :color="rateColor(stat.redemption_rate)" :stroke-width="10" :show-text="false" />
			</div>
			<div class="card">
				<div class="k">状态分布</div>
				<div class="tri">
					<span class="t ok">生效中 <b class="amt">{{ stat.active_count }}</b></span>
					<span class="t off">已到期 <b class="amt">{{ stat.expired_count }}</b></span>
					<span class="t stop">已终止 <b class="amt">{{ stat.terminated_count }}</b></span>
				</div>
			</div>
		</div>

		<div class="agr-toolbar">
			<el-button v-permission="'agr:stats:export'" :loading="exporting" @click="doExport">导出统计</el-button>
			<span class="hint">按兑现率从高到低排序</span>
		</div>

		<div class="agr-content">
			<el-table v-loading="loading" :data="rows" border stripe height="100%">
				<el-table-column type="index" label="#" width="56" align="center" :index="indexMethod" />
				<el-table-column prop="agreement_no" label="协议编号" width="130" show-overflow-tooltip>
					<template #default="{ row }"><span class="amt">{{ row.agreement_no }}</span></template>
				</el-table-column>
				<el-table-column prop="title" label="协议名称" min-width="180" show-overflow-tooltip />
				<el-table-column prop="customer_name" label="客户" min-width="150" show-overflow-tooltip />
				<el-table-column label="有效期" width="200" align="center">
					<template #default="{ row }"><span class="amt">{{ row.start_date }} ~ {{ row.end_date }}</span></template>
				</el-table-column>
				<el-table-column prop="amount" label="协议额" width="130" align="right">
					<template #default="{ row }"><span class="amt">¥{{ fmtMoney(row.amount) }}</span></template>
				</el-table-column>
				<el-table-column prop="redeemed_amount" label="已兑现额" width="130" align="right">
					<template #default="{ row }"><span class="amt">¥{{ fmtMoney(row.redeemed_amount) }}</span></template>
				</el-table-column>
				<el-table-column prop="pending_amount" label="待兑现额" width="130" align="right">
					<template #default="{ row }"><span class="amt">¥{{ fmtMoney(row.pending_amount) }}</span></template>
				</el-table-column>
				<el-table-column label="兑现率" min-width="200">
					<template #default="{ row }">
						<el-progress :percentage="clampedRate(row.redemption_rate)" :color="rateColor(row.redemption_rate)" :stroke-width="10" :format="rateFormat(row.redemption_rate)" />
					</template>
				</el-table-column>
				<el-table-column label="状态" width="92" align="center">
					<template #default="{ row }">
						<el-tag :type="row.status_tag_type || 'info'" size="small" effect="light">{{ row.status_label }}</el-tag>
					</template>
				</el-table-column>
				<template #empty>
					<FinEmpty text="当前筛选条件下暂无陈列协议" />
				</template>
			</el-table>

			<div class="agr-pager">
				<el-pagination
					:current-page="page"
					:page-size="pageSize"
					:page-sizes="[20, 50, 100]"
					:total="total"
					layout="total, sizes, prev, pager, next, jumper"
					@current-change="onPage"
					@size-change="onSize"
				/>
			</div>
		</div>
	</div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import agrApi from '@/api/agr'
import { saveBlob } from '@/utils/fileDownload'
import FinEmpty from '../../finance/_FinEmpty.vue'
import { fmtMoney, fmtRate, toNum } from '../_fmt.js'

const EMPTY_STAT = { count: 0, amount: 0, redeemed_amount: 0, redemption_rate: 0, active_count: 0, expired_count: 0, terminated_count: 0 }

const customers = ref([])
const rows = ref([])
const stat = ref({ ...EMPTY_STAT })
const total = ref(0)
const page = ref(1)
const pageSize = ref(20)
const loading = ref(false)
const exporting = ref(false)

const search = reactive({ keyword: '', customer_id: null })
const dateRange = ref([])

// 服务端待兑现合计字段未返回，用汇总两项相减，负值夹到 0（脏数据不应显示负数）
const pendingTotal = computed(() => Math.max(0, toNum(stat.value.amount) - toNum(stat.value.redeemed_amount)))

/** 兑现率色阶：≤60% 橙、≥100% 绿，其余主色蓝 */
const rateColor = (v) => {
	const r = toNum(v)
	if (r >= 100) return '#67C23A'
	if (r <= 60) return '#E6A23C'
	return '#409EFF'
}
const rateClass = (v) => {
	const r = toNum(v)
	if (r >= 100) return 'is-ok'
	if (r <= 60) return 'is-warn'
	return ''
}
// el-progress 的 percentage 只接受 0~100，越界要夹住；文案仍显示真实值
const clampedRate = (v) => Math.min(100, Math.max(0, toNum(v)))
const rateFormat = (v) => () => `${fmtRate(v)}%`
const indexMethod = (i) => (page.value - 1) * pageSize.value + i + 1

const params = () => ({
	keyword: search.keyword || undefined,
	customer_id: search.customer_id || undefined,
	start_from: dateRange.value?.[0] || undefined,
	start_to: dateRange.value?.[1] || undefined,
})

const loadOptions = async () => {
	try {
		const res = await agrApi.agreement.options.get()
		if (res?.code === 200) customers.value = res.data?.customers || []
	} catch { /* 下拉失败不阻断统计 */ }
}

const loadList = async () => {
	loading.value = true
	try {
		const res = await agrApi.stats.get({ ...params(), page: page.value, page_size: pageSize.value })
		if (res?.code === 200) {
			rows.value = res.data?.list || []
			total.value = res.data?.total || 0
			stat.value = { ...EMPTY_STAT, ...(res.data?.stat || {}) }
		}
	} finally { loading.value = false }
}

const refresh = () => { page.value = 1; loadList() }
const onPage = (p) => { page.value = p; loadList() }
const onSize = (s) => { pageSize.value = s; refresh() }
const resetSearch = () => {
	search.keyword = ''
	search.customer_id = null
	dateRange.value = []
	refresh()
}

// 统计页只覆盖列表所示的陈列协议，导出须显式带 type，否则导出全部类型
const doExport = async () => {
	if (!rows.value.length) return ElMessage.warning('当前筛选条件下暂无数据')
	exporting.value = true
	try {
		const blob = await agrApi.agreement.export({ ...params(), type: 'display' })
		saveBlob(blob, `agr_display_stats_${new Date().toISOString().slice(0, 10).replace(/-/g, '')}.csv`)
	} finally { exporting.value = false }
}

onMounted(() => { loadOptions(); loadList() })
</script>

<style>
@import '../_shared.css';
</style>

<style scoped>
.agr-stats { height: 100%; display: flex; flex-direction: column; }
.agr-filter { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.stat-cards { display: flex; gap: 12px; padding: 12px 20px; background: var(--bg-card); border-bottom: 1px solid var(--border-lighter); }
.stat-cards .card { flex: 1; min-width: 0; padding: 10px 14px; border: 1px solid var(--border-lighter); border-radius: var(--radius-md); }
.stat-cards .k { font-size: var(--font-aux); color: var(--text-secondary); }
.stat-cards .v { margin: 4px 0 6px; font-size: var(--font-h2); font-weight: 600; color: var(--text-primary); }
.stat-cards .v.is-ok { color: var(--color-success); }
.stat-cards .v.is-warn { color: var(--color-warning); }
.stat-cards .s { font-size: var(--font-aux); color: var(--text-secondary); }
.stat-cards .tri { display: flex; flex-direction: column; gap: 2px; margin-top: 4px; font-size: var(--font-aux); }
.stat-cards .t.ok { color: var(--color-success); }
.stat-cards .t.off { color: var(--text-secondary); }
.stat-cards .t.stop { color: var(--color-danger); }
.agr-toolbar { display: flex; align-items: center; gap: 8px; padding: 12px 20px; }
.agr-toolbar .hint { font-size: var(--font-aux); color: var(--text-placeholder); }
.agr-content { flex: 1; display: flex; flex-direction: column; overflow: auto; padding: 0 20px; }
:deep(.el-table) { color: var(--text-primary); }
</style>
