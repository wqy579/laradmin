<template>
	<el-dialog v-model="visible" title="批量分配角色" :loading="loading" width="450px" destroy-on-close @close="handleClose">
		<el-form label-width="60px">
			<el-form-item label="角色">
				<el-select v-model="selectedRoleIds" multiple placeholder="请选择角色" clearable filterable style="width: 100%">
					<el-option v-for="role in roleOptions" :key="role.id" :label="role.name" :value="role.id" />
				</el-select>
			</el-form-item>
		</el-form>
		<template #footer>
			<el-button @click="handleClose">取消</el-button>
			<el-button type="primary" :loading="loading" @click="handleOk">确定</el-button>
		</template>
	</el-dialog>
</template>

<script setup>
import { ref } from 'vue'
import { ElMessage } from 'element-plus'
import authApi from '@/api/auth'

const emit = defineEmits(['success', 'closed'])

const visible = ref(false)
const loading = ref(false)
const roleOptions = ref([])
const userIds = ref([])
const selectedRoleIds = ref([])

function open(ids) {
	visible.value = true
	userIds.value = ids
	selectedRoleIds.value = []
	loadRoles()
}

async function loadRoles() {
	try {
		const res = await authApi.role.all.get()
		roleOptions.value = res.data || []
	} catch (error) {
		console.error('加载角色列表失败:', error)
	}
}

async function handleOk() {
	try {
		loading.value = true
		await authApi.user.batchRoles.post({
			ids: userIds.value,
			role_ids: selectedRoleIds.value,
		})
		ElMessage.success('分配成功')
		emit('success')
		handleClose()
	} catch (error) {
		console.error('批量分配角色失败:', error)
	} finally {
		loading.value = false
	}
}

function handleClose() {
	visible.value = false
	loading.value = false
	selectedRoleIds.value = []
	emit('closed')
}

defineExpose({ open })
</script>
