<template>
	<div class="rp-page">
		<div class="rp-toolbar">
			<el-radio-group v-model="mode" size="small" @change="onModeChange">
				<el-radio-button value="year">年度查询</el-radio-button>
				<el-radio-button value="month">月度查询</el-radio-button>
			</el-radio-group>
			<el-date-picker
				v-model="period"
				:type="mode === 'year' ? 'year' : 'month'"
				:placeholder="mode === 'year' ? '选择年份' : '选择月份'"
				:value-format="mode === 'year' ? 'YYYY' : 'YYYY-MM'"
				size="small"
				style="width: 150px"
				@change="fetchData"
			/>
			<el-select
				v-model="salesmanIds"
				multiple
				collapse-tags
				collapse-tags-tooltip
				filterable
				clearable
				size="small"
				style="width: 220px"
				placeholder="业务员（不选为全部）"
				@change="fetchData"
			>
				<el-option v-for="u in users" :key="u.id" :label="u.name" :value="u.id" />
			</el-select>
			<el-button size="small" class="rp-btn-primary" @click="fetchData">查询</el-button>
			<span class="rp-spacer"></span>
			<span class="rp-rate-legend">
				<span class="rp-rate-high">≥100% 达标</span>
				<span class="rp-rate-mid">80~99%</span>
				<span class="rp-rate-low">&lt;80%</span>
			</span>
			<el-button size="small" circle title="刷新" @click="fetchData">
				<el-icon><Refresh /></el-icon>
			</el-button>
		</div>

		<div class="ach-table">
			<el-table
				v-loading="loading"
				:data="list"
				size="small"
				border
				height="100%"
				:row-style="{ height: '36px' }"
				:header-cell-style="{ height: '40px', background: '#f5f7fa', color: '#909399', fontWeight: 700, fontSize: '13px' }"
				@row-dblclick="openTrend"
			>
				<el-table-column prop="employee_code" label="业务员编码" width="110" fixed="left" />
				<el-table-column prop="employee_name" label="业务员" width="100" fixed="left" />
				<el-table-column prop="route_name" label="所属线路" width="140" show-overflow-tooltip />
				<el-table-column prop="plan_customers" label="计划客户数" width="100" align="right" />
				<el-table-column prop="actual_visits" label="实际拜访数" width="100" align="right" />
				<el-table-column prop="achievement_rate" label="达成率" width="90" align="right">
					<template #default="{ row }">
						<span :class="rateClass(row.achievement_rate)">{{ Number(row.achievement_rate || 0).toFixed(1) }}%</span>
					</template>
				</el-table-column>
				<el-table-column prop="reached_customers" label="覆盖客户数" width="100" align="right" />
				<el-table-column prop="coverage_rate" label="覆盖率" width="90" align="right">
					<template #default="{ row }">
						<span :class="rateClass(row.coverage_rate)">{{ Number(row.coverage_rate || 0).toFixed(1) }}%</span>
					</template>
				</el-table-column>

				<!-- 年度 12 个月列 / 月度 5 个周列 -->
				<el-table-column
					v-for="(b, i) in bucketLabels"
					:key="i"
					:label="b"
					width="72"
					align="right"
				>
					<template #default="{ row }">
						<span :class="bucketClass(row.buckets?.[i]?.rate, row.buckets?.[i]?.value)">
							{{ row.buckets?.[i]?.value ?? 0 }}
						</span>
					</template>
				</el-table-column>
			</el-table>
		</div>

		<div class="rp-summary">
			<span>业务员 <b>{{ summary.salesman_count || 0 }}</b></span>
			<span>计划客户数 <b>{{ summary.plan_customers || 0 }}</b></span>
			<span>实际拜访数 <b>{{ summary.actual_visits || 0 }}</b></span>
			<span>覆盖客户数 <b>{{ summary.reached_customers || 0 }}</b></span>
			<span>整体达成率
				<b :class="rateClass(overallRate)">{{ overallRate.toFixed(1) }}%</b>
			</span>
			<span class="rp-tip">双击任意一行查看达成率走势</span>
		</div>

		<!-- 走势弹窗 -->
		<el-dialog v-model="trendVisible" :title="`${trend.employee_name} · 达成率走势`" width="720px" top="8vh">
			<div v-loading="trendLoading" class="trend-box">
				<svg :viewBox="`0 0 ${W} ${H}`" class="trend-svg" preserveAspectRatio="xMidYMid meet">
					<!-- 网格与 Y 轴刻度 -->
					<line v-for="g in yGrids" :key="'g' + g.value"
						:x1="PAD_L" :x2="W - PAD_R" :y1="g.y" :y2="g.y"
						stroke="#e4e7ed" stroke-width="1" />
					<text v-for="g in yGrids" :key="'t' + g.value"
						:x="PAD_L - 8" :y="g.y + 4" text-anchor="end" font-size="11" fill="#909399">{{ g.value }}%</text>

					<!-- 80% / 100% 参考线 -->
					<line v-for="ref in REF_LINES" :key="'r' + ref.value"
						:x1="PAD_L" :x2="W - PAD_R" :y1="yOf(ref.value)" :y2="yOf(ref.value)"
						:stroke="ref.color" stroke-width="1" stroke-dasharray="5 4" />
					<text v-for="ref in REF_LINES" :key="'rt' + ref.value"
						:x="W - PAD_R + 4" :y="yOf(ref.value) + 4" font-size="11" :fill="ref.color">{{ ref.label }}</text>

					<!-- 折线 -->
					<polyline :points="linePoints" fill="none" stroke="#409eff" stroke-width="2" />
					<!-- 数据点 -->
					<circle v-for="(p, i) in points" :key="i" :cx="p.x" :cy="p.y" r="3.5"
						:fill="pointColor(p.rate)" />
					<!-- X 轴标签 -->
					<text v-for="(p, i) in points" :key="'x' + i"
						:x="p.x" :y="H - 8" text-anchor="middle" font-size="11" fill="#606266">{{ p.label }}</text>
				</svg>
				<div class="trend-foot">
					计划客户数 {{ trend.plan_customers || 0 }} ·
					折线为各{{ mode === 'year' ? '月' : '周' }}达成率，虚线为 80% / 100% 参考线
				</div>
			</div>
		</el-dialog>
	</div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import { Refresh } from '@element-plus/icons-vue'
import businessApi from '@/api/business'

const mode = ref('year')
const period = ref(String(new Date().getFullYear()))
const list = ref([])
const summary = ref({})
const loading = ref(false)
const users = ref([])
const salesmanIds = ref([])

/** 年度 12 个月、月度 5 个周段 */
const bucketLabels = computed(() =>
	mode.value === 'year'
		? Array.from({ length: 12 }, (_, i) => `${i + 1}月`)
		: ['第1周', '第2周', '第3周', '第4周', '第5周']
)

function rateClass(v) {
	const n = Number(v || 0)
	if (n >= 100) return 'rp-rate-high'
	if (n >= 80) return 'rp-rate-mid'
	return 'rp-rate-low'
}
/** 0 次的月份不参与配色（灰色），否则整片红看起来像全是问题 */
function bucketClass(rate, value) {
	if (!Number(value || 0)) return 'rp-cell-zero'
	return rateClass(rate)
}

const overallRate = computed(() => {
	const plan = Number(summary.value.plan_customers || 0)
	if (!plan) return 0
	return (Number(summary.value.actual_visits || 0) / plan) * 100
})

function onModeChange() {
	period.value = mode.value === 'year'
		? String(new Date().getFullYear())
		: `${new Date().getFullYear()}-${String(new Date().getMonth() + 1).padStart(2, '0')}`
	fetchData()
}

/** 后端 date 参数：年度传 YYYY-01-01，月度传 YYYY-MM-01，让 Carbon::parse 稳定命中区间 */
function dateParam() {
	return mode.value === 'year' ? `${period.value}-01-01` : `${period.value}-01`
}

async function fetchData() {
	loading.value = true
	try {
		const res = await businessApi.visit.achievement.get({
			mode: mode.value,
			date: dateParam(),
			salesman_ids: salesmanIds.value,
		})
		if (res.code === 200) {
			list.value = res.data?.list || []
			summary.value = res.data?.summary || {}
		}
	} catch {
		ElMessage.error('加载达成率失败')
	} finally {
		loading.value = false
	}
}

async function fetchUsers() {
	const res = await businessApi.mail.contacts.get()
	if (res.code === 200) users.value = res.data?.users?.map((u) => ({ id: u.value, name: u.label })) || []
}

// ============ 走势弹窗（手写 SVG，项目没有引入 echarts） ============
const trendVisible = ref(false)
const trendLoading = ref(false)
const trend = reactive({ employee_name: '', plan_customers: 0, series: [] })

const W = 660
const H = 300
const PAD_L = 44
const PAD_R = 48
const PAD_T = 16
const PAD_B = 28
const REF_LINES = [
	{ value: 100, label: '100%', color: '#67c23a' },
	{ value: 80, label: '80%', color: '#e6a23c' },
]

const yMax = computed(() => {
	const max = Math.max(100, ...(trend.series || []).map((s) => Number(s.rate || 0)))
	return Math.ceil(max / 20) * 20
})
const yGrids = computed(() => {
	const ticks = 5
	return Array.from({ length: ticks + 1 }, (_, i) => {
		const value = Math.round((yMax.value / ticks) * i)
		return { value, y: yOf(value) }
	})
})
function yOf(value) {
	const inner = H - PAD_T - PAD_B
	return PAD_T + inner * (1 - Math.min(Number(value || 0), yMax.value) / yMax.value)
}
const points = computed(() => {
	const series = trend.series || []
	if (!series.length) return []
	const inner = W - PAD_L - PAD_R
	const step = series.length > 1 ? inner / (series.length - 1) : 0
	return series.map((s, i) => ({
		x: PAD_L + step * i,
		y: yOf(s.rate),
		rate: Number(s.rate || 0),
		label: s.label,
		value: s.value,
	}))
})
const linePoints = computed(() => points.value.map((p) => `${p.x},${p.y}`).join(' '))
function pointColor(rate) {
	if (rate >= 100) return '#67c23a'
	if (rate >= 80) return '#e6a23c'
	return '#f56c6c'
}

async function openTrend(row) {
	trendVisible.value = true
	trendLoading.value = true
	try {
		const res = await businessApi.visit.trend.get({
			employee_id: row.employee_id,
			mode: mode.value,
			date: dateParam(),
		})
		if (res.code === 200) {
			trend.employee_name = res.data?.employee_name || row.employee_name
			trend.plan_customers = res.data?.plan_customers || 0
			trend.series = res.data?.series || []
		}
	} catch {
		ElMessage.error('加载走势失败')
	} finally {
		trendLoading.value = false
	}
}

onMounted(() => {
	fetchUsers()
	fetchData()
})
</script>

<style scoped>
.ach-table {
	flex: 1;
	min-height: 0;
}
.rp-rate-legend {
	display: flex;
	gap: 12px;
	font-size: 12px;
	margin-right: 8px;
}
.rp-tip {
	margin-left: auto;
	color: #909399;
	font-size: 12px;
}
.trend-box {
	padding: 4px 0;
}
.trend-svg {
	width: 100%;
	height: 300px;
}
.trend-foot {
	text-align: center;
	font-size: 12px;
	color: #909399;
	padding-top: 4px;
}
.rp-cell-zero {
	color: #c0c4cc;
}
</style>
