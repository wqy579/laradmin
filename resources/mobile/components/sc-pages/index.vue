<template>
  <view class="sc-pages" :style="pageStyle">
    <!-- 顶部导航栏 -->
    <uni-nav-bar
      v-if="showNavbar"
      :fixed="fixed"
      :background-color="navbarBgColor"
      :color="navbarColor"
      :status-bar="statusBar"
      :shadow="shadow"
      :border="border"
      :left-icon="leftIcon"
      :title="title"
      :title-width="titleWidth"
      :dark="dark"
      @click-left="handleLeftClick"
      @click-right="handleRightClick"
    >
      <!-- 左侧插槽 -->
      <template #left>
        <slot name="navbar-left">
          <view v-if="showBack" class="navbar-back" @click="handleBack">
            <uni-icons type="back" :size="24" :color="navbarColor"></uni-icons>
          </view>
        </slot>
      </template>

      <!-- 标题插槽 -->
      <template #default>
        <slot name="navbar-title">
          <text class="navbar-title">{{ title }}</text>
        </slot>
      </template>

      <!-- 右侧插槽 -->
      <template #right>
        <slot name="navbar-right">
          <view v-if="rightIcon" class="navbar-right-icon" @click="handleRightClick">
            <uni-icons :type="rightIcon" :size="24" :color="navbarColor"></uni-icons>
          </view>
        </slot>
      </template>
    </uni-nav-bar>

    <!-- 中间内容区域 -->
    <scroll-view
      class="sc-pages__content"
      scroll-y
      :scroll-top="scrollTop"
      :scroll-with-animation="scrollWithAnimation"
      :show-scrollbar="showScrollbar"
      :refresher-enabled="refresherEnabled"
      :refresher-triggered="refresherTriggered"
      :refresher-background="refresherBackground"
      @scrolltoupper="handleScrollToUpper"
      @scrolltolower="handleScrollToLower"
      @scroll="handleScroll"
      @refresherrefresh="handleRefresherRefresh"
      @refresherrestore="handleRefresherRestore"
    >
      <view class="sc-pages__content-inner">
        <slot></slot>
      </view>
    </scroll-view>

    <!-- 底部 TabBar -->
    <sc-tabbar
      v-if="showTabbar"
      :list="tabbarList"
      :model-value="currentTab"
      :fixed="fixed"
      :safe-area-inset-bottom="safeAreaInsetBottom"
      :active-color="tabbarActiveColor"
      :inactive-color="tabbarInactiveColor"
      :bg-color="tabbarBgColor"
      :border-color="tabbarBorderColor"
      @change="handleTabbarChange"
      @click="handleTabbarClick"
    >
      <template #icon="scope">
        <slot name="tabbar-icon" v-bind="scope"></slot>
      </template>
    </sc-tabbar>
  </view>
</template>

<script setup>
import { ref, computed } from 'vue'

const props = defineProps({
  // ===== 页面基础配置 =====
  // 页面标题
  title: {
    type: String,
    default: ''
  },
  // 页面样式
  pageStyle: {
    type: [String, Object],
    default: ''
  },
  // 固定定位
  fixed: {
    type: Boolean,
    default: true
  },

  // ===== 导航栏配置 =====
  // 是否显示导航栏
  showNavbar: {
    type: Boolean,
    default: true
  },
  // 是否显示返回按钮
  showBack: {
    type: Boolean,
    default: true
  },
  // 导航栏背景色
  navbarBgColor: {
    type: String,
    default: '#FFFFFF'
  },
  // 导航栏文字颜色
  navbarColor: {
    type: String,
    default: '#000000'
  },
  // 是否沉浸式状态栏
  statusBar: {
    type: Boolean,
    default: true
  },
  // 是否显示阴影
  shadow: {
    type: Boolean,
    default: false
  },
  // 是否显示边框
  border: {
    type: Boolean,
    default: true
  },
  // 左侧图标
  leftIcon: {
    type: String,
    default: 'left'
  },
  // 右侧图标
  rightIcon: {
    type: String,
    default: ''
  },
  // 标题宽度
  titleWidth: {
    type: String,
    default: ''
  },
  // 暗黑模式
  dark: {
    type: Boolean,
    default: false
  },

  // ===== 内容区域配置 =====
  // 滚动条位置
  scrollTop: {
    type: Number,
    default: 0
  },
  // 是否使用滚动动画
  scrollWithAnimation: {
    type: Boolean,
    default: false
  },
  // 是否显示滚动条
  showScrollbar: {
    type: Boolean,
    default: false
  },

  // ===== 下拉刷新配置 =====
  // 是否开启下拉刷新
  refresherEnabled: {
    type: Boolean,
    default: false
  },
  // 下拉刷新状态
  refresherTriggered: {
    type: Boolean,
    default: false
  },
  // 下拉刷新背景色
  refresherBackground: {
    type: String,
    default: '#FFFFFF'
  },

  // ===== TabBar 配置 =====
  // 是否显示 TabBar
  showTabbar: {
    type: Boolean,
    default: false
  },
  // TabBar 列表
  tabbarList: {
    type: Array,
    default: () => []
  },
  // 当前选中的 Tab
  currentTab: {
    type: Number,
    default: 0
  },
  // 是否开启底部安全区适配
  safeAreaInsetBottom: {
    type: Boolean,
    default: true
  },
  // TabBar 选中颜色
  tabbarActiveColor: {
    type: String,
    default: '#007AFF'
  },
  // TabBar 未选中颜色
  tabbarInactiveColor: {
    type: String,
    default: '#999999'
  },
  // TabBar 背景颜色
  tabbarBgColor: {
    type: String,
    default: '#FFFFFF'
  },
  // TabBar 边框颜色
  tabbarBorderColor: {
    type: String,
    default: '#E5E5E5'
  }
})

const emit = defineEmits([
  'back',
  'left-click',
  'right-click',
  'scroll',
  'scrolltoupper',
  'scrolltolower',
  'refresherrefresh',
  'refresherrestore',
  'tabbar-change',
  'tabbar-click'
])

// ===== 导航栏事件处理 =====

// 左侧点击事件
const handleLeftClick = () => {
  emit('left-click')
}

// 右侧点击事件
const handleRightClick = () => {
  emit('right-click')
}

// 返回上一页
const handleBack = () => {
  emit('back')
  uni.navigateBack({
    delta: 1
  })
}

// ===== 内容区域事件处理 =====

// 滚动事件
const handleScroll = (e) => {
  emit('scroll', e)
}

// 滚动到顶部
const handleScrollToUpper = (e) => {
  emit('scrolltoupper', e)
}

// 滚动到底部
const handleScrollToLower = (e) => {
  emit('scrolltolower', e)
}

// 下拉刷新
const handleRefresherRefresh = (e) => {
  emit('refresherrefresh', e)
}

// 下拉刷新结束
const handleRefresherRestore = (e) => {
  emit('refresherrestore', e)
}

// ===== TabBar 事件处理 =====

// TabBar 切换
const handleTabbarChange = (index, item) => {
  emit('tabbar-change', index, item)
}

// TabBar 点击
const handleTabbarClick = (index, item) => {
  emit('tabbar-click', index, item)
}

// ===== 暴露方法 =====

// 滚动到指定位置
const scrollTo = (top, duration = 0) => {
  // 通过修改 scrollTop 实现
  emit('scroll-to', top)
}

// 开始下拉刷新
const startRefresh = () => {
  emit('update:refresherTriggered', true)
}

// 结束下拉刷新
const stopRefresh = () => {
  emit('update:refresherTriggered', false)
}

defineExpose({
  scrollTo,
  startRefresh,
  stopRefresh
})
</script>

<style scoped lang="scss">
.sc-pages {
  display: flex;
  flex-direction: column;
  width: 100vw;
  height: 100vh;
  overflow: hidden;

  &__content {
    flex: 1;
    width: 100%;
    overflow: hidden;
    background: #f5f5f5;
  }

  &__content-inner {
    min-height: 100%;
    padding: 0;
  }
}

.navbar-back {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  border-radius: 50%;
  transition: background 0.3s;

  &:active {
    background: rgba(0, 0, 0, 0.05);
  }
}

.navbar-right-icon {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  border-radius: 50%;
  transition: background 0.3s;

  &:active {
    background: rgba(0, 0, 0, 0.05);
  }
}

.navbar-title {
  font-size: 18px;
  font-weight: 500;
  color: inherit;
}
</style>
