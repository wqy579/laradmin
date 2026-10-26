<template>
	<el-drawer
		v-model="visible"
		title="客户订单列表"
		direction="rtl"
		size="680px"
		:close-on-click-modal="true"
		:show-close="true"
	>
		<div v-loading="loading" class="cdd-body">
			<template v-if="header">
				<!-- 客户表头 -->
				<div class="cdd-head">
					<span class="cdd-name">{{ header.customer_name || '—' }}</span>
					<el-tag v-if="header.customer_code" type="info" size="small" effect="plain">{{ header.customer_code }}</el-tag>
				</div>
				<el-descriptions :column="1" size="small" class="cdd-desc">
					<el-descriptions-item v-if="header.category" label="客户类别">{{ header.category }}</el-descriptions-item>
					<el-descriptions-item v-if="header.contact" label="联系人">{{ header.contact }}</el-descriptions-item>
					<el-descriptions-item v-if="header.phone" label="联系电话">{{ header.phone }}</el-descriptions-item>
					<el-descriptions-item v-if="header.address" label="地址">{{ header.address }}</el-descriptions-item>
				</el-descriptions>

				<!-- 订单列表：列由后端 columns 驱动 -->
				<div class="cdd-subtitle">订单列表（{{ items.length }} 笔）</div>
				<el-table :data="items" size="small" border :row-style="rowStyle" :header-cell-style="headerCellStyle" class="cdd-table">
					<el-table-column v-for="col in columns" :key="col.prop" :prop="col.prop" :label="col.label" :width="col.width || null" :align="alignOf(col)" show-overflow-tooltip>
						<template #default="{ row }">
							<Cell :col="col" :row="row" />
						</template>
					</el-table-column>
				</el-table>
				<div class="cdd-total">未收合计：<b>¥{{ money(unpaidTotal) }}</b></div>
			</template>

			<el-empty v-else-if="!loading" description="未找到该客户" :image-size="72" />
		</div>
	</el-drawer>
</template>

<script setup>
import { ref, computed, watch, h } from 'vue'

const props = defineProps({
	modelValue: { type: Boolean, default: false },
	/** 穿透载荷：{ customer_id } 或 { customer_code } */
	payload: { type: Object, default: null },
	/** 拉取客户订单列表的接口，签名 (payload) => Promise，返回 { header, items, columns } */
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

/** 通用单元格：按列 type 渲染（金额/数量右对齐千分位、文本直出、tag 用下拉文案） */
const Cell = {
	props: { col: Object, row: Object },
	setup(p) {
		return () => {
			const raw = p.row?.[p.col.prop]
			switch (p.col.type) {
				case 'money':
					return h('span', { style: { fontVariantNumeric: 'tabular-nums' } }, '¥' + money(raw))
				case 'number':
					return h('span', { style: { fontVariantNumeric: 'tabular-nums' } }, num(raw, 2))
				case 'int':
					return h('span', { style: { fontVariantNumeric: 'tabular-nums' } }, num(raw, 0))
				default:
					return h('span', {}, raw === null || raw === undefined ? '' : String(raw))
			}
		}
	},
}

const unpaidTotal = computed(() => items.value.reduce((s, r) => s + Number(r.unpaid_amount || 0), 0))
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
.cdd-body {
	height: 100%;
	overflow: auto;
	padding-right: 4px;
}
.cdd-head {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-bottom: 12px;
}
.cdd-name {
	font-size: 15px;
	font-weight: 700;
	color: #303133;
	margin-right: auto;
}
.cdd-desc {
	margin-bottom: 6px;
}
.cdd-subtitle {
	font-size: 13px;
	font-weight: 700;
	color: #303133;
	margin: 14px 0 8px;
}
.cdd-table {
	width: 100%;
}
.cdd-table :deep(.el-table__cell) {
	font-size: 12px;
}
.cdd-total {
	text-align: right;
	font-size: 13px;
	color: #303133;
	margin-top: 10px;
	padding-top: 8px;
	border-top: 1px dashed #ebeef5;
	font-variant-numeric: tabular-nums;
}
</style>