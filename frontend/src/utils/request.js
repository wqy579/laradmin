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

		// 文案只算一次，toast 与 error.message 共用。
		// 不回落 error.message：那是 axios 的英文原文 "Request failed with status code 500"，
		// 直接弹出来是中英混杂。各调用方的 catch(err => err.message) 靠下面那行拿到中文。
		let message
		if (!response) {
			message = '网络异常，请检查网络连接'
		} else {
			switch (response.status) {
				case 401:
					// 有 token 才是会话过期；登录页输错密码也返回 401，
					// 此时提示"已过期"是错的，必须显示后端给的具体原因。
					message = userStore.token
						? '登录已过期，请重新登录'
						: response.data?.message || '登录失败'
					break
				case 403:
					message = response.data?.message || '没有权限访问该资源'
					break
				case 422:
					message = response.data?.message || '参数验证失败'
					break
				case 429:
					// BUG-06 治理：登录/接口被限流。后端固定下发 message「操作频繁，请稍后再试」，
					// 这里直接取，绝不落到 default 分支的「请求失败（429）」——页面不白屏、只轻提示。
					// retry_after（秒）在顶层，需要倒计时展示时从这里取。
					message = response.data?.message || '操作频繁，请稍后再试'
					break
				default:
					message = response.data?.message || `请求失败（${response.status}）`
			}
		}

		// 401 + 已有 token = 会话过期，这是系统级跳转，不走 toast：
		// 此时若应用处于路由过渡期（logout 触发 router.push），appContext.provides 可能不完整，
		// ElMessage 内部创建实例会撞 Object.create(undefined) 渲染崩溃。登出登录用专门的
		// 提示组件处理，这里只 logout + 跳登录，不弹 toast。
		const isSessionExpired = response?.status === 401 && userStore.token

		if (!isSessionExpired) {
			// grouping: true 让拦截器这里与调用方 catch 里弹出的同一句合并成一条（带次数角标），
			// 否则每次失败都会看到两条一模一样的提示。
			ElMessage.error(message, { grouping: true })
		}

		if (response?.status === 401) {
			// 避免在 logout 过程中重复触发
			if (userStore.token) {
				userStore.logout()
			}
			router.push({ path: '/login', query: { redirect: router.currentRoute.value.fullPath } })
		}

		error.message = message
		return Promise.reject(error)
	},
)

export default service
