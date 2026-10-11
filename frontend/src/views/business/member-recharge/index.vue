<template>
	<div class="biz-list">
		<el-row :gutter="12" class="stats">
			<el-col :span="6"><el-card shadow="never" class="stat"><div class="num">{{ fmt(stats.total_amount) }}</div><div class="lbl">充值总额(元)</div></el-card></el-col>
			<el-col :span="6"><el-card shadow="never" class="stat"><div class="num">{{ fmt(stats.gift_amount) }}</div><div class="lbl">赠送金额</div></el-card></el-col>
			<el-col :span="6"><el-card shadow="never" class="stat"><div class="num">{{ stats.count }}</div><div class="lbl">已审核笔数</div></el-card></el-col>
			<el-col :span="6"><el-card shadow="never" class="stat"><div class="num warn">{{ stats.pending_count }}</div><div class="lbl">待审核</div></el-card></el-col>
		</el-row>

		<div class="toolbar">
			<div class="left-tools">
				<el-select v-model="searchForm.status" clearable size="small" placeholder="状态" style="width:110px" @change="search">
					<el-option label="待审核" :value="0" />
					<el-option label="已审核" :value="1" />
					<el-option label="已驳回" :value="2" />
					<el-option label="已取消" :value="3" />
				</el-select>
				<el-date-picker v-model="dateRange" type="daterange" size="small" range-separator="~"
					start-placeholder="起始" end-placeholder="截止" value-format="YYYY-MM-DD"
					style="width:240px" @change="onDateChange" />
				<el-input v-model="searchForm.keyword" placeholder="单号/会员名/手机" clearable size="small"
					style="width:180px" @clear="search" @keyup.enter="search" />
			</div>
			<div class="right-tools">
				<el-button type="primary" @click="handleAdd">新增充值</el-button>
			</div>
		</div>

		<sTable ref="tableRef" tableName="business_member_recharge" :data="data" :columns="columns" :searchForm="searchForm"
			:loading="loading" :total="total" :currentPage="paginationProps.currentPage"
			:pageSize="paginationProps.pageSize" :pageSizes="paginationProps.pageSizes"
			rowKey="id" height="100%" stripe @refresh="refresh" @search="search"
			@pageChange="handlePageChange" @pageSizeChange="handlePageSizeChange">
			<template #pay_method_default="{ row }">{{ payMethod(row.pay_method) }}</template>
			<template #recharge_amount_default="{ row }"><span class="money">{{ fmt(row.recharge_amount) }}</span></template>
			<template #total_amount_default="{ row }"><span class="money">{{ fmt(row.total_amount) }}</span></template>
			<template #status_default="{ row }">
				<el-tag :type="statusType(row.status)" size="small">{{ statusName(row.status) }}</el-tag>
			</template>
			<template #action_default="{ row }">
				<el-button type="primary" link size="small" @click="handleView(row)">详情</el-button>
				<el-button v-if="row.status === 0" type="success" link size="small" @click="handleApprove(row)">审核</el-button>
				<el-button v-if="row.status === 0" type="danger" link size="small" @click="handleReject(row)">驳回</el-button>
				<el-button v-if="row.status === 0" type="warning" link size="small" @click="handleCancel(row)">取消</el-button>
				<el-button v-if="row.status !== 1" type="primary" link size="small" @click="handleEdit(row)">编辑</el-button>
				<el-popconfirm v-if="row.status !== 1" title="确定删除该充值单？" @confirm="handleDelete(row)">
					<template #reference><el-button type="danger" link size="small">删除</el-button></template>
				</el-popconfirm>
			</template>
		</sTable>
	</div>

	<RechargeDialog v-if="dialog.visible" v-model:visible="dialog.visible" :record="current" @success="onChanged" />
	<RechargeDetail v-if="drawer.visible" v-model:visible="drawer.visible" :recharge="current" />
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { useTable } from '@/hooks/useTable'
import businessApi from '@/api/business'
import RechargeDialog from '../components/member-recharge-dialog.vue'
import RechargeDetail from '../components/member-recharge-detail.vue'

const searchForm = ref({ keyword: '', status: null, start_date: null, end_date: null })
const dateRange = ref(null)

const { tableRef, data, total, loading, paginationProps, refresh, search,
	handlePageChange, handlePageSizeChange } = useTable({
	apiObj: { get: (params) => businessApi.memberRecharge.list.get(params) },
	searchForm: searchForm.value,
})

const stats = reactive({ total_amount: 0, gift_amount: 0, count: 0, pending_count: 0 })

const columns = [
	{ prop: 'recharge_no', title: '充值单号', width: 170 },
	{ prop: 'member_name', title: '会员', width: 120 },
	{ prop: 'member_no', title: '会员编号', width: 130 },
	{ prop: 'recharge_amount', title: '充值金额', width: 110, align: 'right', slots: { default: 'recharge_amount_default' } },
	{ prop: 'gift_amount', title: '赠送金额', width: 100, align: 'right' },
	{ prop: 'total_amount', title: '到账金额', width: 110, align: 'right', slots: { default: 'total_amount_default' } },
	{ prop: 'pay_method', title: '支付方式', width: 100, align: 'center', slots: { default: 'pay_method_default' } },
	{ prop: 'recharge_date', title: '充值日期', width: 110, align: 'center' },
	{ prop: 'operator_name', title: '经办人', width: 100 },
	{ prop: 'status', title: '状态', width: 90, align: 'center', slots: { default: 'status_default' } },
	{ prop: 'action_col', title: '操作', width: 230, align: 'center', fixed: 'right', slots: { default: 'action_default' } },
]

const statuses = { 0: '待审核', 1: '已审核', 2: '已驳回', 3: '已取消' }
const statusName = (s) => statuses[s] || '-'
const statusType = (s) => ({ 0: 'warning', 1: 'success', 2: 'danger', 3: 'info' }[s] || 'info')
const pays = { cash: '现金', wechat: '微信', alipay: '支付宝', bank: '银行卡', other: '其他' }
const payMethod = (p) => pays[p] || p || '-'
const fmt = (n) => (n == null ? '0.00' : Number(n).toFixed(2))

const loadStats = async () => {
	const res = await businessApi.memberRecharge.statistics.get()
	Object.assign(stats, res.data || {})
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
const handleApprove = (row) => {
	ElMessageBox.confirm(`确定审核通过「${row.recharge_no}」？审核后将向会员储值账户加款 ${fmt(row.total_amount)} 元`, '审核确认', { type: 'warning' })
		.then(async () => {
			const res = await businessApi.memberRecharge.approve.post(row.id)
			if (res.code === 200) { ElMessage.success(res.message || '审核通过'); onChanged() }
		}).catch(() => {})
}
const handleReject = (row) => {
	ElMessageBox.prompt('请输入驳回原因', '驳回', { inputPattern: /\S+/, inputErrorMessage: '请填写原因' })
		.then(async ({ value }) => {
			const res = await businessApi.memberRecharge.reject.post(row.id, { reason: value })
			if (res.code === 200) { ElMessage.success(res.message || '已驳回'); onChanged() }
		}).catch(() => {})
}
const handleCancel = async (row) => {
	const res = await businessApi.memberRecharge.cancel.post(row.id)
	if (res.code === 200) { ElMessage.success(res.message || '已取消'); onChanged() }
}
const handleDelete = async (row) => {
	const res = await businessApi.memberRecharge.delete.delete(row.id)
	if (res.code === 200) { ElMessage.success(res.message || '已删除'); onChanged() }
}

onMounted(() => { loadStats(); refresh() })
</script>

<style scoped>
.biz-list { height: 100%; display: flex; flex-direction: column; }
.stats { margin-bottom: 12px; flex-shrink: 0; }
.stat { text-align: center; }
.stat .num { font-size: 20px; font-weight: 700; color: #333; }
.stat .num.warn { color: var(--el-color-warning); }
.stat .lbl { font-size: 12px; color: #999; margin-top: 2px; }
.toolbar { margin-bottom: 12px; display: flex; justify-content: space-between; align-items: center; flex-shrink: 0; }
.left-tools { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.right-tools { display: flex; gap: 8px; }
.money { color: var(--el-color-primary); font-weight: 700; }
</style>
