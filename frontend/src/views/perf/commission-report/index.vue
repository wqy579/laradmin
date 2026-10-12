<template>
	<div class="perf-list">
		<div class="perf-filter">
			<span class="label">所属月份</span>
			<el-date-picker v-model="search.period_start" type="month" value-format="YYYY-MM" placeholder="起" size="small" style="width: 120px" />
			<span style="color: #dcdfe6">~</span>
			<el-date-picker v-model="search.period_end" type="month" value-format="YYYY-MM" placeholder="止" size="small" style="width: 120px" />
			<span class="label">业务员</span>
			<el-input v-model="search.keyword" placeholder="业务员/单号" clearable size="small" style="width: 160px" @keyup.enter="refresh" @clear="refresh" />
			<span class="label">状态</span>
			<el-select v-model="search.status" placeholder="全部" clearable size="small" style="width: 110px" @change="refresh">
				<el-option label="待审核" value="pending" /><el-option label="已审核" value="audited" /><el-option label="已发放" value="paid" /><el-option label="已驳回" value="rejected" />
			</el-select>
			<el-button type="primary" size="small" @click="refresh">查询</el-button>
			<el-button size="small" @click="resetSearch">重置</el-button>
		</div>

		<sTable ref="tableRef" tableName="perf_commission_order" :data="data" :columns="columns" :loading="loading" :total="total"
			:currentPage="paginationProps.currentPage" :pageSize="paginationProps.pageSize" rowKey="id" height="100%" stripe
			@refresh="refresh" @pageChange="handlePageChange" @pageSizeChange="handlePageSizeChange">
			<template #status_default="{ row }">
				<el-tag :type="statusType(row.status)" size="small">{{ statusLabel(row.status) }}</el-tag>
			</template>
			<template #action_default="{ row }">
				<el-button type="primary" link size="small" @click="handleView(row)">查看明细</el-button>
				<el-button v-if="row.status === 'pending'" type="success" link size="small" @click="handleAudit(row)">审核</el-button>
				<el-button v-if="['pending','audited'].includes(row.status)" type="warning" link size="small" @click="handleReject(row)">驳回</el-button>
				<el-button v-if="row.status === 'audited'" type="primary" link size="small" @click="handlePay(row)">标记发放</el-button>
				<el-button type="info" link size="small" @click="handleExport(row)">工资条</el-button>
			</template>
		</sTable>

		<!-- 详情/工资条弹窗 -->
		<el-dialog v-model="detailVisible" title="提成工资条" width="640px">
			<div v-if="detail" class="pay-slip">
				<div class="slip-header">
					<h2>提成工资条</h2>
					<div class="slip-meta">业务员：{{ detail.employee_name }} | 月份：{{ detail.period }} | 单号：{{ detail.bill_no }}</div>
				</div>
				<table class="slip-table">
					<tbody>
						<tr><td>销售额</td><td class="r">¥{{ fmt(detail.sale_amount) }}</td></tr>
						<tr><td>回款额</td><td class="r">¥{{ fmt(detail.payment_amount) }}</td></tr>
						<tr><td>退货金额</td><td class="r">¥{{ fmt(detail.return_amount) }}</td></tr>
						<tr><td>毛利</td><td class="r">¥{{ fmt(detail.gross_profit) }}</td></tr>
						<tr><td>应发提成</td><td class="r">¥{{ fmt(detail.commission_gross) }}</td></tr>
						<tr><td>扣减</td><td class="r">-¥{{ fmt(detail.commission_deduct) }}</td></tr>
						<tr class="total-row"><td>实发提成</td><td class="r total">¥{{ fmt(detail.commission_amount) }}</td></tr>
					</tbody>
				</table>
				<div class="slip-sign">
					<span>制单：{{ detail.maker_name || '-' }}</span>
					<span>审核：{{ detail.auditor_name || '-' }}</span>
					<span>领款人：________</span>
				</div>
				<div class="slip-items" v-if="detail.items?.length">
					<h4>明细</h4>
					<el-table :data="detail.items" border size="small">
						<el-table-column prop="source_no" label="来源" min-width="160" />
						<el-table-column prop="rate_or_unit" label="比例/单额" width="120" />
						<el-table-column prop="base_amount" label="基数" width="100" align="right">
							<template #default="{ row }">¥{{ fmt(row.base_amount) }}</template>
						</el-table-column>
						<el-table-column prop="amount" label="提成" width="120" align="right">
							<template #default="{ row }"><span class="text-red">¥{{ fmt(row.amount) }}</span></template>
						</el-table-column>
					</el-table>
				</div>
			</div>
			<template #footer><el-button @click="detailVisible = false">关闭</el-button></template>
		</el-dialog>
	</div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { useTable } from '@/hooks/useTable'
import perfApi from '@/api/perf'
import { saveBlob } from '@/utils/fileDownload'

const fmt = (v) => Number(v || 0).toFixed(2)
const statusLabel = (s) => ({ pending: '待审核', audited: '已审核', paid: '已发放', rejected: '已驳回' }[s] || s)
const statusType = (s) => ({ pending: 'warning', audited: 'success', paid: '', rejected: 'danger' }[s] || 'info')

const search = reactive({ period_start: '', period_end: '', keyword: '', status: '' })
const { tableRef, data, total, loading, paginationProps, refresh, handlePageChange, handlePageSizeChange } = useTable({
	apiObj: { get: (params) => perfApi.order.list.get(params) },
	searchForm: search,
})

const columns = [
	{ type: 'checkbox', width: 45 },
	{ prop: 'bill_no', title: '单号', width: 150 },
	{ prop: 'period', title: '所属月份', width: 100, align: 'center' },
	{ prop: 'employee_name', title: '业务员', width: 120 },
	{ prop: 'department_name', title: '部门', width: 120 },
	{ prop: 'sale_amount', title: '销售额', width: 120, align: 'right' },
	{ prop: 'payment_amount', title: '回款额', width: 120, align: 'right' },
	{ prop: 'gross_profit', title: '毛利', width: 110, align: 'right' },
	{ prop: 'commission_gross', title: '应发提成', width: 110, align: 'right' },
	{ prop: 'commission_deduct', title: '扣减', width: 100, align: 'right' },
	{ prop: 'commission_amount', title: '实发提成', width: 130, align: 'right' },
	{ prop: 'status', title: '状态', width: 100, align: 'center', slots: { default: 'status_default' } },
	{ prop: 'auditor_name', title: '审核人', width: 100 },
	{ prop: 'action_col', title: '操作', width: 280, align: 'center', fixed: 'right', slots: { default: 'action_default' } },
]

const detailVisible = ref(false)
const detail = ref(null)

const handleView = async (row) => {
	const res = await perfApi.order.detail.get(row.id)
	if (res.code === 200) { detail.value = res.data; detailVisible.value = true }
}
const handleAudit = async (row) => {
	try { await ElMessageBox.confirm('确认审核该提成单？审核后数据将锁定。', '操作确认', { type: 'warning' }) } catch { return }
	const res = await perfApi.order.audit.post(row.id)
	if (res.code === 200) { ElMessage.success('审核成功'); refresh() }
}
const handleReject = async (row) => {
	try { await ElMessageBox.confirm('确认驳回该提成单？', '操作确认', { type: 'warning' }) } catch { return }
	const res = await perfApi.order.reject.post(row.id)
	if (res.code === 200) { ElMessage.success('已驳回'); refresh() }
}
const handlePay = async (row) => {
	try { await ElMessageBox.confirm('确认标记该提成单为已发放？', '操作确认', { type: 'warning' }) } catch { return }
	const res = await perfApi.order.pay.post(row.id)
	if (res.code === 200) { ElMessage.success('已标记发放'); refresh() }
}
const handleExport = async (row) => {
	const res = await perfApi.order.export(row.id)
	saveBlob(res, `commission_${row.bill_no}.csv`)
}
const resetSearch = () => { search.period_start = ''; search.period_end = ''; search.keyword = ''; search.status = ''; refresh() }

onMounted(() => refresh())
</script>

<style scoped>
.perf-list { height: 100%; display: flex; flex-direction: column; }
.perf-filter { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; padding: 16px 20px; background: #fff; border-bottom: 1px solid #ebeef5; }
.perf-filter .label { font-size: 14px; color: #606266; }
.text-red { color: #f56c6c; font-weight: 600; }
.pay-slip { padding: 8px; }
.slip-header { text-align: center; margin-bottom: 16px; }
.slip-header h2 { font-size: 20px; color: #303133; margin: 0 0 8px; }
.slip-meta { font-size: 13px; color: #606266; }
.slip-table { width: 100%; border-collapse: collapse; }
.slip-table td { border: 1px solid #ebeef5; padding: 8px 12px; font-size: 14px; color: #606266; }
.slip-table td.r { text-align: right; }
.slip-table .total-row td { background: #f5f7fa; font-weight: bold; color: #303133; }
.slip-table .total { font-size: 22px; color: #f56c6c; }
.slip-sign { display: flex; justify-content: space-around; margin-top: 24px; font-size: 13px; color: #909399; }
.slip-items { margin-top: 20px; }
.slip-items h4 { font-size: 14px; color: #303133; margin: 0 0 8px; }
</style>
