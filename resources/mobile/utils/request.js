import Request from 'luch-request'
import { useUserStore } from '@/store/user'

// 请求配置
const http = new Request({
  // 基础 URL
  baseURL: import.meta.env.VITE_API_BASE_URL || 'https://api.example.com',
  // 超时时间
  timeout: 60000,
  // 请求头
  header: {
    'Content-Type': 'application/json;charset=UTF-8'
  },
  // 自定义配置
  custom: {
    auth: true, // 是否需要认证
    loading: true, // 是否显示加载提示
    loadingText: '加载中...',
    showError: true, // 是否显示错误提示
    showErrorType: 'toast' // 错误提示方式：toast、modal、none
  }
})

// 请求拦截器
http.interceptors.request.use(
  (config) => {
    const userStore = useUserStore()

    // 添加 Token
    if (config.custom?.auth !== false && userStore.token) {
      config.header['Authorization'] = `Bearer ${userStore.token}`
    }

    // 添加时间戳防止缓存
    if (config.method === 'GET' && config.params) {
      config.params._t = Date.now()
    }

    // 显示加载提示
    if (config.custom?.loading !== false) {
      uni.showLoading({
        title: config.custom?.loadingText || '加载中...',
        mask: true
      })
    }

    return config
  },
  (config) => {
    // 请求错误
    uni.hideLoading()
    return Promise.reject(config)
  }
)

// 响应拦截器
http.interceptors.response.use(
  (response) => {
    // 隐藏加载提示
    uni.hideLoading()

    const { statusCode, data } = response

    // HTTP 状态码判断
    if (statusCode !== 200) {
      handleHttpError(statusCode)
      return Promise.reject(response)
    }

    // 业务状态码判断
    if (data.code !== 200) {
      handleBusinessError(data, response.config)
      return Promise.reject(data)
    }

    return data
  },
  (error) => {
    // 隐藏加载提示
    uni.hideLoading()

    // 网络错误处理
    handleNetworkError(error)

    return Promise.reject(error)
  }
)

// 处理 HTTP 错误
const handleHttpError = (statusCode) => {
  let message = '网络错误'

  switch (statusCode) {
    case 400:
      message = '请求参数错误'
      break
    case 401:
      message = '未授权，请重新登录'
      handleUnauthorized()
      break
    case 403:
      message = '拒绝访问'
      break
    case 404:
      message = '请求的资源不存在'
      break
    case 405:
      message = '请求方法不允许'
      break
    case 408:
      message = '请求超时'
      break
    case 500:
      message = '服务器内部错误'
      break
    case 502:
      message = '网关错误'
      break
    case 503:
      message = '服务不可用'
      break
    case 504:
      message = '网关超时'
      break
    default:
      message = `连接错误 ${statusCode}`
  }

  uni.showToast({
    title: message,
    icon: 'none'
  })
}

// 处理业务错误
const handleBusinessError = (data, config) => {
  const { code, message } = data

  // 是否显示错误提示
  const showError = config?.custom?.showError !== false

  if (!showError) {
    return
  }

  // 错误提示方式
  const errorType = config?.custom?.showErrorType || 'toast'

  switch (code) {
    case 401:
      // 未授权
      handleUnauthorized()
      break
    case 403:
      // 无权限
      if (errorType === 'modal') {
        uni.showModal({
          title: '提示',
          content: message || '没有权限访问',
          showCancel: false
        })
      } else {
        uni.showToast({
          title: message || '没有权限',
          icon: 'none'
        })
      }
      break
    default:
      // 其他错误
      if (errorType === 'modal') {
        uni.showModal({
          title: '提示',
          content: message || '操作失败',
          showCancel: false
        })
      } else if (errorType !== 'none') {
        uni.showToast({
          title: message || '操作失败',
          icon: 'none'
        })
      }
  }
}

// 处理网络错误
const handleNetworkError = (error) => {
  let message = '网络连接失败'

  if (error.errMsg) {
    if (error.errMsg.includes('timeout')) {
      message = '请求超时，请稍后重试'
    } else if (error.errMsg.includes('request:fail')) {
      message = '网络连接失败，请检查网络'
    } else if (error.errMsg.includes('abort')) {
      message = '请求已取消'
    }
  }

  uni.showToast({
    title: message,
    icon: 'none'
  })
}

// 处理未授权
const handleUnauthorized = () => {
  const userStore = useUserStore()

  // 清除用户信息
  userStore.logout()

  // 跳转到登录页
  uni.reLaunch({
    url: '/pages/login/index'
  })
}

// 请求方法封装
const request = {
  // GET 请求
  get(url, params = {}, config = {}) {
    return http.get(url, { params, ...config })
  },

  // POST 请求
  post(url, data = {}, config = {}) {
    return http.post(url, data, config)
  },

  // PUT 请求
  put(url, data = {}, config = {}) {
    return http.put(url, data, config)
  },

  // DELETE 请求
  delete(url, data = {}, config = {}) {
    return http.delete(url, data, config)
  },

  // 上传文件
  upload(url, filePath, formData = {}, config = {}) {
    return http.upload(url, {
      filePath,
      formData,
      ...config
    })
  },

  // 下载文件
  download(url, config = {}) {
    return http.download(url, config)
  }
}

export default request
