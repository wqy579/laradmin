<template>
	<el-dialog v-model="visible" title="批量分配部门" :loading="loading" width="450px" destroy-on-close @close="handleClose">
		<el-form label-width="60px">
			<el-form-item label="部门">
				<el-tree-select v-model="form.department_id" :data="departmentTree" :props="{ label: 'name', children: 'children', value: 'id' }" placeholder="请选择部门" clearable check-strictly filterable style="width: 100%" />
			</el-form-item>
		</el-form>
		<template #footer>
			<el-button @click="handleClose">取消</el-button>
			<el-button type="primary" :loading="loading" @click="handleOk">确定</el-button>
		</template>
	</el-dialog>
</template>

<script setup>
import { ref, reactive } from 'vue'
import { ElMessage } from 'element-plus'
import authApi from '@/api/auth'

const emit = defineEmits(['success', 'closed'])

const visible = ref(false)
const loading = ref(false)
const departmentTree = ref([])
const userIds = ref([])

const form = reactive({ department_id: null })

function open(ids) {
	visible.value = true
	userIds.value = ids
	form.department_id = null
	loadDepartment()
}

async function loadDepartment() {
	try {
		const res = await authApi.department.tree.get()
		departmentTree.value = res.data || []
	} catch (error) {
		console.error('加载部门树失败:', error)
	}
}

async function handleOk() {
	if (!form.department_id) {
		ElMessage.warning('请选择部门')
		return
	}
	try {
		loading.value = true
		await authApi.user.batchDepartment.post({
			ids: userIds.value,
			department_id: form.department_id,
		})
		ElMessage.success('分配成功')
		emit('success')
		handleClose()
	} catch (error) {
		console.error('批量分配部门失败:', error)
	} finally {
		loading.value = false
	}
}

function handleClose() {
	visible.value = false
	loading.value = false
	form.department_id = null
	emit('closed')
}

defineExpose({ open })
</script>
