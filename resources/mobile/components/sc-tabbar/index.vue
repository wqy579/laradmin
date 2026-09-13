<template>
  <view class="sc-tabbar" :class="[{ 'sc-tabbar--fixed': fixed }, customClass]">
    <view
      v-for="(item, index) in list"
      :key="index"
      class="sc-tabbar__item"
      :class="{ 'sc-tabbar__item--active': currentIndex === index }"
      @click="handleClick(index, item)"
    >
      <view class="sc-tabbar__icon">
        <slot name="icon" :item="item" :index="index" :active="currentIndex === index">
          <text
            class="iconfont sc-tabbar__icon-text"
            :class="currentIndex === index ? item.selectedIconPath || item.iconPath : item.iconPath"
          ></text>
        </slot>
        <view v-if="item.badge && item.badge !== '0'" class="sc-tabbar__badge">
          <text class="sc-tabbar__badge-text">{{ item.badge }}</text>
        </view>
        <view v-if="item.dot" class="sc-tabbar__dot"></view>
      </view>
      <text class="sc-tabbar__text">{{ item.text }}</text>
    </view>
    <view v-if="safeAreaInsetBottom" class="sc-tabbar__safearea"></view>
  </view>
</template>

<script setup>
import { ref, watch, onMounted } from 'vue'

const props = defineProps({
  // tab 列表
  list: {
    type: Array,
    default: () => []
  },
  // 当前选中的索引
  modelValue: {
    type: Number,
    default: 0
  },
  // 是否固定在底部
  fixed: {
    type: Boolean,
    default: true
  },
  // 是否开启底部安全区适配
  safeAreaInsetBottom: {
    type: Boolean,
    default: true
  },
  // 选中颜色
  activeColor: {
    type: String,
    default: '#007AFF'
  },
  // 未选中颜色
  inactiveColor: {
    type: String,
    default: '#999999'
  },
  // 背景颜色
  bgColor: {
    type: String,
    default: '#FFFFFF'
  },
  // 边框颜色
  borderColor: {
    type: String,
    default: '#E5E5E5'
  },
  // 自定义类名
  customClass: {
    type: String,
    default: ''
  }
})

const emit = defineEmits(['update:modelValue', 'change', 'click'])

const currentIndex = ref(props.modelValue)

// 监听外部 modelValue 变化
watch(() => props.modelValue, (newVal) => {
  currentIndex.value = newVal
})

// 处理点击事件
const handleClick = (index, item) => {
  if (currentIndex.value === index) return

  currentIndex.value = index
  emit('update:modelValue', index)
  emit('change', index, item)
  emit('click', index, item)
}

// 获取当前激活的 tab
const getCurrentTab = () => {
  return props.list[currentIndex.value]
}

// 暴露方法给父组件
defineExpose({
  getCurrentTab
})
</script>

<style scoped lang="scss">
.sc-tabbar {
  display: flex;
  align-items: center;
  justify-content: space-around;
  width: 100%;
  height: 100rpx;
  background: v-bind('bgColor');
  border-top: 1rpx solid v-bind('borderColor');
  position: relative;
  z-index: 999;

  &--fixed {
    position: fixed;
    bottom: 0;
    left: 0;
  }

  &__item {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    height: 100%;
    position: relative;
    transition: all 0.3s ease;

    &--active {
      .sc-tabbar__text {
        color: v-bind('activeColor');
      }
    }
  }

  &__icon {
    position: relative;
    width: 48rpx;
    height: 48rpx;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 4rpx;
  }

  &__icon-text {
    font-size: 48rpx;
    color: v-bind('inactiveColor');
    transition: color 0.3s ease;

    .sc-tabbar__item--active & {
      color: v-bind('activeColor');
    }
  }

  &__text {
    font-size: 20rpx;
    line-height: 28rpx;
    color: v-bind('inactiveColor');
    transition: color 0.3s ease;
  }

  // 徽标样式
  &__badge {
    position: absolute;
    top: 0;
    right: -8rpx;
    min-width: 32rpx;
    height: 32rpx;
    padding: 0 8rpx;
    background: #FF4444;
    border-radius: 16rpx;
    display: flex;
    align-items: center;
    justify-content: center;
    transform: translate(100%, -50%);

    &-text {
      font-size: 20rpx;
      line-height: 28rpx;
      color: #FFFFFF;
      white-space: nowrap;
    }
  }

  // 圆点样式
  &__dot {
    position: absolute;
    top: 0;
    right: 0;
    width: 16rpx;
    height: 16rpx;
    background: #FF4444;
    border-radius: 50%;
    transform: translate(100%, -50%);
  }

  // 底部安全区
  &__safearea {
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    height: 0;
    padding-bottom: constant(safe-area-inset-bottom);
    padding-bottom: env(safe-area-inset-bottom);
  }
}
</style>
