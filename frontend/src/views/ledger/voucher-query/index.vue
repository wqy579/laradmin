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
				<template #attachment="{ row }">
					<div v-if="photoUrls(row).length" class="vq-photos">
						<el-image
							v-for="(u, i) in photoUrls(row).slice(0, 3)"
							:key="i"
							class="vq-photo"
							fit="cover"
							:src="u"
							:preview-src-list="photoUrls(row)"
							:initial-index="i"
							preview-teleported
							hide-on-click-modal
						/>
						<a v-if="row.attachments_count > 3" class="link vq-photo-more" @click="openDetail(row)">+{{ row.attachments_count - 3 }}</a>
					</div>
					<a v-else-if="row.attachments_count > 0" class="link" @click="openDetail(row)">📎 {{ row.attachments_count }} 张</a>
					<span v-else>—</span>
				</template>
				<template #red_ops="{ row }">
					<el-button v-if="canRed(row)" link type="danger" size="small" @click.stop="redCancel(row)">红冲撤单</el-button>
					<el-button v-if="canRed(row)" link type="warning" size="small" @click.stop="redAmend(row)">红冲改单</el-button>
					<el-button link type="info" size="small" @click.stop="openDetail(row)">打印/预览</el-button>
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
	{ field: 'attachments_count', title: '单据照片', width: 100, align: 'center', slots: { default: 'attachment' } },
	{ field: 'red_ops', title: '红冲操作', width: 170, align: 'center', slots: { default: 'red_ops' } },
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
// 红冲前提与后端一致：仅已审核且未被红冲的凭证
const canRed = (row) => row.status === 'audited' && !row.is_red
// 单据照片列只展示图片附件；pdf 等走「📎 N 张」入口在详情里查看
const photoUrls = (row) => (row.attachments || []).filter((a) => /\.(jpe?g|png|gif|webp|bmp)$/i.test(a.url || '')).map((a) => a.url)
// 红冲撤单：生成红冲单 + 原单整单作废（mode=cancel）
const redCancel = async (row) => {
	try {
		await ElMessageBox.confirm(`确认对凭证 ${row.voucher_no} 红冲撤单？将生成红冲单，且原凭证整单作废。`, '提示', { type: 'warning' })
		await ledgerApi.voucher.redReverse.post(row.id, { mode: 'cancel' })
		ElMessage.success('红冲撤单成功'); load()
	} catch (e) { if (e !== 'cancel') ElMessage.error(e?.message || '红冲撤单失败') }
}
// 红冲改单：生成红冲单 + 更正草稿，草稿到记账凭证页修改后重新提交（mode=modify）
const redAmend = async (row) => {
	try {
		await ElMessageBox.confirm(`确认对凭证 ${row.voucher_no} 红冲改单？将生成红冲单和一张更正草稿，请在「记账凭证」中修改草稿后重新提交。`, '提示', { type: 'warning' })
		const res = await ledgerApi.voucher.redReverse.post(row.id, { mode: 'modify' })
		ElMessage.success(`红冲改单成功，已生成更正草稿 ${res.data?.correction?.voucher_no || ''}`); load()
	} catch (e) { if (e !== 'cancel') ElMessage.error(e?.message || '红冲改单失败') }
}
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
.title { font-size: 16px; font-weight: 700; color: #303133; }
.card { margin: 16px 20px; }
.filter { display: flex; gap: 12px; flex-wrap: wrap; padding-bottom: 12px; }
.link { color: #409eff; cursor: pointer; }
.vq-photos { display: flex; align-items: center; gap: 4px; justify-content: center; }
.vq-photo { width: 26px; height: 26px; border-radius: 3px; border: 1px solid var(--border-lighter, #e4e7ed); cursor: zoom-in; }
.vq-photo-more { font-size: 12px; }
.amt { color: #303133; font-weight: 500; text-align: right; display: inline-block; width: 100%; font-variant-numeric: tabular-nums; font-family: var(--font-numeric); }
/* DS：表头 44px 13px/600、明细行 41px、分页 52px（vxe-grid / sTable 页面级覆盖） */
:deep(.vxe-header--row) { height: 44px !important; }
:deep(.vxe-header--column .vxe-cell) { font-size: var(--font-base); font-weight: 600; }
:deep(.vxe-body--row) { height: 41px !important; }
:deep(.vxe-body--column .vxe-cell) { font-size: var(--font-base); }
:deep(.sTable-page) { height: 52px !important; }
</style>
