// 环境配置
export const config = {
  // API 基础 URL
  apiBaseUrl: import.meta.env.VITE_API_BASE_URL || 'https://api.example.com',

  // 应用配置
  appName: 'UniApp 移动应用',
  appVersion: '1.0.0',

  // 分页配置
  pageSize: 20,

  // 存储键名
  storage: {
    token: 'app_token',
    userInfo: 'app_user_info',
    settings: 'app_settings'
  },

  // 请求超时时间（毫秒）
  timeout: 60000,

  // 上传配置
  upload: {
    maxFileSize: 10 * 1024 * 1024, // 10MB
    allowedTypes: ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx']
  }
}

// 环境判断
export const isDev = import.meta.env.DEV
export const isProd = import.meta.env.PROD

// 当前环境
export const env = import.meta.env.MODE || 'development'

export default config
