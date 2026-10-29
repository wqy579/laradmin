<template>
	<el-drawer
		v-model="visible"
		:title="headerTitle"
		direction="rtl"
		size="720px"
		:close-on-click-modal="true"
		:show-close="true"
	>
		<div v-loading="loading" class="gdd-body">
			<template v-if="header">
				<!-- 表头：大标题 + 次要编码 Tag -->
				<div class="gdd-head">
					<span class="gdd-title">{{ header.title || '明细' }}</span>
					<el-tag v-if="header.code" type="info" size="small" effect="plain">{{ header.code }}</el-tag>
				</div>

				<!-- 键值描述：由后端 columns 契约的 desc 数组驱动 -->
				<el-descriptions v-if="header.desc && header.desc.length" :column="1" size="small" class="gdd-desc">
					<el-descriptions-item v-for="(d, i) in header.desc" :key="i" :label="d.label">
						{{ d.value || '—' }}
					</el-descriptions-item>
				</el-descriptions>

				<!-- 明细表：列由后端 columns 驱动 -->
				<div class="gdd-subtitle">明细（{{ items.length }} 行）</div>
				<el-table :data="items" size="small" border :row-style="rowStyle" :header-cell-style="headerCellStyle" class="gdd-table">
					<el-table-column v-for="col in columns" :key="col.prop" :prop="col.prop" :label="col.label" :width="col.width || null" :align="alignOf(col)" show-overflow-tooltip>
						<template #default="{ row }">
							<Cell :col="col" :row="row" />
						</template>
					</el-table-column>
				</el-table>

				<!-- 底部合计：totals 契约 [{label, value}]，金额右对齐红色 -->
				<div v-if="header.totals && header.totals.length" class="gdd-totals">
					<span v-for="(t, i) in header.totals" :key="i" class="gdd-total-item">
						{{ t.label }}：<b>¥{{ money(t.value) }}</b>
					</span>
				</div>
			</template>

			<el-empty v-else-if="!loading" description="未找到该明细" :image-size="72" />
		</div>
	</el-drawer>
</template>

<script setup>
import { ref, computed, watch, h } from 'vue'

const props = defineProps({
	modelValue: { type: Boolean, default: false },
	/** 穿透载荷：由父组件从双击行算出，如 { order_no } / { adjust_no } / { supplier_id } / { product_id } */
	payload: { type: Object, default: null },
	/** 拉取明细的接口函数，签名 (payload) => Promise，返回 { header, items, columns } */
	fetcher: { type: Function, default: null },
})

const emit = defineEmits(['update:modelValue'])

const visible = computed({
	get: () => props.modelValue,
	set: (v) => emit('update:modelValue', v),
})

const loading = ref(false)
const header = ref(null)
const items = ref([])
const columns = ref([])

const headerTitle = computed(() => header.value?.title || '明细')

function money(v) {
	return Number(v || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
function num(v, digits = 2) {
	const n = Number(v || 0)
	return digits === 0 ? String(Math.round(n)) : n.toFixed(digits)
}
function alignOf(col) {
	return ['money', 'number', 'int', 'percent', 'rate'].includes(col.type) ? 'right' : col.align || 'left'
}

/** 通用单元格：按列 type 渲染（金额/数量右对齐千分位、文本直出、bold 加粗） */
const Cell = {
	props: { col: Object, row: Object },
	setup(p) {
		return () => {
			const raw = p.row?.[p.col.prop]
			const bold = p.col.bold ? { fontWeight: 700 } : {}
			switch (p.col.type) {
				case 'money':
					return h('span', { style: { fontVariantNumeric: 'tabular-nums', ...bold } }, '¥' + money(raw))
				case 'number':
					return h('span', { style: { fontVariantNumeric: 'tabular-nums', ...bold } }, num(raw, 2))
				case 'int':
					return h('span', { style: { fontVariantNumeric: 'tabular-nums', ...bold } }, num(raw, 0))
				default:
					return h('span', { style: bold }, raw === null || raw === undefined ? '' : String(raw))
			}
		}
	},
}

const rowStyle = () => ({ height: '41px' })
const headerCellStyle = () => ({ height: '44px', background: '#F5F7FA', color: '#606266', fontWeight: 500, fontSize: '13px' })

async function open() {
	if (!props.fetcher || !props.payload) return
	loading.value = true
	header.value = null
	items.value = []
	columns.value = []
	try {
		const res = await props.fetcher(props.payload)
		const data = res?.header ? res : (res?.data ?? {})
		header.value = data.header || null
		items.value = data.items || []
		columns.value = data.columns || []
	} catch {
		header.value = null
		items.value = []
		columns.value = []
	} finally {
		loading.value = false
	}
}

watch(visible, (v) => { if (v) open() })
</script>

<style scoped>
.gdd-body {
	height: 100%;
	overflow: auto;
	padding-right: 4px;
}
.gdd-head {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-bottom: 12px;
}
.gdd-title {
	font-size: 15px;
	font-weight: 700;
	color: #303133;
	margin-right: auto;
}
.gdd-desc {
	margin-bottom: 6px;
}
.gdd-subtitle {
	font-size: 13px;
	font-weight: 700;
	color: #303133;
	margin: 14px 0 8px;
}
.gdd-table {
	width: 100%;
}
.gdd-table :deep(.el-table__cell) {
	font-size: 12px;
}
.gdd-totals {
	text-align: right;
	font-size: 13px;
	color: #303133;
	margin-top: 10px;
	padding-top: 8px;
	border-top: 1px dashed #ebeef5;
	font-variant-numeric: tabular-nums;
}
.gdd-total-item {
	margin-left: 16px;
}
.gdd-total-item b {
	color: #f56c6c;
}
</style>
