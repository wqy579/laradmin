<template>
	<el-dialog v-model="visible" title="设置角色" :loading="loading" width="450px" destroy-on-close @close="handleClose">
		<el-form label-width="60px">
			<el-form-item label="用户">
				<el-input :model-value="userForm.username" disabled />
			</el-form-item>
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
import { ref, reactive } from 'vue'
import { ElMessage } from 'element-plus'
import authApi from '@/api/auth'

const emit = defineEmits(['success', 'closed'])

const visible = ref(false)
const loading = ref(false)
const roleOptions = ref([])
const selectedRoleIds = ref([])
const userId = ref(null)

const userForm = reactive({ username: '' })

function open() {
	visible.value = true
	loadRoles()
}

function setData(user) {
	userId.value = user.id
	userForm.username = user.username
	selectedRoleIds.value = (user.roles || []).map((r) => r.id)
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
	if (!userId.value) {
		ElMessage.warning('用户ID不能为空')
		return
	}
	try {
		loading.value = true
		await authApi.user.batchRoles.post({
			ids: [userId.value],
			role_ids: selectedRoleIds.value,
		})
		ElMessage.success('设置成功')
		emit('success')
		handleClose()
	} catch (error) {
		console.error('设置角色失败:', error)
	} finally {
		loading.value = false
	}
}

function handleClose() {
	visible.value = false
	userId.value = null
	userForm.username = ''
	selectedRoleIds.value = []
	emit('closed')
}

defineExpose({ open, setData })
</script>
