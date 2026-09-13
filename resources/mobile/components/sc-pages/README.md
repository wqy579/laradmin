# sc-pages 组件

功能完善的移动端页面框架组件，基于 Vue 3 + Composition API 开发，集成了 uni-nav-bar 和 sc-tabbar 组件。

## 功能特性

- ✅ 顶部导航栏（基于 uni-nav-bar）
  - 支持自定义标题、左右按钮
  - 支持沉浸式状态栏
  - 支持自定义主题色
  - 支持自定义插槽
- ✅ 中间内容区域
  - Flex 布局，占满剩余高度
  - 滚动条只在内容区域显示
  - 支持下拉刷新
  - 支持滚动事件监听
- ✅ 底部 TabBar（基于 sc-tabbar）
  - 支持图标、徽标、红点
  - 可在不同页面移除
  - 支持主题自定义
- ✅ 响应式布局
  - 自动适配不同屏幕
  - 支持底部安全区适配

## 安装使用

由于项目已配置 easycom 组件自动引入，无需手动 import，直接在模板中使用即可。

### 基础用法

```vue
<template>
  <sc-pages
    title="页面标题"
    show-back
  >
    <!-- 内容区域 -->
    <view class="content">
      <text>页面内容</text>
    </view>
  </sc-pages>
</template>

<script setup>
// 无需额外代码
</script>
```

## Props 属性

### 页面基础配置

| 属性名 | 类型 | 默认值 | 说明 |
|--------|------|--------|------|
| title | String | '' | 页面标题 |
| pageStyle | String, Object | '' | 页面样式 |
| fixed | Boolean | true | 是否固定定位 |

### 导航栏配置

| 属性名 | 类型 | 默认值 | 说明 |
|--------|------|--------|------|
| showNavbar | Boolean | true | 是否显示导航栏 |
| showBack | Boolean | true | 是否显示返回按钮 |
| navbarBgColor | String | '#FFFFFF' | 导航栏背景色 |
| navbarColor | String | '#000000' | 导航栏文字颜色 |
| statusBar | Boolean | true | 是否沉浸式状态栏 |
| shadow | Boolean | false | 是否显示阴影 |
| border | Boolean | true | 是否显示边框 |
| leftIcon | String | 'left' | 左侧图标 |
| rightIcon | String | '' | 右侧图标 |
| titleWidth | String | '' | 标题宽度 |
| dark | Boolean | false | 暗黑模式 |

### 内容区域配置

| 属性名 | 类型 | 默认值 | 说明 |
|--------|------|--------|------|
| scrollTop | Number | 0 | 滚动条位置 |
| scrollWithAnimation | Boolean | false | 是否使用滚动动画 |
| showScrollbar | Boolean | false | 是否显示滚动条 |
| refresherEnabled | Boolean | false | 是否开启下拉刷新 |
| refresherTriggered | Boolean | false | 下拉刷新状态 |
| refresherBackground | String | '#FFFFFF' | 下拉刷新背景色 |

### TabBar 配置

| 属性名 | 类型 | 默认值 | 说明 |
|--------|------|--------|------|
| showTabbar | Boolean | false | 是否显示 TabBar |
| tabbarList | Array | [] | TabBar 列表 |
| currentTab | Number | 0 | 当前选中的 Tab |
| safeAreaInsetBottom | Boolean | true | 是否开启底部安全区适配 |
| tabbarActiveColor | String | '#007AFF' | TabBar 选中颜色 |
| tabbarInactiveColor | String | '#999999' | TabBar 未选中颜色 |
| tabbarBgColor | String | '#FFFFFF' | TabBar 背景颜色 |
| tabbarBorderColor | String | '#E5E5E5' | TabBar 边框颜色 |

## Events 事件

### 导航栏事件

| 事件名 | 参数 | 说明 |
|--------|------|------|
| back | - | 返回按钮点击时触发 |
| left-click | - | 左侧按钮点击时触发 |
| right-click | - | 右侧按钮点击时触发 |

### 内容区域事件

| 事件名 | 参数 | 说明 |
|--------|------|------|
| scroll | e | 滚动时触发 |
| scrolltoupper | e | 滚动到顶部时触发 |
| scrolltolower | e | 滚动到底部时触发 |
| refresherrefresh | e | 下拉刷新时触发 |
| refresherrestore | e | 下拉刷新结束时触发 |

### TabBar 事件

| 事件名 | 参数 | 说明 |
|--------|------|------|
| tabbar-change | (index, item) | Tab 切换时触发 |
| tabbar-click | (index, item) | Tab 点击时触发 |

## Slots 插槽

| 插槽名 | 说明 | 参数 |
|--------|------|------|
| default | 默认内容（页面内容） | - |
| navbar-left | 自定义导航栏左侧内容 | - |
| navbar-title | 自定义导航栏标题 | - |
| navbar-right | 自定义导航栏右侧内容 | - |
| tabbar-icon | 自定义 TabBar 图标 | { item, index, active } |

## 使用示例

### 1. 基础用法（带导航栏）

```vue
<template>
  <sc-pages title="基础用法" show-back>
    <view class="content">
      <text>页面内容</text>
    </view>
  </sc-pages>
</template>
```

### 2. 完整页面（带 TabBar）

```vue
<template>
  <sc-pages
    title="完整页面"
    :show-tabbar="true"
    :tabbar-list="tabbarList"
    :current-tab="currentTab"
    @tabbar-change="handleTabChange"
  >
    <view class="content">
      <text>页面内容</text>
    </view>
  </sc-pages>
</template>

<script setup>
import { ref } from 'vue'

const currentTab = ref(0)
const tabbarList = ref([
  { text: '首页', iconPath: 'home', selectedIconPath: 'home-fill' },
  { text: '消息', iconPath: 'message', selectedIconPath: 'message-fill', badge: '5' }
])

const handleTabChange = (index, item) => {
  console.log('切换到 tab:', index, item)
}
</script>
```

### 3. 下拉刷新

```vue
<template>
  <sc-pages
    title="下拉刷新"
    :refresher-enabled="true"
    :refresher-triggered="isRefreshing"
    @refresherrefresh="handleRefresh"
  >
    <view class="content">
      <text>页面内容</text>
    </view>
  </sc-pages>
</template>

<script setup>
import { ref } from 'vue'

const isRefreshing = ref(false)

const handleRefresh = async () => {
  isRefreshing.value = true
  // 执行刷新逻辑
  setTimeout(() => {
    isRefreshing.value = false
  }, 1500)
}
</script>
```

### 4. 自定义导航栏

```vue
<template>
  <sc-pages
    :show-back="false"
    :navbar-bg-color="navbarBgColor"
    :navbar-color="navbarColor"
  >
    <!-- 自定义左侧内容 -->
    <template #navbar-left>
      <image src="/static/logo.png" mode="aspectFit" />
    </template>

    <!-- 自定义标题 -->
    <template #navbar-title>
      <view class="custom-title">
        <text>自定义标题</text>
        <text>副标题</text>
      </view>
    </template>

    <!-- 自定义右侧内容 -->
    <template #navbar-right>
      <uni-icons type="more" :size="20" />
    </template>

    <view class="content">
      <text>页面内容</text>
    </view>
  </sc-pages>
</template>

<script setup>
import { ref } from 'vue'

const navbarBgColor = ref('#FFFFFF')
const navbarColor = ref('#000000')
</script>
```

### 5. 无导航栏

```vue
<template>
  <sc-pages :show-navbar="false" :show-tabbar="true">
    <view class="content">
      <text>全屏内容</text>
    </view>
  </sc-pages>
</template>
```

### 6. 无 TabBar

```vue
<template>
  <sc-pages title="普通页面">
    <view class="content">
      <text>页面内容</text>
    </view>
  </sc-pages>
</template>
```

## 布局说明

组件采用 Flex 布局结构：

```
sc-pages (flex-direction: column, height: 100vh)
├── uni-nav-bar (固定高度)
├── scroll-view (flex: 1, 占满剩余高度)
│   └── view (内容区域)
└── sc-tabbar (可选，固定高度)
```

- 整体容器使用 `flex-direction: column` 垂直布局
- 中间内容区域使用 `flex: 1` 占据剩余全部高度
- 滚动条只在 scroll-view 区域显示
- 导航栏和 TabBar 根据配置动态显示/隐藏

## 注意事项

1. **TabBar 配置**: `show-tabbar` 为 `false` 时，TabBar 不会渲染，可在不同页面灵活控制
2. **导航栏配置**: `show-navbar` 为 `false` 时，导航栏不会渲染，适合全屏页面
3. **下拉刷新**: 使用 `refresher-triggered` 控制刷新状态，刷新完成后需将其设为 `false`
4. **滚动事件**: 监听 `scrolltolower` 事件实现上拉加载更多
5. **安全区适配**: iPhone X 及以上设备会自动适配底部安全区

## 完整示例

查看 `resources/mobile/pages/example/pages/` 目录下的示例页面：

- `example1.vue` - 基础用法
- `example2.vue` - 完整页面（带 TabBar）
- `example3.vue` - 下拉刷新
- `example4.vue` - 自定义导航栏
- `example5.vue` - 无导航栏

## License

MIT
