<template>
	<div class="biz-list">
		<div class="filter-bar">
			<el-select v-model="filters.category" placeholder="往来类别" clearable size="small" style="width:120px" @change="fetchData">
				<el-option label="全部" value="" />
				<el-option label="城区" value="城区" />
				<el-option label="乡镇" value="乡镇" />
				<el-option label="特通渠道" value="特通渠道" />
				<el-option label="业务员" value="业务员" />
			</el-select>
			<el-radio-group v-model="filters.customerType" size="small" @change="fetchData">
				<el-radio-button value="all">全部客户</el-radio-button>
				<el-radio-button value="debt">欠款客户</el-radio-button>
				<el-radio-button value="prepaid">预收款客户</el-radio-button>
			</el-radio-group>
			<el-date-picker v-model="dateRange" type="daterange" range-separator="至" start-placeholder="开始" end-placeholder="结束" size="small" style="width:240px" value-format="YYYY-MM-DD" @change="fetchData" />
			<el-checkbox v-model="filters.onlyNegative" @change="fetchData">只显示欠款客户</el-checkbox>
			<el-button size="small" type="primary" @click="fetchData">查询</el-button>
		</div>
		<div style="padding:8px 12px">
			<el-button size="small" type="primary" @click="loadBalances">余额查询</el-button>
		</div>
		<sTable ref="tableRef" tableName="customer_statement" :data="data" :columns="columns" :loading="loading" height="100%" stripe>
			<template #opening_balance="{ row }">
				<span :class="row.opening_balance > 0 ? 'text-red' : 'text-green'">¥{{ Number(row.opening_balance || 0).toFixed(2) }}</span>
			</template>
			<template #period_receivable="{ row }">
				<span class="text-red">¥{{ Number(row.period_receivable || 0).toFixed(2) }}</span>
			</template>
			<template #period_received="{ row }">
				<span class="text-green">¥{{ Number(row.period_received || 0).toFixed(2) }}</span>
			</template>
			<template #period_discount="{ row }">
				<span>¥{{ Number(row.period_discount || 0).toFixed(2) }}</span>
			</template>
			<template #closing_balance="{ row }">
				<b :class="row.closing_balance > 0 ? 'text-red' : 'text-green'">¥{{ Number(row.closing_balance || 0).toFixed(2) }}</b>
			</template>
			<template #action="{ row }">
				<el-button type="primary" link size="small" @click="viewDetail(row)">查看对账单</el-button>
			</template>
		</sTable>

		<!-- 对账单详情弹窗 -->
		<el-dialog v-model="detailVisible" title="客户对账单" width="900px" top="3vh" destroy-on-close>
			<div class="dialog-header">
				<span>客户：{{ currentCustomer?.customer_name }}</span>
				<span style="margin-left:24px;color:var(--el-text-color-secondary)">对账期间：{{ dateRange?.[0] || '-' }} 至 {{ dateRange?.[1] || '-' }}</span>
				<div style="margin-left:auto">
					<el-button size="small" type="primary" @click="printStatement">打印</el-button>
					<el-button size="small" @click="detailVisible = false">关闭</el-button>
				</div>
			</div>
			<div class="dialog-summary">
				<span>上期结余：¥{{ Number(currentCustomer?.opening_balance || 0).toFixed(2) }}</span>
				<span style="margin-left:24px">本期应收：<span class="text-red">¥{{ Number(currentCustomer?.period_receivable || 0).toFixed(2) }}</span></span>
				<span style="margin-left:24px">本期已收：<span class="text-green">¥{{ Number(currentCustomer?.period_received || 0).toFixed(2) }}</span></span>
				<span style="margin-left:24px">期末结余：<b :class="currentCustomer?.closing_balance > 0 ? 'text-red' : 'text-green'">¥{{ Number(currentCustomer?.closing_balance || 0).toFixed(2) }}</b></span>
			</div>
			<el-table :data="detailData" size="small" border>
				<el-table-column prop="date" label="日期" width="100" align="center" />
				<el-table-column prop="doc_no" label="单据编号" width="160" />
				<el-table-column prop="type" label="单据类型" width="100" align="center" />
				<el-table-column prop="summary" label="摘要" show-overflow-tooltip />
				<el-table-column label="应收" width="110" align="right">
					<template #default="{ row }"><span class="text-red">¥{{ Number(row.receivable || 0).toFixed(2) }}</span></template>
				</el-table-column>
				<el-table-column label="已收" width="110" align="right">
					<template #default="{ row }"><span class="text-green">¥{{ Number(row.received || 0).toFixed(2) }}</span></template>
				</el-table-column>
			</el-table>
		</el-dialog>
	</div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import businessApi from '@/api/business'
import sTable from '@/components/sTable/index.vue'

const data = ref([])
const loading = ref(false)
const dateRange = ref(null)
const detailVisible = ref(false)
const currentCustomer = ref(null)
const detailData = ref([])
const filters = reactive({ category: '', customerType: 'all', onlyNegative: false })

const columns = [
	{ prop: 'customer_name', title: '客户名称', width: 200 },
	{ prop: 'opening_balance', title: '上期结余', width: 120, align: 'right', slots: { default: 'opening_balance' } },
	{ prop: 'period_receivable', title: '本期应收', width: 120, align: 'right', slots: { default: 'period_receivable' } },
	{ prop: 'period_received', title: '本期已收', width: 120, align: 'right', slots: { default: 'period_received' } },
	{ prop: 'period_discount', title: '本期优惠', width: 120, align: 'right', slots: { default: 'period_discount' } },
	{ prop: 'closing_balance', title: '期末结余', width: 120, align: 'right', slots: { default: 'closing_balance' } },
	{ prop: 'action', title: '操作', width: 120, slots: { default: 'action' } },
]

const loadBalances = () => fetchData()

async function fetchData() {
	loading.value = true
	try {
		// 从应收账款接口取数据，构造余额表
		const res = await businessApi.receive.receivable.get({})
		if (res.code === 200) {
			let list = (res.data?.list || []).map(r => ({
				customer_name: r.customer_name,
				customer_id: r.customer_id,
				opening_balance: 0, // 简化：上期=0
				period_receivable: Number(r.order_total || 0),
				period_received: Number(r.received_total || 0),
				period_discount: 0,
				closing_balance: Number(r.receivable || 0),
			}))
			if (filters.onlyNegative) list = list.filter(r => r.closing_balance > 0)
			data.value = list
		}
	} catch { ElMessage.error('加载失败') }
	finally { loading.value = false }
}

const viewDetail = (row) => {
	currentCustomer.value = row
	detailData.value = [
		{ date: row.period_receivable > 0 ? '本期' : '-', doc_no: '-', type: '应收', summary: '本期应收款', receivable: row.period_receivable, received: 0 },
		{ date: row.period_received > 0 ? '本期' : '-', doc_no: '-', type: '收款', summary: '本期已收款', receivable: 0, received: row.period_received },
	]
	detailVisible.value = true
}

const printStatement = () => { window.print() }
onMounted(() => fetchData())
</script>

<style scoped>
.biz-list { height: 100%; display: flex; flex-direction: column; }
.filter-bar { display: flex; align-items: center; gap: 8px; padding: 8px; flex-wrap: wrap; }
.dialog-header { display: flex; align-items: center; padding: 12px 0; border-bottom: 1px solid var(--el-border-color); margin-bottom: 8px; font-size: 14px; }
.dialog-summary { display: flex; padding: 8px 0; border-bottom: 1px solid var(--el-border-color); margin-bottom: 12px; font-size: 14px; }
.text-red { color: #f56c6c; font-weight: 600; }
.text-green { color: #67c23a; font-weight: 600; }
</style>
