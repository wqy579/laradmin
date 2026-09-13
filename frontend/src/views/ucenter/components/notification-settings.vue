<template>
	<div class="notification-settings">
		<h3 class="section-title">消息通知设置</h3>

		<div class="setting-group">
			<div class="group-header">系统通知</div>
			<div class="setting-item" v-for="item in systemNotifications" :key="item.key">
				<div class="setting-info">
					<div class="setting-label">{{ item.label }}</div>
					<div class="setting-desc">{{ item.description }}</div>
				</div>
				<el-switch v-model="item.enabled" />
			</div>
		</div>

		<div class="setting-group">
			<div class="group-header">安全通知</div>
			<div class="setting-item" v-for="item in securityNotifications" :key="item.key">
				<div class="setting-info">
					<div class="setting-label">{{ item.label }}</div>
					<div class="setting-desc">{{ item.description }}</div>
				</div>
				<el-switch v-model="item.enabled" />
			</div>
		</div>

		<div class="setting-group">
			<div class="group-header">通知方式</div>
			<div class="setting-item" v-for="item in notifyChannels" :key="item.key">
				<div class="setting-info">
					<div class="setting-label">{{ item.label }}</div>
					<div class="setting-desc">{{ item.description }}</div>
				</div>
				<el-switch v-model="item.enabled" />
			</div>
		</div>

		<div class="setting-actions">
			<el-button type="primary" @click="handleSave" :loading="loading">保存设置</el-button>
			<el-button @click="handleReset">恢复默认</el-button>
		</div>
	</div>
</template>

<script setup>
import { ref, reactive } from 'vue'
import { ElMessage } from 'element-plus'

const loading = ref(false)

const systemNotifications = reactive([
	{ key: 'system_update', label: '系统更新', description: '系统版本更新、维护等通知', enabled: true },
	{ key: 'task_assign', label: '任务分配', description: '有新任务分配给您时通知', enabled: true },
	{ key: 'approval', label: '审批通知', description: '收到审批请求或审批结果时通知', enabled: true },
	{ key: 'announcement', label: '公告通知', description: '系统公告和重要通知', enabled: true },
])

const securityNotifications = reactive([
	{ key: 'login_alert', label: '登录提醒', description: '账号在新设备或异地登录时通知', enabled: true },
	{ key: 'password_change', label: '密码修改', description: '账号密码被修改时通知', enabled: true },
	{ key: 'permission_change', label: '权限变更', description: '角色或权限发生变化时通知', enabled: true },
])

const notifyChannels = reactive([
	{ key: 'site_message', label: '站内消息', description: '在系统内接收消息通知', enabled: true },
	{ key: 'email', label: '邮件通知', description: '通过邮件接收通知', enabled: false },
])

const handleSave = async () => {
	try {
		loading.value = true
		const settings = {
			system: systemNotifications.reduce((acc, item) => ({ ...acc, [item.key]: item.enabled }), {}),
			security: securityNotifications.reduce((acc, item) => ({ ...acc, [item.key]: item.enabled }), {}),
			channels: notifyChannels.reduce((acc, item) => ({ ...acc, [item.key]: item.enabled }), {}),
		}
		console.log('Notification settings saved:', settings)
		ElMessage.success('通知设置已保存')
	} catch (error) {
		ElMessage.error(error.message || '保存失败，请重试')
	} finally {
		loading.value = false
	}
}

const handleReset = () => {
	systemNotifications.forEach((item) => (item.enabled = true))
	securityNotifications.forEach((item) => (item.enabled = true))
	notifyChannels.forEach((item) => {
		item.enabled = item.key === 'site_message'
	})
	ElMessage.info('已恢复默认设置')
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

.setting-group {
	margin-bottom: 24px;
}

.group-header {
	font-size: 14px;
	font-weight: 600;
	color: #303133;
	margin-bottom: 12px;
	padding-left: 10px;
	border-left: 3px solid #409eff;
}

.setting-item {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 14px 0;
	border-bottom: 1px solid #f5f5f5;
}

.setting-item:last-child {
	border-bottom: none;
}

.setting-info {
	flex: 1;
}

.setting-label {
	font-size: 14px;
	color: #303133;
	margin-bottom: 4px;
}

.setting-desc {
	font-size: 12px;
	color: #909399;
}

.setting-actions {
	margin-top: 32px;
	padding-top: 16px;
	border-top: 1px solid #f0f0f0;
}
</style>
