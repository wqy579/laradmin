<template>
  <sc-pages title="首页">
    <!-- 内容区域 -->
    <view class="content">
      <view class="welcome-card">
        <view class="welcome-title">欢迎使用 UniApp</view>
        <view class="welcome-desc">移动端组件库示例</view>
      </view>

      <view class="section-card">
        <view class="section-title">组件列表</view>
        <view class="component-list">
          <view
            v-for="item in components"
            :key="item.path"
            class="component-item"
            @click="navigateTo(item.path)"
          >
            <view class="item-icon">
              <uni-icons :type="item.icon" :size="24" color="#007AFF"></uni-icons>
            </view>
            <view class="item-info">
              <view class="item-title">{{ item.title }}</view>
              <view class="item-desc">{{ item.desc }}</view>
            </view>
            <view class="item-arrow">
              <uni-icons type="right" :size="18" color="#999"></uni-icons>
            </view>
          </view>
        </view>
      </view>
    </view>
  </sc-pages>
</template>

<script setup>
// 组件列表
const components = [
  {
    title: 'sc-pages 页面框架',
    desc: '功能完善的页面框架组件',
    icon: 'gear',
    path: '/pages/example/pages/index'
  },
  {
    title: 'sc-tabbar 底部导航',
    desc: '功能完善的底部导航栏组件',
    icon: 'nav',
    path: '/pages/example/tabbar/index'
  }
]

// 跳转到示例页面
const navigateTo = (path) => {
  uni.navigateTo({
    url: path,
    fail: (err) => {
      console.error('页面跳转失败:', err)
      uni.showToast({
        title: '页面跳转失败',
        icon: 'none'
      })
    }
  })
}
</script>

<style scoped lang="scss">
.content {
  padding: 32rpx;
}

.welcome-card {
  padding: 48rpx 32rpx;
  background: linear-gradient(135deg, #007AFF 0%, #00C6FF 100%);
  border-radius: 16rpx;
  text-align: center;
  margin-bottom: 32rpx;

  .welcome-title {
    font-size: 40rpx;
    font-weight: 600;
    color: #fff;
    margin-bottom: 16rpx;
  }

  .welcome-desc {
    font-size: 28rpx;
    color: rgba(255, 255, 255, 0.9);
  }
}

.section-card {
  padding: 32rpx;
  background: #fff;
  border-radius: 16rpx;

  .section-title {
    font-size: 32rpx;
    font-weight: 600;
    color: #333;
    margin-bottom: 24rpx;
  }
}

.component-list {
  display: flex;
  flex-direction: column;
  gap: 24rpx;
}

.component-item {
  display: flex;
  align-items: center;
  padding: 24rpx;
  background: #f8f8f8;
  border-radius: 12rpx;
  transition: all 0.3s ease;

  &:active {
    background: #e8e8e8;
    transform: scale(0.98);
  }
}

.item-icon {
  width: 64rpx;
  height: 64rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(0, 122, 255, 0.1);
  border-radius: 16rpx;
  margin-right: 24rpx;
}

.item-info {
  flex: 1;

  .item-title {
    font-size: 32rpx;
    font-weight: 500;
    color: #333;
    margin-bottom: 8rpx;
  }

  .item-desc {
    font-size: 24rpx;
    color: #999;
  }
}

.item-arrow {
  margin-left: 16rpx;
}
</style>
