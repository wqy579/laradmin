# LarAdmin 公开网站

基于 Nuxt 4 的 SSR 公开网站，对接 LarAdmin 后台管理系统和 CMS 模块。

## 技术栈

| 依赖 | 版本 | 用途 |
|------|------|------|
| Nuxt | ^4.4 | SSR 框架 |
| TypeScript | - | 类型安全 |
| @nuxt/image | ^2.0 | 图片优化 |

## 功能特性

- **首页展示**：产品展示、新闻列表、特色功能等
- **内容页面**：关于我们、联系我们等静态页面
- **CMS 集成**：对接 CMS 模块的内容模型、栏目和内容
- **响应式设计**：适配桌面和移动设备
- **SEO 优化**：服务器端渲染，支持 SEO

## 目录结构

```
resources/web/
├── app/
│   ├── pages/              # 页面路由
│   │   ├── index.vue       # 首页
│   │   ├── about/
│   │   │   └── index.vue   # 关于我们
│   │   ├── news/
│   │   │   ├── index.vue   # 新闻列表
│   │   │   └── [id].vue    # 新闻详情
│   │   ├── products/
│   │   │   └── index.vue   # 产品列表
│   │   ├── cases/
│   │   │   ├── index.vue   # 案例列表
│   │   │   └── [id].vue    # 案例详情
│   │   └── contact/
│   │       └── index.vue   # 联系我们
│   ├── composables/         # 组合式函数
│   │   └── useCms.ts       # CMS API 封装
│   ├── components/          # 组件
│   │   ├── common/          # 通用组件
│   │   │   ├── Breadcrumb.vue
│   │   │   ├── ContentCard.vue
│   │   │   ├── DataState.vue
│   │   │   ├── PageBanner.vue
│   │   │   ├── Pagination.vue
│   │   │   ├── SiteFooter.vue
│   │   │   └── SiteHeader.vue
│   │   └── home/            # 首页组件
│   │       ├── CtaSection.vue
│   │       ├── FeaturesSection.vue
│   │       ├── HeroSection.vue
│   │       ├── LatestNews.vue
│   │       └── ProductsSection.vue
│   └── layouts/             # 布局
│       └── default.vue      # 默认布局
├── public/                  # 静态资源
├── nuxt.config.ts           # Nuxt 配置
├── tsconfig.json            # TypeScript 配置
└── package.json
```

## 开发命令

```bash
# 安装依赖
npm install
# 或使用 yarn
yarn install

# 启动开发服务器
npm run dev
# 或
yarn dev

# 生产构建
npm run build
# 或
yarn build

# 预览生产构建
npm run preview
# 或
yarn preview
```

## API 对接

通过 `useCms.ts` 对接后台 CMS API：

```typescript
// 获取栏目树
const { data: categories } = await useCms().getCategories('article')

// 获取内容列表
const { data: contents } = await useCms().getContents('article', { 
  category_id: 1,
  page: 1,
  page_size: 10
})

// 获取内容详情
const { data: content } = await useCms().getContent('article', 1)
```

## 配置说明

在 `nuxt.config.ts` 中配置 API 基础地址：

```typescript
export default defineNuxtConfig({
  runtimeConfig: {
    public: {
      apiBase: process.env.API_BASE_URL || 'http://localhost:8000'
    }
  }
})
```

或通过环境变量配置：

```bash
API_BASE_URL=https://api.example.com npm run dev
```
