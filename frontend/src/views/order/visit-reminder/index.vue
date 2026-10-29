<template>
	<div class="perf-list">
		<!-- 4 KPI 卡（F3）：待提醒 / 已提醒 / 今日待办 / 本月覆盖率 -->
		<div class="stat-cards">
			<div class="stat-card">
				<div class="stat-label">待提醒</div>
				<div class="stat-value text-warning">{{ stats.pending }}</div>
			</div>
			<div class="stat-card">
				<div class="stat-label">已提醒</div>
				<div class="stat-value text-success">{{ stats.sent }}</div>
			</div>
			<div class="stat-card">
				<div class="stat-label">今日待办</div>
				<div class="stat-value text-primary">{{ stats.today_pending }}</div>
			</div>
			<div class="stat-card">
				<div class="stat-label">本月覆盖率</div>
				<div class="stat-value" :class="coverageClass">{{ stats.coverage_rate }}<span class="stat-unit">%</span></div>
				<div class="stat-sub">已访 {{ stats.reached_customers }} / 应访 {{ stats.plan_customers }}</div>
			</div>
		</div>

		<!-- 筛选栏 -->
		<div class="perf-filter">
			<el-input
				v-model="filters.keyword"
				placeholder="提醒编号 / 业务员 / 客户 / 内容"
				clearable
				style="width: 240px"
				@keyup.enter="onSearch"
				@clear="onSearch"
			/>
			<el-select v-model="filters.status" placeholder="状态" clearable style="width: 140px" @change="onSearch">
				<el-option label="待提醒" value="pending" />
				<el-option label="已提醒" value="sent" />
				<el-option label="已作废" value="cancelled" />
			</el-select>
			<el-button type="primary" :loading="loading" @click="onSearch">查询</el-button>
			<el-button @click="resetFilters">重置</el-button>
			<el-button type="success" @click="openForm">新建提醒</el-button>
			<el-button type="primary" :disabled="!selection.length" :loading="batchSending" @click="onBatchSend">
				批量提醒{{ selection.length ? `（${selection.length}）` : '' }}
			</el-button>
		</div>

		<!-- 列表 -->
		<el-table
			v-loading="loading"
			:data="list"
			class="perf-table"
			height="100%"
			row-key="id"
			:header-cell-style="{ background: '#F5F7FA', color: '#606266', fontWeight: 600 }"
			@selection-change="onSelectionChange"
		>
			<el-table-column type="selection" width="46" reserve-selection />
			<el-table-column prop="remind_no" label="提醒编号" min-width="160" />
			<el-table-column prop="employee" label="业务员" min-width="100" show-overflow-tooltip />
			<el-table-column prop="customer" label="拜访客户" min-width="120" show-overflow-tooltip />
			<el-table-column prop="visit_date" label="计划拜访日期" width="120" align="center" />
			<el-table-column prop="content" label="提醒内容" min-width="180" show-overflow-tooltip />
			<el-table-column label="状态" width="90" align="center">
				<template #default="{ row }">
					<el-tag v-if="row.status === 'pending'" type="warning" size="small">待提醒</el-tag>
					<el-tag v-else-if="row.status === 'sent'" type="success" size="small">已提醒</el-tag>
					<el-tag v-else type="info" size="small">已作废</el-tag>
				</template>
			</el-table-column>
			<el-table-column prop="sent_at" label="发送时间" width="160" align="center" />
			<el-table-column label="操作" width="150" align="center" fixed="right">
				<template #default="{ row }">
					<el-button link type="primary" size="small" @click="openDetail(row)">历史</el-button>
					<el-button v-if="row.status === 'pending'" link type="success" size="small" @click="onSend(row)">发送</el-button>
					<el-button v-if="row.status === 'pending'" link type="danger" size="small" @click="onCancel(row)">作废</el-button>
				</template>
			</el-table-column>
			<template #empty>
				<el-empty description="暂无拜访提醒" />
			</template>
		</el-table>

		<!-- 分页 -->
		<div class="perf-pager">
			<el-pagination
				v-model:current-page="page"
				v-model:page-size="pageSize"
				:total="total"
				:page-sizes="[20, 50, 100]"
				layout="total, sizes, prev, pager, next"
				background
				@current-change="load"
				@size-change="load"
			/>
		</div>

		<!-- 新建提醒弹窗 -->
		<el-dialog v-model="formVisible" title="新建拜访提醒" width="560px" @closed="resetForm">
			<el-form :model="form" label-width="100px">
				<el-form-item label="业务员" required>
					<el-select v-model="form.employee_id" filterable placeholder="请选择业务员" style="width: 100%">
						<el-option v-for="emp in employees" :key="emp.id" :label="emp.real_name || emp.username" :value="emp.id" />
					</el-select>
				</el-form-item>
				<el-form-item label="拜访客户">
					<el-select v-model="form.customer_id" filterable clearable placeholder="可不选" style="width: 100%">
						<el-option v-for="cust in customers" :key="cust.id" :label="cust.name" :value="cust.id" />
					</el-select>
				</el-form-item>
				<el-form-item label="计划拜访日期" required>
					<el-date-picker
						v-model="form.visit_date"
						type="date"
						value-format="YYYY-MM-DD"
						placeholder="选择日期"
						style="width: 100%"
					/>
				</el-form-item>
				<el-form-item label="提醒内容">
					<el-input
						v-model="form.content"
						type="textarea"
						:rows="3"
						maxlength="500"
						show-word-limit
						placeholder="例如：明天上午拜访客户，带上新品样册"
					/>
				</el-form-item>
			</el-form>
			<template #footer>
				<el-button @click="formVisible = false">取消</el-button>
				<el-button type="primary" @click="submitForm">确定</el-button>
			</template>
		</el-dialog>

		<!-- 详情弹窗：提醒信息 + 操作留痕 -->
		<el-dialog v-model="detailVisible" :title="`拜访提醒 · ${detail.remind_no || ''}`" width="640px" top="6vh">
			<el-descriptions :column="2" border size="small" style="margin-bottom: 12px">
				<el-descriptions-item label="提醒编号">{{ detail.remind_no }}</el-descriptions-item>
				<el-descriptions-item label="业务员">{{ detail.employee }}</el-descriptions-item>
				<el-descriptions-item label="拜访客户">{{ detail.customer || '—' }}</el-descriptions-item>
				<el-descriptions-item label="计划拜访日期">{{ detail.visit_date }}</el-descriptions-item>
				<el-descriptions-item label="状态">
					<el-tag v-if="detail.status === 'pending'" type="warning" size="small">待提醒</el-tag>
					<el-tag v-else-if="detail.status === 'sent'" type="success" size="small">已提醒</el-tag>
					<el-tag v-else type="info" size="small">已作废</el-tag>
				</el-descriptions-item>
				<el-descriptions-item label="发送时间">{{ detail.sent_at || '—' }}</el-descriptions-item>
				<el-descriptions-item label="提醒内容" :span="2">{{ detail.content || '—' }}</el-descriptions-item>
			</el-descriptions>

			<div class="detail-sub-title">操作留痕</div>
			<el-timeline v-if="detail.history && detail.history.length" style="padding-left: 4px">
				<el-timeline-item
					v-for="log in detail.history"
					:key="log.id"
					:timestamp="log.created_at"
					:type="log.to_status === 'cancelled' ? 'danger' : 'primary'"
				>
					<span class="history-action">{{ log.action_label || log.action }}</span>
					<span class="history-operator">{{ log.operator }}</span>
					<div v-if="log.detail" class="history-detail">{{ log.detail }}</div>
					<div v-if="log.remark" class="history-remark">备注：{{ log.remark }}</div>
				</el-timeline-item>
			</el-timeline>
			<el-empty v-else description="暂无操作记录" :image-size="60" />
		</el-dialog>
	</div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import api from '@/api/order'
import authApi from '@/api/auth'
import businessApi from '@/api/business'

const loading = ref(false)
const list = ref([])
const page = ref(1)
const pageSize = ref(20)
const total = ref(0)
const filters = reactive({ keyword: '', status: '' })
const employees = ref([])
const customers = ref([])
const selection = ref([])

// ---------------- 4 KPI 卡（F3） ----------------
const stats = reactive({ pending: 0, sent: 0, cancelled: 0, today_pending: 0, plan_customers: 0, reached_customers: 0, coverage_rate: 0 })
const coverageClass = computed(() => {
	const r = Number(stats.coverage_rate) || 0
	if (r >= 100) return 'text-success'
	if (r >= 80) return 'text-warning'
	return 'text-danger'
})

const loadStats = async () => {
	try {
		const res = await api.visitReminder.stats()
		Object.assign(stats, res.data || {})
	} catch {
		// 统计失败不阻塞列表
	}
}

const formVisible = ref(false)
const form = reactive({ employee_id: null, customer_id: null, visit_date: '', content: '' })

const load = async () => {
	loading.value = true
	try {
		const res = await api.visitReminder.list({
			page: page.value,
			page_size: pageSize.value,
			keyword: filters.keyword,
			status: filters.status,
		})
		list.value = res.data?.list || []
		total.value = res.data?.total || 0
		selection.value = []
	} finally {
		loading.value = false
	}
}

const onSearch = () => {
	page.value = 1
	load()
}

const resetFilters = () => {
	filters.keyword = ''
	filters.status = ''
	page.value = 1
	load()
}

const onSelectionChange = (rows) => {
	selection.value = rows
}

const loadOptions = async () => {
	const [empRes, custRes] = await Promise.all([
		authApi.user.list.get({ page_size: 9999 }),
		businessApi.customer.list.get({ page_size: 9999 }),
	])
	employees.value = empRes.data?.list || []
	customers.value = custRes.data?.list || []
}

const openForm = () => {
	formVisible.value = true
}

const resetForm = () => {
	form.employee_id = null
	form.customer_id = null
	form.visit_date = ''
	form.content = ''
}

const submitForm = async () => {
	if (!form.employee_id) {
		ElMessage.warning('请选择业务员')
		return
	}
	if (!form.visit_date) {
		ElMessage.warning('请选择计划拜访日期')
		return
	}
	await api.visitReminder.create({
		employee_id: form.employee_id,
		customer_id: form.customer_id || null,
		visit_date: form.visit_date,
		content: form.content || null,
	})
	ElMessage.success('提醒已创建')
	formVisible.value = false
	onSearch()
	loadStats()
}

const onSend = async (row) => {
	await ElMessageBox.confirm(`确认向业务员「${row.employee}」发送该拜访提醒？`, '发送提醒', { type: 'warning' })
	await api.visitReminder.send(row.id)
	ElMessage.success('已提醒')
	load()
	loadStats()
}

/** 批量提醒（F3）：站内信 + 短信占位，仅 pending 可发，其余跳过。 */
const batchSending = ref(false)
const onBatchSend = async () => {
	const pendingIds = selection.value.filter((r) => r.status === 'pending').map((r) => r.id)
	if (!pendingIds.length) {
		ElMessage.warning('请选择待提醒的记录')
		return
	}
	await ElMessageBox.confirm(`确认批量提醒 ${pendingIds.length} 条待提醒记录？（站内信 + 短信占位）`, '批量提醒', { type: 'warning' })
	batchSending.value = true
	try {
		const res = await api.visitReminder.batchSend(pendingIds)
		const { sent = 0, skipped = 0 } = res.data || {}
		ElMessage.success(`已提醒 ${sent} 条${skipped ? `，跳过 ${skipped} 条（非待提醒）` : ''}`)
		load()
		loadStats()
	} finally {
		batchSending.value = false
	}
}

const onCancel = async (row) => {
	await ElMessageBox.confirm('确定作废该提醒？已提醒的记录不可作废。', '作废提醒', { type: 'warning' })
	await api.visitReminder.cancel(row.id)
	ElMessage.success('已作废')
	load()
	loadStats()
}

const detailVisible = ref(false)
const detail = reactive({ remind_no: '', employee: '', customer: '', visit_date: '', content: '', status: '', sent_at: '', history: [] })

const openDetail = async (row) => {
	detailVisible.value = true
	const res = await api.visitReminder.detail(row.id)
	Object.assign(detail, res.data || {})
}

onMounted(() => {
	load()
	loadStats()
	loadOptions()
})
</script>

<style scoped>
/* 沿用 perf 域视觉规范：表体 14px #606266，行高 48px，表头 #F5F7FA */
.perf-list {
	height: 100%;
	display: flex;
	flex-direction: column;
	overflow: auto;
}
.perf-table {
	flex: 1;
	margin: 12px 20px 0;
}
.perf-pager {
	display: flex;
	justify-content: flex-end;
	padding: 12px 20px;
}
/* 4 KPI 卡（沿用 perf/stat-card 视觉） */
.stat-cards {
	display: flex;
	gap: 12px;
	padding: 16px 20px 0;
}
.stat-card {
	flex: 1;
	padding: 16px;
	border-radius: 8px;
	background: var(--el-fill-color-light);
	text-align: center;
}
.stat-label {
	font-size: 13px;
	color: var(--el-text-color-secondary);
}
.stat-value {
	font-size: 24px;
	font-weight: 700;
	margin-top: 6px;
	line-height: 1.2;
	font-variant-numeric: tabular-nums;
}
.stat-unit {
	font-size: 14px;
	font-weight: 600;
	margin-left: 2px;
}
.stat-sub {
	font-size: 12px;
	color: var(--el-text-color-secondary);
	margin-top: 4px;
}
.text-primary {
	color: #409eff;
}
.text-success {
	color: #67c23a;
}
.text-warning {
	color: #e6a23c;
}
.text-danger {
	color: #f56c6c;
}
.detail-sub-title {
	margin: 16px 0 8px;
	font-size: 14px;
	font-weight: 600;
	color: #303133;
}
.history-action {
	font-weight: 600;
	color: #303133;
	margin-right: 8px;
}
.history-operator {
	color: #909399;
}
.history-detail {
	font-size: 13px;
	color: #606266;
	margin-top: 2px;
}
.history-remark {
	font-size: 13px;
	color: #909399;
	margin-top: 2px;
}
</style>
