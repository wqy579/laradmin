<template>
	<div class="page">
		<div class="page-header"><span class="title">凭证查询</span></div>
		<el-card shadow="never" class="card">
			<div class="filter">
				<el-input v-model="filters.voucher_no" placeholder="凭证字号" style="width:170px" clearable @keyup.enter="load" />
				<el-date-picker v-model="dateRange" type="daterange" value-format="YYYY-MM-DD" range-separator="至" start-placeholder="开始" end-placeholder="结束" style="width:240px" @change="onDateChange" />
				<el-select v-model="filters.subject_id" placeholder="会计科目" filterable clearable style="width:200px">
					<el-option v-for="s in subjectOptions" :key="s.id" :label="`${s.code} ${s.name}`" :value="s.id" />
				</el-select>
				<el-select v-model="filters.status" placeholder="状态" style="width:120px" clearable>
					<el-option label="草稿" value="draft" />
					<el-option label="待审核" value="submitted" />
					<el-option label="已审核" value="audited" />
					<el-option label="已作废" value="reversed" />
				</el-select>
				<el-input v-model="filters.maker_name" placeholder="制单人" style="width:120px" clearable />
				<el-input v-model="filters.auditor_name" placeholder="审核人" style="width:120px" clearable />
				<el-input v-model="filters.min_amount" placeholder="最小金额" style="width:100px" clearable />
				<el-input v-model="filters.max_amount" placeholder="最大金额" style="width:100px" clearable />
				<el-button type="primary" @click="load">查询</el-button>
				<el-button @click="reset">重置</el-button>
				<el-button @click="handleExport">导出</el-button>
			</div>
			<sTable :columns="columns" :data="list" :loading="loading" :pagination="{ total, page, pageSize: filters.page_size }" @page-change="onPageChange" height="100%" stripe>
				<template #voucher_no="{ row }"><a class="link" @click="openDetail(row)">{{ row.voucher_no }}</a></template>
				<template #status="{ row }"><el-tag :type="statusType(row.status)" size="small">{{ statusLabel(row.status) }}</el-tag></template>
				<template #total_debit="{ row }"><span class="amt">{{ fmt(row.total_debit) }}</span></template>
				<template #total_credit="{ row }"><span class="amt">{{ fmt(row.total_credit) }}</span></template>
			</sTable>
		</el-card>
		<VoucherDetail ref="detailRef" />
	</div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import ledgerApi from '@/api/ledger'
import sTable from '@/components/sTable/index.vue'
import VoucherDetail from '../components/VoucherDetail.vue'

const list = ref([])
const total = ref(0)
const loading = ref(false)
const dateRange = ref([])
const detailRef = ref(null)
const subjectOptions = ref([])
const filters = reactive({ voucher_no: '', status: '', start_date: '', end_date: '', subject_id: '', maker_name: '', auditor_name: '', min_amount: '', max_amount: '', page: 1, page_size: 20 })

const columns = [
	{ field: 'voucher_no', title: '凭证字号', width: 170, slots: { default: 'voucher_no' } },
	{ field: 'voucher_date', title: '凭证日期', width: 130, align: 'center' },
	{ field: 'period', title: '期间', width: 90, align: 'center' },
	{ field: 'summary', title: '摘要', minWidth: 280 },
	{ field: 'total_debit', title: '借方合计', width: 130, align: 'right', slots: { default: 'total_debit' } },
	{ field: 'total_credit', title: '贷方合计', width: 130, align: 'right', slots: { default: 'total_credit' } },
	{ field: 'maker_name', title: '制单人', width: 100 },
	{ field: 'auditor_name', title: '审核人', width: 100 },
	{ field: 'status', title: '状态', width: 100, align: 'center', slots: { default: 'status' } },
]
const fmt = (v) => Number(v || 0).toFixed(2)
const statusLabel = (s) => ({ draft: '草稿', submitted: '待审核', audited: '已审核', reversed: '已作废' }[s] || s)
const statusType = (s) => ({ draft: 'info', submitted: 'warning', audited: 'success', reversed: 'danger' }[s] || 'info')
const onDateChange = (val) => { filters.start_date = val?.[0] || ''; filters.end_date = val?.[1] || '' }
const onPageChange = (p) => { filters.page = p; load() }
const openDetail = (row) => detailRef.value?.open(row.id)

const load = async () => {
	loading.value = true
	try { const res = await ledgerApi.voucher.list.get(filters); list.value = res.data?.list || []; total.value = res.data?.total || 0 }
	catch { ElMessage.error('加载失败') } finally { loading.value = false }
}
const reset = () => { Object.assign(filters, { voucher_no: '', status: '', start_date: '', end_date: '', subject_id: '', maker_name: '', auditor_name: '', min_amount: '', max_amount: '', page: 1 }); dateRange.value = []; load() }
const handleExport = async () => {
	try { const blob = await ledgerApi.voucher.export(filters); const url = URL.createObjectURL(blob); const a = document.createElement('a'); a.href = url; a.download = 'ledger_vouchers.csv'; a.click(); setTimeout(() => URL.revokeObjectURL(url), 1000) }
	catch { ElMessage.error('导出失败') }
}
const loadSubjects = async () => { const res = await ledgerApi.subject.options.get(); subjectOptions.value = res.data?.options || [] }
onMounted(() => { load(); loadSubjects() })
</script>

<style scoped>
.page { background: var(--bg-body); min-height: 100vh; }
.page-header { height: 50px; background: #fff; border-bottom: 1px solid var(--border-lighter); padding: 0 20px; display: flex; align-items: center; }
.title { font-size: 20px; font-weight: 700; color: #303133; }
.card { margin: 16px 20px; }
.filter { display: flex; gap: 12px; flex-wrap: wrap; padding-bottom: 12px; }
.link { color: #409eff; cursor: pointer; }
.amt { color: #303133; font-weight: 500; text-align: right; display: inline-block; width: 100%; }
</style>
