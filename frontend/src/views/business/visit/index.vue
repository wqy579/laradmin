<template>
	<sPageSplit side-title="拜访明细">
		<div class="toolbar">
			<el-form :model="searchForm" inline>
				<el-form-item label="员工">
					<el-select v-model="searchForm.employee_id" placeholder="请选择" clearable style="width:120px">
						<el-option v-for="e in employees" :key="e.id" :label="e.real_name" :value="e.id" />
					</el-select>
				</el-form-item>
				<el-form-item label="线路">
					<el-select v-model="searchForm.route_id" placeholder="请选择" clearable style="width:120px">
						<el-option v-for="r in routes" :key="r.id" :label="r.name" :value="r.id" />
					</el-select>
				</el-form-item>
				<el-form-item label="日期">
					<el-date-picker v-model="searchForm.date_range" type="daterange" range-separator="至" start-placeholder="开始" end-placeholder="结束" value-format="YYYY-MM-DD" style="width:200px" />
				</el-form-item>
				<el-form-item label="状态">
					<el-select v-model="searchForm.status" placeholder="请选择" clearable style="width:100px">
						<el-option label="待拜访" :value="1" />
						<el-option label="已拜访" :value="2" />
					</el-select>
				</el-form-item>
				<el-form-item>
					<el-button type="primary" @click="handleSearch">搜索</el-button>
					<el-button @click="handleReset">重置</el-button>
				</el-form-item>
			</el-form>
			<div class="right-panel">
				<el-button type="primary" @click="handleAdd">新增拜访</el-button>
				<el-button @click="handleExport">导出</el-button>
			</div>
		</div>

		<sTable
			ref="tableRef"
			tableName="business_visit_logs"
			:data="data"
			:columns="columns"
			:loading="loading"
			:total="total"
			:currentPage="paginationProps.currentPage"
			:pageSize="paginationProps.pageSize"
			:pageSizes="paginationProps.pageSizes"
			rowKey="id"
			height="100%"
			stripe
			@refresh="refresh"
			@pageChange="handlePageChange"
			@pageSizeChange="handlePageSizeChange"
		>
			<template #employee_name="{ row }">
				<span>{{ row.employee?.real_name || '-' }}</span>
			</template>
			<template #customer_name="{ row }">
				<span>{{ row.customer?.name || '-' }}</span>
			</template>
			<template #route_name="{ row }">
				<span>{{ row.route?.name || '-' }}</span>
			</template>
			<template #status="{ row }">
				<el-tag v-if="row.status === 1" type="info" size="small">待拜访</el-tag>
				<el-tag v-else-if="row.status === 2" type="success" size="small">已拜访</el-tag>
				<el-tag v-else size="small">未知</el-tag>
			</template>
			<template #visit_result="{ row }">
				<el-tag v-if="row.visit_result === '正常'" type="success" size="small">正常</el-tag>
				<el-tag v-else-if="row.visit_result === '客户不在'" type="warning" size="small">客户不在</el-tag>
				<el-tag v-else-if="row.visit_result === '拒绝拜访'" type="danger" size="small">拒绝拜访</el-tag>
				<el-tag v-else size="small">{{ row.visit_result || '-' }}</el-tag>
			</template>
			<template #action_default="{ row }">
				<el-button type="primary" link size="small" @click="handleEdit(row)">编辑</el-button>
				<el-button type="danger" link size="small" @click="handleDelete(row)">删除</el-button>
			</template>
		</sTable>

		<el-dialog v-model="dialogVisible" :title="dialogType === 'add' ? '新增拜访' : '编辑拜访'" width="600px" destroy-on-close @close="formRef?.resetFields()">
			<el-form ref="formRef" :model="form" :rules="rules" label-width="100px">
				<el-form-item label="员工" prop="employee_id">
					<el-select v-model="form.employee_id" placeholder="请选择员工" style="width:100%">
						<el-option v-for="e in employees" :key="e.id" :label="e.real_name" :value="e.id" />
					</el-select>
				</el-form-item>
				<el-form-item label="客户" prop="customer_id">
					<el-select v-model="form.customer_id" placeholder="请选择客户" style="width:100%">
						<el-option v-for="c in customers" :key="c.id" :label="c.name" :value="c.id" />
					</el-select>
				</el-form-item>
				<el-form-item label="线路">
					<el-select v-model="form.route_id" placeholder="请选择线路" clearable style="width:100%">
						<el-option v-for="r in routes" :key="r.id" :label="r.name" :value="r.id" />
					</el-select>
				</el-form-item>
				<el-form-item label="签到时间" prop="checkin_time">
					<el-date-picker v-model="form.checkin_time" type="datetime" placeholder="选择签到时间" value-format="YYYY-MM-DD HH:mm:ss" style="width:100%" />
				</el-form-item>
				<el-form-item label="签到地址">
					<el-input v-model="form.checkin_address" placeholder="请输入签到地址" maxlength="255" />
				</el-form-item>
				<el-form-item label="签到照片">
					<el-upload action="#" list-type="picture-card" :auto-upload="false">
						<el-icon><Plus /></el-icon>
					</el-upload>
				</el-form-item>
				<el-form-item label="签退时间">
					<el-date-picker v-model="form.checkout_time" type="datetime" placeholder="选择签退时间" value-format="YYYY-MM-DD HH:mm:ss" style="width:100%" />
				</el-form-item>
				<el-form-item label="拜访结果" prop="visit_result">
					<el-select v-model="form.visit_result" placeholder="请选择拜访结果" style="width:100%">
						<el-option label="正常" value="正常" />
						<el-option label="客户不在" value="客户不在" />
						<el-option label="拒绝拜访" value="拒绝拜访" />
						<el-option label="其他" value="其他" />
					</el-select>
				</el-form-item>
				<el-form-item label="备注">
					<el-input v-model="form.remark" type="textarea" :rows="2" placeholder="请输入备注" />
				</el-form-item>
				<el-form-item label="状态" prop="status">
					<el-radio-group v-model="form.status">
						<el-radio :value="1">待拜访</el-radio>
						<el-radio :value="2">已拜访</el-radio>
					</el-radio-group>
				</el-form-item>
			</el-form>
			<template #footer>
				<el-button @click="dialogVisible = false">取消</el-button>
				<el-button type="primary" :loading="submitting" @click="handleSubmit">确定</el-button>
			</template>
		</el-dialog>
	</sPageSplit>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import { useTable } from '@/hooks/useTable'
import businessApi from '@/api/business'
import sPageSplit from '@/components/sPageSplit/index.vue'

const searchForm = ref({
	employee_id: null,
	route_id: null,
	date_range: [],
	status: null,
})

const { tableRef, data, total, loading, paginationProps, refresh, handlePageChange, handlePageSizeChange } = useTable({
	apiObj: { get: (params) => businessApi.visit.log.list.get(params) },
	searchForm: searchForm.value,
})

const employees = ref([])
const customers = ref([])
const routes = ref([])
const dialogVisible = ref(false)
const dialogType = ref('add')
const formRef = ref(null)
const submitting = ref(false)
const form = ref({
	employee_id: null,
	customer_id: null,
	route_id: null,
	checkin_time: '',
	checkin_address: '',
	checkout_time: '',
	visit_result: '正常',
	remark: '',
	status: 1,
})
const rules = {
	employee_id: [{ required: true, message: '请选择员工', trigger: 'change' }],
	customer_id: [{ required: true, message: '请选择客户', trigger: 'change' }],
	checkin_time: [{ required: true, message: '请选择签到时间', trigger: 'change' }],
}

const columns = [
	{ prop: 'id', title: 'ID', width: 70, align: 'center' },
	{ prop: 'visit_no', title: '拜访单号', width: 140 },
	{ prop: 'employee_name', title: '员工', width: 100, slots: { default: 'employee_name' } },
	{ prop: 'customer_name', title: '客户', width: 150, slots: { default: 'customer_name' } },
	{ prop: 'route_name', title: '线路', width: 100, slots: { default: 'route_name' } },
	{ prop: 'checkin_time', title: '签到时间', width: 140 },
	{ prop: 'checkout_time', title: '签退时间', width: 140 },
	{ prop: 'visit_duration', title: '时长(分)', width: 80, align: 'center' },
	{ prop: 'status', title: '状态', width: 80, align: 'center', slots: { default: 'status' } },
	{ prop: 'visit_result', title: '拜访结果', width: 100, slots: { default: 'visit_result' } },
	{ prop: 'checkin_address', title: '签到地址', width: 180, showOverflowTooltip: true },
	{ prop: 'action_col', title: '操作', width: 120, align: 'center', fixed: 'right', slots: { default: 'action_default' } },
]

const loadOptions = async () => {
	const [empRes, custRes, routeRes] = await Promise.all([
		businessApi.employee.list.get({ page_size: 9999 }),
		businessApi.customer.list.get({ page_size: 9999 }),
		businessApi.route.list.get({ page_size: 9999 }),
	])
	if (empRes.code === 200) employees.value = empRes.data?.list || []
	if (custRes.code === 200) customers.value = custRes.data?.list || []
	if (routeRes.code === 200) routes.value = routeRes.data?.list || []
}

const handleSearch = () => {
	const params = { ...searchForm.value }
	if (searchForm.value.date_range) {
		params.date_start = searchForm.value.date_range[0]
		params.date_end = searchForm.value.date_range[1]
	}
	delete params.date_range
	refresh(params)
}

const handleReset = () => {
	searchForm.value = { employee_id: null, route_id: null, date_range: [], status: null }
	refresh()
}

const handleAdd = () => {
	form.value = { employee_id: null, customer_id: null, route_id: null, checkin_time: '', checkin_address: '', checkout_time: '', visit_result: '正常', remark: '', status: 1 }
	dialogType.value = 'add'
	dialogVisible.value = true
}

const handleEdit = (row) => {
	form.value = { ...row }
	dialogType.value = 'edit'
	dialogVisible.value = true
}

const handleSubmit = async () => {
	await formRef.value.validate()
	submitting.value = true
	try {
		const res = dialogType.value === 'add'
			? await businessApi.visit.log.add.post(form.value)
			: await businessApi.visit.log.edit.put(form.value.id, form.value)
		if (res.code === 200) {
			ElMessage.success(res.message || '操作成功')
			dialogVisible.value = false
			refresh()
		}
	} finally {
		submitting.value = false
	}
}

const handleDelete = async (row) => {
	await ElMessageBox.confirm('确定删除该拜访记录吗？', '提示', { type: 'warning' })
	const res = await businessApi.visit.log.delete.delete(row.id)
	if (res.code === 200) {
		ElMessage.success('删除成功')
		refresh()
	}
}

const handleExport = () => {
	ElMessage.info('导出功能开发中...')
}

onMounted(() => {
	loadOptions()
	refresh()
})
</script>

<style scoped>
.toolbar { margin-bottom: 12px; display: flex; justify-content: space-between; align-items: center; }
.toolbar .right-panel { display: flex; gap: 8px; }
</style>
