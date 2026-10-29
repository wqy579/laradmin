<template>
	<div class="agr-list agr-page">
		<!-- 查询区 -->
		<div class="agr-filter">
			<span class="label">关键字</span>
			<el-input v-model="search.keyword" placeholder="编号 / 名称 / 客户" clearable size="small" style="width: 190px" @keyup.enter="refresh" @clear="refresh" />
			<span class="label">客户</span>
			<el-select v-model="search.customer_id" placeholder="全部" clearable filterable size="small" style="width: 170px" @change="refresh">
				<el-option v-for="c in customers" :key="c.id" :label="c.name" :value="c.id" />
			</el-select>
			<span class="label">类型</span>
			<el-select v-model="search.type" placeholder="全部" clearable size="small" style="width: 120px" @change="refresh">
				<el-option label="陈列协议" value="display" />
				<el-option label="一般协议" value="general" />
			</el-select>
			<span class="label">状态</span>
			<el-select v-model="search.status" placeholder="全部" clearable size="small" style="width: 110px" @change="refresh">
				<el-option label="生效中" value="active" />
				<el-option label="已到期" value="expired" />
				<el-option label="已终止" value="terminated" />
			</el-select>
			<span class="label">有效期</span>
			<el-date-picker
				v-model="dateRange"
				type="daterange"
				value-format="YYYY-MM-DD"
				range-separator="~"
				start-placeholder="开始日"
				end-placeholder="结束日"
				size="small"
				style="width: 230px"
				@change="refresh"
			/>
			<el-button type="primary" size="small" @click="refresh">查询</el-button>
			<el-button size="small" @click="resetSearch">重置</el-button>
		</div>

		<!-- 汇总条 -->
		<div class="summary-bar">
			<span>协议数：<b>{{ stat.count }}</b></span>
			<span class="sep">|</span>
			<span>协议额：<span class="amt">¥{{ fmtMoney(stat.amount) }}</span></span>
			<span class="sep">|</span>
			<span>已兑现：<span class="amt">¥{{ fmtMoney(stat.redeemed_amount) }}</span></span>
			<span class="sep">|</span>
			<span>总兑现率：<b class="amt">{{ fmtRate(stat.redemption_rate) }}%</b></span>
			<span class="sep">|</span>
			<span class="cnt-ok">生效中 {{ stat.active_count }}</span>
			<span class="cnt-off">已到期 {{ stat.expired_count }}</span>
			<span class="cnt-stop">已终止 {{ stat.terminated_count }}</span>
		</div>

		<div class="agr-toolbar">
			<el-button v-permission="'agr:list:create'" type="primary" @click="openCreate">新增协议</el-button>
			<el-button v-permission="'agr:list:export'" :loading="exporting" @click="doExport">导出</el-button>
		</div>

		<div class="agr-content">
			<el-table v-loading="loading" :data="rows" border stripe height="100%">
				<el-table-column prop="agreement_no" label="协议编号" width="130" show-overflow-tooltip>
					<template #default="{ row }"><span class="amt">{{ row.agreement_no }}</span></template>
				</el-table-column>
				<el-table-column prop="title" label="协议名称" min-width="180" show-overflow-tooltip />
				<el-table-column prop="customer_name" label="客户" min-width="150" show-overflow-tooltip />
				<el-table-column prop="type_label" label="类型" width="100" align="center" />
				<el-table-column label="有效期" width="200" align="center">
					<template #default="{ row }"><span class="amt">{{ row.start_date }} ~ {{ row.end_date }}</span></template>
				</el-table-column>
				<el-table-column prop="amount" label="协议额" width="130" align="right">
					<template #default="{ row }"><span class="amt">¥{{ fmtMoney(row.amount) }}</span></template>
				</el-table-column>
				<el-table-column prop="redeemed_amount" label="已兑现额" width="130" align="right">
					<template #default="{ row }"><span class="amt">¥{{ fmtMoney(row.redeemed_amount) }}</span></template>
				</el-table-column>
				<el-table-column prop="pending_amount" label="待兑现额" width="130" align="right">
					<template #default="{ row }"><span class="amt">¥{{ fmtMoney(row.pending_amount) }}</span></template>
				</el-table-column>
				<el-table-column label="兑现率" width="170">
					<template #default="{ row }">
						<el-progress :percentage="clampedRate(row.redemption_rate)" :color="rateColor(row.redemption_rate)" :stroke-width="10" :format="rateFormat(row.redemption_rate)" />
					</template>
				</el-table-column>
				<el-table-column label="状态" width="92" align="center">
					<template #default="{ row }">
						<el-tag :type="row.status_tag_type || 'info'" size="small" effect="light">{{ row.status_label }}</el-tag>
					</template>
				</el-table-column>
				<el-table-column label="操作" width="210" align="center" fixed="right">
					<template #default="{ row }">
						<el-button v-permission="'agr:list:redeem'" type="primary" link size="small" :disabled="row.status === 'terminated'" @click="openRedeem(row)">登记兑现</el-button>
						<el-button v-permission="'agr:list:edit'" type="primary" link size="small" :disabled="row.status === 'terminated'" @click="openEdit(row)">编辑</el-button>
						<el-button v-permission="'agr:list:terminate'" type="warning" link size="small" :disabled="row.status === 'terminated'" @click="doTerminate(row)">终止</el-button>
						<el-button v-permission="'agr:list:edit'" type="danger" link size="small" @click="doDelete(row)">删除</el-button>
					</template>
				</el-table-column>
				<template #empty>
					<FinEmpty text="当前筛选条件下暂无协议" />
				</template>
			</el-table>

			<div class="agr-pager">
				<el-pagination
					:current-page="page"
					:page-size="pageSize"
					:page-sizes="[20, 50, 100]"
					:total="total"
					layout="total, sizes, prev, pager, next, jumper"
					@current-change="onPage"
					@size-change="onSize"
				/>
			</div>
		</div>

		<!-- 新增 / 编辑 -->
		<el-dialog v-model="formVisible" class="agr-dialog" :title="form.id ? '编辑协议' : '新增协议'" width="620px" :close-on-click-modal="false">
			<el-form ref="formRef" :model="form" :rules="formRules" label-width="84px">
				<el-form-item label="协议名称" prop="title">
					<el-input v-model="form.title" maxlength="100" placeholder="如：XX 卖场 2026 陈列协议" />
				</el-form-item>
				<el-form-item label="客户" prop="customer_name">
					<el-select v-model="form.customer_name" placeholder="选择或输入客户名称" filterable allow-create default-first-option style="width: 100%" @change="onCustomerPick">
						<el-option v-for="c in customers" :key="c.id" :label="c.name" :value="c.name" />
					</el-select>
				</el-form-item>
				<el-form-item label="协议类型" prop="type">
					<el-radio-group v-model="form.type">
						<el-radio value="display">陈列协议</el-radio>
						<el-radio value="general">一般协议</el-radio>
					</el-radio-group>
				</el-form-item>
				<el-form-item label="有效期" prop="date_range">
					<el-date-picker v-model="form.date_range" type="daterange" value-format="YYYY-MM-DD" range-separator="~" start-placeholder="生效日期" end-placeholder="到期日期" style="width: 100%" />
				</el-form-item>
				<el-form-item label="协议额" prop="amount">
					<el-input-number v-model="form.amount" :min="0" :precision="2" :step="1000" controls-position="right" style="width: 100%" />
				</el-form-item>
				<el-form-item v-if="form.id" label="已兑现">
					<span class="amt readonly-val">¥{{ fmtMoney(form.redeemed_amount) }}（兑现率 {{ fmtRate(form.redemption_rate) }}%，通过「登记兑现」变更）</span>
				</el-form-item>
				<el-form-item label="备注">
					<el-input v-model="form.remark" type="textarea" :rows="2" maxlength="500" show-word-limit placeholder="选填" />
				</el-form-item>
			</el-form>
			<template #footer>
				<el-button @click="formVisible = false">取消</el-button>
				<el-button type="primary" :loading="saving" @click="onSave">保存</el-button>
			</template>
		</el-dialog>

		<!-- 登记兑现 -->
		<el-dialog v-model="redeemVisible" class="agr-dialog" title="登记兑现" width="440px" :close-on-click-modal="false">
			<el-descriptions v-if="redeemTarget" :column="1" border size="small" class="redeem-head">
				<el-descriptions-item label="协议">{{ redeemTarget.agreement_no }} · {{ redeemTarget.title }}</el-descriptions-item>
				<el-descriptions-item label="协议额"><span class="amt">¥{{ fmtMoney(redeemTarget.amount) }}</span></el-descriptions-item>
				<el-descriptions-item label="已兑现"><span class="amt">¥{{ fmtMoney(redeemTarget.redeemed_amount) }}</span></el-descriptions-item>
				<el-descriptions-item label="待兑现"><span class="amt">¥{{ fmtMoney(redeemTarget.pending_amount) }}</span></el-descriptions-item>
			</el-descriptions>
			<el-form ref="redeemFormRef" :model="redeemForm" :rules="redeemRules" label-width="84px" style="margin-top: 16px">
				<el-form-item label="本次兑现" prop="amount">
					<el-input-number v-model="redeemForm.amount" :min="0.01" :precision="2" :step="1000" controls-position="right" style="width: 100%" />
				</el-form-item>
			</el-form>
			<template #footer>
				<el-button @click="redeemVisible = false">取消</el-button>
				<el-button type="primary" :loading="saving" @click="onRedeem">确认登记</el-button>
			</template>
		</el-dialog>
	</div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import agrApi from '@/api/agr'
import { saveBlob } from '@/utils/fileDownload'
import FinEmpty from '../../finance/_FinEmpty.vue'
import { fmtMoney, fmtRate, toNum } from '../_fmt.js'

const EMPTY_STAT = { count: 0, amount: 0, redeemed_amount: 0, redemption_rate: 0, active_count: 0, expired_count: 0, terminated_count: 0 }

const customers = ref([])
const rows = ref([])
const stat = ref({ ...EMPTY_STAT })
const total = ref(0)
const page = ref(1)
const pageSize = ref(20)
const loading = ref(false)
const exporting = ref(false)
const saving = ref(false)

const search = reactive({ keyword: '', customer_id: null, type: '', status: '' })
const dateRange = ref([])

/** 兑现率色阶：≤60% 橙、≥100% 绿，其余主色蓝 */
const rateColor = (v) => {
	const r = toNum(v)
	if (r >= 100) return '#67C23A'
	if (r <= 60) return '#E6A23C'
	return '#409EFF'
}
// el-progress 的 percentage 只接受 0~100，越界要夹住；文案仍显示真实值
const clampedRate = (v) => Math.min(100, Math.max(0, toNum(v)))
const rateFormat = (v) => () => `${fmtRate(v)}%`

const params = () => ({
	keyword: search.keyword || undefined,
	customer_id: search.customer_id || undefined,
	type: search.type || undefined,
	status: search.status || undefined,
	start_from: dateRange.value?.[0] || undefined,
	start_to: dateRange.value?.[1] || undefined,
})

const loadOptions = async () => {
	try {
		const res = await agrApi.agreement.options.get()
		if (res?.code === 200) customers.value = res.data?.customers || []
	} catch { /* 下拉失败不阻断列表 */ }
}

const loadList = async () => {
	loading.value = true
	try {
		const res = await agrApi.agreement.list.get({ ...params(), page: page.value, page_size: pageSize.value })
		if (res?.code === 200) {
			rows.value = res.data?.list || []
			total.value = res.data?.total || 0
			stat.value = { ...EMPTY_STAT, ...(res.data?.stat || {}) }
		}
	} finally { loading.value = false }
}

const refresh = () => { page.value = 1; loadList() }
const onPage = (p) => { page.value = p; loadList() }
const onSize = (s) => { pageSize.value = s; refresh() }
const resetSearch = () => {
	search.keyword = ''
	search.customer_id = null
	search.type = ''
	search.status = ''
	dateRange.value = []
	refresh()
}

// ── 新增 / 编辑 ───────────────────────────────────────────────
const formVisible = ref(false)
const formRef = ref()
const form = reactive({
	id: null, title: '', customer_id: null, customer_name: '', type: 'display',
	date_range: [], amount: 0, remark: '', redeemed_amount: 0, redemption_rate: 0,
})
const formRules = {
	title: [{ required: true, message: '请填写协议名称', trigger: 'blur' }],
	customer_name: [{ required: true, message: '请选择或输入客户', trigger: 'change' }],
	type: [{ required: true, message: '请选择协议类型', trigger: 'change' }],
	date_range: [{ required: true, message: '请选择有效期', trigger: 'change' }],
	amount: [{ required: true, type: 'number', min: 0, message: '请填写协议额', trigger: 'change' }],
}

const blankForm = () => ({
	id: null, title: '', customer_id: null, customer_name: '', type: 'display',
	date_range: [], amount: 0, remark: '', redeemed_amount: 0, redemption_rate: 0,
})

const openCreate = () => {
	Object.assign(form, blankForm())
	formVisible.value = true
	formRef.value?.clearValidate()
}

const openEdit = async (row) => {
	Object.assign(form, blankForm(), {
		id: row.id,
		title: row.title,
		customer_id: row.customer_id,
		customer_name: row.customer_name,
		type: row.type,
		date_range: [row.start_date, row.end_date],
		amount: toNum(row.amount),
		redeemed_amount: toNum(row.redeemed_amount),
		redemption_rate: toNum(row.redemption_rate),
	})
	try {
		const res = await agrApi.agreement.detail.get(row.id)
		if (res?.code === 200 && res.data) {
			form.remark = res.data.remark || ''
			form.customer_id = res.data.customer_id
			form.customer_name = res.data.customer_name
		}
	} catch { /* 详情失败时用列表行数据兜底 */ }
	formVisible.value = true
	formRef.value?.clearValidate()
}

// 下拉里选中已有客户 → 带上 customer_id；手输的新客户保持 null（后端允许）
const onCustomerPick = (name) => {
	const hit = customers.value.find((c) => c.name === name)
	form.customer_id = hit ? hit.id : null
}

const onSave = async () => {
	try {
		await formRef.value.validate()
	} catch { return }
	const payload = {
		title: form.title,
		customer_id: form.customer_id,
		customer_name: form.customer_name,
		type: form.type,
		start_date: form.date_range[0],
		end_date: form.date_range[1],
		amount: toNum(form.amount),
		remark: form.remark || null,
	}
	saving.value = true
	try {
		const res = form.id ? await agrApi.agreement.edit.put(form.id, payload) : await agrApi.agreement.add.post(payload)
		if (res?.code === 200) {
			ElMessage.success(res.message || '已保存')
			formVisible.value = false
			loadList()
		}
	} catch (e) { /* 服务端校验文案已由拦截器弹出（如「协议额不能小于已兑现额」） */ } finally { saving.value = false }
}

// ── 登记兑现 ─────────────────────────────────────────────────
const redeemVisible = ref(false)
const redeemFormRef = ref()
const redeemTarget = ref(null)
const redeemForm = reactive({ amount: 0 })
const redeemRules = {
	amount: [{ required: true, type: 'number', validator: (r, v, cb) => (toNum(v) > 0 ? cb() : cb(new Error('兑现金额必须大于 0'))), trigger: 'change' }],
}

const openRedeem = (row) => {
	redeemTarget.value = row
	redeemForm.amount = toNum(row.pending_amount) > 0 ? toNum(row.pending_amount) : 0
	redeemVisible.value = true
	redeemFormRef.value?.clearValidate()
}

const onRedeem = async () => {
	try {
		await redeemFormRef.value.validate()
	} catch { return }
	saving.value = true
	try {
		const res = await agrApi.agreement.redeem.post(redeemTarget.value.id, { amount: toNum(redeemForm.amount) })
		if (res?.code === 200) {
			ElMessage.success(res.message || '兑现已登记')
			redeemVisible.value = false
			loadList()
		}
	} catch (e) { /* 累计超协议额等 422 文案由拦截器弹出 */ } finally { saving.value = false }
}

// ── 终止 / 删除 / 导出 ────────────────────────────────────────
const doTerminate = async (row) => {
	try {
		await ElMessageBox.confirm(`终止后协议「${row.title}」不可再登记兑现或编辑，是否继续？`, '终止确认', { type: 'warning' })
	} catch { return }
	try {
		const res = await agrApi.agreement.terminate.post(row.id, {})
		if (res?.code === 200) { ElMessage.success(res.message || '协议已终止'); loadList() }
	} catch { /* 拦截器已提示 */ }
}

const doDelete = async (row) => {
	if (toNum(row.redeemed_amount) > 0) {
		return ElMessage.warning('该协议已有兑现记录，不能删除')
	}
	try {
		await ElMessageBox.confirm(`确认删除协议「${row.title}」？`, '删除确认', { type: 'warning' })
	} catch { return }
	try {
		const res = await agrApi.agreement.delete.delete(row.id)
		if (res?.code === 200) { ElMessage.success(res.message || '已删除'); loadList() }
	} catch { /* 拦截器已提示（服务端同样拦已有兑现的协议） */ }
}

const doExport = async () => {
	if (!rows.value.length) return ElMessage.warning('当前筛选条件下暂无数据')
	exporting.value = true
	try {
		const blob = await agrApi.agreement.export(params())
		saveBlob(blob, `agreements_${new Date().toISOString().slice(0, 10).replace(/-/g, '')}.csv`)
	} finally { exporting.value = false }
}

onMounted(() => { loadOptions(); loadList() })
</script>

<style>
@import '../_shared.css';
</style>

<style scoped>
.agr-list { height: 100%; display: flex; flex-direction: column; }
/* 排版留在本页，白底/内边距/下边框/标签字号由 _shared.css .agr-filter 提供 */
.agr-filter { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.summary-bar { display: flex; gap: 8px; align-items: center; padding: 8px 20px; background: var(--bg-hover); font-size: var(--font-base); color: var(--text-regular); border-bottom: 1px solid var(--border-lighter); }
.summary-bar .sep { color: var(--border-base); }
.summary-bar .cnt-ok { color: var(--color-success); }
.summary-bar .cnt-off { color: var(--text-secondary); }
.summary-bar .cnt-stop { color: var(--color-danger); }
.agr-toolbar { display: flex; gap: 8px; padding: 12px 20px; }
.agr-content { flex: 1; display: flex; flex-direction: column; overflow: auto; padding: 0 20px; }
.readonly-val { color: var(--text-regular); }
:deep(.el-table) { color: var(--text-primary); }
</style>
