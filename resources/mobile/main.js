import App from './App'
import pinia from './store'
import { useUserStore, useAppStore } from './store'

import { createSSRApp } from 'vue'

export function createApp() {
  const app = createSSRApp(App)

  // 使用 pinia
  app.use(pinia)

  // 初始化 store
  const userStore = useUserStore()
  const appStore = useAppStore()

  // 从本地存储初始化数据
  userStore.initFromStorage()
  appStore.initFromStorage()

  // 设置设备类型
  appStore.setDevice(getDeviceType())

  return {
    app
  }
}

/**
 * 获取设备类型
 * @returns {string}
 */
function getDeviceType() {
  // #ifdef H5
  return 'h5'
  // #endif

  // #ifdef APP-PLUS
  return 'app'
  // #endif

  // #ifdef MP-WEIXIN
  return 'mp-weixin'
  // #endif

  // #ifdef MP-ALIPAY
  return 'mp-alipay'
  // #endif

  // #ifdef MP-BAIDU
  return 'mp-baidu'
  // #endif

  // #ifdef MP-TOUTIAO
  return 'mp-toutiao'
  // #endif

  // #ifdef MP-QQ
  return 'mp-qq'
  // #endif

  // #ifdef MP-KS
  return 'mp-ks'
  // #endif

  // #ifdef MP-JD
  return 'mp-jd'
  // #endif

  return 'unknown'
}
