<template>
  <section class="products section">
    <div class="container">
      <div class="section-header">
        <span class="badge">公司产品</span>
        <h2>核心产品</h2>
        <p>自主研发的企业级应用产品，经过大量客户验证</p>
      </div>

      <div class="product-showcase">
        <div v-for="product in products" :key="product.name" class="product-item" :id="product.id">
          <div class="product-visual" :style="{ background: product.bg }">
            <div class="product-icon" v-html="product.icon"></div>
          </div>
          <div class="product-body">
            <h3>{{ product.name }}</h3>
            <p class="product-tagline">{{ product.tagline }}</p>
            <p class="product-desc">{{ product.desc }}</p>
            <ul class="product-features">
              <li v-for="f in product.features" :key="f">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--color-success)" stroke-width="2.5"><path d="M20 6L9 17l-5-5" /></svg>
                {{ f }}
              </li>
            </ul>
            <div class="product-actions">
              <NuxtLink :to="product.link" class="btn btn-primary">了解详情</NuxtLink>
              <NuxtLink to="/contact" class="btn btn-outline">申请试用</NuxtLink>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>
</template>

<script setup lang="ts">
const products = [
  {
    id: 'sentcms',
    name: 'SentCMS',
    tagline: '灵活高效的内容管理系统',
    desc: 'SentCMS 是南昌腾速科技倾力打造的内容管理系统，继承 ThinkPHP 优秀品质，秉承"大道至简"的设计理念。支持自定义内容模型、树形栏目管理、多端内容发布，为网站建设减少 90% 的代码编写，完全开源。',
    features: ['自定义内容模型与字段', '树形栏目管理', '多状态内容发布流程', 'RESTful 公开 API', '多端适配发布'],
    bg: 'linear-gradient(135deg, #faf5ff, #ede9fe)',
    icon: '<svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="1.5"><path d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z"/></svg>',
    link: '/products#sentcms',
  },
  {
    id: 'laradmin',
    name: 'LarAdmin',
    tagline: '现代化后台管理框架',
    desc: 'LarAdmin 基于 Laravel + Vue3 构建，提供开箱即用的 RBAC 权限管理、模块化架构、代码生成器，大幅提升后台管理系统开发效率。',
    features: ['Laravel + Vue3 全栈', 'RBAC 权限管理', '模块化插件架构', '动态菜单与路由', '响应式管理后台'],
    bg: 'linear-gradient(135deg, #ecfeff, #e0f2fe)',
    icon: '<svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#0891b2" stroke-width="1.5"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg>',
    link: '/products#laradmin',
  },
]
</script>

<style scoped>
.product-showcase {
  display: flex;
  flex-direction: column;
  gap: 3rem;
}

.product-item {
  display: grid;
  grid-template-columns: 300px 1fr;
  gap: 3rem;
  align-items: center;
  padding: 2.5rem;
  background: #fff;
  border-radius: var(--radius-lg);
  border: 1px solid var(--color-gray-100);
  transition: var(--transition);
}

.product-item:hover {
  box-shadow: var(--shadow-lg);
  border-color: transparent;
}

.product-visual {
  display: flex;
  align-items: center;
  justify-content: center;
  height: 240px;
  border-radius: var(--radius-lg);
}

.product-body h3 {
  font-size: var(--font-size-2xl);
  font-weight: 700;
  color: var(--color-gray-900);
  margin-bottom: 0.25rem;
}

.product-tagline {
  font-size: var(--font-size-base);
  color: var(--color-primary);
  font-weight: 500;
  margin-bottom: 0.75rem;
}

.product-desc {
  font-size: var(--font-size-sm);
  color: var(--color-gray-500);
  line-height: 1.8;
  margin-bottom: 1rem;
}

.product-features {
  list-style: none;
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 0.5rem 1.5rem;
  margin-bottom: 1.5rem;
}

.product-features li {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-size: var(--font-size-sm);
  color: var(--color-gray-700);
}

.product-actions {
  display: flex;
  gap: 0.75rem;
}

@media (max-width: 768px) {
  .product-item {
    grid-template-columns: 1fr;
    gap: 1.5rem;
    padding: 1.5rem;
  }

  .product-visual {
    height: 160px;
  }

  .product-features {
    grid-template-columns: 1fr;
  }
}
</style>
