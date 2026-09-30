<template>
	<div class="order-summary">
		<!-- 三个维度并列显示，切换只改高亮不隐藏 -->
		<div class="dim-tabs">
			<div v-for="t in tabs" :key="t.type" :class="['dim-tab', { active: activeTab === t.type }]" @click="activeTab = t.type">{{ t.label }}</div>
		</div>
		<div class="summary-scroll">
			<div v-for="t in tabs" :key="t.type" v-show="activeTab === t.type" class="summary-list">
				<div :class="['sum-item', { active: activeId(t.type) == null }]" @click="onPick(t.type, null)">
					<div class="sum-top">
						<span class="sum-name">{{ t.totalLabel }}</span>
						<span class="sum-amount"><b style="color:#FF5500">{{ fmt(totals.total_amount) }}</b><span class="unit">元</span></span>
						<span v-if="(totals.order_count || 0) > 0" class="sum-badge">{{ totals.order_count || 0 }}</span>
					</div>
					<div class="sum-sub">
						<span>普:{{ (totals.order_count || 0) > 0 ? totals.order_count : '--' }}</span>
						<span>退:--</span>
					</div>
					<div class="sum-sub">
						<span>大:{{ (totals.qty_large || 0) > 0 ? totals.qty_large : '--' }}</span>
						<span>中:{{ (totals.qty_medium || 0) > 0 ? totals.qty_medium : '--' }}</span>
						<span>小:{{ (totals.qty_small || 0) > 0 ? totals.qty_small : '--' }}</span>
					</div>
				</div>
				<div v-for="(r, i) in rows(t.type)" :key="t.type + i" :class="['sum-item', { active: eq(activeId(t.type), r[t.idKey]) }]" @click="onPick(t.type, r[t.idKey])">
					<div class="sum-top">
						<span class="sum-name">{{ r.name || t.emptyName }}</span>
						<span class="sum-amount"><b style="color:#FF5500">{{ fmt(r.total_amount) }}</b><span class="unit">元</span></span>
						<span v-if="(r.order_count || 0) > 0" class="sum-badge">{{ r.order_count }}</span>
					</div>
					<div class="sum-sub">
						<span>普:{{ (r.order_count || 0) > 0 ? r.order_count : '--' }}</span>
						<span>退:--</span>
					</div>
					<div class="sum-sub">
						<span>大:{{ (r.qty_large || 0) > 0 ? r.qty_large : '--' }}</span>
						<span>中:{{ (r.qty_medium || 0) > 0 ? r.qty_medium : '--' }}</span>
						<span>小:{{ (r.qty_small || 0) > 0 ? r.qty_small : '--' }}</span>
					</div>
				</div>
				<div v-if="!rows(t.type).length" class="summary-empty">暂无数据</div>
			</div>
		</div>
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
const eq = (a, b) => String(a ?? '') === String(b ?? '')
const onPick = (type, id) => emit('filter', { type, id })
</script>

<style scoped>
/* 背景与项目主背景一致，去掉填充色 */
.order-summary { height: 100%; display: flex; flex-direction: column; min-height: 0; background: var(--el-bg-color); }
.dim-tabs { display: flex; border-bottom: 1px solid var(--el-border-color); flex-shrink: 0; }
.dim-tab { flex: 1; text-align: center; padding: 8px 0; font-size: 13px; cursor: pointer; color: var(--el-text-color-regular); border-bottom: 2px solid transparent; }
.dim-tab.active { color: var(--el-color-primary); font-weight: 600; border-bottom-color: var(--el-color-primary); }
.summary-scroll { flex: 1; overflow-y: auto; min-height: 0; }
.summary-list { }
.summary-empty { padding: 12px; text-align: center; color: var(--el-text-color-placeholder); font-size: 12px; }

.sum-item {
	cursor: pointer;
	border-bottom: 1px solid #e0e0e0;
	padding: 4px 8px;
	background: var(--el-bg-color);
}
.sum-item:hover { background: var(--el-fill-color-light); }
.sum-item.active { background: var(--el-color-primary-light-9); }
.sum-top { display: flex; align-items: center; height: 30px; font-size: 14px; }
.sum-name { flex: 1; font-weight: bold; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.sum-amount { text-align: right; }
.sum-amount .unit { color: #ACACAC; font-size: 12px; margin-left: 2px; }
.sum-badge {
	min-width: 20px; height: 20px; line-height: 20px; text-align: center;
	background: var(--el-color-danger); color: #fff; border-radius: 10px;
	font-size: 11px; padding: 0 5px; margin-left: 6px;
}
.sum-sub { display: flex; font-size: 12px; height: 22px; align-items: center; color: var(--el-text-color-regular); }
.sum-sub span { width: 22%; padding-left: 2px; }
.sum-sub span:last-child { width: auto; }
</style>
