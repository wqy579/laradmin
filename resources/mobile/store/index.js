// Store 入口文件
import { createPinia } from 'pinia'

// 导出所有 store
export { useUserStore } from './user'
export { useAppStore } from './app'

// 创建 pinia 实例
const pinia = createPinia()

export default pinia
