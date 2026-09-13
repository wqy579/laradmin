<template>
  <sc-pages
    title="下拉刷新"
    :refresher-enabled="true"
    :refresher-triggered="isRefreshing"
    @refresherrefresh="handleRefresh"
  >
    <!-- 内容区域 -->
    <view class="content">
      <view class="card">
        <view class="card-title">功能说明</view>
        <view class="card-content">
          <text class="item">• 开启下拉刷新功能</text>
          <text class="item">• 下拉时显示刷新指示器</text>
          <text class="item">• 刷新完成后自动收起</text>
        </view>
      </view>

      <view class="card">
        <view class="card-title">使用方式</view>
        <view class="code-block">
          <text class="code">
<sc-pages
  title="下拉刷新"
  :refresher-enabled="true"
  :refresher-triggered="isRefreshing"
  @refresherrefresh="handleRefresh"
>
  <!-- 内容区域 -->
</sc-pages>
          </text>
        </view>
      </view>

      <view class="card">
        <view class="card-title">刷新状态</view>
        <view class="card-content">
          <text class="item">当前状态: {{ refreshStatus }}</text>
          <text class="item">刷新次数: {{ refreshCount }}</text>
        </view>
      </view>

      <view class="card">
        <view class="card-title">滚动内容（下拉刷新）</view>
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

// 刷新状态
const isRefreshing = ref(false)
const refreshStatus = ref('未刷新')
const refreshCount = ref(0)

// 下拉刷新处理
const handleRefresh = async () => {
  refreshStatus.value = '刷新中...'
  isRefreshing.value = true

  // 模拟异步请求
  setTimeout(() => {
    refreshStatus.value = '刷新完成'
    refreshCount.value++
    isRefreshing.value = false

    uni.showToast({
      title: '刷新成功',
      icon: 'success'
    })
  }, 1500)
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
