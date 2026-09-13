<template>
	<sPageSplit side-title="业务员行程">
		<div class="toolbar">
			<el-form :model="searchForm" inline>
				<el-form-item label="日期">
					<el-date-picker v-model="searchForm.date" type="date" placeholder="选择日期" value-format="YYYY-MM-DD" style="width:140px" />
				</el-form-item>
				<el-form-item>
					<el-button type="primary" @click="handleSearch">查询</el-button>
				</el-form-item>
			</el-form>
		</div>

		<div class="schedule-list">
			<el-card v-for="emp in scheduleData" :key="emp.employee_id" class="schedule-card" shadow="hover">
				<template #header>
					<div class="card-header">
						<span class="employee-name">{{ emp.employee_name }}</span>
						<el-tag size="small">{{ emp.visits.length }} 个拜访</el-tag>
					</div>
				</template>
				<el-timeline>
					<el-timeline-item
						v-for="(visit, index) in emp.visits"
						:key="visit.id"
						:timestamp="formatTime(visit.checkin_time)"
						:type="index === 0 ? 'primary' : ''"
						:icon="index === 0 ? 'Location' : ''"
					>
						<el-card shadow="never">
							<div class="visit-info">
								<span class="customer-name">{{ visit.customer_name }}</span>
								<el-tag v-if="visit.visit_result" size="small" :type="getResultType(visit.visit_result)">{{ visit.visit_result }}</el-tag>
							</div>
							<div class="visit-address" v-if="visit.address">
								<el-icon><Location /></el-icon>
								<span>{{ visit.address }}</span>
							</div>
							<div class="visit-duration" v-if="visit.visit_duration">
								<el-icon><Clock /></el-icon>
								<span>时长: {{ visit.visit_duration }} 分钟</span>
							</div>
						</el-card>
					</el-timeline-item>
				</el-timeline>
			</el-card>
		</div>
	</sPageSplit>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import businessApi from '@/api/business'

const searchForm = ref({
	date: '',
})

const scheduleData = ref([])

const formatTime = (time) => {
	if (!time) return ''
	const d = new Date(time)
	return d.toLocaleTimeString('zh-CN', { hour: '2-digit', minute: '2-digit' })
}

const getResultType = (result) => {
	const map = { '正常': 'success', '客户不在': 'warning', '拒绝拜访': 'danger' }
	return map[result] || ''
}

const handleSearch = async () => {
	const res = await businessApi.visit.schedule.get({ date: searchForm.value.date })
	if (res.code === 200) {
		scheduleData.value = res.data || []
	}
}

onMounted(() => {
	searchForm.value.date = new Date().toISOString().split('T')[0]
	handleSearch()
})
</script>

<style scoped>
.toolbar { margin-bottom: 12px; }
.schedule-list { display: flex; flex-direction: column; gap: 16px; }
.schedule-card { }
.card-header { display: flex; justify-content: space-between; align-items: center; }
.employee-name { font-size: 16px; font-weight: bold; }
.visit-info { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
.customer-name { font-weight: bold; }
.visit-address, .visit-duration { display: flex; align-items: center; gap: 4px; color: #909399; font-size: 12px; margin-top: 4px; }
</style>
