<template>
	<div class="stock-monitor-page">
		<!-- 顶部统计卡片 -->
		<div class="stats-bar">
			<div class="stat-item stat-date">
				<div class="stat-value">{{ summary.snapshot_date || '-' }}</div>
				<div class="stat-label">最新快照日期</div>
			</div>
			<div class="stat-item stat-total">
				<div class="stat-value">{{ fmt(summary.total_products) }}</div>
				<div class="stat-label">库存品种</div>
			</div>
			<div class="stat-item stat-qty">
				<div class="stat-value">{{ fmt(summary.total_quantity) }}</div>
				<div class="stat-label">库存总量</div>
			</div>
			<div class="stat-item stat-freeze">
				<div class="stat-value">{{ fmt(summary.frozen_quantity) }}</div>
				<div class="stat-label">冻结库存</div>
			</div>
			<div class="stat-item stat-changed">
				<div class="stat-value" :class="{ 'has-change': summary.changed_count > 0 }">{{ summary.changed_count }}</div>
				<div class="stat-label">变动商品数</div>
			</div>
		</div>

		<div class="monitor-body">
			<!-- 近 N 天库存趋势 -->
			<section class="monitor-card trend-card">
				<div class="card-header">
					<span class="card-title"><i class="el-icon-data-line"></i>近 {{ trend.length }} 天库存总量趋势</span>
					<div class="card-tools">
						<el-select v-model="days" size="small" style="width: 100px" @change="fetchData">
							<el-option :value="7" label="近7天" />
							<el-option :value="14" label="近14天" />
							<el-option :value="30" label="近30天" />
						</el-select>
						<el-button size="small" @click="fetchData"><i class="el-icon-refresh"></i>刷新</el-button>
					</div>
				</div>
				<div class="card-body">
					<div class="trend-list">
						<div v-for="t in trend" :key="t.snapshot_date" class="trend-row">
							<span class="trend-date">{{ t.snapshot_date }}</span>
							<div class="trend-bar-wrap">
								<div class="trend-bar" :style="{ width: barWidth(t.total_quantity) + '%' }"></div>
							</div>
							<span class="trend-val">{{ fmt(t.total_quantity) }}</span>
							<span class="trend-count">{{ t.product_count }} 种</span>
						</div>
						<div v-if="!trend.length" class="panel-empty">暂无快照数据</div>
					</div>
				</div>
			</section>

			<!-- 仓库库存变化 -->
			<section class="monitor-card wh-card">
				<div class="card-header">
					<span class="card-title"><i class="el-icon-office-building"></i>各仓库库存变化（最新 vs 上一快照）</span>
				</div>
				<div class="card-body">
					<table class="mini-table">
						<thead>
							<tr>
								<th>仓库</th>
								<th>昨日库存</th>
								<th>今日库存</th>
								<th>变动</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="w in warehouseChanges" :key="w.warehouse_id">
								<td>{{ w.warehouse_name }}</td>
								<td>{{ fmt(w.yesterday_qty) }}</td>
								<td>{{ fmt(w.today_qty) }}</td>
								<td :class="diffClass(w.diff_qty)">
									{{ diffText(w.diff_qty) }}
								</td>
							</tr>
							<tr v-if="!warehouseChanges.length">
								<td colspan="4" class="panel-empty">暂无仓库数据</td>
							</tr>
						</tbody>
					</table>
				</div>
			</section>
		</div>

		<!-- 商品变动明细 -->
		<section class="monitor-card change-card">
			<div class="card-header">
				<span class="card-title"><i class="el-icon-sort"></i>库存变动明细（{{ summary.snapshot_date || '' }} vs 上一快照）</span>
				<span class="card-hint">仅显示有变动的商品，按变动幅度排序</span>
			</div>
			<div class="card-body">
				<sTable
					tableName="stock_monitor_changes"
					:data="changes"
					:columns="changeColumns"
					:loading="loading"
					height="100%"
					stripe
				>
					<template #yesterday_qty="{ row }">
						<span>{{ fmt(row.yesterday_qty) }}</span>
					</template>
					<template #today_qty="{ row }">
						<span>{{ fmt(row.today_qty) }}</span>
					</template>
					<template #diff_qty="{ row }">
						<span :class="diffClass(row.diff_qty)">{{ diffText(row.diff_qty) }}</span>
					</template>
				</sTable>
				<div v-if="!loading && !changes.length" class="panel-empty" style="padding: 30px">
					暂无变动（今日与上一快照一致）
				</div>
			</div>
		</section>
	</div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import businessApi from '@/api/business'
import sTable from '@/components/sTable/index.vue'

const summary = ref({})
const trend = ref([])
const warehouseChanges = ref([])
const changes = ref([])
const loading = ref(false)
const days = ref(7)

const changeColumns = [
	{ prop: 'product_name', title: '商品名称', width: 220, showOverflowTooltip: true },
	{ prop: 'spec', title: '规格', width: 100 },
	{ prop: 'barcode', title: '条码', width: 130 },
	{ prop: 'warehouse_name', title: '仓库', width: 130 },
	{ prop: 'yesterday_qty', title: '昨日库存', width: 110, slots: { default: 'yesterday_qty' } },
	{ prop: 'today_qty', title: '今日库存', width: 110, slots: { default: 'today_qty' } },
	{ prop: 'diff_qty', title: '变动', width: 120, slots: { default: 'diff_qty' } },
]

function fmt(v) {
	const n = Number(v ?? 0)
	if (Math.abs(n - Math.round(n)) < 0.001) return String(Math.round(n))
	return String(Math.round(n * 100) / 100)
}

function diffText(v) {
	const n = Number(v ?? 0)
	if (n > 0) return '+' + fmt(n)
	if (n < 0) return fmt(n)
	return '0'
}

function diffClass(v) {
	const n = Number(v ?? 0)
	if (n > 0) return 'diff-up'
	if (n < 0) return 'diff-down'
	return 'diff-zero'
}

function barWidth(qty) {
	const maxQty = Math.max(...trend.value.map(t => Number(t.total_quantity) || 0), 1)
	const ratio = (Number(qty) || 0) / maxQty
	return Math.max(2, Math.round(ratio * 100))
}

async function fetchData() {
	loading.value = true
	try {
		const res = await businessApi.liankaiStockMonitor.list.get({ days: days.value })
		const d = res.data || {}
		summary.value = d.summary || {}
		trend.value = d.trend || []
		warehouseChanges.value = d.warehouse_changes || []
		changes.value = d.changes || []
	} catch (error) {
		ElMessage.error(error?.response?.data?.message || '数据加载失败')
	} finally {
		loading.value = false
	}
}

onMounted(() => {
	fetchData()
})
</script>

<style scoped>
.stock-monitor-page {
	display: flex;
	flex-direction: column;
	height: 100%;
	overflow: auto;
	padding: 12px;
	gap: 12px;
	background: var(--el-bg-color-page);
}

/* 统计卡片 */
.stats-bar {
	display: flex;
	gap: 10px;
	flex-wrap: wrap;
}
.stat-item {
	flex: 1;
	min-width: 140px;
	background: var(--el-bg-color);
	border-radius: 8px;
	padding: 14px 16px;
	border: 1px solid var(--el-border-color-lighter);
	text-align: center;
}
.stat-value {
	font-size: 20px;
	font-weight: 700;
	color: var(--el-text-color-primary);
}
.stat-label {
	font-size: 12px;
	color: var(--el-text-color-secondary);
	margin-top: 4px;
}
.stat-date .stat-value { font-size: 15px; color: var(--el-color-primary); }
.stat-total .stat-value { color: #409eff; }
.stat-qty .stat-value { color: #67c23a; }
.stat-freeze .stat-value { color: #e6a23c; }
.stat-changed .stat-value.has-change { color: #f56c6c; }

/* 卡片 */
.monitor-body {
	display: flex;
	gap: 12px;
	flex-wrap: wrap;
}
.monitor-card {
	background: var(--el-bg-color);
	border: 1px solid var(--el-border-color-lighter);
	border-radius: 8px;
	overflow: hidden;
}
.trend-card { flex: 1.6; min-width: 320px; }
.wh-card { flex: 1; min-width: 280px; }
.change-card { flex: 1; min-width: 100%; }

.card-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 10px 14px;
	border-bottom: 1px solid var(--el-border-color-lighter);
}
.card-title {
	font-size: 13px;
	font-weight: 600;
	color: var(--el-text-color-primary);
	display: flex;
	align-items: center;
	gap: 6px;
}
.card-tools { display: flex; align-items: center; gap: 6px; }
.card-hint { font-size: 11px; color: var(--el-text-color-placeholder); }
.card-body { padding: 10px 14px; }

/* 趋势 */
.trend-list { display: flex; flex-direction: column; gap: 6px; }
.trend-row {
	display: flex;
	align-items: center;
	gap: 10px;
	font-size: 12px;
}
.trend-date { width: 90px; flex-shrink: 0; color: var(--el-text-color-secondary); }
.trend-bar-wrap {
	flex: 1;
	height: 16px;
	background: var(--el-fill-color-light);
	border-radius: 3px;
	overflow: hidden;
}
.trend-bar {
	height: 100%;
	background: linear-gradient(90deg, #409eff, #67c23a);
	border-radius: 3px;
	min-width: 2px;
	transition: width 0.3s;
}
.trend-val { width: 80px; text-align: right; font-weight: 600; flex-shrink: 0; }
.trend-count { width: 60px; text-align: right; color: var(--el-text-color-placeholder); flex-shrink: 0; }

/* 迷你表 */
.mini-table {
	width: 100%;
	border-collapse: collapse;
	font-size: 12px;
}
.mini-table th, .mini-table td {
	padding: 7px 8px;
	border-bottom: 1px solid var(--el-border-color-lighter);
	text-align: left;
}
.mini-table th { color: var(--el-text-color-secondary); font-weight: 600; background: var(--el-fill-color-light); }
.mini-table td { color: var(--el-text-color-regular); }

/* 差异 */
.diff-up { color: #67c23a; font-weight: 600; }
.diff-down { color: #f56c6c; font-weight: 600; }
.diff-zero { color: var(--el-text-color-placeholder); }

.panel-empty { text-align: center; color: var(--el-text-color-placeholder); font-size: 12px; }

@media (max-width: 768px) {
	.stats-bar { gap: 6px; }
	.stat-item { min-width: 100px; padding: 10px; }
	.stat-value { font-size: 16px; }
	.trend-date { width: 80px; }
	.trend-val { width: 70px; }
	.card-hint { display: none; }
}
</style>
