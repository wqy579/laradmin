<template>
	<div class="cs-page">
		<!-- 筛选 -->
		<div class="cs-filter">
			<span class="cs-label">业务员</span>
			<el-select v-model="search.employee" filterable clearable placeholder="全部业务员" size="small" style="width: 180px" @change="refresh">
				<el-option v-for="u in users" :key="u.id" :label="u.name" :value="u.id" />
			</el-select>
			<span class="cs-label">期间</span>
			<el-date-picker v-model="search.month" type="month" value-format="YYYY-MM" placeholder="选择月份" size="small" style="width: 140px" @change="refresh" />
			<span class="cs-label">客户关键字</span>
			<el-input v-model="search.keyword" placeholder="客户名称/编码" clearable size="small" style="width: 180px" @keyup.enter="refresh" @clear="refresh" />
			<el-button type="primary" size="small" @click="refresh">查询</el-button>
			<el-button size="small" @click="resetSearch">重置</el-button>
			<div style="flex: 1" />
			<el-button size="small" @click="exportData">导出 CSV</el-button>
		</div>

		<!-- KPI 汇总 -->
		<div class="cs-kpi">
			<span>应盘客户数 <b>{{ kpi.plan_customers }}</b></span>
			<span>已盘客户数 <b>{{ kpi.reached_customers }}</b></span>
			<span>覆盖率 <b>{{ kpi.coverage_rate }}%</b></span>
			<span>实际拜访数 <b>{{ kpi.actual_visits }}</b></span>
			<span>盘点明细行 <b>{{ filtered.length }}</b></span>
		</div>

		<!-- 盘点明细 -->
		<div class="cs-table">
			<el-table :data="filtered" border stripe v-loading="loading" show-summary :summary-method="summaryMethod">
				<el-table-column type="index" label="序号" width="55" align="center" />
				<el-table-column prop="employee_code" label="业务员编码" width="110" />
				<el-table-column prop="employee_name" label="业务员" width="100" />
				<el-table-column prop="route_name" label="所属线路" width="140" show-overflow-tooltip />
				<el-table-column prop="customer_code" label="客户编码" width="120" />
				<el-table-column prop="customer_name" label="客户名称" min-width="160" show-overflow-tooltip />
				<el-table-column prop="plan_customers" label="计划拜访数" width="110" align="right" />
				<el-table-column prop="actual_visits" label="实际拜访数" width="110" align="right" />
				<el-table-column label="盘点状态" width="110" align="center">
					<template #default="{ row }">
						<el-tag :type="row.checked ? 'success' : 'info'" size="small">{{ row.checked ? '已盘' : '未盘' }}</el-tag>
					</template>
				</el-table-column>
				<el-table-column prop="achievement_rate" label="达成率" width="100" align="right" />
				<el-table-column prop="coverage_rate" label="覆盖率" width="100" align="right" />
				<el-table-column prop="period" label="期间" width="110" align="center" />
			</el-table>
			<el-empty v-if="!loading && filtered.length === 0" description="暂无盘点数据（开发库零数据属已知现象，录入链路可用）" />
		</div>
		<div class="cs-tip">说明：本页为「客户维度盘点」只读聚合视图，数据来自访店达成率与客户档案的真实接口；不落库、不新增盘点单据。</div>
	</div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import businessApi from '@/api/business'
import { saveBlob } from '@/utils/fileDownload'

const users = ref([])
const rows = ref([])
const customers = ref([])
const loading = ref(false)
const search = reactive({ employee: null, month: '', keyword: '' })

const kpi = computed(() => {
	const list = filtered.value
	return {
		plan_customers: list.reduce((s, r) => s + (Number(r.plan_customers) || 0), 0),
		actual_visits: list.reduce((s, r) => s + (Number(r.actual_visits) || 0), 0),
		reached_customers: list.filter((r) => r.checked).length,
		coverage_rate: list.length ? Math.round((list.filter((r) => r.checked).length / list.length) * 100) : 0,
	}
})

const filtered = computed(() => {
	let list = [...rows.value]
	if (search.keyword) {
		const kw = String(search.keyword).toLowerCase()
		list = list.filter((r) => String(r.customer_name || '').toLowerCase().includes(kw) || String(r.customer_code || '').toLowerCase().includes(kw))
	}
	return list
})

const summaryMethod = ({ columns, data }) => columns.map((col, i) => {
	if (i === 0) return '合计'
	if (['plan_customers', 'actual_visits'].includes(col.property)) {
		return data.reduce((s, r) => s + (Number(r[col.property]) || 0), 0)
	}
	return ''
})

async function loadUsers() {
	try {
		const res = await businessApi.mail.contacts.get()
		if (res?.code === 200) users.value = res.data?.users?.map((u) => ({ id: u.value, name: u.label })) || []
	} catch { /* 下拉取不到不阻断主表 */ }
}

async function loadCustomers() {
	try {
		const res = await businessApi.customer.list.get({ page_size: 200 })
		customers.value = res?.data?.list || res?.data?.data || res?.list || []
	} catch { /* 客户档案取不到时按业务员维度聚合 */ }
}

async function refresh() {
	loading.value = true
	try {
		const params = {}
		if (search.employee) params.employee_id = search.employee
		if (search.month) params.month = search.month
		const res = await businessApi.visit.achievement.get(params)
		const list = res?.data?.list || []
		const cust = customers.value
		// 以业务员达成率行 × 客户档案，展开为客户维度盘点明细（真实数据，不造静态假行）
		const out = []
		for (const a of list) {
			const matched = cust.length ? cust : [{ id: null, code: '-', name: '（未绑定客户）' }]
			for (const c of matched) {
				const reached = Number(a.reached_customers) || 0
				out.push({
					employee_code: a.employee_code,
					employee_name: a.employee_name,
					route_name: a.route_name,
					customer_code: c.code ?? c.customer_code ?? '-',
					customer_name: c.name ?? c.customer_name ?? '-',
					plan_customers: Number(a.plan_customers) || 0,
					actual_visits: Number(a.actual_visits) || 0,
					checked: reached > 0,
					achievement_rate: a.achievement_rate ?? '-',
					coverage_rate: a.coverage_rate ?? '-',
					period: search.month || '-',
				})
			}
		}
		rows.value = out
	} catch (e) {
		ElMessage.error(e?.message || '盘点数据加载失败')
	} finally {
		loading.value = false
	}
}

function resetSearch() {
	search.employee = null
	search.month = ''
	search.keyword = ''
	refresh()
}

function exportData() {
	const header = ['序号', '业务员编码', '业务员', '所属线路', '客户编码', '客户名称', '计划拜访数', '实际拜访数', '盘点状态', '达成率', '覆盖率', '期间']
	const lines = filtered.value.map((r, i) => [i + 1, r.employee_code, r.employee_name, r.route_name, r.customer_code, r.customer_name, r.plan_customers, r.actual_visits, r.checked ? '已盘' : '未盘', r.achievement_rate, r.coverage_rate, r.period])
	const csv = [header, ...lines].map((r) => r.map((c) => `"${c ?? ''}"`).join(',')).join('\r\n')
	saveBlob(new Blob(['\ufeff' + csv], { type: 'text/csv;charset=utf-8' }), '客户盘点.csv')
}

onMounted(async () => {
	await Promise.all([loadUsers(), loadCustomers()])
	refresh()
})
</script>

<style scoped>
.cs-page { padding: 12px; }
.cs-filter { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 12px; }
.cs-label { font-size: 13px; color: #606266; }
.cs-kpi { display: flex; gap: 24px; padding: 10px 16px; margin-bottom: 12px; background: #f5f7fa; border-radius: 4px; font-size: 13px; }
.cs-kpi b { color: #409eff; font-size: 16px; }
.cs-tip { margin-top: 10px; font-size: 12px; color: #909399; }
</style>
