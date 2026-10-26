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
			:currentPage="paginationProps.currentPage" :pageSize="paginationProps.pageSize" :page-sizes="[30, 50, 100]"
			pagination-layout="total, prev, pager, next, jumper, sizes" empty-text="当前筛选条件下暂无数据"
			rowKey="id" height="100%" stripe
			@refresh="refresh" @pageChange="handlePageChange" @pageSizeChange="handlePageSizeChange">
			<template #bill_no_default="{ row }">
				<el-button type="primary" link size="small" @click="handleView(row)">{{ row.bill_no }}</el-button>
			</template>
			<template #actual_default="{ row }">¥{{ fmt(row.actual_amount) }}</template>
			<template #status_default="{ row }">
				<el-tag class="status-tag" :type="statusType(row.status)" size="small">{{ statusLabel(row.status) }}</el-tag>
			</template>
			<template #action_default="{ row }">
				<el-button type="primary" link size="small" @click="handleView(row)">查看明细</el-button>
				<template v-if="row.status === 'pending'">
					<el-button v-if="hasPerm('perf.audit')" type="success" link size="small" @click="handleAudit(row)">审核</el-button>
					<el-button v-if="hasPerm('perf.audit')" type="warning" link size="small" @click="handleReject(row)">驳回</el-button>
				</template>
				<el-button v-if="row.status === 'audited' && hasPerm('perf.audit')" type="primary" link size="small" @click="handlePay(row)">标记发放</el-button>
				<el-button v-if="hasPerm('perf.export')" type="info" link size="small" @click="handleExport(row)">工资条</el-button>
			</template>
		</sTable>

		<!-- 工资条弹窗：900px，标题栏 50px，footer 60px，圆角 4px -->
		<el-dialog v-model="detailVisible" class="slip-dialog" title="提成工资条" width="900px" :close-on-click-modal="false">
			<div v-if="detail" class="pay-slip">
				<div class="slip-header">
					<h2>提成工资条</h2>
					<div class="slip-meta">业务员：{{ detail.employee_name }} | 月份：{{ detail.period }} | 单号：{{ detail.bill_no }} | 部门：{{ detail.department_name || '-' }}</div>
				</div>
				<table class="slip-table">
					<tbody>
						<tr><td>销售额</td><td class="r">¥{{ fmt(detail.sales_amount) }}</td></tr>
						<tr><td>回款额</td><td class="r">¥{{ fmt(detail.received_amount) }}</td></tr>
						<tr><td>退货金额</td><td class="r">¥{{ fmt(detail.return_amount) }}</td></tr>
						<tr><td>毛利</td><td class="r">¥{{ fmt(detail.profit_amount) }}</td></tr>
						<tr><td>应发提成</td><td class="r">¥{{ fmt(detail.commission_amount) }}</td></tr>
						<tr><td>扣减</td><td class="r">-¥{{ fmt(detail.deduction) }}</td></tr>
						<tr class="total-row"><td>实发提成</td><td class="r total">¥{{ fmt(detail.actual_amount) }}</td></tr>
					</tbody>
				</table>
				<div class="slip-sign">
					<span>制表：{{ detail.maker_name || '-' }}</span>
					<span>审核：{{ detail.auditor_name || '-' }}</span>
					<span>领款人：________</span>
				</div>
				<div v-if="detail.items?.length" class="slip-items">
					<h4>明细</h4>
					<el-table :data="detail.items" border size="small">
						<el-table-column prop="source_no" label="来源" min-width="160" />
						<el-table-column prop="product_name" label="商品" min-width="140" show-overflow-tooltip />
						<el-table-column prop="rate_or_unit" label="比例/单额" width="120" />
						<el-table-column prop="base_amount" label="基数" width="110" align="right">
							<template #default="{ row }">¥{{ fmt(row.base_amount) }}</template>
						</el-table-column>
						<el-table-column prop="amount" label="提成" width="110" align="right">
							<template #default="{ row }"><span class="text-red">¥{{ fmt(row.amount) }}</span></template>
						</el-table-column>
					</el-table>
				</div>
			</div>
			<template #footer>
				<el-button @click="detailVisible = false">关闭</el-button>
				<el-button type="primary" @click="handlePrint">打印（A5）</el-button>
			</template>
		</el-dialog>
	</div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { useTable } from '@/hooks/useTable'
import { usePermission } from '@/composables/usePermission'
import perfApi from '@/api/perf'
import { saveBlob } from '@/utils/fileDownload'

const fmt = (v) => Number(v || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const statusLabel = (s) => ({ pending: '待审核', audited: '已审核', paid: '已发放', rejected: '已驳回' }[s] || s)
// 12px 状态标签：待审核橙 #E6A23E / 已审核绿 #67C23A / 已发放蓝 #409EFF / 已驳回红 #F56C6C
const statusType = (s) => ({ pending: 'warning', audited: 'success', paid: 'primary', rejected: 'danger' }[s] || 'info')

const { has: hasPerm } = usePermission()

const search = reactive({ period_start: '', period_end: '', keyword: '', status: '' })
const { tableRef, data, total, loading, paginationProps, refresh, handlePageChange, handlePageSizeChange } = useTable({
	apiObj: { get: (params) => perfApi.order.list.get(params) },
	searchForm: search,
	pageSize: 30,
})

// 金额口径（后端字段）：应发 commission_amount / 扣减 deduction / 实发 actual_amount
const columns = [
	{ type: 'checkbox', width: 45 },
	{ prop: 'bill_no', title: '单号', width: 150, slots: { default: 'bill_no_default' } },
	{ prop: 'period', title: '所属月份', width: 100, align: 'center' },
	{ prop: 'employee_name', title: '业务员', width: 120 },
	{ prop: 'department_name', title: '部门', width: 120 },
	{ prop: 'sales_amount', title: '销售额', width: 120, align: 'right', formatter: ({ row }) => fmt(row.sales_amount) },
	{ prop: 'received_amount', title: '回款额', width: 120, align: 'right', formatter: ({ row }) => fmt(row.received_amount) },
	{ prop: 'profit_amount', title: '毛利', width: 110, align: 'right', formatter: ({ row }) => fmt(row.profit_amount) },
	{ prop: 'commission_amount', title: '应发提成', width: 110, align: 'right', formatter: ({ row }) => fmt(row.commission_amount) },
	{ prop: 'deduction', title: '扣减', width: 100, align: 'right', formatter: ({ row }) => fmt(row.deduction) },
	{ prop: 'actual_amount', title: '实发提成', width: 130, align: 'right', className: 'col-actual', slots: { default: 'actual_default' } },
	{ prop: 'status', title: '状态', width: 100, align: 'center', slots: { default: 'status_default' } },
	{ prop: 'auditor_name', title: '审核人', width: 100 },
	{ prop: 'action_col', title: '操作', width: 300, align: 'center', fixed: 'right', slots: { default: 'action_default' } },
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
	if (res.code === 200) { ElMessage.success(res.message || '审核成功'); refresh() }
}
const handleReject = async (row) => {
	try { await ElMessageBox.confirm('确认驳回该提成单？驳回后不可恢复。', '操作确认', { type: 'warning' }) } catch { return }
	const res = await perfApi.order.reject.post(row.id)
	if (res.code === 200) { ElMessage.success(res.message || '已驳回'); refresh() }
}
const handlePay = async (row) => {
	try { await ElMessageBox.confirm('确认标记该提成单为已发放？', '操作确认', { type: 'warning' }) } catch { return }
	const res = await perfApi.order.pay.post(row.id)
	if (res.code === 200) { ElMessage.success(res.message || '已标记发放'); refresh() }
}
const handleExport = async (row) => {
	const res = await perfApi.order.export(row.id)
	saveBlob(res, `commission_${row.bill_no}.csv`)
}
const handlePrint = () => window.print()
const resetSearch = () => { search.period_start = ''; search.period_end = ''; search.keyword = ''; search.status = ''; refresh() }

onMounted(() => refresh())
</script>

<style scoped>
.perf-list { height: 100%; display: flex; flex-direction: column; }
.perf-filter { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; padding: 16px 20px; background: #fff; border-bottom: 1px solid #ebeef5; }
.perf-filter .label { font-size: 14px; color: #606266; }
.text-red { color: #f56c6c; font-weight: 600; }

/* 表格：表头 #F5F7FA 14px/500，表体 14px #606266，行高 48px，斑马 #FAFAFA */
:deep(.el-table th.el-table__cell) { background: #f5f7fa; color: #303133; font-size: 14px; font-weight: 500; height: 48px; }
:deep(.el-table td.el-table__cell) { color: #606266; font-size: 14px; height: 48px; }
:deep(.el-table .el-table__row--striped td.el-table__cell) { background: #fafafa; }

/* sTable 走 vxe：金额千分位、实发加粗红字、状态 12px */
:deep(.vxe-table .vxe-cell) { font-size: 14px; color: #606266; }
:deep(.vxe-table .vxe-header--column) { background: #f5f7fa; font-size: 14px; font-weight: 500; color: #303133; }
:deep(.vxe-table .vxe-body--row.row--stripe) { background: #fafafa; }
:deep(.col-actual .vxe-cell), :deep(td.col-actual .vxe-cell) { font-weight: bold; color: #f56c6c; }
:deep(.status-tag) { font-size: 12px; }

/* 弹窗规格：标题栏 50px/16px 加粗，footer 60px，圆角 4px，浅底 #FAFAFA，边框 #DCDFE6 */
:deep(.slip-dialog .el-dialog) { border-radius: 4px; }
:deep(.slip-dialog .el-dialog__header) { margin: 0; padding: 0 20px; height: 50px; line-height: 50px; border-bottom: 1px solid #dcdfe6; }
:deep(.slip-dialog .el-dialog__title) { font-size: 16px; font-weight: 600; color: #303133; line-height: 50px; }
:deep(.slip-dialog .el-dialog__body) { padding: 16px 24px; }
:deep(.slip-dialog .el-dialog__footer) { height: 60px; box-sizing: border-box; padding: 12px 24px; background: #fafafa; border-top: 1px solid #dcdfe6; }
:deep(.slip-dialog .el-form-item__label) { width: 110px; text-align: right; padding-right: 12px; background: #fafafa; }
:deep(.slip-dialog .el-input__wrapper) { min-height: 36px; }
:deep(.slip-dialog .el-button) { height: 36px; }

.pay-slip { padding: 8px; }
.slip-header { text-align: center; margin-bottom: 16px; }
.slip-header h2 { font-size: 20px; color: #303133; margin: 0 0 8px; }
.slip-meta { font-size: 13px; color: #606266; }
.slip-table { width: 100%; border-collapse: collapse; }
.slip-table td { border: 1px solid #dcdfe6; padding: 8px 12px; font-size: 14px; color: #606266; }
.slip-table td.r { text-align: right; }
.slip-table .total-row td { background: #fafafa; font-weight: bold; color: #303133; }
.slip-table .total { font-size: 22px; color: #f56c6c; }
.slip-sign { display: flex; justify-content: space-around; margin-top: 24px; font-size: 13px; color: #909399; }
.slip-items { margin-top: 20px; }
.slip-items h4 { font-size: 14px; color: #303133; margin: 0 0 8px; }

/* A5 打印：只留工资条，隐藏筛选区/按钮/列表 */
@media print {
	@page { size: A5; margin: 10mm; }
	body * { visibility: hidden; }
	.pay-slip, .pay-slip * { visibility: visible; }
	.pay-slip { position: absolute; left: 0; top: 0; width: 100%; padding: 0; }
	:deep(.slip-dialog .el-dialog) { position: absolute !important; left: 0 !important; top: 0 !important; margin: 0 !important; width: 100% !important; max-width: 100% !important; box-shadow: none !important; border-radius: 0 !important; }
	:deep(.slip-dialog .el-dialog__header), :deep(.slip-dialog .el-dialog__footer), .perf-filter, .perf-filter * { display: none !important; }
	:deep(.slip-dialog .el-dialog__body) { padding: 0; }
}
</style>
