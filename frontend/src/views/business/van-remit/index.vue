<template>
	<div class="page">
		<div class="header"><span class="title">车销上交货款</span></div>
		<el-card shadow="never" class="card">
			<div class="filter">
				<el-input v-model="query.remit_no" placeholder="上交单号" style="width:180px" clearable @keyup.enter="load" />
				<el-select v-model="query.status" placeholder="状态" clearable style="width:120px" @change="load">
					<el-option label="待确认" value="pending" />
					<el-option label="已确认" value="confirmed" />
					<el-option label="已驳回" value="rejected" />
				</el-select>
				<el-button type="primary" @click="load">查询</el-button>
				<el-button @click="exportCsv">导出</el-button>
			</div>
			<el-table :data="list" border stripe v-loading="loading">
				<el-table-column prop="remit_no" label="上交单号" width="180" />
				<el-table-column prop="salesman_name" label="业务员" width="100" />
				<el-table-column prop="remit_date" label="上交日期" width="110" />
				<el-table-column label="现金" width="90" align="right"><template #default="{ row }">{{ formatMoney(row.cash_amount) }}</template></el-table-column>
				<el-table-column label="微信" width="90" align="right"><template #default="{ row }">{{ formatMoney(row.wechat_amount) }}</template></el-table-column>
				<el-table-column label="支付宝" width="90" align="right"><template #default="{ row }">{{ formatMoney(row.alipay_amount) }}</template></el-table-column>
				<el-table-column label="银行卡" width="90" align="right"><template #default="{ row }">{{ formatMoney(row.bank_amount) }}</template></el-table-column>
				<el-table-column label="合计" width="100" align="right"><template #default="{ row }">{{ formatMoney(row.total_amount) }}</template></el-table-column>
				<el-table-column label="状态" width="90" align="center"><template #default="{ row }">{{ statusLabel(row.status) }}</template></el-table-column>
			</el-table>
			<el-pagination
				background layout="total, prev, pager, next" :total="total" :page-size="query.page_size"
				:current-page="query.page" @current-change="onPage" style="margin-top:12px;justify-content:flex-end"
			/>
		</el-card>
	</div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import request from '@/utils/request'

const list = ref([])
const total = ref(0)
const loading = ref(false)
const query = reactive({ remit_no: '', status: '', page: 1, page_size: 20 })

const load = async () => {
	loading.value = true
	try {
		const res = await request.get('business/van-remit', { params: query })
		list.value = res.data?.data?.data ?? []
		total.value = res.data?.data?.total ?? 0
	} finally {
		loading.value = false
	}
}
const onPage = (p) => { query.page = p; load() }
const exportCsv = () => { window.open(request.defaults.baseURL + 'business/van-remit/export?' + new URLSearchParams(query).toString()) }
const formatMoney = (v) => Number(v || 0).toFixed(2)
const statusLabel = (s) => ({ pending: '待确认', confirmed: '已确认', rejected: '已驳回' }[s] ?? s)

onMounted(load)
</script>

<style scoped>
.page { padding: 12px; }
.header { margin-bottom: 8px; }
.title { font-size: 16px; font-weight: 600; }
.card .filter { display: flex; gap: 8px; margin-bottom: 12px; flex-wrap: wrap; }
</style>
