<template>
	<el-dialog v-model="visible" title="记账凭证" width="1100px" top="3vh" destroy-on-close>
		<div class="voucher-print" id="voucherPrintArea">
			<div class="vp-title">记账凭证</div>
			<div class="vp-head">
				<span class="vp-no">凭证字号：{{ voucher.voucher_no }}</span>
				<span class="vp-unit">{{ companyName }}</span>
				<span class="vp-date">日期：{{ voucher.voucher_date }}</span>
				<span class="vp-att">附单据 {{ voucher.attachments_count }} 张</span>
			</div>
			<table class="vp-table">
				<thead>
					<tr>
						<th style="width:280px">摘要</th>
						<th style="width:260px">会计科目</th>
						<th style="width:180px">辅助核算</th>
						<th style="width:150px">借方金额</th>
						<th style="width:150px">贷方金额</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="e in voucher.entries" :key="e.id">
						<td>{{ e.summary }}</td>
						<td>{{ e.subject_code }} {{ e.subject_name }}</td>
						<td>{{ e.aux_name || '—' }}</td>
						<td class="amt">{{ fmt(e.debit_amount) }}</td>
						<td class="amt">{{ fmt(e.credit_amount) }}</td>
					</tr>
				</tbody>
				<tfoot>
					<tr>
						<td colspan="3" class="vp-chinese">合计：人民币（大写）{{ voucher.amount_chinese }}</td>
						<td class="amt"><b>借方合计：{{ fmt(voucher.total_debit) }}</b></td>
						<td class="amt"><b>贷方合计：{{ fmt(voucher.total_credit) }}</b></td>
					</tr>
				</tfoot>
			</table>
			<div class="vp-signs">
				<div class="vp-sign"><span class="lbl">制单</span><span class="name">{{ voucher.maker_name }}</span></div>
				<div class="vp-sign"><span class="lbl">审核</span><span class="name">{{ voucher.auditor_name }}</span></div>
				<div class="vp-sign"><span class="lbl">记账</span><span class="name"></span></div>
				<div class="vp-sign"><span class="lbl">出纳</span><span class="name"></span></div>
			</div>
			<div v-if="voucher.status === 'audited'" class="vp-stamp audit-stamp">审核</div>
			<div v-if="voucher.status === 'reversed'" class="vp-stamp void-stamp">作废</div>
		</div>
		<template #footer>
			<el-button @click="visible = false">返回</el-button>
			<el-button type="primary" @click="print">打印</el-button>
		</template>
	</el-dialog>
</template>

<script setup>
import { ref } from 'vue'
import { ElMessage } from 'element-plus'
import ledgerApi from '@/api/ledger'

const visible = ref(false)
const voucher = ref({})
const companyName = ref('公司名称')

const open = async (id) => {
	try {
		const res = await ledgerApi.voucher.detail.get(id)
		voucher.value = res.data?.voucher || {}
		visible.value = true
	} catch { ElMessage.error('加载失败') }
}
defineExpose({ open })
const fmt = (v) => Number(v || 0).toFixed(2)
const print = () => {
	const html = document.getElementById('voucherPrintArea')?.innerHTML
	const w = window.open('', '_blank', 'width=900,height=700')
	w.document.write(`<html><head><title>记账凭证</title><style>
		.vp-title { text-align:center; font-size:20px; font-weight:700; margin:16px 0; }
		.vp-head { display:flex; justify-content:space-between; font-size:14px; margin-bottom:12px; }
		.vp-table { width:100%; border-collapse:collapse; }
		.vp-table th, .vp-table td { border:1px solid #303133; padding:6px 8px; font-size:14px; text-align:center; }
		.amt { text-align:right; }
		.vp-chinese { text-align:left; font-weight:700; }
		.vp-signs { display:flex; justify-content:space-around; margin-top:40px; }
		.vp-sign { text-align:center; }
		.vp-sign .lbl { font-size:13px; color:#606266; display:block; }
		.vp-sign .name { display:inline-block; min-width:80px; border-bottom:1px solid #606266; margin-top:8px; }
	</style></head><body style="padding:40px;">${html}</body></html>`)
	w.document.close()
	w.focus()
	w.print()
}
</script>

<style scoped>
.voucher-print { padding: 20px; }
.vp-title { text-align:center; font-size:20px; font-weight:700; color:#303133; margin-bottom:16px; }
.vp-head { display:flex; justify-content:space-between; font-size:14px; color:#606266; margin-bottom:12px; }
.vp-table { width:100%; border-collapse:collapse; }
.vp-table th { background:#f5f7fa; border:1px solid #303133; padding:8px; font-size:14px; font-weight:500; color:#606266; }
.vp-table td { border:1px solid #303133; padding:8px; font-size:14px; }
.amt { text-align:right; }
.vp-chinese { text-align:left; font-weight:700; }
.vp-signs { display:flex; justify-content:space-around; margin-top:40px; }
.vp-sign { text-align:center; }
.vp-sign .lbl { font-size:13px; color:#606266; display:block; }
.vp-sign .name { display:inline-block; min-width:80px; border-bottom:1px solid #606266; margin-top:8px; }
.vp-stamp { position:absolute; top:40%; left:40%; font-size:40px; font-weight:700; border:3px solid; padding:8px 16px; opacity:0.8; transform:rotate(-15deg); pointer-events:none; }
.audit-stamp { color:#f56c6c; border-color:#f56c6c; border-radius:50%; }
.void-stamp { color:#f56c6c; border-color:#f56c6c; }
</style>
