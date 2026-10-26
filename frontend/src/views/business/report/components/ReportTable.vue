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
				:row-style="rowStyle"
				:header-cell-style="headerCellStyle"
				:empty-text="loading ? '' : ' '"
				@row-dblclick="onRowDblclick"
			>
				<template #empty>
					<div class="rp-empty-box" v-if="!loading">
						<svg width="96" height="80" viewBox="0 0 96 80" fill="none" xmlns="http://www.w3.org/2000/svg">
							<rect x="18" y="12" width="60" height="44" rx="2" fill="#fff" stroke="#dcdfe6" stroke-width="1.5"/>
							<rect x="14" y="20" width="8" height="36" rx="1" fill="#dcdfe6"/>
							<path d="M30 22h28" stroke="#e4e7ed" stroke-width="1.5" stroke-linecap="round"/>
							<path d="M30 29h20" stroke="#e4e7ed" stroke-width="1.5" stroke-linecap="round"/>
							<path d="M30 36h24" stroke="#e4e7ed" stroke-width="1.5" stroke-linecap="round"/>
							<path d="M34 51l6-6 7 4 7-7 6 5" stroke="#c0c4cc" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
							<rect x="18" y="60" width="60" height="5" rx="2.5" fill="#f5f7fa"/>
							<path d="M38 66h20" stroke="#dcdfe6" stroke-width="1.5" stroke-linecap="round"/>
						</svg>
						<p class="rp-empty-text">暂无数据</p>
					</div>
				</template>
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
				small
				layout="total, sizes, prev, pager, next, jumper"
				:total="total"
				:page-size="pageSize"
				:page-sizes="[20, 30]"
				:current-page="currentPage"
				@current-change="$emit('pageChange', $event)"
				@update:page-size="$emit('pageSizeChange', $event)"
			/>
			<span class="rp-spacer"></span>
			<!-- 列设置：显隐 + 拖拽排序 + 持久化 -->
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
					<div class="rp-col-panel-list" @dragover.prevent @drop="onDrop">
						<div
							v-for="(c, i) in columnState"
							:key="c.prop"
							class="rp-col-item"
							draggable="true"
							@dragstart="dragIndex = i"
							@dragenter="dragOver = i"
							@dragend="dragOver = null"
							:class="{ 'rp-col-dragging': dragOver === i }"
						>
							<el-icon class="rp-col-drag"><Rank /></el-icon>
							<el-checkbox v-model="c.visible" @change="persistColumns" />
							<span class="rp-col-label">{{ c.label }}</span>
						</div>
					</div>
					<div class="rp-col-panel-foot">拖动左侧手柄调整列顺序，勾选显隐，布局自动保存</div>
				</div>
			</el-popover>
			<el-button size="small" circle title="刷新" @click="$emit('refresh')">
				<el-icon><Refresh /></el-icon>
			</el-button>
		</div>

		<!-- 穿透抽屉：双击行 → 源单明细 -->
		<ReportDrillDrawer
			v-model="drillOpen"
			:payload="drillPayload"
			:fetcher="drillFetcher"
		/>
	</div>
</template>

<script setup>
import { ref, computed, watch, h, onMounted } from 'vue'
import { Setting, Refresh, Rank } from '@element-plus/icons-vue'
import businessApi from '@/api/business'
import ReportDrillDrawer from './ReportDrillDrawer.vue'

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
	/** 报表标识：列配置持久化按此 key 区分（如 sales / stock / salesman / combined） */
	report: { type: String, default: '' },
	columns: { type: Array, default: () => [] },
	data: { type: Array, default: () => [] },
	loading: { type: Boolean, default: false },
	total: { type: Number, default: 0 },
	currentPage: { type: Number, default: 1 },
	pageSize: { type: Number, default: 30 },
	summary: { type: [Object, null], default: null },
	/** 汇总行要展示哪些列（不传则自动取所有数值列） */
	summaryProps: { type: Array, default: null },
	/** 明细视图用更紧凑的行高（41px），列表视图默认 48px */
	dense: { type: Boolean, default: false },
	/** 穿透：双击行后拉源单明细的接口（payload）=> Promise，不传则禁用穿透 */
	drillFetcher: { type: Function, default: null },
/** 自定义穿透载荷构造器（row）=> payload；默认按 order_no / order_id 构造，客户类报表按 customer_id 下钻 */
	drillPayload: { type: Function, default: null },
})
const emit = defineEmits(['pageChange', 'pageSizeChange', 'refresh', 'rowDblclick'])

// ================= 规范行高 / 表头 =================
// 列表 48px / 明细 41px；表头 44px #F5F7FA 14px/500 #606266
const rowStyle = () => ({ height: props.dense ? '41px' : '48px' })
const headerCellStyle = () => ({ height: '44px', background: '#F5F7FA', color: '#606266', fontWeight: 500, fontSize: '14px' })

// ================= 穿透抽屉 =================
const drillOpen = ref(false)
const drillPayload = ref(null)
function onRowDblclick(row) {
// 内置穿透抽屉：order/stock 等明细类报表，双击行直接打开源单明细
	if (props.drillFetcher) {
		drillPayload.value = toDrillPayload(row)
		if (drillPayload.value) drillOpen.value = true
	}
	// 自定义穿透：客户类报表双击行由父组件决定（如打开该客户订单列表抽屉）
	emit('rowDblclick', row)
}
/** 父组件可传 drillPayload 自定义载荷构造器（如客户报表按 customer_id 下钻） */
function toDrillPayload(row) {
	return props.drillPayload ? props.drillPayload(row) : defaultDrillPayload(row)
}
function defaultDrillPayload(row) {
	return row?.order_no != null ? { order_no: row.order_no } : row?.order_id != null ? { order_id: row.order_id } : null
}

// ================= 列显隐 + 排序 + 持久化 =================
const columnState = ref([])
const dragIndex = ref(null)
const dragOver = ref(null)

/** 后端列定义变化（维度切换）时重建列状态；同名列保留旧显隐，顺序默认按后端下发 */
watch(
	() => props.columns,
	(cols) => {
		const prev = new Map(columnState.value.map((c) => [c.prop, c.visible]))
		columnState.value = cols.map((c) => ({
			prop: c.prop,
			label: c.label,
			visible: prev.has(c.prop) ? prev.get(c.prop) : true,
		}))
		applyPersisted()
	},
	{ immediate: true, deep: false }
)

/** 打开报表时回放该用户该报表的列布局快照 */
async function applyPersisted() {
	if (!props.report) return
	try {
		const res = await businessApi.report.columns.get({ report: props.report })
		if (res.code !== 200 || !Array.isArray(res.data) || !res.data.length) return
		const saved = new Map(res.data.map((c) => [c.prop, c]))
		// 只回放当前报表真实存在的列，顺序按用户保存的顺序；新列默认可见排尾部
		const current = new Map(columnState.value.map((c) => [c.prop, c]))
		const ordered = res.data.filter((c) => current.has(c.prop))
		const rest = columnState.value.filter((c) => !saved.has(c.prop))
		columnState.value = [
			...ordered.map((c) => ({ prop: c.prop, label: c.label, visible: c.visible !== false })),
			...rest,
		]
	} catch {
		/* 持久化读失败不阻塞表格展示 */
	}
}

/** 显隐 / 排序变化后把当前列布局写回后端 */
function persistColumns() {
	if (!props.report) return
	const payload = columnState.value.map((c) => ({ prop: c.prop, label: c.label, visible: c.visible }))
	businessApi.report.columns.save({ report: props.report, columns: payload }).catch(() => {})
}

const visibleColumns = computed(() =>
	columnState.value
		.filter((s) => s.visible !== false)
		.map((s) => props.columns.find((c) => c.prop === s.prop))
		.filter(Boolean)
)

function resetColumns() {
	columnState.value.forEach((c) => (c.visible = true))
	persistColumns()
}

/** 拖拽排序：把源列插到目标列前 */
function onDrop() {
	const from = dragIndex.value
	const to = dragOver.value
	dragIndex.value = null
	dragOver.value = null
	if (from == null || to == null || from === to) return
	const next = columnState.value.slice()
	const [moved] = next.splice(from, 1)
	next.splice(to, 0, moved)
	columnState.value = next
	persistColumns()
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

/* 空态：纸箱插图 + 14px #C0C4CC */
.rp-empty-box {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 10px;
	padding: 32px 0;
}
.rp-empty-text {
	font-size: 14px;
	color: #c0c4cc;
	margin: 0;
}

/* 分页条 52px，pager 按钮 24×24 圆角 2px */
.rp-grid-page {
	display: flex;
	align-items: center;
	gap: 12px;
	height: 52px;
	padding: 0 16px;
	flex-shrink: 0;
	border-top: 1px solid #ebeef5;
}
.rp-grid-page :deep(.el-pagination) {
	height: 28px;
}
.rp-grid-page :deep(.el-pagination.is-background .el-pager li),
.rp-grid-page :deep(.el-pagination .btn-prev),
.rp-grid-page :deep(.el-pagination .btn-next) {
	min-width: 24px;
	height: 24px;
	line-height: 24px;
	border-radius: 2px;
}
.rp-grid-page :deep(.el-pagination .el-pagination__sizes) {
	margin-right: 4px;
}
.rp-grid-page :deep(.el-pagination__jump) {
	display: flex;
	align-items: center;
}
.rp-spacer {
	flex: 1;
}

/* 列设置面板 */
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
	padding: 3px 4px;
	font-size: 13px;
	color: #606266;
	border-radius: 3px;
}
.rp-col-item.draggable {
	cursor: grab;
}
.rp-col-dragging {
	background: #f0f7ff;
	outline: 1px dashed #409eff;
}
.rp-col-drag {
	color: #c0c4cc;
	cursor: move;
}
.rp-col-label {
	flex: 1;
}
.rp-col-panel-foot {
	margin-top: 8px;
	font-size: 12px;
	color: #c0c4cc;
}
</style>