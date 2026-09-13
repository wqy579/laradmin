<template>
  <sc-pages
    title="完整页面"
    :show-tabbar="true"
    :tabbar-list="tabbarList"
    :current-tab="currentTab"
    @tabbar-change="handleTabChange"
  >
    <!-- 自定义导航栏右侧按钮 -->
    <template #navbar-right>
      <view class="navbar-action" @click="handleShare">
        <uni-icons type="redo" :size="20" color="#000000"></uni-icons>
      </view>
    </template>

    <!-- 内容区域 -->
    <view class="content">
      <view class="card">
        <view class="card-title">功能说明</view>
        <view class="card-content">
          <text class="item">• 显示顶部导航栏</text>
          <text class="item">• 显示底部 TabBar</text>
          <text class="item">• 支持 TabBar 切换</text>
          <text class="item">• Flex 布局自适应</text>
          <text class="item">• 滚动条只在内容区域显示</text>
        </view>
      </view>

      <view class="card">
        <view class="card-title">当前 Tab: {{ tabbarList[currentTab]?.text }}</view>
        <view class="card-content">
          <text class="item">TabBar 列表项: {{ tabbarList.length }} 个</text>
          <text class="item">点击底部 Tab 可以切换页面</text>
        </view>
      </view>

      <view class="card">
        <view class="card-title">滚动内容</view>
        <view class="scroll-content">
          <view v-for="i in 30" :key="i" class="scroll-item">
            {{ tabbarList[currentTab]?.text }} - 列表项 {{ i }}
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
    badge: '5'
  },
  {
    text: '我的',
    iconPath: 'user',
    selectedIconPath: 'user-fill',
    dot: true
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

// 分享按钮
const handleShare = () => {
  uni.showToast({
    title: '分享功能',
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

.navbar-action {
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
</style>
