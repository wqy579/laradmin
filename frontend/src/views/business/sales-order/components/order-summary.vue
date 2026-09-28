<template>
	<div class="order-summary">
		<el-tabs v-model="activeTab" class="summary-tabs">
			<el-tab-pane v-for="t in tabs" :key="t.type" :label="t.label" :name="t.type">
				<ul class="summary-list">
					<li :class="['summary-row', 'summary-total', { active: activeId(t.type) == null }]" @click="onPick(t.type, null)">
						<span class="row-name">{{ t.totalLabel }}</span>
						<span class="row-count">{{ totals.order_count || 0 }}</span>
						<span class="row-amount">¥{{ fmt(totals.total_amount) }}</span>
						<span class="row-qty">{{ totals.total_qty || 0 }}</span>
					</li>
					<li v-for="(r, i) in rows(t.type)" :key="t.type + i" :class="['summary-row', { active: eq(activeId(t.type), r[t.idKey]) }]" @click="onPick(t.type, r[t.idKey])">
						<span class="row-name">{{ r.name || t.emptyName }}</span>
						<span class="row-count">{{ r.order_count }}</span>
						<span class="row-amount">¥{{ fmt(r.total_amount) }}</span>
						<span class="row-qty">{{ r.total_qty || 0 }}</span>
					</li>
					<li v-if="!rows(t.type).length" class="summary-empty">暂无数据</li>
				</ul>
			</el-tab-pane>
		</el-tabs>
	</div>
</template>

<script setup>
import { ref, computed } from 'vue'

const props = defineProps({
	summary: { type: Object, default: () => ({}) },
	activeFilter: { type: Object, default: () => ({ salesman: null, vehicle: null, route: null }) },
})
const emit = defineEmits(['filter'])
const activeTab = ref('salesman')
// 三维度配置：type 与父 searchForm 字段、active 键一致
const tabs = [
	{ type: 'salesman', label: '业务员', idKey: 'salesman_id', emptyName: '未分配业务员', totalLabel: '合计' },
	{ type: 'vehicle', label: '车辆', idKey: 'vehicle_id', emptyName: '未分配车辆', totalLabel: '合计' },
	{ type: 'route', label: '客户', idKey: 'route_id', emptyName: '未分线路', totalLabel: '全部' },
]
const totals = computed(() => props.summary?.totals || {})
const rows = (type) => {
	const key = { salesman: 'bySalesman', vehicle: 'byVehicle', route: 'byRoute' }[type]
	return props.summary?.[key] || []
}
const activeId = (type) => props.activeFilter?.[type] ?? null
const fmt = (n) => Number(n || 0).toFixed(2)
// 松等：id 数字/null 统一比较
const eq = (a, b) => String(a ?? '') === String(b ?? '')
const onPick = (type, id) => emit('filter', { type, id })
</script>

<style scoped>
.order-summary { height: 100%; display: flex; flex-direction: column; min-height: 0; }
.summary-tabs { flex: 1; display: flex; flex-direction: column; min-height: 0; }
.summary-tabs :deep(.el-tabs__content) { flex: 1; overflow: auto; min-height: 0; }
.summary-tabs :deep(.el-tabs__header) { margin: 0 8px; }
.summary-list { list-style: none; margin: 0; padding: 0; }
.summary-row {
	display: grid;
	grid-template-columns: 1fr 44px 76px 44px;
	gap: 4px;
	padding: 6px 10px;
	cursor: pointer;
	font-size: 12px;
	align-items: center;
	border-bottom: 1px solid var(--el-border-color-lighter);
}
.summary-row:hover { background: var(--el-fill-color-light); }
.summary-row.active { background: var(--el-color-primary-light-9); color: var(--el-color-primary); font-weight: 600; }
.summary-total { background: var(--el-fill-color); font-weight: 600; position: sticky; top: 0; z-index: 1; }
.summary-empty { padding: 12px; text-align: center; color: var(--el-text-color-placeholder); font-size: 12px; }
.row-name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.row-count, .row-qty { text-align: right; color: var(--el-text-color-regular); }
.row-amount { text-align: right; color: var(--el-color-success); }
</style>
