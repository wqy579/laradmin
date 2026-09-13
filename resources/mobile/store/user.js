import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import config from '@/config'

export const useUserStore = defineStore('user', () => {
  // State
  const token = ref('')
  const refreshToken = ref('')
  const userInfo = ref(null)

  // Getters
  const isLoggedIn = computed(() => !!token.value)
  const userName = computed(() => userInfo.value?.name || '')
  const userAvatar = computed(() => userInfo.value?.avatar || '')
  const userId = computed(() => userInfo.value?.id || '')

  // Actions

  /**
   * 设置 Token
   * @param {string} newToken
   */
  function setToken(newToken) {
    token.value = newToken
    uni.setStorageSync(config.storage.token, newToken)
  }

  /**
   * 设置刷新 Token
   * @param {string} newRefreshToken
   */
  function setRefreshToken(newRefreshToken) {
    refreshToken.value = newRefreshToken
    uni.setStorageSync('app_refresh_token', newRefreshToken)
  }

  /**
   * 设置用户信息
   * @param {object} info
   */
  function setUserInfo(info) {
    userInfo.value = info
    uni.setStorageSync(config.storage.userInfo, JSON.stringify(info))
  }

  /**
   * 获取用户信息
   * @returns {object|null}
   */
  function getUserInfo() {
    return userInfo.value
  }

  /**
   * 从本地存储初始化
   */
  function initFromStorage() {
    // 获取 Token
    const savedToken = uni.getStorageSync(config.storage.token)
    if (savedToken) {
      token.value = savedToken
    }

    // 获取刷新 Token
    const savedRefreshToken = uni.getStorageSync('app_refresh_token')
    if (savedRefreshToken) {
      refreshToken.value = savedRefreshToken
    }

    // 获取用户信息
    const savedUserInfo = uni.getStorageSync(config.storage.userInfo)
    if (savedUserInfo) {
      try {
        userInfo.value = JSON.parse(savedUserInfo)
      } catch (e) {
        console.error('解析用户信息失败:', e)
        userInfo.value = null
      }
    }
  }

  /**
   * 清除用户数据
   */
  function clearUserData() {
    token.value = ''
    refreshToken.value = ''
    userInfo.value = null

    // 清除本地存储
    uni.removeStorageSync(config.storage.token)
    uni.removeStorageSync('app_refresh_token')
    uni.removeStorageSync(config.storage.userInfo)
  }

  /**
   * 登出
   */
  function logout() {
    clearUserData()

    // 跳转到登录页
    uni.reLaunch({
      url: '/pages/login/index'
    })
  }

  /**
   * 更新用户信息
   * @param {object} data
   */
  function updateUserInfo(data) {
    userInfo.value = {
      ...userInfo.value,
      ...data
    }
    uni.setStorageSync(config.storage.userInfo, JSON.stringify(userInfo.value))
  }

  return {
    // State
    token,
    refreshToken,
    userInfo,

    // Getters
    isLoggedIn,
    userName,
    userAvatar,
    userId,

    // Actions
    setToken,
    setRefreshToken,
    setUserInfo,
    getUserInfo,
    initFromStorage,
    clearUserData,
    logout,
    updateUserInfo
  }
})
