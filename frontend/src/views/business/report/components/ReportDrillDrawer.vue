<template>
	<el-drawer
		v-model="visible"
		title="源单明细"
		direction="rtl"
		size="360px"
		:close-on-click-modal="true"
		:show-close="true"
	>
		<div v-loading="loading" class="rp-drill-body">
			<template v-if="header">
				<div class="rp-drill-head">
					<div class="rp-drill-no">{{ header.order_no }}</div>
					<el-tag v-if="header.order_type" type="info" size="small">{{ orderTypeLabel(header.order_type) }}</el-tag>
					<el-tag type="info" size="small" effect="plain">{{ statusLabel(header.status) }}</el-tag>
				</div>
				<el-descriptions :column="1" size="small" class="rp-drill-desc">
					<el-descriptions-item label="客户">{{ header.customer_code || '' }} {{ header.customer_name || '' }}</el-descriptions-item>
					<el-descriptions-item label="仓库">{{ header.warehouse_name || '—' }}</el-descriptions-item>
					<el-descriptions-item label="业务员">{{ header.salesman_name || '—' }}</el-descriptions-item>
					<el-descriptions-item label="下单日期">{{ header.order_date || '—' }}</el-descriptions-item>
					<el-descriptions-item label="配货日期">{{ header.dispatch_date || '—' }}</el-descriptions-item>
					<el-descriptions-item label="订单金额">
						<span class="rp-drill-amount">¥{{ money(header.total_amount) }}</span>
					</el-descriptions-item>
					<el-descriptions-item label="已收金额">
						<span class="rp-drill-amount">¥{{ money(header.paid_amount) }}</span>
					</el-descriptions-item>
				</el-descriptions>
				<div v-if="header.remark" class="rp-drill-remark">{{ header.remark }}</div>

				<div class="rp-drill-subtitle">商品明细</div>
				<el-table :data="items" size="small" border class="rp-drill-table">
					<el-table-column prop="product_code" label="编码" width="68" show-overflow-tooltip />
					<el-table-column prop="product_name" label="商品" min-width="88" show-overflow-tooltip />
					<el-table-column prop="spec" label="规格" width="64" show-overflow-tooltip />
					<el-table-column prop="quantity" label="数量" width="56" align="right" class-name="rp-num" />
					<el-table-column label="单价" width="76" align="right" class-name="rp-num">
						<template #default="{ row }">{{ money(row.price) }}</template>
					</el-table-column>
					<el-table-column label="金额" width="86" align="right" class-name="rp-num">
						<template #default="{ row }">{{ money(row.amount) }}</template>
					</el-table-column>
				</el-table>
				<div class="rp-drill-total">
					合计：<b>¥{{ money(itemsTotal) }}</b>
				</div>
			</template>

			<el-empty v-else-if="!loading" description="未找到该源单" :image-size="72" />
		</div>
	</el-drawer>
</template>

<script setup>
import { ref, computed, watch } from 'vue'

const props = defineProps({
	modelValue: { type: Boolean, default: false },
	/** 穿透载荷：由父组件从双击行算出，如 { order_id } / { order_no } */
	payload: { type: Object, default: null },
	/** 拉取源单的接口函数，签名 (payload) => Promise，返回 { header, items } */
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

const itemsTotal = computed(() => items.value.reduce((s, r) => s + Number(r.amount || 0), 0))

function money(v) {
	return Number(v || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

const ORDER_TYPE_LABELS = { normal: '正常销售', promotion: '促销销售', special: '特价销售' }
const STATUS_LABELS = { 0: '草稿', 1: '已审核', 2: '已配货', 3: '已发货', 4: '已完成', 5: '已红冲' }
function orderTypeLabel(t) { return ORDER_TYPE_LABELS[t] || t || '—' }
function statusLabel(s) {
	if (s === 1 || s === '1') return '已审核'
	return STATUS_LABELS[s] ?? (s ?? '—')
}

async function open() {
	if (!props.fetcher || !props.payload) return
	loading.value = true
	header.value = null
	items.value = []
	try {
		const res = await props.fetcher(props.payload)
		// 兼容 { data: { header, items } } 与直接 { header, items } 两种返回
		const data = res?.header ? res : (res?.data ?? {})
		header.value = data.header || null
		items.value = data.items || []
	} catch {
		header.value = null
		items.value = []
	} finally {
		loading.value = false
	}
}

watch(visible, (v) => { if (v) open() })
</script>

<style scoped>
.rp-drill-body {
	height: 100%;
	overflow: auto;
	padding-right: 4px;
}
.rp-drill-head {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-bottom: 12px;
}
.rp-drill-no {
	font-size: 15px;
	font-weight: 700;
	color: #303133;
	margin-right: auto;
}
.rp-drill-remark {
	font-size: 12px;
	color: #909399;
	margin-bottom: 4px;
	line-height: 20px;
}
.rp-drill-subtitle {
	font-size: 13px;
	font-weight: 700;
	color: #303133;
	margin: 14px 0 8px;
}
.rp-drill-table {
	width: 100%;
}
.rp-drill-table :deep(.el-table__cell) {
	font-size: 12px;
}
.rp-drill-total {
	text-align: right;
	font-size: 13px;
	color: #303133;
	margin-top: 10px;
	padding-top: 8px;
	border-top: 1px dashed #ebeef5;
	font-variant-numeric: tabular-nums;
}
.rp-drill-amount {
	color: #f56c6c;
	font-weight: 700;
	font-variant-numeric: tabular-nums;
}
.rp-drill-body :deep(.rp-num) {
	font-variant-numeric: tabular-nums;
}
</style>