<template>
  <sc-pages
    :show-navbar="false"
    :show-tabbar="true"
    :tabbar-list="tabbarList"
    :current-tab="currentTab"
    @tabbar-change="handleTabChange"
  >
    <!-- 内容区域 -->
    <view class="content">
      <view class="card">
        <view class="card-title">功能说明</view>
        <view class="card-content">
          <text class="item">• 隐藏顶部导航栏</text>
          <text class="item">• 显示底部 TabBar</text>
          <text class="item">• 内容区域占满全部高度</text>
          <text class="item">• 适合全屏内容页面</text>
        </view>
      </view>

      <view class="card">
        <view class="card-title">使用场景</view>
        <view class="card-content">
          <text class="item">• 启动页</text>
          <text class="item">• 引导页</text>
          <text class="item">• 全屏图片浏览</text>
          <text class="item">• 游戏页面</text>
          <text class="item">• 特殊展示页面</text>
        </view>
      </view>

      <view class="card">
        <view class="card-title">使用方式</view>
        <view class="code-block">
          <text class="code">
<sc-pages
  :show-navbar="false"
  :show-tabbar="true"
  :tabbar-list="tabbarList"
  :current-tab="currentTab"
  @tabbar-change="handleTabChange"
>
  <!-- 内容区域 -->
</sc-pages>
          </text>
        </view>
      </view>

      <view class="card">
        <view class="card-title">当前 Tab: {{ tabbarList[currentTab]?.text }}</view>
        <view class="card-content">
          <text class="item">点击底部 Tab 可以切换</text>
        </view>
      </view>

      <view class="card">
        <view class="card-title">滚动内容</view>
        <view class="scroll-content">
          <view v-for="i in 20" :key="i" class="scroll-item">
            列表项 {{ i }}
          </view>
        </view>
      </view>
    </view>
  </sc-pages>
</template>

<script setup>
import { ref } from 'vue'

// 当前选中的 Tab
const currentTab = ref(0)

// TabBar 列表
const tabbarList = ref([
  {
    text: '首页',
    iconPath: 'home',
    selectedIconPath: 'home-fill'
  },
  {
    text: '发现',
    iconPath: 'compass',
    selectedIconPath: 'compass-fill'
  },
  {
    text: '消息',
    iconPath: 'message',
    selectedIconPath: 'message-fill',
    badge: '3'
  },
  {
    text: '我的',
    iconPath: 'user',
    selectedIconPath: 'user-fill'
  }
])

// Tab 切换事件
const handleTabChange = (index, item) => {
  console.log('切换到 tab:', index, item)
  currentTab.value = index
  uni.showToast({
    title: `切换到 ${item.text}`,
    icon: 'none'
  })
}
</script>

<style scoped lang="scss">
.content {
  padding: 32rpx;
}

.card {
  margin-bottom: 32rpx;
  padding: 32rpx;
  background: #fff;
  border-radius: 16rpx;

  .card-title {
    font-size: 32rpx;
    font-weight: 600;
    color: #333;
    margin-bottom: 16rpx;
  }

  .card-content {
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

.code-block {
  padding: 24rpx;
  background: #f5f5f5;
  border-radius: 8rpx;
  border-left: 4rpx solid #007AFF;

  .code {
    font-size: 24rpx;
    color: #333;
    line-height: 36rpx;
    white-space: pre-wrap;
  }
}

.scroll-content {
  .scroll-item {
    padding: 24rpx 0;
    border-bottom: 1rpx solid #f0f0f0;
    font-size: 28rpx;
    color: #666;

    &:last-child {
      border-bottom: none;
    }
  }
}
</style>
