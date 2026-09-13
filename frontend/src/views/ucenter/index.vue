<template>
	<div class="ucenter">
		<el-container class="ucenter-container">
			<el-aside width="260px" class="ucenter-sidebar">
				<div class="sidebar-top">
					<div class="avatar-wrapper" @click="showAvatarModal = true">
						<el-avatar :size="64" :src="userInfo.avatar">
							{{ userInfo.nickname?.charAt(0) || userInfo.username?.charAt(0) }}
						</el-avatar>
						<div class="avatar-edit">
							<el-icon><ElIconEdit /></el-icon>
						</div>
					</div>
					<div class="user-name">{{ userInfo.nickname || userInfo.username || '未知用户' }}</div>
					<div class="user-role">
						<el-tag size="small" type="info" v-for="role in displayRoles" :key="role">{{ role }}</el-tag>
					</div>
				</div>
				<el-menu :default-active="activeMenu" mode="vertical" class="ucenter-menu" @select="handleMenuSelect">
					<el-menu-item-group title="基本设置">
						<el-menu-item index="basic">
							<el-icon><ElIconUser /></el-icon>
							<span>个人资料</span>
						</el-menu-item>
						<el-menu-item index="password">
							<el-icon><ElIconLock /></el-icon>
							<span>修改密码</span>
						</el-menu-item>
					</el-menu-item-group>
					<el-menu-item-group title="安全与通知">
						<el-menu-item index="security">
							<el-icon><ElIconUserFilled /></el-icon>
							<span>账号安全</span>
						</el-menu-item>
						<el-menu-item index="notification">
							<el-icon><ElIconBell /></el-icon>
							<span>消息通知</span>
						</el-menu-item>
					</el-menu-item-group>
				</el-menu>
			</el-aside>
			<el-main class="ucenter-content">
				<div class="content-wrapper">
					<Suspense>
						<component :is="currentComponent" :user-info="userInfo" @update="handleUpdateUserInfo" @success="handlePasswordSuccess" @change-password="handleChangePassword" />
					</Suspense>
				</div>
			</el-main>
		</el-container>

		<el-dialog v-model="showAvatarModal" title="更换头像" width="480px" :close-on-click-modal="false">
			<div class="avatar-upload">
				<el-upload class="avatar-uploader" action="" :show-file-list="false" :before-upload="beforeUpload" :on-change="handleAvatarChange" accept="image/jpeg,image/png">
					<img v-if="avatarUrl" :src="avatarUrl" class="avatar" />
					<el-icon v-else class="avatar-uploader-icon"><ElIconPlus /></el-icon>
				</el-upload>
				<div class="upload-tip">支持 JPG、PNG 格式，文件大小不超过 2MB</div>
			</div>
			<template #footer>
				<el-button @click="cancelAvatarUpload">取消</el-button>
				<el-button type="primary" :loading="uploadLoading" @click="handleAvatarUpload">确定</el-button>
			</template>
		</el-dialog>
	</div>
</template>

<script setup>
import { ref, computed, onMounted, defineAsyncComponent } from 'vue'
import { ElMessage } from 'element-plus'
import { useUserStore } from '../../stores/modules/user'
import api from '../../api/auth'
import systemApi from '../../api/system'

const BasicInfo = defineAsyncComponent(() => import('./components/basic-info.vue'))
const Password = defineAsyncComponent(() => import('./components/password.vue'))
const Security = defineAsyncComponent(() => import('./components/security.vue'))
const NotificationSettings = defineAsyncComponent(() => import('./components/notification-settings.vue'))

const userStore = useUserStore()

const userInfo = ref({})
const activeMenu = ref('basic')
const showAvatarModal = ref(false)
const avatarUrl = ref('')
const uploadLoading = ref(false)

const componentMap = { basic: BasicInfo, password: Password, security: Security, notification: NotificationSettings }
const currentComponent = computed(() => componentMap[activeMenu.value] || BasicInfo)

const displayRoles = computed(() => {
	if (!userInfo.value.roles) return []
	if (Array.isArray(userInfo.value.roles)) return userInfo.value.roles.map((r) => r.name || r)
	return [userInfo.value.roles]
})

const initUserInfo = async () => {
	try {
		const storeUserInfo = userStore.userInfo
		if (storeUserInfo && storeUserInfo.id) {
			userInfo.value = storeUserInfo
		}
		const response = await api.me.get()
		if (response && response.data) {
			userStore.setUserInfo(response.data)
			userInfo.value = response.data
		}
	} catch (err) {
		ElMessage.error(err.message || '获取用户信息失败')
	}
}

const handleMenuSelect = (index) => {
	activeMenu.value = index
}

const handleUpdateUserInfo = (data) => {
	Object.assign(userInfo.value, data)
}

const handlePasswordSuccess = () => {}

const handleChangePassword = () => {
	activeMenu.value = 'password'
}

const beforeUpload = (file) => {
	const isJpgOrPng = file.type === 'image/jpeg' || file.type === 'image/png'
	if (!isJpgOrPng) {
		ElMessage.error('只能上传 JPG/PNG 格式的文件!')
		return false
	}
	const isLt2M = file.size / 1024 / 1024 < 2
	if (!isLt2M) {
		ElMessage.error('图片大小不能超过 2MB!')
		return false
	}
	return true
}

const handleAvatarChange = (file) => {
	if (file.raw) {
		avatarUrl.value = URL.createObjectURL(file.raw)
	}
}

const cancelAvatarUpload = () => {
	showAvatarModal.value = false
	avatarUrl.value = ''
}

const handleAvatarUpload = async () => {
	if (!avatarUrl.value) {
		ElMessage.warning('请先选择头像')
		return
	}

	try {
		uploadLoading.value = true
		const response = await fetch(avatarUrl.value)
		const blob = await response.blob()
		const file = new File([blob], 'avatar.jpg', { type: blob.type })
		const formData = new FormData()
		formData.append('file', file)
		await systemApi.upload.post(formData)
		await initUserInfo()
		ElMessage.success('头像更新成功')
		showAvatarModal.value = false
		avatarUrl.value = ''
	} catch (error) {
		ElMessage.error(error.message || '头像上传失败，请重试')
	} finally {
		uploadLoading.value = false
	}
}

onMounted(() => {
	initUserInfo()
})
</script>

<style scoped>
.ucenter {
	height: 100%;
	width: 100%;
	margin: 0;
	padding: 0;
}

.ucenter-container {
	height: 100%;
	width: 100%;
}

.ucenter-sidebar {
	background: #fff;
	padding: 0;
	border-right: 1px solid #f0f0f0;
	overflow-y: auto;
}

.sidebar-top {
	display: flex;
	flex-direction: column;
	align-items: center;
	padding: 24px 16px 16px;
	border-bottom: 1px solid #f0f0f0;
}

.avatar-wrapper {
	position: relative;
	cursor: pointer;
	margin-bottom: 12px;
}

.avatar-wrapper :deep(.el-avatar) {
	border: 2px solid #e4e7ed;
	font-size: 24px;
	font-weight: 600;
	color: #409eff;
	background: #ecf5ff;
	transition: border-color 0.3s;
}

.avatar-wrapper:hover :deep(.el-avatar) {
	border-color: #409eff;
}

.avatar-edit {
	position: absolute;
	bottom: 0;
	right: 0;
	width: 22px;
	height: 22px;
	background: #409eff;
	border-radius: 50%;
	display: flex;
	align-items: center;
	justify-content: center;
	color: #fff;
	font-size: 12px;
	border: 2px solid #fff;
}

.user-name {
	font-size: 16px;
	font-weight: 600;
	color: #303133;
	margin-bottom: 8px;
}

.user-role {
	display: flex;
	gap: 4px;
	flex-wrap: wrap;
	justify-content: center;
}

.ucenter-menu {
	border: none;
	padding: 8px 0;
}

.ucenter-menu :deep(.el-menu-item-group__title) {
	font-size: 12px;
	color: #909399;
	padding: 12px 20px 4px;
	line-height: 1;
}

.ucenter-menu :deep(.el-menu-item) {
	border-radius: 6px;
	margin: 2px 8px;
	height: 40px;
	line-height: 40px;
}

.ucenter-menu :deep(.el-menu-item:hover) {
	background: #f5f7fa;
}

.ucenter-menu :deep(.el-menu-item.is-active) {
	background: #ecf5ff;
	color: #409eff;
}

.ucenter-content {
	background: #f5f7fa;
	padding: 0;
	margin: 0;
}

.content-wrapper {
	padding: 24px;
	background: #fff;
	min-height: 100%;
	margin: 16px;
	border-radius: 8px;
}

.avatar-upload {
	text-align: center;
}

.upload-tip {
	margin-top: 12px;
	color: #909399;
	font-size: 12px;
}

.avatar-uploader {
	border: 1px dashed #d9d9d9;
	border-radius: 8px;
	cursor: pointer;
	position: relative;
	overflow: hidden;
	width: 178px;
	height: 178px;
	margin: 0 auto;
	display: flex;
	align-items: center;
	justify-content: center;
	transition: border-color 0.3s;
}

.avatar-uploader:hover {
	border-color: #409eff;
}

.avatar-uploader-icon {
	font-size: 28px;
	color: #8c939d;
}

.avatar {
	width: 178px;
	height: 178px;
	display: block;
	object-fit: cover;
}

@media (max-width: 768px) {
	.ucenter-container {
		flex-direction: column !important;
	}
	.ucenter-sidebar {
		width: 100% !important;
		border-right: none;
		border-bottom: 1px solid #f0f0f0;
	}
	.sidebar-top {
		flex-direction: row;
		padding: 16px;
		gap: 12px;
		align-items: center;
	}
	.sidebar-top :deep(.el-avatar) {
		width: 48px;
		height: 48px;
	}
	.avatar-wrapper {
		margin-bottom: 0;
	}
	.user-name {
		margin-bottom: 0;
		font-size: 14px;
	}
	.user-role {
		justify-content: flex-start;
	}
	.ucenter-menu {
		display: flex;
		flex-direction: row;
		overflow-x: auto;
		padding: 0;
		border-bottom: 1px solid #f0f0f0;
	}
	.ucenter-menu :deep(.el-menu-item-group) {
		display: flex;
		flex-direction: row;
	}
	.ucenter-menu :deep(.el-menu-item-group__title) {
		display: none;
	}
	.ucenter-menu :deep(.el-menu-item) {
		flex-shrink: 0;
	}
	.content-wrapper {
		margin: 8px;
		padding: 16px;
	}
}
</style>
