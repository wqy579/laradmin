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
			<el-button type="primary" size="small" @click="openDialog">发放积分</el-button>
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
				<el-table-column label="积分数量" width="120" align="right">
					<template #default="{ row }">
						<span class="tabular pts-num--up">+{{ row.points }}</span>
					</template>
				</el-table-column>
				<el-table-column prop="sourceOrder" label="来源单号" width="150" align="center">
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

		<!-- 发放弹窗 -->
		<el-dialog v-model="dialogVisible" title="积分发放" width="600px" :close-on-click-modal="false" destroy-on-close>
			<el-form ref="grantFormRef" :model="grantForm" :rules="rules" label-width="110px" label-position="right">
				<el-form-item label="客户" prop="customerId">
					<el-select v-model="grantForm.customerId" filterable placeholder="选择客户" size="small" style="width: 100%">
						<el-option v-for="c in customers" :key="c.id" :label="`${c.name}（${c.code}）`" :value="c.id" />
					</el-select>
				</el-form-item>
				<el-form-item label="积分数量" prop="points">
					<el-input-number v-model="grantForm.points" :min="1" :step="100" size="small" style="width: 200px" />
				</el-form-item>
				<el-form-item label="来源单号" prop="sourceOrder">
					<el-input v-model="grantForm.sourceOrder" placeholder="关联销售单号（选填）" maxlength="30" style="width: 100%" />
				</el-form-item>
				<el-form-item label="事由" prop="remark">
					<el-input v-model="grantForm.remark" type="textarea" :rows="2" maxlength="200" show-word-limit placeholder="请输入发放事由" style="width: 100%" />
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
import { MOCK_CUSTOMERS, MOCK_TXN, customerName } from '../../../mock/points'

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
const grantFormRef = ref(null)

const grantForm = reactive({
	customerId: null,
	points: 100,
	sourceOrder: '',
	remark: '',
})

const rules = {
	customerId: [{ required: true, message: '请选择客户', trigger: 'change' }],
	points: [{ required: true, type: 'number', message: '请输入积分数量', trigger: 'blur' }],
	remark: [{ required: true, message: '请输入发放事由', trigger: 'blur' }],
}

// 只取发放流水（JF 前缀）
const filtered = computed(() => {
	let list = MOCK_TXN.filter((t) => t.type === 'grant').map((t) => ({
		no: t.no,
		date: t.date,
		customerId: t.customerId,
		customerName: customerName(t.customerId),
		points: t.points,
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

function openDialog() {
	Object.assign(grantForm, { customerId: null, points: 100, sourceOrder: '', remark: '' })
	dialogVisible.value = true
}

function save() {
	grantFormRef.value.validate((valid) => {
		if (!valid) return
		if (grantForm.points < 1) {
			ElMessage.warning('积分数量必须大于 0')
			return
		}
		saving.value = true
		// TODO(api): 替换为 integral_txn.save 接口（单据号前缀 JF）
		setTimeout(() => {
			saving.value = false
			dialogVisible.value = false
			ElMessage.success(`已向 ${customerName(grantForm.customerId)} 发放 ${grantForm.points} 积分`)
		}, 300)
	})
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
.pts-num--up {
	color: var(--color-danger);
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
.pts-pager {
	display: flex;
	justify-content: flex-end;
	padding: 12px 16px 0;
}
</style>
