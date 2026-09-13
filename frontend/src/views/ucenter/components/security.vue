<template>
	<div class="security-settings">
		<h3 class="section-title">账号安全</h3>

		<div class="security-item" v-for="item in securityList" :key="item.action">
			<div class="security-icon">
				<el-icon :size="24"><component :is="item.icon" /></el-icon>
			</div>
			<div class="security-info">
				<div class="security-label">{{ item.title }}</div>
				<div class="security-desc">{{ item.description }}</div>
			</div>
			<div class="security-action">
				<el-tag v-if="item.status" :type="item.statusType" size="small">{{ item.status }}</el-tag>
				<el-button type="primary" link @click="handleAction(item.action)">{{ item.buttonText }}</el-button>
			</div>
		</div>
	</div>
</template>

<script setup>
import { ref } from 'vue'
import { ElMessage } from 'element-plus'

const emit = defineEmits(['change-password'])

const securityList = ref([
	{
		title: '登录密码',
		description: '用于登录系统的密码，建议定期更换以保障账号安全',
		buttonText: '修改',
		action: 'password',
		icon: Lock,
		status: '已设置',
		statusType: 'success',
	},
	{
		title: '手机绑定',
		description: '用于接收重要通知和安全验证',
		buttonText: '修改',
		action: 'phone',
		icon: Iphone,
		status: '未绑定',
		statusType: 'info',
	},
	{
		title: '邮箱绑定',
		description: '用于接收重要通知和找回密码',
		buttonText: '绑定',
		action: 'email',
		icon: Message,
		status: '未绑定',
		statusType: 'info',
	},
	{
		title: '登录设备管理',
		description: '查看和管理已登录的设备，可踢出可疑设备',
		buttonText: '查看',
		action: 'device',
		icon: Monitor,
		status: '',
		statusType: 'info',
	},
])

const handleAction = (action) => {
	switch (action) {
		case 'password':
			emit('change-password')
			break
		case 'phone':
			ElMessage.info('手机绑定功能开发中')
			break
		case 'email':
			ElMessage.info('邮箱绑定功能开发中')
			break
		case 'device':
			ElMessage.info('登录设备管理功能开发中')
			break
		default:
			break
	}
}
</script>

<style scoped>
.section-title {
	font-size: 16px;
	font-weight: 600;
	color: #303133;
	margin: 0 0 24px;
	padding-bottom: 12px;
	border-bottom: 1px solid #f0f0f0;
}

.security-item {
	display: flex;
	align-items: center;
	padding: 18px 0;
	border-bottom: 1px solid #f5f5f5;
}

.security-item:last-child {
	border-bottom: none;
}

.security-icon {
	width: 48px;
	height: 48px;
	border-radius: 8px;
	background: #f0f5ff;
	display: flex;
	align-items: center;
	justify-content: center;
	color: #409eff;
	flex-shrink: 0;
	margin-right: 16px;
}

.security-info {
	flex: 1;
}

.security-label {
	font-size: 14px;
	font-weight: 500;
	color: #303133;
	margin-bottom: 4px;
}

.security-desc {
	font-size: 12px;
	color: #909399;
}

.security-action {
	display: flex;
	align-items: center;
	gap: 12px;
	flex-shrink: 0;
}
</style>
