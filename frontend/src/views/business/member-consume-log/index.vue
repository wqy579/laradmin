<template>
	<div class="biz-list">
		<!-- 统计 -->
		<el-row :gutter="12" class="stats">
			<el-col :span="6"><el-card shadow="never" class="stat"><div class="num">{{ stats.total_count }}</div><div class="lbl">台账总数</div></el-card></el-col>
			<el-col :span="6"><el-card shadow="never" class="stat"><div class="num ok">{{ fmt(stats.total_recharge) }}</div><div class="lbl">充值入账合计</div></el-card></el-col>
			<el-col :span="6"><el-card shadow="never" class="stat"><div class="num warn">{{ stats.total_deduct_points }}</div><div class="lbl">积分抵扣合计</div></el-card></el-col>
			<el-col :span="6"><el-card shadow="never" class="stat"><div class="num">{{ byType.length }}</div><div class="lbl">业务类型数</div></el-card></el-col>
		</el-row>

		<div class="toolbar">
			<div class="left-tools">
				<el-select v-model="searchForm.consume_type" clearable size="small" placeholder="业务类型" style="width:130px" @change="search">
					<el-option v-for="(name, key) in types" :key="key" :label="name" :value="key" />
				</el-select>
				<el-date-picker v-model="dateRange" type="daterange" size="small" range-separator="~"
					start-placeholder="起始" end-placeholder="截止" value-format="YYYY-MM-DD"
					style="width:240px" @change="onDateChange" />
				<el-input v-model="searchForm.keyword" clearable size="small" placeholder="台账号/会员/关联单据"
					style="width:200px" @clear="search" @keyup.enter="search" />
			</div>
		</div>

		<sTable ref="tableRef" tableName="business_member_consume_log" :data="data" :columns="columns" :searchForm="searchForm"
			:loading="loading" :total="total" :currentPage="paginationProps.currentPage"
			:pageSize="paginationProps.pageSize" :pageSizes="paginationProps.pageSizes"
			rowKey="id" height="100%" stripe @refresh="refresh" @search="search"
			@pageChange="handlePageChange" @pageSizeChange="handlePageSizeChange">
			<template #consume_type_default="{ row }">{{ types[row.consume_type] || row.consume_type }}</template>
			<template #direction_default="{ row }">
				<el-tag :type="row.direction === 'in' ? 'success' : 'danger'" size="small">
					{{ row.direction === 'in' ? '入账' : '支出' }}
				</el-tag>
			</template>
			<template #consume_amount_default="{ row }"><span class="money">{{ fmt(row.consume_amount) }}</span></template>
			<template #points_default="{ row }">
				<span class="pts-in" v-if="row.earn_points">+{{ row.earn_points }}</span>
				<span class="pts-out" v-if="row.use_points">-{{ row.use_points }}</span>
				<span v-if="!row.earn_points && !row.use_points" class="muted">-</span>
			</template>
			<template #action_default="{ row }">
				<el-button type="primary" link size="small" @click="view(row)">详情</el-button>
			</template>
		</sTable>
	</div>

	<el-drawer v-model="drawer.visible" title="台账详情" size="480px" destroy-on-close>
		<el-descriptions v-if="drawer.log" :column="1" border>
			<el-descriptions-item label="台账号">{{ drawer.log.log_no }}</el-descriptions-item>
			<el-descriptions-item label="会员">{{ drawer.log.member_name }}<span class="muted"> {{ drawer.log.member_no }}</span></el-descriptions-item>
			<el-descriptions-item label="关联单据">{{ drawer.log.related_order_no || '-' }}</el-descriptions-item>
			<el-descriptions-item label="业务类型">{{ types[drawer.log.consume_type] || drawer.log.consume_type }}</el-descriptions-item>
			<el-descriptions-item label="方向">{{ drawer.log.direction === 'in' ? '入账' : '支出' }}</el-descriptions-item>
			<el-descriptions-item label="消费金额">{{ fmt(drawer.log.consume_amount) }}</el-descriptions-item>
			<el-descriptions-item label="使用储值">{{ fmt(drawer.log.use_balance) }}</el-descriptions-item>
			<el-descriptions-item label="消耗积分">-{{ drawer.log.use_points || 0 }}</el-descriptions-item>
			<el-descriptions-item label="获得积分">+{{ drawer.log.earn_points || 0 }}</el-descriptions-item>
			<el-descriptions-item label="储值后余额">{{ fmt(drawer.log.balance_after) }}</el-descriptions-item>
			<el-descriptions-item label="积分后余额">{{ drawer.log.points_after }}</el-descriptions-item>
			<el-descriptions-item label="累计消费后">{{ fmt(drawer.log.total_consume_after) }}</el-descriptions-item>
			<el-descriptions-item label="经办人">{{ drawer.log.operator_name || '-' }}</el-descriptions-item>
			<el-descriptions-item label="时间">{{ drawer.log.created_at }}</el-descriptions-item>
			<el-descriptions-item label="备注">{{ drawer.log.remark || '-' }}</el-descriptions-item>
		</el-descriptions>
	</el-drawer>
</template>

<script setup>
import { ref, reactive, onMounted, computed } from 'vue'
import { useTable } from '@/hooks/useTable'
import businessApi from '@/api/business'

const types = {
	order: '订单消费', recharge: '储值充值', points_deduct: '积分抵扣',
	order_refund: '订单退款', recharge_refund: '充值冲正',
}

const searchForm = ref({ keyword: '', consume_type: null, start_date: null, end_date: null })
const dateRange = ref(null)

const { tableRef, data, total, loading, paginationProps, refresh, search,
	handlePageChange, handlePageSizeChange } = useTable({
	apiObj: { get: (params) => businessApi.memberConsumeLog.list.get(params) },
	searchForm: searchForm.value,
})

const stats = reactive({ total_count: 0, total_recharge: 0, total_deduct_points: 0 })
const byType = computed(() => {
	if (!stats.by_type) return []
	return Array.isArray(stats.by_type) ? stats.by_type : Object.values(stats.by_type)
})

const columns = [
	{ prop: 'log_no', title: '台账号', width: 180 },
	{ prop: 'member_name', title: '会员', width: 120 },
	{ prop: 'member_no', title: '会员编号', width: 130 },
	{ prop: 'related_order_no', title: '关联单据', width: 180, showOverflowTooltip: true },
	{ prop: 'consume_type', title: '业务类型', width: 100, align: 'center', slots: { default: 'consume_type_default' } },
	{ prop: 'direction', title: '方向', width: 80, align: 'center', slots: { default: 'direction_default' } },
	{ prop: 'consume_amount', title: '消费金额', width: 100, align: 'right', slots: { default: 'consume_amount_default' } },
	{ prop: 'points', title: '积分变动', width: 90, align: 'center', slots: { default: 'points_default' } },
	{ prop: 'balance_after', title: '储值后', width: 100, align: 'right' },
	{ prop: 'operator_name', title: '经办人', width: 90 },
	{ prop: 'created_at', title: '时间', width: 150 },
	{ prop: 'action_col', title: '操作', width: 80, align: 'center', fixed: 'right', slots: { default: 'action_default' } },
]

const fmt = (n) => (n == null ? '0.00' : Number(n).toFixed(2))

const loadStats = async () => {
	const res = await businessApi.memberConsumeLog.statistics.get({
		start_date: searchForm.value.start_date,
		end_date: searchForm.value.end_date,
	})
	Object.assign(stats, res.data || {})
}

const onDateChange = (val) => {
	searchForm.value.start_date = val?.[0] ?? null
	searchForm.value.end_date = val?.[1] ?? null
	search(); loadStats()
}

const drawer = reactive({ visible: false, log: null })
const view = (row) => { drawer.log = row; drawer.visible = true }

onMounted(() => { loadStats(); refresh() })
</script>

<style scoped>
.biz-list { height: 100%; display: flex; flex-direction: column; }
.stats { margin-bottom: 12px; flex-shrink: 0; }
.stat { text-align: center; }
.stat .num { font-size: 20px; font-weight: 700; color: #333; }
.stat .num.ok { color: var(--el-color-success); }
.stat .num.warn { color: var(--el-color-warning); }
.stat .lbl { font-size: 12px; color: #999; margin-top: 2px; }
.toolbar { margin-bottom: 12px; display: flex; align-items: center; flex-shrink: 0; }
.left-tools { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.money { color: var(--el-color-primary); font-weight: 700; }
.pts-in { color: var(--el-color-success); font-weight: 700; }
.pts-out { color: var(--el-color-danger); font-weight: 700; }
.muted { color: #c0c4cc; }
</style>
