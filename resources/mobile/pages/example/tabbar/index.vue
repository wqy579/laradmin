<template>
  <view class="tabbar-example-page">
    <!-- 页面内容区域 -->
    <scroll-view class="page-content" scroll-y>
      <view class="content-card">
        <view class="card-title">TabBar 组件示例</view>
        <view class="card-desc">当前选中：{{ currentTab }}</view>

        <view class="section">
          <view class="section-title">功能说明</view>
          <view class="section-content">
            <text class="item">• 支持自定义图标（iconfont）</text>
            <text class="item">• 支持徽标显示（badge）</text>
            <text class="item">• 支持红点显示（dot）</text>
            <text class="item">• 支持自定义主题色</text>
            <text class="item">• 支持底部安全区适配</text>
            <text class="item">• 支持 v-model 双向绑定</text>
          </view>
        </view>

        <view class="section">
          <view class="section-title">TabBar 数据</view>
          <view class="section-content">
            <text class="item" v-for="(item, index) in tabList" :key="index">
              {{ index + 1 }}. {{ item.text }}
              <text v-if="item.badge"> (徽标: {{ item.badge }})</text>
              <text v-if="item.dot"> (红点)</text>
            </text>
          </view>
        </view>
      </view>
    </scroll-view>

    <!-- TabBar 组件 -->
    <sc-tabbar
      v-model="currentTab"
      :list="tabList"
      :fixed="true"
      :safe-area-inset-bottom="true"
      active-color="#007AFF"
      inactive-color="#999999"
      @change="handleTabChange"
    >
      <!-- 自定义图标插槽 -->
      <template #icon="{ item, index, active }">
        <text class="iconfont sc-tabbar__icon-text" :class="active ? item.selectedIconPath : item.iconPath"></text>
      </template>
    </sc-tabbar>
  </view>
</template>

<script setup>
import { ref } from 'vue'

// 当前选中的 tab 索引
const currentTab = ref(0)

// tab 列表数据
const tabList = ref([
  {
    text: '首页',
    iconPath: 'home',
    selectedIconPath: 'home-fill',
    pagePath: '/pages/index/index'
  },
  {
    text: '发现',
    iconPath: 'compass',
    selectedIconPath: 'compass-fill',
    pagePath: '/pages/discover/index'
  },
  {
    text: '消息',
    iconPath: 'message',
    selectedIconPath: 'message-fill',
    badge: '5', // 徽标显示
    pagePath: '/pages/message/index'
  },
  {
    text: '购物车',
    iconPath: 'shopping-cart',
    selectedIconPath: 'shopping-cart-fill',
    dot: true, // 红点显示
    pagePath: '/pages/cart/index'
  },
  {
    text: '我的',
    iconPath: 'user',
    selectedIconPath: 'user-fill',
    pagePath: '/pages/user/index'
  }
])

// tab 切换事件
const handleTabChange = (index, item) => {
  console.log('切换到 tab:', index, item)

  // 跳转到对应页面
  if (item.pagePath) {
    uni.switchTab({
      url: item.pagePath,
      fail: () => {
        // 如果不是 tabBar 页面，使用 navigateTo
        uni.navigateTo({
          url: item.pagePath
        })
      }
    })
  }
}
</script>

<style scoped lang="scss">
.tabbar-example-page {
  display: flex;
  flex-direction: column;
  height: 100vh;
  background: #f5f5f5;
}

.page-content {
  flex: 1;
  padding-bottom: 120rpx;
}

.content-card {
  margin: 16rpx;
  padding: 32rpx;
  background: #fff;
  border-radius: 16rpx;

  .card-title {
    font-size: 36rpx;
    font-weight: 600;
    color: #333;
    margin-bottom: 16rpx;
  }

  .card-desc {
    font-size: 28rpx;
    color: #666;
    margin-bottom: 32rpx;
  }
}

.section {
  margin-bottom: 32rpx;

  &-title {
    font-size: 32rpx;
    font-weight: 500;
    color: #333;
    margin-bottom: 16rpx;
  }

  &-content {
    display: flex;
    flex-direction: column;
    gap: 12rpx;

    .item {
      font-size: 28rpx;
      color: #666;
      line-height: 40rpx;
    }
  }
}
</style>
