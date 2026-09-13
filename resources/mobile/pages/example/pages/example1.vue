<template>
  <sc-pages title="基础用法" show-back>
    <!-- 内容区域 -->
    <view class="content">
      <view class="card">
        <view class="card-title">功能说明</view>
        <view class="card-content">
          <text class="item">• 显示顶部导航栏</text>
          <text class="item">• 带返回按钮</text>
          <text class="item">• 内容区域可滚动</text>
          <text class="item">• Flex 布局自适应</text>
        </view>
      </view>

      <view class="card">
        <view class="card-title">使用方式</view>
        <view class="code-block">
          <text class="code">
<sc-pages
  title="基础用法"
  show-back
>
  <!-- 内容区域 -->
</sc-pages>
          </text>
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
