<template>
	<div class="page">
		<div class="page-header">
			<span class="title">刷卡收款记录</span>
			<el-button @click="onExport">
				<el-icon><Download /></el-icon><span>导出 Excel</span>
			</el-button>
		</div>

		<el-card shadow="never" class="card">
			<div class="filter">
				<el-date-picker
					v-model="dateRange"
					type="daterange"
					value-format="YYYY-MM-DD"
					range-separator="至"
					start-placeholder="开始日期"
					end-placeholder="结束日期"
					style="width:240px"
					@change="onDateChange"
				/>
				<el-select v-model="filters.customer_id" placeholder="客户" clearable filterable style="width:160px">
					<el-option v-for="c in customers" :key="c.id" :label="c.name" :value="c.id" />
				</el-select>
				<el-select v-model="filters.salesman_id" placeholder="业务员" clearable filterable style="width:130px">
					<el-option v-for="s in salesmen" :key="s.id" :label="s.name" :value="s.id" />
				</el-select>
				<el-input v-model="filters.card_last4" placeholder="卡号后四位" clearable maxlength="4" style="width:110px" />
				<el-select v-model="filters.status" placeholder="状态" clearable style="width:110px">
					<el-option label="成功" value="success" />
					<el-option label="失败" value="fail" />
					<el-option label="已退款" value="refund" />
				</el-select>
				<el-button type="primary" :loading="loading" @click="onSearch">查询</el-button>
				<el-button @click="onReset">重置</el-button>
			</div>

			<div class="stat-strip">
				<div class="stat">
					<div class="stat-label">记录数</div>
					<div class="stat-value tabular">{{ fmtCount(stat.count) }}</div>
				</div>
				<div class="stat">
					<div class="stat-label">成功收款</div>
					<div class="stat-value tabular ok">¥{{ fmtMoney(stat.success_amount) }}</div>
				</div>
				<div class="stat">
					<div class="stat-label">失败笔数</div>
					<div class="stat-value tabular warn">{{ fmtCount(stat.fail_count) }}</div>
				</div>
				<div class="stat">
					<div class="stat-label">手续费合计</div>
					<div class="stat-value tabular">¥{{ fmtMoney(stat.fee_sum) }}</div>
				</div>
			</div>

			<el-table
				v-loading="loading"
				:data="rows"
				border
				stripe
				show-summary
				:summary-method="summaryMethod"
				height="calc(100% - 265px)"
				empty-text="暂无刷卡记录"
			>
				<el-table-column prop="serial_no" label="流水号" width="150">
					<template #default="{ row }"><span class="tabular">{{ row.serial_no }}</span></template>
				</el-table-column>
				<el-table-column prop="txn_time" label="刷卡日期时间" width="160" align="center">
					<template #default="{ row }"><span class="tabular">{{ fmtDateTime(row.txn_time) }}</span></template>
				</el-table-column>
				<el-table-column label="客户" width="160" show-overflow-tooltip>
					<template #default="{ row }">{{ row.customer_name || '—' }}</template>
				</el-table-column>
				<el-table-column label="业务员" width="100" align="center">
					<template #default="{ row }">{{ row.salesman_name || '—' }}</template>
				</el-table-column>
				<el-table-column label="卡类型" width="90" align="center">
					<template #default="{ row }">{{ row.card_type_label || '—' }}</template>
				</el-table-column>
				<el-table-column label="卡号" width="140" align="center">
					<template #default="{ row }"><span class="tabular">{{ row.card_no_masked || '—' }}</span></template>
				</el-table-column>
				<el-table-column label="金额" width="130" align="right">
					<template #default="{ row }">
						<span class="tabular amt" :class="row.amount < 0 ? 'neg' : ''">{{ fmtMoney(row.amount) }}</span>
					</template>
				</el-table-column>
				<el-table-column label="手续费" width="90" align="right">
					<template #default="{ row }"><span class="tabular">{{ fmtMoney(row.fee) }}</span></template>
				</el-table-column>
				<el-table-column label="状态" width="90" align="center">
					<template #default="{ row }">
						<span class="st-tag" :class="'st-' + row.status">{{ row.status_label }}</span>
					</template>
				</el-table-column>
				<el-table-column label="操作" width="90" align="center" fixed="right">
					<template #default="{ row }">
						<el-tooltip :content="refundTip(row)" :disabled="row.refundable" placement="top">
							<span>
								<el-button
									link
									type="danger"
									size="small"
									:disabled="!row.refundable"
									@click="onRefund(row)"
								>退款</el-button>
							</span>
						</el-tooltip>
					</template>
				</el-table-column>
			</el-table>

			<div class="pager">
				<el-pagination
					v-model:current-page="page"
					v-model:page-size="pageSize"
					:page-sizes="[20, 50, 100]"
					:total="total"
					layout="total, sizes, prev, pager, next, jumper"
					@size-change="load"
					@current-change="load"
				/>
			</div>
		</el-card>
	</div>
</template>

<script setup>
import { reactive, ref, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Download } from '@element-plus/icons-vue'
import cashApi from '@/api/cash'
import { saveBlob } from '@/utils/fileDownload'
import { fmtMoney, fmtCount, fmtDateTime } from '@/views/cash/shared'

const customers = ref([])
const salesmen = ref([])
const filters = reactive({ customer_id: '', salesman_id: '', card_last4: '', status: '', start_date: '', end_date: '' })
const dateRange = ref([])
const rows = ref([])
const total = ref(0)
const stat = ref({ count: 0, success_amount: 0, fail_count: 0, fee_sum: 0 })
const page = ref(1)
const pageSize = ref(20)
const loading = ref(false)

const onDateChange = (val) => {
	filters.start_date = val?.[0] || ''
	filters.end_date = val?.[1] || ''
}

// 合计行只算当前页（金额含退款负数），全量口径看上方统计条
const summaryMethod = ({ columns, data }) =>
	columns.map((col, i) => {
		if (i === 0) return `本页合计（${data.length} 笔）`
		if (col.label === '金额') return '¥' + fmtMoney(data.reduce((s, r) => s + Number(r.amount || 0), 0))
		if (col.label === '手续费') return '¥' + fmtMoney(data.reduce((s, r) => s + Number(r.fee || 0), 0))
		return ''
	})

const refundParams = () => ({
	start_date: filters.start_date,
	end_date: filters.end_date,
	customer_id: filters.customer_id,
	salesman_id: filters.salesman_id,
	card_last4: filters.card_last4.trim(),
	status: filters.status,
})

async function load() {
	loading.value = true
	try {
		const res = await cashApi.card.list.get({ ...refundParams(), page: page.value, page_size: pageSize.value })
		const data = res.data || {}
		rows.value = data.list || []
		total.value = data.total || 0
		stat.value = data.stat || { count: 0, success_amount: 0, fail_count: 0, fee_sum: 0 }
	} catch (e) {
		ElMessage.error(e.message || '查询失败')
	} finally {
		loading.value = false
	}
}

const onSearch = () => {
	page.value = 1
	load()
}

const onReset = () => {
	Object.assign(filters, { customer_id: '', salesman_id: '', card_last4: '', status: '', start_date: '', end_date: '' })
	dateRange.value = []
	page.value = 1
	load()
}

/* 退款闸门在后端（成功且当日），前端只解释置灰原因 */
const refundTip = (row) => {
	if (row.refundable) return ''
	if (row.status === 'success') return '仅当日成功流水可退款'
	if (row.status === 'refund') return '该流水已退款'
	return '仅成功流水可退款'
}

const onRefund = (row) => {
	ElMessageBox.confirm(
		`确定对流水 ${row.serial_no} 执行退款？退款金额 ¥${fmtMoney(-Number(row.amount))}，退款后状态变更为「已退款」。`,
		'刷卡退款',
		{ type: 'warning', confirmButtonText: '确认退款', cancelButtonText: '取消' }
	).then(async () => {
		try {
			await cashApi.card.refund.post(row.id)
			ElMessage.success(`流水 ${row.serial_no} 已退款`)
			load()
		} catch (e) {
			ElMessage.error(e.message || '退款失败')
		}
	}).catch(() => {})
}

const onExport = async () => {
	if (!total.value) return ElMessage.warning('当前无数据可导出')
	try {
		const blob = await cashApi.card.export(refundParams())
		saveBlob(blob, `刷卡收款记录_${Date.now()}.csv`)
		ElMessage.success('导出成功')
	} catch (e) {
		ElMessage.error(e.message || '导出失败')
	}
}

onMounted(async () => {
	load()
	try {
		const res = await cashApi.card.options.get()
		customers.value = res.data?.customers || []
		salesmen.value = res.data?.salesmen || []
	} catch (e) {
		// 下拉选项失败不阻塞列表
	}
})
</script>

<style scoped>
.page { background: var(--bg-body); min-height: 100vh; display: flex; flex-direction: column; }
.page-header { height: 50px; background: var(--bg-card); border-bottom: 1px solid var(--border-lighter); padding: 0 var(--space-xl); display: flex; align-items: center; justify-content: space-between; }
.title { font-size: var(--font-h1); font-weight: 600; color: var(--text-primary); }
.card { flex: 1; margin: var(--space-lg) var(--space-xl); display: flex; flex-direction: column; }

.filter { display: flex; gap: var(--space-sm); flex-wrap: wrap; padding-bottom: var(--space-md); }
.pager { display: flex; justify-content: flex-end; padding-top: var(--space-md); }

.stat-strip { display: flex; gap: var(--space-lg); margin-bottom: var(--space-md); }
.stat { flex: 1; padding: var(--space-sm) var(--space-md); background: var(--bg-hover); border-radius: var(--radius-md); }
.stat-label { font-size: var(--font-aux); color: var(--text-secondary); line-height: 18px; }
.stat-value { font-size: var(--font-stat); font-weight: 600; color: var(--text-primary); line-height: 32px; }
.stat-value.warn { color: var(--color-warning); }
.stat-value.ok { color: var(--color-success); }

.amt { color: var(--text-primary); font-weight: 500; }
.amt.neg { color: var(--color-danger); }
/* 连凯对标：状态 Tag 成功=绿 #67C23A / 已退款=橙 #E6A23C / 失败=红 #F56C6C，自绘以锁精确色值 */
.st-tag { display: inline-block; padding: 0 8px; height: 22px; line-height: 22px; font-size: 12px; border-radius: 3px; color: #fff; }
.st-success { background: #67C23A; }
.st-refund { background: #E6A23C; }
.st-fail { background: #F56C6C; }
</style>
