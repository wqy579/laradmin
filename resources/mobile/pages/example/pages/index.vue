<template>
  <view class="pages-example">
    <!-- 示例 1: 基础用法（带导航栏） -->
    <view class="example-section">
      <view class="example-title">示例 1: 基础用法（带导航栏）</view>
      <view class="example-desc">显示顶部导航栏，带返回按钮，内容区域可滚动</view>
      <button @click="showExample1">查看示例</button>
    </view>

    <!-- 示例 2: 完整页面（带 TabBar） -->
    <view class="example-section">
      <view class="example-title">示例 2: 完整页面（带 TabBar）</view>
      <view class="example-desc">显示顶部导航栏和底部 TabBar，完整页面结构</view>
      <button @click="showExample2">查看示例</button>
    </view>

    <!-- 示例 3: 下拉刷新 -->
    <view class="example-section">
      <view class="example-title">示例 3: 下拉刷新</view>
      <view class="example-desc">开启下拉刷新功能</view>
      <button @click="showExample3">查看示例</button>
    </view>

    <!-- 示例 4: 自定义导航栏 -->
    <view class="example-section">
      <view class="example-title">示例 4: 自定义导航栏</view>
      <view class="example-desc">自定义导航栏标题和右侧按钮</view>
      <button @click="showExample4">查看示例</button>
    </view>

    <!-- 示例 5: 无导航栏 -->
    <view class="example-section">
      <view class="example-title">示例 5: 无导航栏</view>
      <view class="example-desc">隐藏顶部导航栏</view>
      <button @click="showExample5">查看示例</button>
    </view>
  </view>
</template>

<script setup>
// 跳转到示例 1
const showExample1 = () => {
  uni.navigateTo({
    url: '/pages/example/pages/example1'
  })
}

// 跳转到示例 2
const showExample2 = () => {
  uni.navigateTo({
    url: '/pages/example/pages/example2'
  })
}

// 跳转到示例 3
const showExample3 = () => {
  uni.navigateTo({
    url: '/pages/example/pages/example3'
  })
}

// 跳转到示例 4
const showExample4 = () => {
  uni.navigateTo({
    url: '/pages/example/pages/example4'
  })
}

// 跳转到示例 5
const showExample5 = () => {
  uni.navigateTo({
    url: '/pages/example/pages/example5'
  })
}
</script>

<style scoped lang="scss">
.pages-example {
  min-height: 100vh;
  padding: 32rpx;
  background: #f5f5f5;
}

.example-section {
  margin-bottom: 32rpx;
  padding: 32rpx;
  background: #fff;
  border-radius: 16rpx;

  .example-title {
    font-size: 32rpx;
    font-weight: 600;
    color: #333;
    margin-bottom: 16rpx;
  }

  .example-desc {
    font-size: 28rpx;
    color: #666;
    margin-bottom: 24rpx;
    line-height: 40rpx;
  }

  button {
    width: 100%;
    height: 80rpx;
    line-height: 80rpx;
    font-size: 28rpx;
    background: #007AFF;
    color: #fff;
    border: none;
    border-radius: 12rpx;
  }
}
</style>
