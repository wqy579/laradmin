<template>
	<el-dialog v-model="visible" title="日志详情" :width="dialogWidth" :close-on-click-modal="false">
		<el-descriptions v-if="logData" :column="descColumn" border size="small">
			<el-descriptions-item label="日志ID">{{ logData.id }}</el-descriptions-item>
			<el-descriptions-item label="用户名">{{ logData.username || '-' }}</el-descriptions-item>
			<el-descriptions-item label="模块">{{ logData.module || '-' }}</el-descriptions-item>
			<el-descriptions-item label="操作">{{ logData.action || '-' }}</el-descriptions-item>
			<el-descriptions-item label="请求方式">
				<el-tag :type="getMethodType(logData.method)" size="small">{{ logData.method }}</el-tag>
			</el-descriptions-item>
			<el-descriptions-item label="URL">{{ logData.url || '-' }}</el-descriptions-item>
			<el-descriptions-item label="IP地址">{{ logData.ip || '-' }}</el-descriptions-item>
			<el-descriptions-item label="状态码">{{ logData.status_code || '-' }}</el-descriptions-item>
			<el-descriptions-item label="状态">
				<el-tag :type="logData.status === 'success' ? 'success' : 'danger'" size="small">
					{{ logData.status === 'success' ? '成功' : '失败' }}
				</el-tag>
			</el-descriptions-item>
			<el-descriptions-item label="执行时间">
				<span :style="{ color: getExecTimeColor(logData.execution_time) }">{{ logData.execution_time }}ms</span>
			</el-descriptions-item>
			<el-descriptions-item label="创建时间" :span="2">{{ logData.created_at || '-' }}</el-descriptions-item>
			<el-descriptions-item label="User Agent" :span="2">
				<div style="max-height: 60px; overflow-y: auto">{{ logData.user_agent || '-' }}</div>
			</el-descriptions-item>
			<el-descriptions-item v-if="logData.error_message" label="错误信息" :span="2">
				<el-alert :title="logData.error_message" type="error" show-icon :closable="false" />
			</el-descriptions-item>
			<el-descriptions-item v-if="logData.params && Object.keys(logData.params).length > 0" label="请求参数" :span="2">
				<pre class="json-pre">{{ formatJson(logData.params) }}</pre>
			</el-descriptions-item>
			<el-descriptions-item v-if="logData.result" label="返回结果" :span="2">
				<pre class="json-pre">{{ formatJson(logData.result) }}</pre>
			</el-descriptions-item>
		</el-descriptions>
		<template #footer>
			<el-button @click="handleClose">关闭</el-button>
		</template>
	</el-dialog>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useResponsive } from '@/hooks/useResponsive'
import systemApi from '@/api/system'

const { isMobile } = useResponsive()
const dialogWidth = computed(() => (isMobile.value ? '95%' : '800px'))
const descColumn = computed(() => (isMobile.value ? 1 : 2))

const visible = ref(false)
const logData = ref(null)

const getMethodType = (method) => {
	const types = { GET: '', POST: 'success', PUT: 'warning', DELETE: 'danger', PATCH: 'info' }
	return types[method] || 'info'
}

const getExecTimeColor = (time) => {
	if (time > 1000) return 'var(--el-color-danger)'
	if (time > 500) return 'var(--el-color-warning)'
	return 'var(--el-color-success)'
}

const formatJson = (data) => {
	if (typeof data === 'string') {
		try {
			return JSON.stringify(JSON.parse(data), null, 2)
		} catch {
			return data
		}
	}
	return JSON.stringify(data, null, 2)
}

const open = async (data) => {
	visible.value = true
	if (data && data.id) {
		if (data.params || data.result) {
			logData.value = data
		} else {
			try {
				const res = await systemApi.log.detail.get(data.id)
				if (res.code === 200) {
					logData.value = res.data
				}
			} catch (error) {
				console.error('获取日志详情失败:', error)
			}
		}
	} else {
		logData.value = data
	}
}

const handleClose = () => {
	visible.value = false
	logData.value = null
}

defineExpose({ open })
</script>

<style scoped>
.json-pre {
	max-height: 300px;
	overflow-y: auto;
	margin: 0;
	padding: 8px;
	background: var(--el-fill-color-lighter);
	border-radius: 4px;
	font-size: 12px;
	line-height: 1.5;
}
</style>
