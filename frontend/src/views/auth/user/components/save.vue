<template>
	<el-dialog v-model="visible" :title="titleMap[mode]" :width="560" destroy-on-close @close="handleClose">
		<el-form ref="formRef" :model="form" :rules="rules" :disabled="mode === 'show'" label-width="80px" label-position="right" status-icon>
			<!-- 头像 + 基本信息 -->
			<div class="form-section">
				<div class="form-section-title">基本信息</div>
				<el-row :gutter="20">
					<el-col :span="6">
						<el-form-item label="" prop="avatar" label-width="0">
							<el-upload class="avatar-uploader" action="" :show-file-list="false" :auto-upload="false" accept="image/*" :on-change="handleAvatarChange">
								<el-avatar v-if="form.avatar" :src="form.avatar" :size="80" />
								<div v-else class="avatar-placeholder">
									<el-icon :size="28"><ElIconPlus /></el-icon>
									<span>上传头像</span>
								</div>
							</el-upload>
						</el-form-item>
					</el-col>
					<el-col :span="18">
						<el-form-item label="用户名" prop="username">
							<el-input v-model="form.username" placeholder="请输入用户名" clearable :disabled="mode === 'edit'" />
						</el-form-item>
						<el-form-item label="真实姓名" prop="real_name">
							<el-input v-model="form.real_name" placeholder="请输入真实姓名" clearable />
						</el-form-item>
					</el-col>
				</el-row>
			</div>

			<!-- 联系方式 -->
			<div class="form-section">
				<div class="form-section-title">联系方式</div>
				<el-row :gutter="20">
					<el-col :span="12">
						<el-form-item label="邮箱" prop="email" label-width="60px">
							<el-input v-model="form.email" placeholder="请输入邮箱" clearable />
						</el-form-item>
					</el-col>
					<el-col :span="12">
						<el-form-item label="手机号" prop="phone" label-width="60px">
							<el-input v-model="form.phone" placeholder="请输入手机号" clearable />
						</el-form-item>
					</el-col>
				</el-row>
			</div>

			<!-- 密码（仅新增） -->
			<div class="form-section" v-if="mode === 'add'">
				<div class="form-section-title">登录密码</div>
				<el-row :gutter="20">
					<el-col :span="12">
						<el-form-item label="密码" prop="password" label-width="60px">
							<el-input v-model="form.password" type="password" placeholder="请输入密码" clearable show-password />
						</el-form-item>
					</el-col>
					<el-col :span="12">
						<el-form-item label="确认" prop="password2" label-width="60px">
							<el-input v-model="form.password2" type="password" placeholder="请再次输入密码" clearable show-password />
						</el-form-item>
					</el-col>
				</el-row>
			</div>

			<!-- 组织角色 -->
			<div class="form-section">
				<div class="form-section-title">组织与角色</div>
				<el-row :gutter="20">
					<el-col :span="12">
						<el-form-item label="部门" prop="department_id" label-width="60px">
							<el-tree-select v-model="form.department_id" :data="departmentTree" :props="{ label: 'name', children: 'children', value: 'id' }" placeholder="请选择部门" clearable check-strictly filterable style="width: 100%" />
						</el-form-item>
					</el-col>
					<el-col :span="12">
						<el-form-item label="角色" prop="role_ids" label-width="60px">
							<el-select v-model="form.role_ids" multiple placeholder="请选择角色" clearable filterable style="width: 100%">
								<el-option v-for="role in rolesList" :key="role.id" :label="role.name" :value="role.id" />
							</el-select>
						</el-form-item>
					</el-col>
				</el-row>
				<el-form-item label="状态" prop="status" label-width="60px">
					<el-radio-group v-model="form.status">
						<el-radio :value="1">正常</el-radio>
						<el-radio :value="0">禁用</el-radio>
					</el-radio-group>
				</el-form-item>
			</div>
		</el-form>
		<template #footer v-if="mode !== 'show'">
			<el-button @click="handleClose">取消</el-button>
			<el-button type="primary" :loading="saving" @click="handleSubmit">保存</el-button>
		</template>
	</el-dialog>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import authApi from '@/api/auth'

const emit = defineEmits(['success', 'closed'])

const mode = ref('add')
const titleMap = { add: '新增用户', edit: '编辑用户', show: '查看用户' }
const visible = ref(false)
const saving = ref(false)
const formRef = ref(null)

const form = reactive({
	id: '',
	avatar: '',
	username: '',
	real_name: '',
	email: '',
	phone: '',
	password: '',
	password2: '',
	department_id: null,
	role_ids: [],
	status: 1,
})

const validatePass2 = (_rule, value, callback) => {
	if (value !== form.password) {
		callback(new Error('两次输入密码不一致'))
	} else {
		callback()
	}
}

const rules = {
	username: [
		{ required: true, message: '请输入用户名', trigger: 'blur' },
		{ min: 3, max: 50, message: '用户名长度在 3 到 50 个字符', trigger: 'blur' },
	],
	real_name: [
		{ required: true, message: '请输入真实姓名', trigger: 'blur' },
		{ min: 2, max: 50, message: '真实姓名长度在 2 到 50 个字符', trigger: 'blur' },
	],
	email: [{ type: 'email', message: '请输入正确的邮箱地址', trigger: 'blur' }],
	phone: [{ pattern: /^1[3-9]\d{9}$/, message: '请输入正确的手机号', trigger: 'blur' }],
	password: [
		{ required: true, message: '请输入登录密码', trigger: 'blur' },
		{ min: 6, max: 20, message: '密码长度在 6 到 20 个字符', trigger: 'blur' },
	],
	password2: [
		{ required: true, message: '请再次输入密码', trigger: 'blur' },
		{ validator: validatePass2, trigger: 'blur' },
	],
}

const departmentTree = ref([])
const rolesList = ref([])

async function loadDepartment() {
	try {
		const res = await authApi.department.tree.get()
		departmentTree.value = res.data || []
	} catch (error) {
		console.error('加载部门树失败:', error)
	}
}

async function loadRoles() {
	try {
		const res = await authApi.role.all.get()
		rolesList.value = res.data || []
	} catch (error) {
		console.error('加载角色列表失败:', error)
	}
}

function handleAvatarChange(file) {
	if (file && file.raw) {
		const reader = new FileReader()
		reader.onload = (e) => {
			form.avatar = e.target.result
		}
		reader.readAsDataURL(file.raw)
	}
}

function open(openMode = 'add', data = null) {
	mode.value = openMode
	visible.value = true
	if (data) {
		Object.assign(form, {
			id: data.id,
			avatar: data.avatar || '',
			username: data.username,
			real_name: data.real_name,
			email: data.email,
			phone: data.phone,
			department_id: data.department?.id || data.department_id || null,
			role_ids: data.roles ? data.roles.map((r) => r.id) : [],
			status: data.status ?? 1,
		})
	}
	return { open }
}

function close() {
	visible.value = false
}

function handleClose() {
	emit('closed')
	visible.value = false
	Object.assign(form, {
		id: '',
		avatar: '',
		username: '',
		real_name: '',
		email: '',
		phone: '',
		password: '',
		password2: '',
		department_id: null,
		role_ids: [],
		status: 1,
	})
}

async function handleSubmit() {
	try {
		await formRef.value.validate()
		saving.value = true

		const submitData = {
			username: form.username,
			avatar: form.avatar,
			real_name: form.real_name,
			email: form.email,
			phone: form.phone,
			department_id: form.department_id,
			role_ids: form.role_ids,
			status: form.status,
		}

		if (mode.value === 'add') {
			submitData.password = form.password
			await authApi.user.add.post(submitData)
		} else {
			await authApi.user.edit.put(form.id, submitData)
		}

		ElMessage.success('操作成功')
		emit('success')
		visible.value = false
	} catch (error) {
		if (error !== false) {
			console.error('保存用户失败:', error)
		}
	} finally {
		saving.value = false
	}
}

onMounted(() => {
	loadDepartment()
	loadRoles()
})

defineExpose({ open, close })
</script>

<style scoped>
.form-section {
	margin-bottom: 16px;
	padding-bottom: 16px;
	border-bottom: 1px solid var(--el-border-color-lighter);
}

.form-section:last-of-type {
	border-bottom: none;
	margin-bottom: 0;
	padding-bottom: 0;
}

.form-section-title {
	font-size: 14px;
	font-weight: 600;
	color: var(--el-text-color-primary);
	margin-bottom: 16px;
	padding-left: 10px;
	border-left: 3px solid var(--el-color-primary);
}

.avatar-uploader {
	display: flex;
	justify-content: center;
}

.avatar-uploader :deep(.el-upload) {
	border: 1px dashed var(--el-border-color-darker);
	border-radius: 8px;
	cursor: pointer;
	overflow: hidden;
	transition: border-color 0.2s;
}

.avatar-uploader :deep(.el-upload:hover) {
	border-color: var(--el-color-primary);
}

.avatar-placeholder {
	width: 80px;
	height: 80px;
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	color: var(--el-text-color-secondary);
	font-size: 12px;
	gap: 4px;
}

@media (max-width: 768px) {
	:deep(.el-col) {
		max-width: 100%;
		flex: 0 0 100%;
	}
	.avatar-uploader {
		justify-content: flex-start;
	}
}
</style>
