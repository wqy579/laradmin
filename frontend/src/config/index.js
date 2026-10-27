const defaultConfig = {
	APP_NAME: 'Tensent Admin',
	DASHBOARD_URL: '/dashboard',

	// 白名单路由（不需要登录即可访问）
	whiteList: ['/login', '/register', '/reset-password', '/404'],

	// 接口地址
	API_URL: import.meta.env.VITE_API_BASE_URL || 'http://localhost:8000/admin/',

	// WebSocket 地址（不含协议前缀，自动判断 ws/wss）
	WS_URL: 'localhost:8000',

	// 请求超时
	TIMEOUT: 50000,

	// TokenName（HTTP header 名）
	TOKEN_NAME: 'authorization',

	// Token前缀，注意最后有个空格，如不需要需设置空字符串
	TOKEN_PREFIX: 'Bearer ',

	// 追加其他头
	HEADERS: {},

	// 请求是否开启缓存
	REQUEST_CACHE: false,

	// 语言
	LANG: 'zh-cn',

	// 过渡期「演示数据」水印开关（BUG-04）
	// 未接真实后端前 = true，finance 各页挂「演示数据」水印 + 顶部提示条；
	// 接真实后端后置 false，水印与提示条自动消失。
	FINANCE_DEMO_WATERMARK: true,
}

// 合并 public/config.js 中的覆盖配置
if (typeof window !== 'undefined' && window.SY_CONFIG) {
	Object.assign(defaultConfig, window.SY_CONFIG)
}

export default defaultConfig
