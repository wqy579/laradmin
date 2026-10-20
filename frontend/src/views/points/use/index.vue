<template>
	<div class="pts-page">
		<!-- 筛选 -->
		<div class="pts-filter">
			<span class="pts-filter__label">客户</span>
			<el-input v-model="search.keyword" placeholder="客户名称/编码" clearable size="small" style="width: 200px" @keyup.enter="refresh" @clear="refresh" />
			<span class="pts-filter__label">单据日期</span>
			<el-date-picker v-model="dateRange" type="daterange" value-format="YYYY-MM-DD" range-separator="-" start-placeholder="开始日期" end-placeholder="结束日期" size="small" style="width: 240px" @change="refresh" />
			<el-button type="primary" size="small" @click="refresh">查询</el-button>
			<el-button size="small" @click="resetSearch">重置</el-button>
			<div style="flex: 1" />
			<el-button type="primary" size="small" @click="openDialog">核销积分</el-button>
		</div>

		<!-- 列表 -->
		<div class="pts-table">
			<el-table :data="pagedList" border v-loading="loading">
				<el-table-column type="index" label="序号" width="50" align="center" />
				<el-table-column prop="no" label="单据号" width="150" align="center">
					<template #default="{ row }">
						<span class="tabular">{{ row.no }}</span>
					</template>
				</el-table-column>
				<el-table-column prop="date" label="单据日期" width="120" align="center">
					<template #default="{ row }">
						<span class="tabular">{{ row.date }}</span>
					</template>
				</el-table-column>
				<el-table-column prop="customerName" label="客户" min-width="180" show-overflow-tooltip />
				<el-table-column label="核销积分" width="120" align="right">
					<template #default="{ row }">
						<span class="tabular pts-num--down">{{ row.points }}</span>
					</template>
				</el-table-column>
				<el-table-column prop="afterBalance" label="核销后余额" width="120" align="right">
					<template #default="{ row }">
						<span class="tabular">{{ row.afterBalance }}</span>
					</template>
				</el-table-column>
				<el-table-column prop="sourceOrder" label="关联单号" width="150" align="center">
					<template #default="{ row }">
						<span class="tabular pts-link" @click="goQuery(row)">{{ row.sourceOrder }}</span>
					</template>
				</el-table-column>
				<el-table-column prop="remark" label="事由" min-width="200" show-overflow-tooltip />
				<el-table-column label="操作" width="70" align="center">
					<template #default="{ row }">
						<el-button link type="primary" size="small" @click="goQuery(row)">查看</el-button>
					</template>
				</el-table-column>
			</el-table>
		</div>

		<!-- 分页 -->
		<div class="pts-pager">
			<el-pagination background layout="total, prev, pager, next, sizes" :total="total" :page-size="pageSize" :current-page="page" :page-sizes="[10, 20, 50]" @current-change="onPageChange" @size-change="onSizeChange" />
		</div>

		<!-- 核销弹窗 -->
		<el-dialog v-model="dialogVisible" title="积分核销" width="600px" :close-on-click-modal="false" destroy-on-close>
			<el-form ref="useFormRef" :model="useForm" :rules="rules" label-width="110px" label-position="right">
				<el-form-item label="客户" prop="customerId">
					<el-select v-model="useForm.customerId" filterable placeholder="选择客户" size="small" style="width: 100%" @change="onCustomerChange">
						<el-option v-for="c in customers" :key="c.id" :label="`${c.name}（${c.code}）`" :value="c.id" />
					</el-select>
				</el-form-item>
				<el-form-item label="可用积分">
					<span class="tabular pts-balance">可用 {{ available }}</span>
				</el-form-item>
				<el-form-item label="核销积分" prop="points">
					<el-input-number v-model="useForm.points" :min="1" :max="available || 999999" :step="100" size="small" style="width: 200px" />
				</el-form-item>
				<el-form-item label="关联单号" prop="sourceOrder">
					<el-input v-model="useForm.sourceOrder" placeholder="关联销售单号（选填）" maxlength="30" style="width: 100%" />
				</el-form-item>
				<el-form-item label="事由" prop="remark">
					<el-input v-model="useForm.remark" type="textarea" :rows="2" maxlength="200" show-word-limit placeholder="请输入核销事由" style="width: 100%" />
				</el-form-item>
			</el-form>
			<template #footer>
				<el-button size="small" @click="dialogVisible = false">取消</el-button>
				<el-button type="primary" size="small" :loading="saving" @click="save">确定</el-button>
			</template>
		</el-dialog>
	</div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import { MOCK_CUSTOMERS, MOCK_TXN, customerName, availablePoints } from '../../../mock/points'

const router = useRouter()
const customers = MOCK_CUSTOMERS
const search = reactive({ keyword: '' })
const dateRange = ref([])
const total = ref(0)
const page = ref(1)
const pageSize = ref(20)
const loading = ref(false)
const saving = ref(false)
const dialogVisible = ref(false)
const useFormRef = ref(null)

const useForm = reactive({
	customerId: null,
	points: 100,
	sourceOrder: '',
	remark: '',
})

function validatePoints(_rule, value, callback) {
	if (!value || value < 1) {
		callback(new Error('请输入核销积分'))
	} else if (value > available.value) {
		callback(new Error(`核销积分不能超过可用积分 ${available.value}`))
	} else {
		callback()
	}
}

const rules = {
	customerId: [{ required: true, message: '请选择客户', trigger: 'change' }],
	points: [{ required: true, validator: validatePoints, trigger: 'blur' }],
	remark: [{ required: true, message: '请输入核销事由', trigger: 'blur' }],
}

// 当前所选客户的可用积分
const available = computed(() => {
	if (!useForm.customerId) return 0
	return availablePoints(useForm.customerId)
})

function onCustomerChange() {
	// 切换客户后可用积分变化，重算核销积分校验
	useFormRef.value?.clearValidate('points')
}

function openDialog() {
	Object.assign(useForm, { customerId: null, points: 100, sourceOrder: '', remark: '' })
	dialogVisible.value = true
}

function save() {
	useFormRef.value.validate((valid) => {
		if (!valid) return
		saving.value = true
		// TODO(api): 替换为 integral_txn.save 接口（单据号前缀 HX）
		setTimeout(() => {
			saving.value = false
			dialogVisible.value = false
			ElMessage.success(`已核销 ${customerName(useForm.customerId)} 积分 ${useForm.points}`)
		}, 300)
	})
}

// 只取核销流水（HX 前缀）
const filtered = computed(() => {
	let list = MOCK_TXN.filter((t) => t.type === 'use').map((t) => ({
		no: t.no,
		date: t.date,
		customerId: t.customerId,
		customerName: customerName(t.customerId),
		points: t.points,
		afterBalance: t.balanceAfter,
		sourceOrder: t.sourceOrder,
		remark: t.remark,
	}))
	const kw = search.keyword.trim().toLowerCase()
	if (kw) {
		list = list.filter((r) => r.customerName.toLowerCase().includes(kw) || String(r.customerId).toLowerCase().includes(kw))
	}
	if (dateRange.value && dateRange.value.length === 2) {
		const [s, e] = dateRange.value
		list = list.filter((r) => r.date >= s && r.date <= e)
	}
	return list.sort((a, b) => (a.no < b.no ? 1 : -1))
})

const pagedList = computed(() => {
	const start = (page.value - 1) * pageSize.value
	return filtered.value.slice(start, start + pageSize.value)
})

function refresh() {
	loading.value = true
	// TODO(api): 替换为 integral_txn.list 接口
	setTimeout(() => {
		loading.value = false
		total.value = filtered.value.length
	}, 200)
}

function resetSearch() {
	search.keyword = ''
	dateRange.value = []
	page.value = 1
	refresh()
}

function onPageChange(p) {
	page.value = p
}

function onSizeChange(s) {
	pageSize.value = s
	page.value = 1
}

function goQuery(row) {
	router.push({ path: '/points/query', query: { customer: row.customerId } })
}

onMounted(refresh)
</script>

<style scoped>
.pts-page {
	display: flex;
	flex-direction: column;
	height: 100%;
}
.pts-filter {
	display: flex;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
	padding: 12px 16px;
	background: var(--bg-card);
	border-bottom: 1px solid var(--border-lighter);
}
.pts-filter__label {
	font-size: var(--font-base);
	color: var(--text-regular);
	white-space: nowrap;
}
.pts-table {
	flex: 1;
	padding: 0 16px;
	display: flex;
	flex-direction: column;
	min-height: 0;
}
.pts-table :deep(.el-table) {
	flex: 1;
}
.pts-table :deep(.el-table th.el-table__cell) {
	background: var(--bg-hover);
	color: var(--text-regular);
	font-weight: 600;
	font-size: var(--font-base);
}
.pts-table :deep(.el-table td.el-table__cell) {
	color: var(--text-primary);
	font-size: var(--font-base);
}
.pts-table :deep(.el-table__row) {
	height: 40px;
}
.pts-num--down {
	color: var(--color-success);
	font-weight: 600;
	font-family: var(--font-numeric);
	font-variant-numeric: tabular-nums;
}
.pts-link {
	color: var(--color-primary);
	cursor: pointer;
}
.pts-link:hover {
	text-decoration: underline;
}
.pts-balance {
	font-family: var(--font-numeric);
	font-variant-numeric: tabular-nums;
	color: var(--color-primary);
	font-weight: 600;
	line-height: 28px;
}
.pts-pager {
	display: flex;
	justify-content: flex-end;
	padding: 12px 16px 0;
}
</style>
