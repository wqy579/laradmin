<template>
	<el-dialog v-model="visible" :title="title" width="1100px" top="3vh" :close-on-click-modal="false" destroy-on-close class="voucher-dialog">
		<!-- 凭证头 -->
		<div class="voucher-head">
			<el-form :inline="true" label-width="90px">
				<el-form-item label="凭证字号">
					<el-input v-model="form.voucher_no" disabled style="width:170px" />
				</el-form-item>
				<el-form-item label="凭证日期">
					<el-date-picker v-model="form.voucher_date" type="date" value-format="YYYY-MM-DD" placeholder="选择日期" style="width:160px" />
				</el-form-item>
				<el-form-item label="附单据数">
					<el-input-number v-model="form.attachments_count" :min="0" controls-position="right" style="width:100px" />
				</el-form-item>
				<el-form-item label="录单时自动审核" label-width="110px">
					<el-switch v-model="form.auto_audit" />
				</el-form-item>
			</el-form>
		</div>
		<!-- 分录表格 -->
		<div class="entry-table">
			<table class="acct-table">
				<thead>
					<tr>
						<th style="width:280px">摘要</th>
						<th style="width:260px">会计科目</th>
						<th style="width:180px">辅助核算</th>
						<th style="width:160px">品牌</th>
						<th style="width:150px">借方金额</th>
						<th style="width:150px">贷方金额</th>
						<th style="width:40px"></th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="(entry, idx) in entries" :key="idx">
						<td><el-input v-model="entry.summary" placeholder="摘要" size="small" /></td>
						<td>
							<el-select v-model="entry.subject_id" filterable placeholder="选择末级科目" size="small" style="width:100%" @change="(v) => onSubjectChange(entry, v)">
								<el-option v-for="s in subjectOptions" :key="s.id" :label="`${s.code} ${s.name}`" :value="s.id" />
							</el-select>
						</td>
						<td>
							<template v-if="entry.aux_types && entry.aux_types.length">
								<el-select v-if="entry.aux_types.length === 1" v-model="entry.aux_id" :placeholder="auxLabel(entry.aux_types[0])" filterable size="small" style="width:100%" @change="(v) => onAuxChange(entry, v)">
									<el-option v-for="o in auxOptions(entry.aux_types[0])" :key="o.id" :label="o.name" :value="o.id" />
								</el-select>
								<span v-else class="dash">多辅助核算</span>
							</template>
							<span v-else class="dash">—</span>
						</td>
						<td>
							<el-select v-model="entry.brand_id" placeholder="选择品牌" clearable filterable size="small" style="width:100%" @change="(v) => onBrandChange(entry, v)">
								<el-option v-for="b in brandOptions" :key="b.id" :label="b.name" :value="b.id" />
							</el-select>
						</td>
						<td><el-input-number v-model="entry.debit_amount" :precision="2" :controls="false" size="small" class="amt-cell" :disabled="!!entry.credit_amount" @change="onDebitChange(entry)" /></td>
						<td><el-input-number v-model="entry.credit_amount" :precision="2" :controls="false" size="small" class="amt-cell" :disabled="!!entry.debit_amount" @change="onCreditChange(entry)" /></td>
						<td><el-icon class="del-icon" @click="removeEntry(idx)"><ElIconDelete /></el-icon></td>
					</tr>
				</tbody>
			</table>
			<el-button class="add-entry-btn" @click="addEntry">+ 增加分录</el-button>
		</div>
		<!-- 合计行 -->
		<div class="total-row">
			<span class="chinese">合计：人民币（大写）{{ amountChinese }}</span>
			<span class="total-amt">借方合计：<b :class="balanced ? 'green' : 'red'">{{ fmt(totalDebit) }}</b></span>
			<span class="total-amt">贷方合计：<b :class="balanced ? 'green' : 'red'">{{ fmt(totalCredit) }}</b></span>
		</div>
		<!-- 底部按钮 -->
		<template #footer>
			<div class="footer-bar">
				<div class="left-btns">
					<el-button @click="saveDraft">保存草稿</el-button>
					<el-button @click="saveAndNew">保存并新增</el-button>
				</div>
				<div class="right-btns">
					<el-button @click="visible = false">取消</el-button>
					<el-button v-if="form.auto_audit" type="primary" @click="submitAudit">保存并审核</el-button>
					<el-button v-else type="primary" @click="submitAudit">提交审核</el-button>
				</div>
			</div>
		</template>
	</el-dialog>
</template>

<script setup>
import { ref, reactive, computed } from 'vue'
import { ElMessage } from 'element-plus'
import ledgerApi from '@/api/ledger'

const emit = defineEmits(['saved'])
const visible = ref(false)
const title = ref('新增记账凭证')
const form = reactive({ id: null, voucher_no: '', voucher_date: new Date().toISOString().slice(0, 10), attachments_count: 0, remark: '', auto_audit: false })
const entries = ref([])
const subjectOptions = ref([])
const brandOptions = ref([])
const auxMap = reactive({}) // type => options

const totalDebit = computed(() => round(entries.value.reduce((s, e) => s + (Number(e.debit_amount) || 0), 0)))
const totalCredit = computed(() => round(entries.value.reduce((s, e) => s + (Number(e.credit_amount) || 0), 0)))
const balanced = computed(() => Math.abs(totalDebit.value - totalCredit.value) < 0.005)
const amountChinese = computed(() => digitsToChinese(totalDebit.value))

const fmt = (v) => Number(v || 0).toFixed(2)
const round = (v) => Math.round(v * 100) / 100
const auxLabel = (a) => ({ customer: '客户', supplier: '供应商', employee: '员工', department: '部门', project: '项目' }[a] || a)
const auxOptions = (type) => auxMap[type] || []

/** 录单时自动审核默认值：取系统参数，取不到则 false。 */
const loadAutoAuditDefault = async () => {
	try {
		const res = await import('@/api/system').then(m => m.default.system.config.all.get())
		const cfg = res.data || {}
		form.auto_audit = !!(cfg['ledger.voucher.auto_audit'] ?? cfg['ledger_auto_audit'] ?? false)
	} catch {
		form.auto_audit = false
	}
}

const open = async (row) => {
	Object.assign(form, { id: null, voucher_no: '', voucher_date: new Date().toISOString().slice(0, 10), attachments_count: 0, remark: '', auto_audit: false })
	entries.value = [emptyEntry(), emptyEntry(), emptyEntry(), emptyEntry(), emptyEntry()]
	await Promise.all([loadOptions(), loadAutoAuditDefault()])
	if (row) {
		const res = await ledgerApi.voucher.detail.get(row.id)
		const v = res.data?.voucher
		if (v) {
			Object.assign(form, { id: v.id, voucher_no: v.voucher_no, voucher_date: v.voucher_date, attachments_count: v.attachments_count, remark: v.remark, auto_audit: !!v.auto_audit })
			entries.value = v.entries.map(e => ({ subject_id: e.subject_id, subject_code: e.subject_code, subject_name: e.subject_name, aux_types: subjectOptions.value.find(s => s.id === e.subject_id)?.aux_types || [], aux_type: e.aux_type, aux_id: e.aux_id, aux_name: e.aux_name, brand_id: e.brand_id, brand_name: e.brand_name, summary: e.summary, debit_amount: Number(e.debit_amount), credit_amount: Number(e.credit_amount) }))
			// 补齐至少 5 行
			while (entries.value.length < 5) entries.value.push(emptyEntry())
		}
	}
	title.value = row ? '编辑记账凭证' : '新增记账凭证'
	visible.value = true
}
defineExpose({ open })

const loadOptions = async () => {
	const res = await ledgerApi.subject.options.get()
	subjectOptions.value = res.data?.options || []
	try {
		const b = await import('@/api/business').then(m => m.default.brand.options.get())
		brandOptions.value = b.data?.list || []
	} catch { brandOptions.value = [] }
}
const loadAuxOptions = async (type) => {
	if (auxMap[type]) return
	// 辅助核算对象从业务表取：客户/供应商/员工
	try {
		if (type === 'customer') {
			const r = await import('@/api/business').then(m => m.default.customer.list.get({ page: 1, page_size: 999 }))
			auxMap[type] = (r.data?.list || []).map(c => ({ id: c.id, name: c.name }))
		} else if (type === 'supplier') {
			const r = await import('@/api/business').then(m => m.default.supplier.list.get({ page: 1, page_size: 999 }))
			auxMap[type] = (r.data?.list || []).map(s => ({ id: s.id, name: s.name }))
		}
	} catch { auxMap[type] = [] }
}

const emptyEntry = () => ({ subject_id: null, subject_code: '', subject_name: '', aux_types: [], aux_type: null, aux_id: null, aux_name: '', brand_id: null, brand_name: '', summary: '', debit_amount: 0, credit_amount: 0 })
const addEntry = () => entries.value.push(emptyEntry())
const removeEntry = (idx) => { if (entries.value.length > 1) entries.value.splice(idx, 1) }

const onSubjectChange = (entry, subjectId) => {
	const s = subjectOptions.value.find(x => x.id === subjectId)
	if (s) {
		entry.subject_code = s.code
		entry.subject_name = s.name
		entry.aux_types = s.aux_types || []
		entry.aux_type = entry.aux_types[0] || null
		entry.aux_id = null
		entry.aux_name = ''
		if (entry.aux_types[0]) loadAuxOptions(entry.aux_types[0])
	}
}
const onAuxChange = (entry, auxId) => {
	const o = auxOptions(entry.aux_type).find(x => x.id === auxId)
	entry.aux_name = o?.name || ''
}
const onBrandChange = (entry, brandId) => {
	const o = brandOptions.value.find(x => x.id === brandId)
	entry.brand_name = o?.name || ''
}
const onDebitChange = (entry) => { if (entry.debit_amount) entry.credit_amount = 0 }
const onCreditChange = (entry) => { if (entry.credit_amount) entry.debit_amount = 0 }

const buildPayload = (status) => ({
	...form,
	status,
	entries: entries.value.filter(e => e.subject_id && ((Number(e.debit_amount) || 0) !== 0 || (Number(e.credit_amount) || 0) !== 0)).map(e => ({
		subject_id: e.subject_id, subject_code: e.subject_code, subject_name: e.subject_name,
		aux_type: e.aux_types.length ? e.aux_type : null, aux_id: e.aux_id, aux_name: e.aux_name,
		brand_id: e.brand_id || null, brand_name: e.brand_name || '',
		summary: e.summary, debit_amount: Number(e.debit_amount) || 0, credit_amount: Number(e.credit_amount) || 0,
	})),
})

const saveDraft = async () => {
	try {
		if (form.id) await ledgerApi.voucher.edit.put(form.id, buildPayload('draft'))
		else await ledgerApi.voucher.add.post(buildPayload('draft'))
		ElMessage.success('保存成功')
		visible.value = false
		emit('saved')
	} catch (e) { ElMessage.error(e?.message || '保存失败') }
}
const saveAndNew = async () => {
	try {
		await ledgerApi.voucher.add.post(buildPayload('draft'))
		ElMessage.success('保存成功')
		Object.assign(form, { id: null, voucher_no: '', voucher_date: new Date().toISOString().slice(0, 10), attachments_count: 0, remark: '', auto_audit: false })
		entries.value = [emptyEntry(), emptyEntry(), emptyEntry(), emptyEntry(), emptyEntry()]
	} catch (e) { ElMessage.error(e?.message || '保存失败') }
}
const submitAudit = async () => {
	if (!balanced.value) return ElMessage.error(`借贷不平衡，借方合计 ${fmt(totalDebit.value)}，贷方合计 ${fmt(totalCredit.value)}`)
	// 录单时自动审核开启 → 保存即已审核（制单人自审入账）；否则走提交审核
	const status = form.auto_audit ? 'audited' : 'submitted'
	const successMsg = form.auto_audit ? '保存并审核成功' : '提交成功'
	try {
		if (form.id) await ledgerApi.voucher.edit.put(form.id, buildPayload(status))
		else await ledgerApi.voucher.add.post(buildPayload(status))
		ElMessage.success(successMsg)
		visible.value = false
		emit('saved')
	} catch (e) { ElMessage.error(e?.message || (form.auto_audit ? '保存并审核失败' : '提交失败')) }
}

// 金额转中文大写（简化版）
const digits = ['零', '壹', '贰', '叁', '肆', '伍', '陆', '柒', '捌', '玖']
const units = ['', '拾', '佰', '仟', '万', '拾', '佰', '仟', '亿']
function digitsToChinese(num) {
	num = Number(num) || 0
	if (num === 0) return '零元整'
	const intPart = Math.floor(Math.abs(num))
	const decPart = Math.round((Math.abs(num) - intPart) * 100)
	let str = intPart.toString()
	let result = ''
	for (let i = 0; i < str.length; i++) {
		const d = parseInt(str[i])
		const u = str.length - 1 - i
		result += digits[d] + (d !== 0 ? units[u] : (str[i + 1] && parseInt(str[i + 1]) !== 0 ? '零' : ''))
	}
	result = result.replace(/零+$/, '').replace(/零+/g, '零')
	result += '元'
	if (decPart === 0) result += '整'
	else {
		const jiao = Math.floor(decPart / 10)
		const fen = decPart % 10
		if (jiao > 0) result += digits[jiao] + '角'
		else if (fen > 0) result += '零'
		if (fen > 0) result += digits[fen] + '分'
	}
	return (num < 0 ? '负' : '') + result
}
</script>

<style scoped>
.voucher-head { border-bottom: 1px solid #ebeef5; padding-bottom: 12px; margin-bottom: 16px; }
.entry-table { margin-bottom: 8px; }
.acct-table { width: 100%; border-collapse: collapse; }
.acct-table th { background: #f5f7fa; color: #606266; font-size: 14px; font-weight: 500; height: 40px; border: 1px solid #ebeef5; text-align: center; }
.acct-table td { border: 1px solid #ebeef5; padding: 4px 8px; }
.acct-table td .amt-cell { width: 100%; text-align: right; }
.del-icon { color: #f56c6c; cursor: pointer; font-size: 16px; }
.add-entry-btn { width: 100%; border: 1px dashed #409eff; color: #409eff; margin-top: 8px; }
.total-row { display: flex; align-items: center; gap: 24px; padding: 12px 0; border-top: 1px solid #ebeef5; margin-top: 8px; }
.chinese { font-size: 15px; font-weight: 700; color: #303133; }
.total-amt { font-size: 14px; color: #606266; }
.green { color: #67c23a; }
.red { color: #f56c6c; }
.dash { color: #909399; text-align: center; display: inline-block; width: 100%; }
.footer-bar { display: flex; justify-content: space-between; padding: 0 20px; }
.left-btns .el-button { border-color: #409eff; color: #409eff; }
</style>
