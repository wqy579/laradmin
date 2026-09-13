import axios from 'axios'
import { ElMessage } from 'element-plus'
import router from '../router'
import config from '../config'
import { useUserStore } from '../stores/modules/user'

const service = axios.create({
	baseURL: config.API_URL,
	timeout: config.TIMEOUT,
})

service.interceptors.request.use(
	(reqConfig) => {
		const userStore = useUserStore()
		if (userStore.token) {
			reqConfig.headers[config.TOKEN_NAME] = config.TOKEN_PREFIX + userStore.token
		}

		if (config.HEADERS) {
			Object.assign(reqConfig.headers, config.HEADERS)
		}

		return reqConfig
	},
	(error) => Promise.reject(error),
)

service.interceptors.response.use(
	(response) => {
		const res = response.data
		if (res.code && res.code !== 200) {
			ElMessage.error(res.message || '请求失败')
			if (res.code === 401) {
				const userStore = useUserStore()
				if (userStore.token) {
					userStore.logout()
				}
				router.push({ path: '/login', query: { redirect: router.currentRoute.value.fullPath } })
			}
			return Promise.reject(new Error(res.message || '请求失败'))
		}
		return res
	},
	(error) => {
		const { response } = error
		const userStore = useUserStore()
		if (response) {
			switch (response.status) {
				case 401: {
					// 避免在 logout 过程中重复触发
					if (userStore.token) {
						userStore.logout()
						ElMessage.error('登录已过期，请重新登录')
					}
					router.push({ path: '/login', query: { redirect: router.currentRoute.value.fullPath } })
					break
				}
				case 403:
					ElMessage.error('没有权限访问该资源')
					break
				case 422:
					ElMessage.error(response.data?.message || '参数验证失败')
					break
				default:
					ElMessage.error(response.data?.message || error.message || '请求失败')
			}
		} else {
			ElMessage.error('网络异常，请检查网络连接')
		}
		return Promise.reject(error)
	},
)

export default service
