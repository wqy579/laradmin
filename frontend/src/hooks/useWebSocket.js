import { ref, readonly, watch } from 'vue'
import { useWebSocket as useVueUseWebSocket } from '@vueuse/core'
import { ElNotification } from 'element-plus'
import config from '../config'
import { useUserStore } from '../stores/modules/user'
import { startDeployProbe, stopDeployProbe } from '../utils/deployGuard'

const status = ref('CLOSED')
const messageHandlers = new Map()
let wsInstance = null
let initialized = false

function dispatchMessage(msg) {
	const { type, data } = msg
	const handler = messageHandlers.get(type)
	if (handler) handler(data)
}

messageHandlers.set('connected', (data) => {
	console.log('[WebSocket] 服务器确认连接', data)
})

messageHandlers.set('notification', (data) => {
	const { title, message: content, type } = data
	ElNotification({
		title: title || '系统通知',
		message: content || '',
		type: type || 'info',
		duration: 4500,
	})
	// 更新 notification store
	import('../stores/modules/notification').then(({ useNotificationStore }) => {
		useNotificationStore().handleRealtimeNotification(data)
	})
})

messageHandlers.set('data_update', (data) => {
	console.log('[WebSocket] 数据更新', data)
})

messageHandlers.set('heartbeat_response', () => {})

function buildUrl() {
	const userStore = useUserStore()
	const userId = userStore.userInfo?.id
	const token = userStore.token
	if (!userId || !token) return ''

	const protocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:'
	return `${protocol}//${config.WS_URL}/ws?user_id=${userId}&token=${token}`
}

export function useWebSocket() {
	function init() {
		if (initialized) return

		const url = buildUrl()
		if (!url) {
			console.warn('[WebSocket] 用户信息不完整，跳过初始化')
			return
		}

		initialized = true

		wsInstance = useVueUseWebSocket(url, {
			immediate: true,
			autoReconnect: {
				retries: -1,
				delay: 3000,
			},
			heartbeat: {
				message: JSON.stringify({ type: 'heartbeat', data: { timestamp: Date.now() } }),
				interval: 30000,
				pongTimeout: 10000,
			},
			onConnected() {
				console.log('[WebSocket] 连接已建立')
				// WS 恢复说明服务已恢复，确保遮罩关闭
				stopDeployProbe()
			},
			onDisconnected(_ws, event) {
				console.log('[WebSocket] 连接已断开', event?.code)
				// 断开可能是部署所致，启动健康探测（失败则显示升级遮罩）
				startDeployProbe()
			},
			onError() {
				console.error('[WebSocket] 连接错误')
				// 连接错误同样可能是部署所致
				startDeployProbe()
			},
			onMessage(_ws, event) {
				try {
					dispatchMessage(JSON.parse(event.data))
				} catch {
					// 非 JSON 忽略
				}
			},
		})

		// 同步状态到模块级 ref
		watch(
			wsInstance.status,
			(val) => {
				status.value = val
			},
			{ immediate: true },
		)
	}

	function close() {
		if (wsInstance) {
			wsInstance.close()
			wsInstance = null
		}
		initialized = false
		status.value = 'CLOSED'
	}

	function send(type, data = {}) {
		if (wsInstance && wsInstance.status.value === 'OPEN') {
			wsInstance.send(JSON.stringify({ type, data }))
		}
	}

	function on(type, handler) {
		messageHandlers.set(type, handler)
	}

	function off(type) {
		messageHandlers.delete(type)
	}

	return {
		status: readonly(status),
		init,
		close,
		send,
		on,
		off,
	}
}
