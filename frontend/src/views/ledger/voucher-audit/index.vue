<template>
	<div class="page">
		<div class="page-header"><span class="title">凭证审核</span></div>
		<el-card shadow="never" class="card">
			<div class="filter">
				<el-input v-model="filters.voucher_no" placeholder="凭证字号" style="width:170px" clearable @keyup.enter="load" />
				<el-date-picker v-model="dateRange" type="daterange" value-format="YYYY-MM-DD" range-separator="至" start-placeholder="开始" end-placeholder="结束" style="width:240px" @change="onDateChange" />
				<el-radio-group v-model="filters.status" @change="load">
					<el-radio-button value="submitted">待审核</el-radio-button>
					<el-radio-button value="audited">已审核</el-radio-button>
				</el-radio-group>
				<el-button type="primary" @click="load">查询</el-button>
				<el-button @click="reset">重置</el-button>
			</div>
			<div class="btn-bar" v-if="filters.status === 'submitted'">
				<el-button type="success" style="width:100px" @click="batchAudit">批量审核</el-button>
			</div>
			<sTable :columns="columns" :data="list" :loading="loading" :pagination="{ total, page, pageSize: filters.page_size }" @page-change="onPageChange" @selection-change="onSelectionChange" height="100%" stripe>
				<template #voucher_no="{ row }"><a class="link" @click="openDetail(row)">{{ row.voucher_no }}</a></template>
				<template #status="{ row }"><el-tag :type="statusType(row.status)" size="small">{{ statusLabel(row.status) }}</el-tag></template>
				<template #total_debit="{ row }"><span class="amt">{{ fmt(row.total_debit) }}</span></template>
				<template #total_credit="{ row }"><span class="amt">{{ fmt(row.total_credit) }}</span></template>
				<template #action="{ row }">
					<el-button v-if="row.status === 'submitted'" link type="success" @click="audit(row)">审核</el-button>
					<el-button v-if="row.status === 'audited'" link type="warning" @click="reverseAudit(row)">反审核</el-button>
					<el-button link type="info" @click="openDetail(row)">查看</el-button>
				</template>
			</sTable>
		</el-card>
		<VoucherDetail ref="detailRef" />
	</div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import ledgerApi from '@/api/ledger'
import sTable from '@/components/sTable/index.vue'
import VoucherDetail from '../components/VoucherDetail.vue'

const list = ref([])
const total = ref(0)
const loading = ref(false)
const dateRange = ref([])
const detailRef = ref(null)
const selected = ref([])
const filters = reactive({ voucher_no: '', status: 'submitted', start_date: '', end_date: '', page: 1, page_size: 20 })

const columns = [
	{ type: 'checkbox', width: 45, align: 'center' },
	{ field: 'voucher_no', title: '凭证字号', width: 170, slots: { default: 'voucher_no' } },
	{ field: 'voucher_date', title: '凭证日期', width: 130, align: 'center' },
	{ field: 'summary', title: '摘要', minWidth: 280 },
	{ field: 'total_debit', title: '借方合计', width: 130, align: 'right', slots: { default: 'total_debit' } },
	{ field: 'total_credit', title: '贷方合计', width: 130, align: 'right', slots: { default: 'total_credit' } },
	{ field: 'maker_name', title: '制单人', width: 100 },
	{ field: 'auditor_name', title: '审核人', width: 100 },
	{ field: 'status', title: '状态', width: 100, align: 'center', slots: { default: 'status' } },
	{ title: '操作', width: 180, align: 'center', fixed: 'right', slots: { default: 'action' } },
]
const fmt = (v) => Number(v || 0).toFixed(2)
const statusLabel = (s) => ({ draft: '草稿', submitted: '待审核', audited: '已审核', reversed: '已作废' }[s] || s)
const statusType = (s) => ({ draft: 'info', submitted: 'warning', audited: 'success', reversed: 'danger' }[s] || 'info')
const onDateChange = (val) => { filters.start_date = val?.[0] || ''; filters.end_date = val?.[1] || '' }
const onPageChange = (p) => { filters.page = p; load() }
const onSelectionChange = (rows) => { selected.value = rows }
const openDetail = (row) => detailRef.value?.open(row.id)

const load = async () => {
	loading.value = true
	try { const res = await ledgerApi.voucher.list.get(filters); list.value = res.data?.list || []; total.value = res.data?.total || 0 }
	catch { ElMessage.error('加载失败') } finally { loading.value = false }
}
const reset = () => { Object.assign(filters, { voucher_no: '', start_date: '', end_date: '', page: 1 }); dateRange.value = []; load() }
const audit = (row) => {
	ElMessageBox.confirm('确认审核该凭证？审核后凭证据此记账。', '操作确认', { type: 'warning' })
		.then(async () => { await ledgerApi.voucher.audit.post(row.id); ElMessage.success('审核成功'); load() }).catch(() => {})
}
const batchAudit = async () => {
	if (!selected.value.length) return ElMessage.warning('请先选择凭证')
	try { await ledgerApi.voucher.batchAudit.post({ ids: selected.value.map(r => r.id) }); ElMessage.success('批量审核成功'); load() }
	catch (e) { ElMessage.error(e?.message || '审核失败') }
}
const reverseAudit = (row) => {
	ElMessageBox.confirm('确认反审核该凭证？反审核后回到待审核状态。', '操作确认', { type: 'warning' })
		.then(async () => { await ledgerApi.voucher.reverseAudit.post(row.id); ElMessage.success('反审核成功'); load() }).catch(() => {})
}
onMounted(() => load())
</script>

<style scoped>
.page { background: #f0f2f5; min-height: 100vh; }
.page-header { height: 50px; background: #fff; border-bottom: 1px solid #e8e8e8; padding: 0 20px; display: flex; align-items: center; }
.title { font-size: 18px; font-weight: 700; color: #303133; }
.card { margin: 16px 20px; }
.filter { display: flex; gap: 12px; flex-wrap: wrap; align-items: center; padding-bottom: 12px; }
.btn-bar { height: 40px; display: flex; gap: 8px; align-items: center; border-top: 1px solid #ebeef5; padding-top: 8px; margin-bottom: 8px; }
.link { color: #409eff; cursor: pointer; }
.amt { color: #303133; font-weight: 500; text-align: right; display: inline-block; width: 100%; }
</style>
