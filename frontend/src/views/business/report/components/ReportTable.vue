<template>
	<div class="rp-grid">
		<div class="rp-grid-body">
			<el-table
				v-loading="loading"
				:data="data"
				:height="'100%'"
				size="small"
				border
				stripe
				:row-style="{ height: '36px' }"
				:header-cell-style="{ height: '40px', background: '#f5f7fa', color: '#909399', fontWeight: 700, fontSize: '13px' }"
				:empty-text="loading ? '' : '暂无数据'"
				@row-dblclick="$emit('rowDblclick', $event)"
			>
				<template v-for="col in visibleColumns" :key="col.prop">
					<el-table-column
						:prop="col.prop"
						:label="col.label"
						:width="col.width || null"
						:min-width="col.width ? null : 120"
						:align="alignOf(col)"
						:fixed="col.fixed || false"
						show-overflow-tooltip
					>
						<template #default="{ row }">
							<slot :name="`cell-${col.prop}`" :row="row" :col="col">
								<ReportCell :col="col" :row="row" />
							</slot>
						</template>
					</el-table-column>
				</template>
			</el-table>
		</div>

		<!-- 汇总行 -->
		<div v-if="summary && summaryKeys.length" class="rp-summary">
			<span class="rp-summary-label">合计：</span>
			<span v-for="k in summaryKeys" :key="k.prop">
				{{ k.label }} <b>{{ formatSummary(k) }}</b>
			</span>
		</div>

		<div class="rp-grid-page">
			<el-pagination
				background
				size="small"
				layout="total, sizes, prev, pager, next, jumper"
				:total="total"
				:page-size="pageSize"
				:page-sizes="[10, 20, 30, 50, 100]"
				:current-page="currentPage"
				@current-change="$emit('pageChange', $event)"
				@update:page-size="$emit('pageSizeChange', $event)"
			/>
			<span class="rp-spacer"></span>
			<!-- 列设置 -->
			<el-popover placement="top" :width="360" trigger="click" :hide-after="0">
				<template #reference>
					<el-button size="small" circle title="列设置">
						<el-icon><Setting /></el-icon>
					</el-button>
				</template>
				<div class="rp-col-panel">
					<div class="rp-col-panel-head">
						<span>列设置</span>
						<el-button link size="small" @click="resetColumns">重置</el-button>
					</div>
					<div class="rp-col-panel-list">
						<div v-for="c in columnState" :key="c.prop" class="rp-col-item">
							<el-icon class="rp-col-drag"><Rank /></el-icon>
							<el-checkbox v-model="c.visible" />
							<span class="rp-col-label">{{ c.label }}</span>
						</div>
					</div>
				</div>
			</el-popover>
			<el-button size="small" circle title="刷新" @click="$emit('refresh')">
				<el-icon><Refresh /></el-icon>
			</el-button>
		</div>
	</div>
</template>

<script setup>
import { ref, computed, watch, h } from 'vue'
import { Setting, Refresh, Rank } from '@element-plus/icons-vue'

/** 金额：统一 2 位小数 + 千分位 */
function money(v) {
	const n = Number(v || 0)
	return n.toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
function num(v, digits = 2) {
	const n = Number(v || 0)
	return digits === 0 ? String(Math.round(n)) : n.toFixed(digits)
}
/** 销售类型等标签的配色（与后端 tag_map 的 key 保持一致） */
function tagType(value) {
	const key = String(value ?? '')
	if (key === 'normal' || key === '正常销售') return 'success'
	if (key === 'promotion' || key === '促销销售') return 'warning'
	if (key === 'special' || key === '特价销售') return 'danger'
	return 'info'
}
function tagStyle(type) {
	const map = {
		success: { color: '#67c23a', background: '#f0f9eb', borderColor: '#e1f3d8' },
		warning: { color: '#e6a23c', background: '#fdf6ec', borderColor: '#faecd8' },
		danger: { color: '#f56c6c', background: '#fef0f0', borderColor: '#fde2e2' },
		info: { color: '#909399', background: '#f4f4f5', borderColor: '#e9e9eb' },
	}
	return {
		...map[type],
		display: 'inline-block',
		padding: '0 6px',
		height: '20px',
		lineHeight: '18px',
		borderWidth: '1px',
		borderStyle: 'solid',
		borderRadius: '3px',
		fontSize: '12px',
	}
}
/** 达成率配色：≥100% 绿、80~99% 橙、<80% 红（模块八要求） */
function rateClass(v) {
	const n = Number(v || 0)
	if (n >= 100) return 'rp-rate-high'
	if (n >= 80) return 'rp-rate-mid'
	return 'rp-rate-low'
}

/**
 * 单元格渲染器：按后端下发的列 type 决定格式化方式。
 * 单独抽成普通对象（不用 <template>）是为了能在 v-for 里直接当组件用，
 * 报表 13 个维度的列都是后端下发的，写 13 套插槽不现实。
 */
const ReportCell = {
	props: { col: Object, row: Object },
	setup(props) {
		return () => renderCell(props.col, props.row)
	},
}

function renderCell(col, row) {
	const raw = row?.[col.prop]
	// 标记类：bold 加粗；sign_color 按正负变色（毛利为负要一眼看出来）
	const cls = ['rp-cell']
	if (col.bold) cls.push('rp-cell-bold')
	// 列级自定义类名（如「销售单价」要求 #f56c6c 加粗）
	if (col.cell_class) cls.push(col.cell_class)
	let negative = false

	let node
	switch (col.type) {
		case 'money':
			negative = Number(raw || 0) < 0
			node = h('span', { class: 'rp-money' }, '¥' + money(raw))
			break
		case 'number':
			node = h('span', { class: 'rp-num' }, num(raw, 2))
			break
		case 'int':
			node = h('span', { class: 'rp-num' }, num(raw, 0))
			break
		case 'percent':
			node = h('span', { class: 'rp-num' }, num(raw, 2) + '%')
			break
		case 'tag':
			node = h('span', { style: tagStyle(tagType(raw)) }, String(col.tag_map?.[raw] ?? raw ?? ''))
			break
		case 'rate':
			node = h('span', { class: rateClass(raw) }, num(raw, 2) + '%')
			break
		default:
			node = h('span', {}, raw === null || raw === undefined ? '' : String(raw))
	}

	// sign_color / negative_red：负数标红（毛利为负、库存为负）
	if ((col.sign_color || col.negative_red) && negative) cls.push('rp-danger')
	// zero_gray：零值淡显，让有库存的行跳出来
	if (col.zero_gray && Number(raw || 0) === 0) cls.push('rp-cell-zero')
	return h('span', { class: cls }, [node])
}

const props = defineProps({
	columns: { type: Array, default: () => [] },
	data: { type: Array, default: () => [] },
	loading: { type: Boolean, default: false },
	total: { type: Number, default: 0 },
	currentPage: { type: Number, default: 1 },
	pageSize: { type: Number, default: 30 },
	summary: { type: [Object, null], default: null },
	/** 汇总行要展示哪些列（不传则自动取所有数值列） */
	summaryProps: { type: Array, default: null },
})
defineEmits(['pageChange', 'pageSizeChange', 'refresh', 'rowDblclick'])

/** 列显隐状态：维度切换导致列集合变化时重建，保留同名列的旧选择 */
const columnState = ref([])
watch(
	() => props.columns,
	(cols) => {
		const prev = new Map(columnState.value.map((c) => [c.prop, c.visible]))
		columnState.value = cols.map((c) => ({
			prop: c.prop,
			label: c.label,
			visible: prev.has(c.prop) ? prev.get(c.prop) : true,
		}))
	},
	{ immediate: true, deep: false }
)
const visibleColumns = computed(() =>
	props.columns.filter((c) => columnState.value.find((s) => s.prop === c.prop)?.visible !== false)
)
function resetColumns() {
	columnState.value.forEach((c) => (c.visible = true))
}

const summaryKeys = computed(() => {
	if (!props.summary) return []
	const list = props.summaryProps || props.columns.filter((c) => ['money', 'number', 'int', 'percent'].includes(c.type)).map((c) => c.prop)
	return list
		.map((p) => props.columns.find((c) => c.prop === p))
		.filter(Boolean)
		.filter((c) => props.summary[c.prop] !== undefined && props.summary[c.prop] !== null)
})
function formatSummary(col) {
	const v = props.summary[col.prop]
	if (col.type === 'money') return '¥' + money(v)
	if (col.type === 'int') return num(v, 0)
	if (col.type === 'percent') return num(v, 2) + '%'
	return num(v, 2)
}

function alignOf(col) {
	return ['money', 'number', 'int', 'percent', 'rate'].includes(col.type) ? 'right' : col.align || 'left'
}
</script>

<style scoped>
.rp-grid {
	flex: 1;
	min-height: 0;
	display: flex;
	flex-direction: column;
}
.rp-grid-body {
	flex: 1;
	min-height: 0;
}
.rp-grid :deep(.el-table__row--striped td.el-table__cell) {
	background: #fafafa;
}
.rp-grid :deep(.el-table .cell) {
	font-size: 13px;
	color: #606266;
}
.rp-cell-bold {
	font-weight: 700;
	color: #303133;
}
.rp-cell-zero {
	color: #c0c4cc;
}
.rp-grid-page {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px 16px;
	flex-shrink: 0;
}
.rp-spacer {
	flex: 1;
}
.rp-col-panel-head {
	display: flex;
	justify-content: space-between;
	align-items: center;
	font-size: 13px;
	font-weight: 700;
	color: #303133;
	margin-bottom: 8px;
}
.rp-col-panel-list {
	max-height: 320px;
	overflow: auto;
}
.rp-col-item {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 2px 0;
	font-size: 13px;
	color: #606266;
}
.rp-col-drag {
	color: #c0c4cc;
	cursor: move;
}
.rp-col-label {
	flex: 1;
}
</style>
