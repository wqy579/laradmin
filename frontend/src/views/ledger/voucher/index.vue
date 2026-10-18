<template>
	<div class="page">
		<div class="page-header">
			<span class="title">记账凭证</span>
		</div>
		<el-card shadow="never" class="card">
			<div class="filter">
				<el-input v-model="filters.voucher_no" placeholder="凭证字号" style="width:170px" clearable @keyup.enter="load" />
				<el-select v-model="filters.status" placeholder="状态" style="width:120px" clearable>
					<el-option label="草稿" value="draft" />
					<el-option label="待审核" value="submitted" />
					<el-option label="已审核" value="audited" />
					<el-option label="已作废" value="reversed" />
				</el-select>
				<el-date-picker v-model="dateRange" type="daterange" value-format="YYYY-MM-DD" range-separator="至" start-placeholder="开始" end-placeholder="结束" style="width:240px" @change="onDateChange" />
				<el-button type="primary" @click="load">查询</el-button>
				<el-button @click="reset">重置</el-button>
				<el-checkbox v-model="filters.include_red">含红冲</el-checkbox>
			</div>
			<div class="btn-bar">
				<el-button type="primary" style="width:110px" @click="openForm()">新增凭证</el-button>
				<el-button type="success" style="width:100px" @click="batchAudit">批量审核</el-button>
				<el-button type="danger" plain style="width:100px" @click="batchReverse">批量作废</el-button>
				<el-button style="width:90px" @click="handleExport">导出</el-button>
			</div>
			<sTable ref="tableRef" :columns="columns" :data="list" :loading="loading" :pagination="{ total, page, pageSize: filters.page_size }" @page-change="onPageChange" @selection-change="onSelectionChange" height="100%" stripe>
				<template #voucher_no="{ row }">
					<a class="link" @click="openDetail(row)">{{ row.voucher_no }}</a>
				</template>
				<template #total_debit="{ row }"><span class="amt" :class="{ 'amt-red': !!row.red_of_id }">{{ fmt(row.total_debit) }}</span></template>
				<template #total_credit="{ row }"><span class="amt" :class="{ 'amt-red': !!row.red_of_id }">{{ fmt(row.total_credit) }}</span></template>
				<template #status="{ row }">
					<el-tag :type="statusType(row.status)" size="small">{{ statusLabel(row.status) }}</el-tag>
					<el-tag v-if="row.is_red" type="danger" effect="light" size="small">已红冲</el-tag>
				</template>
				<template #action="{ row }">
					<el-button v-if="row.status === 'draft' || row.status === 'submitted'" link type="primary" @click="openForm(row)">编辑</el-button>
					<el-button v-if="row.status === 'submitted'" link type="success" @click="audit(row)">审核</el-button>
					<el-button v-if="row.status === 'audited' && !row.is_red" link type="danger" @click="redReverse(row)"><el-icon><RefreshLeft /></el-icon>红冲</el-button>
					<el-button link type="info" @click="openDetail(row)">查看</el-button>
					<el-button v-if="row.status !== 'reversed'" link type="danger" @click="reverse(row)">作废</el-button>
				</template>
			</sTable>
		</el-card>
		<VoucherForm ref="formRef" @saved="load" />
		<VoucherDetail ref="detailRef" />
	</div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { RefreshLeft } from '@element-plus/icons-vue'
import ledgerApi from '@/api/ledger'
import sTable from '@/components/sTable/index.vue'
import VoucherForm from '../components/VoucherForm.vue'
import VoucherDetail from '../components/VoucherDetail.vue'

const list = ref([])
const total = ref(0)
const loading = ref(false)
const dateRange = ref([])
const tableRef = ref(null)
const formRef = ref(null)
const detailRef = ref(null)
const selected = ref([])
const filters = reactive({ voucher_no: '', status: '', start_date: '', end_date: '', page: 1, page_size: 20, include_red: false })

const columns = [
	{ type: 'checkbox', width: 45, align: 'center' },
	{ field: 'voucher_no', title: '凭证字号', width: 170, slots: { default: 'voucher_no' } },
	{ field: 'voucher_date', title: '凭证日期', width: 130, align: 'center' },
	{ field: 'attachments_count', title: '附单据数', width: 80, align: 'center' },
	{ field: 'summary', title: '摘要', minWidth: 280 },
	{ field: 'total_debit', title: '借方合计', width: 130, align: 'right', slots: { default: 'total_debit' } },
	{ field: 'total_credit', title: '贷方合计', width: 130, align: 'right', slots: { default: 'total_credit' } },
	{ field: 'maker_name', title: '制单人', width: 100 },
	{ field: 'auditor_name', title: '审核人', width: 100 },
	{ field: 'status', title: '状态', width: 110, align: 'center', slots: { default: 'status' } },
	{ title: '操作', width: 260, align: 'center', fixed: 'right', slots: { default: 'action' } },
]

const fmt = (v) => Number(v || 0).toFixed(2)
const statusLabel = (s) => ({ draft: '草稿', submitted: '待审核', audited: '已审核', reversed: '已作废' }[s] || s)
const statusType = (s) => ({ draft: 'info', submitted: 'warning', audited: 'success', reversed: 'danger' }[s] || 'info')

const onDateChange = (val) => { filters.start_date = val?.[0] || ''; filters.end_date = val?.[1] || '' }
const onSelectionChange = (rows) => { selected.value = rows }
const onPageChange = (p) => { filters.page = p; load() }

const load = async () => {
	loading.value = true
	try {
		const res = await ledgerApi.voucher.list.get(filters)
		list.value = res.data?.list || []
		total.value = res.data?.total || 0
	} catch { ElMessage.error('加载失败') }
	finally { loading.value = false }
}
const reset = () => { Object.assign(filters, { voucher_no: '', status: '', start_date: '', end_date: '', include_red: false, page: 1 }); dateRange.value = []; load() }
const openForm = (row) => formRef.value?.open(row)
const openDetail = (row) => detailRef.value?.open(row.id)
const audit = (row) => {
	ElMessageBox.confirm('确认审核该凭证？审核后凭证据此记账。', '操作确认', { type: 'warning' })
		.then(async () => { await ledgerApi.voucher.audit.post(row.id); ElMessage.success('审核成功'); load() })
		.catch(() => {})
}
const reverse = (row) => {
	ElMessageBox.confirm('确认作废该凭证？作废后保留编号不回收，显示作废红章。', '操作确认', { type: 'warning' })
		.then(async () => { await ledgerApi.voucher.reverse.post(row.id); ElMessage.success('作废成功'); load() })
		.catch(() => {})
}
const redReverse = (row) => {
	ElMessageBox.confirm(`确认红冲凭证 ${row.voucher_no}？红冲后不可撤销`, '操作确认', { type: 'warning' })
		.then(async () => { await ledgerApi.voucher.redReverse.post(row.id); ElMessage.success('红冲成功'); load() })
		.catch(() => {})
}
const batchAudit = async () => {
	if (!selected.value.length) return ElMessage.warning('请先选择凭证')
	try {
		await ledgerApi.voucher.batchAudit.post({ ids: selected.value.map(r => r.id) })
		ElMessage.success('批量审核成功'); load()
	} catch (e) { ElMessage.error(e?.message || '审核失败') }
}
const batchReverse = async () => {
	if (!selected.value.length) return ElMessage.warning('请先选择凭证')
	ElMessageBox.confirm(`确认作废选中的 ${selected.value.length} 张凭证？`, '操作确认', { type: 'warning' })
		.then(async () => { await ledgerApi.voucher.batchReverse.post({ ids: selected.value.map(r => r.id) }); ElMessage.success('批量作废成功'); load() })
		.catch(() => {})
}
const handleExport = async () => {
	try {
		const blob = await ledgerApi.voucher.export(filters)
		const url = URL.createObjectURL(blob); const a = document.createElement('a'); a.href = url; a.download = 'ledger_vouchers.csv'; a.click(); setTimeout(() => URL.revokeObjectURL(url), 1000)
	} catch { ElMessage.error('导出失败') }
}
onMounted(() => load())
</script>

<style scoped>
.page { background: var(--bg-body); min-height: 100vh; }
.page-header { height: 50px; background: var(--bg-card); border-bottom: 1px solid var(--border-light); padding: 0 20px; display: flex; align-items: center; }
.title { font-size: 18px; font-weight: 700; color: var(--text-primary); }
.card { margin: 16px 20px; }
.filter { display: flex; gap: 12px; flex-wrap: wrap; padding-bottom: 12px; }
.btn-bar { height: 50px; display: flex; gap: 8px; align-items: center; border-top: 1px solid var(--border-lighter); padding-top: 12px; margin-bottom: 12px; }
.link { color: var(--color-primary); cursor: pointer; }
.amt { color: var(--text-primary); font-weight: 500; text-align: right; display: inline-block; width: 100%; }
.amt-red { color: var(--color-danger); }
</style>
