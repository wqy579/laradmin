<template>
	<div class="biz-list">
		<!-- 统计概览 -->
		<el-row :gutter="12" class="stats">
			<el-col :span="4"><el-card shadow="never" class="stat"><div class="num">{{ stats.total_members }}</div><div class="lbl">会员总数</div></el-card></el-col>
			<el-col :span="4"><el-card shadow="never" class="stat"><div class="num ok">{{ stats.active_members }}</div><div class="lbl">正常</div></el-card></el-col>
			<el-col :span="4"><el-card shadow="never" class="stat"><div class="num warn">{{ stats.frozen_members }}</div><div class="lbl">已冻结</div></el-card></el-col>
			<el-col :span="4"><el-card shadow="never" class="stat"><div class="num">{{ stats.new_this_month }}</div><div class="lbl">本月新增</div></el-card></el-col>
			<el-col :span="4"><el-card shadow="never" class="stat"><div class="num">{{ fmt(stats.total_balance) }}</div><div class="lbl">储值总额(元)</div></el-card></el-col>
			<el-col :span="4"><el-card shadow="never" class="stat"><div class="num">{{ stats.total_points }}</div><div class="lbl">积分总额</div></el-card></el-col>
		</el-row>

		<div class="toolbar">
			<div class="left-tools">
				<el-select v-model="searchForm.level_id" clearable size="small" placeholder="会员等级" style="width:130px" @change="search">
					<el-option v-for="l in levels" :key="l.id" :label="l.name" :value="l.id" />
				</el-select>
				<el-select v-model="searchForm.status" clearable size="small" placeholder="状态" style="width:100px" @change="search">
					<el-option label="正常" :value="1" />
					<el-option label="冻结" :value="0" />
				</el-select>
				<el-date-picker v-model="dateRange" type="daterange" size="small" range-separator="~"
					start-placeholder="注册起始" end-placeholder="注册截止" value-format="YYYY-MM-DD"
					style="width:240px" @change="onDateChange" />
				<el-input v-model="searchForm.keyword" placeholder="编号/名称/手机/客户" clearable size="small"
					style="width:200px" @clear="search" @keyup.enter="search" />
			</div>
			<div class="right-tools">
				<el-button @click="handleExport">导出</el-button>
				<el-button type="primary" @click="handleAdd">新增会员</el-button>
			</div>
		</div>

		<sTable ref="tableRef" tableName="business_member" :data="data" :columns="columns" :searchForm="searchForm"
			:loading="loading" :total="total" :currentPage="paginationProps.currentPage"
			:pageSize="paginationProps.pageSize" :pageSizes="paginationProps.pageSizes"
			rowKey="id" height="100%" stripe @refresh="refresh" @search="search"
			@pageChange="handlePageChange" @pageSizeChange="handlePageSizeChange"
			@selectionChange="onSelectionChange">
			<template #level_default="{ row }">
				<el-tag size="small" :color="row.level?.color" effect="dark" style="color:#fff" v-if="row.level">
					{{ row.level.name }}
				</el-tag>
				<span v-else class="muted">-</span>
			</template>
			<template #balance_default="{ row }">
				<span class="money">{{ fmt(row.balance) }}</span>
			</template>
			<template #status_default="{ row }">
				<el-tag :type="row.status === 1 ? 'success' : 'info'" size="small">
					{{ row.status === 1 ? '正常' : '冻结' }}
				</el-tag>
			</template>
			<template #action_default="{ row }">
				<el-button type="primary" link size="small" @click="handleEdit(row)">编辑</el-button>
				<el-button type="warning" link size="small" @click="handleView(row)">详情</el-button>
				<el-button v-if="row.status === 1" type="danger" link size="small" @click="handleFreeze(row)">冻结</el-button>
				<el-button v-else type="success" link size="small" @click="handleUnfreeze(row)">解冻</el-button>
				<el-popconfirm title="确定删除该会员？存在消费/储值/积分将被拒绝" @confirm="handleDelete(row)">
					<template #reference><el-button type="danger" link size="small">删除</el-button></template>
				</el-popconfirm>
			</template>
		</sTable>
	</div>

	<MemberDialog v-if="dialog.visible" v-model:visible="dialog.visible" :record="current"
		:levels="levels" @success="onChanged" />
	<MemberDetailDrawer v-if="drawer.visible" v-model:visible="drawer.visible" :member="current"
		@success="refresh" />
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { useTable } from '@/hooks/useTable'
import businessApi from '@/api/business'
import MemberDialog from '../components/member-dialog.vue'
import MemberDetailDrawer from '../components/member-detail-drawer.vue'

const searchForm = ref({ keyword: '', level_id: null, status: null, start_date: null, end_date: null })
const dateRange = ref(null)

const { tableRef, data, total, loading, selectedRows, paginationProps, refresh, search,
	handlePageChange, handlePageSizeChange, onSelectionChange } = useTable({
	apiObj: { get: (params) => businessApi.member.list.get(params) },
	searchForm: searchForm.value,
})

const stats = reactive({ total_members: 0, active_members: 0, frozen_members: 0, new_this_month: 0, total_balance: 0, total_points: 0 })
const levels = ref([])

const columns = [
	{ type: 'checkbox', width: 50 },
	{ prop: 'member_no', title: '会员编号', width: 130 },
	{ prop: 'name', title: '会员名称', width: 130 },
	{ prop: 'customer_name', title: '客户名称', width: 130, showOverflowTooltip: true },
	{ prop: 'contact', title: '联系人', width: 90 },
	{ prop: 'phone', title: '手机', width: 130 },
	{ prop: 'level', title: '等级', width: 100, align: 'center', slots: { default: 'level_default' } },
	{ prop: 'points', title: '积分', width: 90, align: 'right' },
	{ prop: 'balance', title: '储值余额', width: 110, align: 'right', slots: { default: 'balance_default' } },
	{ prop: 'total_consume', title: '累计消费', width: 110, align: 'right' },
	{ prop: 'total_orders', title: '订单数', width: 80, align: 'center' },
	{ prop: 'register_date', title: '注册日期', width: 110, align: 'center' },
	{ prop: 'status', title: '状态', width: 80, align: 'center', slots: { default: 'status_default' } },
	{ prop: 'action_col', title: '操作', width: 210, align: 'center', fixed: 'right', slots: { default: 'action_default' } },
]

const fmt = (n) => (n == null ? '0.00' : Number(n).toFixed(2))

const loadStats = async () => {
	const res = await businessApi.member.statistics.get()
	Object.assign(stats, res.data || {})
}
const loadLevels = async () => {
	const res = await businessApi.memberLevel.list.get()
	levels.value = res.data || []
}

const onDateChange = (val) => {
	searchForm.value.start_date = val?.[0] ?? null
	searchForm.value.end_date = val?.[1] ?? null
	search()
}

const dialog = reactive({ visible: false })
const current = ref(null)
const drawer = reactive({ visible: false })

const onChanged = () => { refresh(); loadStats() }
const handleAdd = () => { current.value = null; dialog.visible = true }
const handleEdit = (row) => { current.value = row; dialog.visible = true }
const handleView = (row) => { current.value = row; drawer.visible = true }
const handleDelete = async (row) => {
	const res = await businessApi.member.delete.delete(row.id)
	if (res.code === 200) { ElMessage.success(res.message || '已删除'); onChanged() }
}
const handleFreeze = (row) => {
	ElMessageBox.confirm(`确定冻结会员「${row.name}」？冻结后不可下单与兑换`, '提示', { type: 'warning' })
		.then(async () => {
			const res = await businessApi.member.freeze.post(row.id)
			if (res.code === 200) { ElMessage.success(res.message || '已冻结'); onChanged() }
		}).catch(() => {})
}
const handleUnfreeze = async (row) => {
	const res = await businessApi.member.unfreeze.post(row.id)
	if (res.code === 200) { ElMessage.success(res.message || '已解冻'); onChanged() }
}
const handleExport = async () => {
	const res = await businessApi.member.export({ ...searchForm.value })
	const url = URL.createObjectURL(res)
	const a = document.createElement('a')
	a.href = url; a.download = 'members.csv'; a.click()
	URL.revokeObjectURL(url)
}

onMounted(() => { loadLevels(); loadStats(); refresh() })
</script>

<style scoped>
.biz-list { height: 100%; display: flex; flex-direction: column; }
.stats { margin-bottom: 12px; flex-shrink: 0; }
.stat { text-align: center; }
.stat .num { font-size: 20px; font-weight: 700; color: #333; }
.stat .num.ok { color: var(--el-color-success); }
.stat .num.warn { color: var(--el-color-warning); }
.stat .lbl { font-size: 12px; color: #999; margin-top: 2px; }
.toolbar { margin-bottom: 12px; display: flex; justify-content: space-between; align-items: center; flex-shrink: 0; }
.left-tools { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.right-tools { display: flex; gap: 8px; }
.money { color: var(--el-color-primary); font-weight: 700; }
.muted { color: #c0c4cc; }
</style>
