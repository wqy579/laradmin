<template>
	<sPageSplit side-title="拜访达成率">
		<div class="toolbar">
			<el-form :model="searchForm" inline>
				<el-form-item label="开始日期">
					<el-date-picker v-model="searchForm.date_start" type="date" placeholder="选择开始日期" value-format="YYYY-MM-DD" style="width:140px" />
				</el-form-item>
				<el-form-item label="结束日期">
					<el-date-picker v-model="searchForm.date_end" type="date" placeholder="选择结束日期" value-format="YYYY-MM-DD" style="width:140px" />
				</el-form-item>
				<el-form-item>
					<el-button type="primary" @click="handleSearch">查询</el-button>
				</el-form-item>
			</el-form>
		</div>

		<sTable
			ref="tableRef"
			tableName="business_visit_achievement"
			:data="data"
			:columns="columns"
			:loading="loading"
			:total="total"
			:currentPage="paginationProps.currentPage"
			:pageSize="paginationProps.pageSize"
			:pageSizes="paginationProps.pageSizes"
			rowKey="employee_id"
			height="100%"
			stripe
		>
			<template #achievement="{ row }">
				<el-progress :percentage="row.achievement" :color="getProgressColor(row.achievement)" :stroke-width="12" />
			</template>
		</sTable>
	</sPageSplit>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import { useTable } from '@/hooks/useTable'
import businessApi from '@/api/business'
import sPageSplit from '@/components/sPageSplit/index.vue'

const searchForm = ref({
	date_start: '',
	date_end: '',
})

const { tableRef, data, total, loading, paginationProps } = useTable({
	apiObj: { get: (params) => businessApi.visit.achievement.get(params) },
	searchForm: searchForm.value,
})

const columns = [
	{ prop: 'employee_name', title: '员工姓名', width: 120 },
	{ prop: 'total_customers', title: '负责客户数', width: 100, align: 'center' },
	{ prop: 'visited_customers', title: '已拜访数', width: 100, align: 'center' },
	{ prop: 'achievement', title: '达成率', width: 200, slots: { default: 'achievement' } },
]

const getProgressColor = (percent) => {
	if (percent >= 80) return '#67c23a'
	if (percent >= 50) return '#e6a23c'
	return '#f56c6c'
}

const handleSearch = () => {
	refresh()
}

onMounted(() => {
	// 默认查询本月
	const now = new Date()
	searchForm.value.date_start = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-01`
	searchForm.value.date_end = now.toISOString().split('T')[0]
	refresh()
})
</script>

<style scoped>
.toolbar { margin-bottom: 12px; }
</style>
