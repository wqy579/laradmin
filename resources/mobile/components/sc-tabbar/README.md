# sc-tabbar 组件

功能完善的移动端底部导航栏组件，基于 Vue 3 + Composition API 开发。

## 功能特性

- ✅ 支持自定义图标（iconfont）
- ✅ 支持徽标显示（badge）
- ✅ 支持红点显示（dot）
- ✅ 支持自定义主题色
- ✅ 支持底部安全区适配
- ✅ 支持 v-model 双向绑定
- ✅ 支持固定定位
- ✅ 支持自定义图标插槽
- ✅ 流畅的过渡动画

## 安装使用

由于项目已配置 easycom 组件自动引入，无需手动 import，直接在模板中使用即可。

```vue
<template>
  <sc-tabbar
    v-model="currentTab"
    :list="tabList"
    @change="handleTabChange"
  />
</template>

<script setup>
import { ref } from 'vue'

const currentTab = ref(0)

const tabList = ref([
  {
    text: '首页',
    iconPath: 'home',
    selectedIconPath: 'home-fill'
  },
  {
    text: '消息',
    iconPath: 'message',
    selectedIconPath: 'message-fill',
    badge: '5'
  }
])

const handleTabChange = (index, item) => {
  console.log('切换到 tab:', index, item)
}
</script>
```

## Props 属性

| 属性名 | 类型 | 默认值 | 说明 |
|--------|------|--------|------|
| list | Array | [] | tab 列表数据 |
| modelValue | Number | 0 | 当前选中的索引（v-model 绑定） |
| fixed | Boolean | true | 是否固定在底部 |
| safeAreaInsetBottom | Boolean | true | 是否开启底部安全区适配 |
| activeColor | String | '#007AFF' | 选中颜色 |
| inactiveColor | String | '#999999' | 未选中颜色 |
| bgColor | String | '#FFFFFF' | 背景颜色 |
| borderColor | String | '#E5E5E5' | 边框颜色 |
| customClass | String | '' | 自定义类名 |

## list 数据结构

```javascript
{
  text: '首页',              // tab 文本
  iconPath: 'home',          // 未选中图标（iconfont 类名）
  selectedIconPath: 'home-fill',  // 选中图标（iconfont 类名）
  badge: '5',                // 徽标数字（可选）
  dot: true,                 // 是否显示红点（可选）
  pagePath: '/pages/index/index'  // 跳转路径（可选）
}
```

## Events 事件

| 事件名 | 参数 | 说明 |
|--------|------|------|
| update:modelValue | index | 当前选中索引更新时触发 |
| change | (index, item) | tab 切换时触发 |
| click | (index, item) | 点击 tab 时触发 |

## Slots 插槽

| 插槽名 | 说明 | 参数 |
|--------|------|------|
| icon | 自定义图标内容 | { item, index, active } |

## 使用示例

### 基础用法

```vue
<template>
  <sc-tabbar
    v-model="currentTab"
    :list="tabList"
  />
</template>

<script setup>
import { ref } from 'vue'

const currentTab = ref(0)

const tabList = ref([
  { text: '首页', iconPath: 'home', selectedIconPath: 'home-fill' },
  { text: '发现', iconPath: 'compass', selectedIconPath: 'compass-fill' },
  { text: '我的', iconPath: 'user', selectedIconPath: 'user-fill' }
])
</script>
```

### 显示徽标

```vue
<template>
  <sc-tabbar
    v-model="currentTab"
    :list="tabList"
  />
</template>

<script setup>
const tabList = ref([
  { 
    text: '首页', 
    iconPath: 'home', 
    selectedIconPath: 'home-fill' 
  },
  { 
    text: '消息', 
    iconPath: 'message', 
    selectedIconPath: 'message-fill',
    badge: '5'  // 显示徽标数字
  },
  { 
    text: '我的', 
    iconPath: 'user', 
    selectedIconPath: 'user-fill',
    dot: true  // 显示红点
  }
])
</script>
```

### 自定义主题色

```vue
<template>
  <sc-tabbar
    v-model="currentTab"
    :list="tabList"
    active-color="#FF6B6B"
    inactive-color="#CCCCCC"
    bg-color="#2C3E50"
    border-color="#34495E"
  />
</template>
```

### 自定义图标插槽

```vue
<template>
  <sc-tabbar
    v-model="currentTab"
    :list="tabList"
  >
    <template #icon="{ item, index, active }">
      <!-- 自定义图标内容 -->
      <image 
        :src="active ? item.selectedIconPath : item.iconPath" 
        mode="aspectFit"
        style="width: 48rpx; height: 48rpx;"
      />
    </template>
  </sc-tabbar>
</template>
```

### 页面跳转

```vue
<template>
  <sc-tabbar
    v-model="currentTab"
    :list="tabList"
    @change="handleTabChange"
  />
</template>

<script setup>
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
  }
])

const handleTabChange = (index, item) => {
  if (item.pagePath) {
    // 如果是 tabBar 页面
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
```

### 配置 pages.json

如果需要在 tabBar 中配置页面，需要在 `pages.json` 中配置：

```json
{
  "tabBar": {
    "color": "#999999",
    "selectedColor": "#007AFF",
    "backgroundColor": "#FFFFFF",
    "borderStyle": "black",
    "list": [
      {
        "pagePath": "pages/index/index",
        "text": "首页",
        "iconPath": "static/icons/home.png",
        "selectedIconPath": "static/icons/home-active.png"
      },
      {
        "pagePath": "pages/discover/index",
        "text": "发现",
        "iconPath": "static/icons/compass.png",
        "selectedIconPath": "static/icons/compass-active.png"
      }
    ]
  }
}
```

## 注意事项

1. **图标字体**：组件默认使用 iconfont 图标库，请确保已引入对应的图标字体文件
2. **页面跳转**：建议在 pages.json 中配置 tabBar 页面，使用 `uni.switchTab` 跳转
3. **徽标显示**：badge 和 dot 不能同时设置，优先显示 badge
4. **安全区适配**：iPhone X 及以上设备会自动适配底部安全区
5. **fixed 定位**：设置为 fixed 时，组件会固定在页面底部

## 完整示例

查看 `resources/mobile/pages/example/tabbar/index.vue` 获取完整的使用示例。

## License

MIT
