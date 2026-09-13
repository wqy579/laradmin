import { defineStore } from 'pinia'
import { ref } from 'vue'
import config from '@/config'

export const useAppStore = defineStore('app', () => {
  // State
  const sidebarCollapsed = ref(false)
  const device = ref('unknown')
  const language = ref('zh-CN')
  const theme = ref('light')
  const settings = ref(null)

  // Actions

  /**
   * 设置侧边栏折叠状态
   * @param {boolean} collapsed
   */
  function setSidebarCollapsed(collapsed) {
    sidebarCollapsed.value = collapsed
  }

  /**
   * 切换侧边栏
   */
  function toggleSidebar() {
    sidebarCollapsed.value = !sidebarCollapsed.value
  }

  /**
   * 设置设备类型
   * @param {string} type - 'h5' | 'app' | 'mp-weixin' | 'mp-alipay' | 'mp-baidu' | 'mp-toutiao' | 'mp-qq' | 'mp-ks' | 'mp-jd'
   */
  function setDevice(type) {
    device.value = type
  }

  /**
   * 设置语言
   * @param {string} lang
   */
  function setLanguage(lang) {
    language.value = lang
    uni.setStorageSync('app_language', lang)
  }

  /**
   * 设置主题
   * @param {string} mode - 'light' | 'dark'
   */
  function setTheme(mode) {
    theme.value = mode
    uni.setStorageSync('app_theme', mode)
  }

  /**
   * 切换主题
   */
  function toggleTheme() {
    const newTheme = theme.value === 'light' ? 'dark' : 'light'
    setTheme(newTheme)
  }

  /**
   * 设置应用配置
   * @param {object} data
   */
  function setSettings(data) {
    settings.value = data
    uni.setStorageSync(config.storage.settings, JSON.stringify(data))
  }

  /**
   * 更新设置
   * @param {object} data
   */
  function updateSettings(data) {
    settings.value = {
      ...settings.value,
      ...data
    }
    uni.setStorageSync(config.storage.settings, JSON.stringify(settings.value))
  }

  /**
   * 从本地存储初始化
   */
  function initFromStorage() {
    // 获取语言设置
    const savedLanguage = uni.getStorageSync('app_language')
    if (savedLanguage) {
      language.value = savedLanguage
    }

    // 获取主题设置
    const savedTheme = uni.getStorageSync('app_theme')
    if (savedTheme) {
      theme.value = savedTheme
    }

    // 获取应用设置
    const savedSettings = uni.getStorageSync(config.storage.settings)
    if (savedSettings) {
      try {
        settings.value = JSON.parse(savedSettings)
      } catch (e) {
        console.error('解析应用设置失败:', e)
        settings.value = null
      }
    }
  }

  /**
   * 清除设置
   */
  function clearSettings() {
    language.value = 'zh-CN'
    theme.value = 'light'
    settings.value = null

    // 清除本地存储
    uni.removeStorageSync('app_language')
    uni.removeStorageSync('app_theme')
    uni.removeStorageSync(config.storage.settings)
  }

  return {
    // State
    sidebarCollapsed,
    device,
    language,
    theme,
    settings,

    // Actions
    setSidebarCollapsed,
    toggleSidebar,
    setDevice,
    setLanguage,
    setTheme,
    toggleTheme,
    setSettings,
    updateSettings,
    initFromStorage,
    clearSettings
  }
})
