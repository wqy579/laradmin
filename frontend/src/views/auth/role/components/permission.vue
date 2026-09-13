<template>
	<el-dialog v-model="visible" title="分配权限" :width="480" destroy-on-close @close="handleClose">
		<div style="margin-bottom: 12px; color: var(--el-text-color-secondary); font-size: 13px">
			当前角色：<el-tag>{{ roleData.name }}</el-tag>
		</div>
		<el-tree ref="treeRef" :data="permissionTree" :props="{ label: 'name', children: 'children' }" node-key="id" show-checkbox default-expand-all :default-checked-keys="checkedKeys" />
		<template #footer>
			<el-button @click="handleClose">取消</el-button>
			<el-button type="primary" :loading="saving" @click="handleSubmit">保存</el-button>
		</template>
	</el-dialog>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import authApi from '@/api/auth'

const emit = defineEmits(['success', 'closed'])

const visible = ref(false)
const saving = ref(false)
const treeRef = ref(null)
const roleData = ref({})
const permissionTree = ref([])
const checkedKeys = ref([])

async function loadPermissionTree() {
	try {
		const res = await authApi.permission.tree.get()
		permissionTree.value = res.data || []
	} catch (error) {
		console.error('加载权限树失败:', error)
	}
}

async function loadRolePermission(roleId) {
	try {
		const res = await authApi.role.detail.get(roleId)
		const permissions = res.data?.permissions || []
		checkedKeys.value = permissions.map((p) => p.id)
	} catch (error) {
		console.error('加载角色权限失败:', error)
	}
}

function open(data) {
	roleData.value = data
	visible.value = true
	loadRolePermission(data.id)
	return { open }
}

function close() {
	visible.value = false
}

function handleClose() {
	emit('closed')
	visible.value = false
	roleData.value = {}
	checkedKeys.value = []
}

async function handleSubmit() {
	saving.value = true
	try {
		const checkedNodes = treeRef.value.getCheckedKeys(false)
		const halfCheckedNodes = treeRef.value.getHalfCheckedKeys()
		const permission_ids = [...checkedNodes, ...halfCheckedNodes]

		await authApi.role.edit.put(roleData.value.id, { permission_ids })
		ElMessage.success('权限分配成功')
		emit('success')
		visible.value = false
	} catch (error) {
		console.error('保存权限失败:', error)
	} finally {
		saving.value = false
	}
}

onMounted(() => {
	loadPermissionTree()
})

defineExpose({ open, close })
</script>
