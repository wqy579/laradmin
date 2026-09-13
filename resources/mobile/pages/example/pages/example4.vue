<template>
  <sc-pages
    title="自定义导航栏"
    :show-back="false"
    :navbar-bg-color="navbarBgColor"
    :navbar-color="navbarColor"
    :right-icon="rightIcon"
    @right-click="handleRightClick"
  >
    <!-- 自定义左侧内容 -->
    <template #navbar-left>
      <view class="custom-left">
        <image
          src="/static/logo.png"
          mode="aspectFit"
          style="width: 40px; height: 40px; border-radius: 8px;"
        />
      </view>
    </template>

    <!-- 自定义标题 -->
    <template #navbar-title>
      <view class="custom-title">
        <text class="title-text">自定义标题</text>
        <text class="title-subtitle">副标题</text>
      </view>
    </template>

    <!-- 内容区域 -->
    <view class="content">
      <view class="card">
        <view class="card-title">功能说明</view>
        <view class="card-content">
          <text class="item">• 自定义左侧内容</text>
          <text class="item">• 自定义标题（支持多行）</text>
          <text class="item">• 自定义右侧按钮</text>
          <text class="item">• 自定义导航栏颜色</text>
        </view>
      </view>

      <view class="card">
        <view class="card-title">导航栏配置</view>
        <view class="config-item">
          <text class="label">背景色:</text>
          <text class="value">{{ navbarBgColor }}</text>
        </view>
        <view class="config-item">
          <text class="label">文字色:</text>
          <text class="value">{{ navbarColor }}</text>
        </view>
        <view class="config-item">
          <text class="label">右侧图标:</text>
          <text class="value">{{ rightIcon }}</text>
        </view>
      </view>

      <view class="card">
        <view class="card-title">主题切换</view>
        <view class="theme-switch">
          <button
            v-for="theme in themes"
            :key="theme.name"
            :class="{ active: currentTheme === theme.name }"
            @click="switchTheme(theme)"
          >
            {{ theme.label }}
          </button>
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

// 导航栏配置
const navbarBgColor = ref('#FFFFFF')
const navbarColor = ref('#000000')
const rightIcon = ref('more')

// 当前主题
const currentTheme = ref('default')

// 主题配置
const themes = [
  { name: 'default', label: '默认', bg: '#FFFFFF', color: '#000000' },
  { name: 'primary', label: '主题蓝', bg: '#007AFF', color: '#FFFFFF' },
  { name: 'dark', label: '深色', bg: '#000000', color: '#FFFFFF' }
]

// 切换主题
const switchTheme = (theme) => {
  currentTheme.value = theme.name
  navbarBgColor.value = theme.bg
  navbarColor.value = theme.color
}

// 右侧按钮点击
const handleRightClick = () => {
  uni.showActionSheet({
    itemList: ['选项1', '选项2', '选项3'],
    success: (res) => {
      console.log('选择了:', res.tapIndex)
    }
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

.config-item {
  display: flex;
  justify-content: space-between;
  padding: 16rpx 0;
  border-bottom: 1rpx solid #f0f0f0;

  &:last-child {
    border-bottom: none;
  }

  .label {
    font-size: 28rpx;
    color: #666;
  }

  .value {
    font-size: 28rpx;
    color: #333;
    font-weight: 500;
  }
}

.theme-switch {
  display: flex;
  gap: 16rpx;

  button {
    flex: 1;
    height: 72rpx;
    line-height: 72rpx;
    font-size: 28rpx;
    background: #f5f5f5;
    color: #333;
    border: none;
    border-radius: 8rpx;

    &.active {
      background: #007AFF;
      color: #fff;
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

.custom-left {
  display: flex;
  align-items: center;
}

.custom-title {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4rpx;

  .title-text {
    font-size: 18px;
    font-weight: 500;
    color: inherit;
  }

  .title-subtitle {
    font-size: 12px;
    color: inherit;
    opacity: 0.7;
  }
}
</style>
