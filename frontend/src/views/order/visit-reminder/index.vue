<template>
	<div class="perf-list">
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
		</div>

		<!-- 列表 -->
		<el-table
			v-loading="loading"
			:data="list"
			class="perf-table"
			height="100%"
			:header-cell-style="{ background: '#F5F7FA', color: '#606266', fontWeight: 600 }"
		>
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
import { onMounted, reactive, ref } from 'vue'
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
}

const onSend = async (row) => {
	await ElMessageBox.confirm(`确认向业务员「${row.employee}」发送该拜访提醒？`, '发送提醒', { type: 'warning' })
	await api.visitReminder.send(row.id)
	ElMessage.success('已提醒')
	load()
}

const onCancel = async (row) => {
	await ElMessageBox.confirm('确定作废该提醒？已提醒的记录不可作废。', '作废提醒', { type: 'warning' })
	await api.visitReminder.cancel(row.id)
	ElMessage.success('已作废')
	load()
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
	loadOptions()
})
</script>

<style scoped>
/* 沿用 perf 域视觉规范：表体 14px #606266，行高 48px，表头 #F5F7FA */
.perf-table {
	flex: 1;
	margin: 12px 20px 0;
}
.perf-pager {
	display: flex;
	justify-content: flex-end;
	padding: 12px 20px;
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
