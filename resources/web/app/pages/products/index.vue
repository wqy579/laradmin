<template>
  <div>
    <PageBanner title="公司产品" subtitle="自主研发的企业级应用，经过大量客户验证" />
    <Breadcrumb :items="[{ label: '公司产品' }]" />

    <section class="section">
      <div class="container">
        <div class="products-page">
          <div v-for="product in products" :key="product.id" class="product-block" :id="product.id">
            <div class="product-visual" :style="{ background: product.bg }">
              <div class="product-icon" v-html="product.icon"></div>
            </div>
            <div class="product-body">
              <h2>{{ product.name }}</h2>
              <p class="product-tagline">{{ product.tagline }}</p>
              <p class="product-desc">{{ product.desc }}</p>

              <h4>核心特性</h4>
              <ul class="feature-list">
                <li v-for="f in product.features" :key="f.label">
                  <div class="feature-icon" :style="{ background: product.iconBg }">
                    <div v-html="f.icon"></div>
                  </div>
                  <div>
                    <strong>{{ f.label }}</strong>
                    <p>{{ f.desc }}</p>
                  </div>
                </li>
              </ul>

              <h4>技术栈</h4>
              <div class="tech-tags">
                <span v-for="t in product.techStack" :key="t" class="tech-tag">{{ t }}</span>
              </div>

              <div class="product-actions">
                <NuxtLink to="/contact" class="btn btn-primary btn-lg">
                  申请试用
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M12 5l7 7-7 7" /></svg>
                </NuxtLink>
                <NuxtLink to="/cases" class="btn btn-outline btn-lg">查看客户案例</NuxtLink>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>
  </div>
</template>

<script setup lang="ts">
const products = [
  {
    id: 'laradmin',
    name: 'LarAdmin 后台管理框架',
    tagline: '现代化企业级后台管理解决方案',
    desc: 'LarAdmin 是基于 Laravel + Vue3 构建的现代化后台管理框架，提供开箱即用的 RBAC 权限管理、模块化插件架构、代码生成器、动态菜单与路由，大幅提升后台管理系统的开发效率。',
    features: [
      { label: 'RBAC 权限', desc: '完整的基于角色的访问控制，支持菜单权限、按钮权限、数据权限', icon: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#0891b2" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>' },
      { label: '模块化架构', desc: '基于 nwidart/laravel-modules，支持模块独立开发、独立启停', icon: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#0891b2" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>' },
      { label: '动态菜单', desc: '后端驱动菜单配置，支持动态路由加载、多级菜单、图标选择', icon: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#0891b2" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>' },
      { label: '丰富组件', desc: '内置 scTable、scForm、scEditor、scUpload 等业务组件，开箱即用', icon: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#0891b2" stroke-width="2"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>' },
    ],
    techStack: ['Laravel', 'Vue 3', 'Ant Design Vue 4', 'Pinia', 'TypeScript', 'MySQL'],
    bg: 'linear-gradient(135deg, #ecfeff, #e0f2fe)',
    iconBg: 'rgba(8,145,178,0.08)',
    icon: '<svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#0891b2" stroke-width="1.5"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg>',
  },
  {
    id: 'sentcms',
    name: 'SentCMS 内容管理系统',
    tagline: '灵活高效的内容管理引擎',
    desc: 'SentCMS 是南昌腾速科技有限公司倾力打造的一款简单易用的网站管理系统。继承 ThinkPHP 优秀品质，秉承"大道至简"的设计理念。支持自定义内容模型与字段、树形栏目管理、多状态内容发布流程、RESTful 公开 API 接口，为网站建设减少 90% 的代码编写，完全开源。',
    features: [
      { label: '全新路由体系', desc: '全新的路由系统，完美的路由解决方案，灵活的URL规则配置', icon: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>' },
      { label: '全新系统架构', desc: '采用 ThinkPHP 6.0 内核框架，全新的系统架构设计，稳定可靠', icon: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg>' },
      { label: '健全权限系统', desc: '权限细化到界面上的按钮和链接，完善的会员体系与角色管理', icon: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>' },
      { label: '便捷文档系统', desc: '简单易用的标签体系，无需编程即可设计出完美的模型与网站', icon: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>' },
    ],
    techStack: ['ThinkPHP 6.0', 'PHP', 'MySQL', 'JavaScript', 'HTML/CSS', 'Bootstrap'],
    bg: 'linear-gradient(135deg, #faf5ff, #ede9fe)',
    iconBg: 'rgba(124,58,237,0.08)',
    icon: '<svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="1.5"><path d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z"/></svg>',
  },
]

useHead({
  title: '公司产品 - LarAdmin',
  meta: [
    { name: 'description', content: '了解 LarAdmin 的核心产品：SentCMS 内容管理系统、LarAdmin 后台管理框架。' },
  ],
})
</script>

<style scoped>
.product-block {
  display: grid;
  grid-template-columns: 360px 1fr;
  gap: 3rem;
  align-items: start;
  padding: 3rem;
  margin-bottom: 3rem;
  background: #fff;
  border-radius: var(--radius-lg);
  border: 1px solid var(--color-gray-100);
}

.product-block:last-child {
  margin-bottom: 0;
}

.product-visual {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 320px;
  border-radius: var(--radius-lg);
  position: sticky;
  top: calc(var(--header-height) + 2rem);
}

.product-body h2 {
  font-size: var(--font-size-2xl);
  font-weight: 700;
  color: var(--color-gray-900);
  margin-bottom: 0.25rem;
}

.product-tagline {
  font-size: var(--font-size-lg);
  color: var(--color-primary);
  font-weight: 500;
  margin-bottom: 1rem;
}

.product-desc {
  font-size: var(--font-size-base);
  color: var(--color-gray-600);
  line-height: 1.8;
  margin-bottom: 2rem;
}

.product-body h4 {
  font-size: var(--font-size-sm);
  font-weight: 600;
  color: var(--color-gray-900);
  margin-bottom: 1rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
}

.feature-list {
  list-style: none;
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 1.25rem;
  margin-bottom: 2rem;
}

.feature-list li {
  display: flex;
  gap: 0.75rem;
}

.feature-icon {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 40px;
  height: 40px;
  border-radius: var(--radius);
  flex-shrink: 0;
}

.feature-list strong {
  display: block;
  font-size: var(--font-size-sm);
  font-weight: 600;
  color: var(--color-gray-900);
  margin-bottom: 0.125rem;
}

.feature-list p {
  font-size: var(--font-size-xs);
  color: var(--color-gray-500);
  line-height: 1.6;
}

.tech-tags {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  margin-bottom: 2rem;
}

.tech-tag {
  display: inline-flex;
  padding: 0.25rem 0.75rem;
  font-size: var(--font-size-xs);
  font-weight: 500;
  background: var(--color-gray-100);
  color: var(--color-gray-600);
  border-radius: 9999px;
}

.product-actions {
  display: flex;
  gap: 0.75rem;
}

@media (max-width: 768px) {
  .product-block {
    grid-template-columns: 1fr;
    gap: 1.5rem;
    padding: 1.5rem;
  }

  .product-visual {
    position: static;
    min-height: 200px;
  }

  .feature-list {
    grid-template-columns: 1fr;
  }
}
</style>
