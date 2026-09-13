<template>
  <section class="features section">
    <div class="container">
      <div class="section-header">
        <span class="badge">核心优势</span>
        <h2>为什么选择我们</h2>
        <p>我们致力于为企业提供最优质的技术服务与解决方案</p>
      </div>

      <div class="features-grid">
        <div v-for="feature in features" :key="feature.title" class="feature-card">
          <div class="feature-icon" :style="{ background: feature.bg }">
            <div v-html="feature.icon"></div>
          </div>
          <h3>{{ feature.title }}</h3>
          <p>{{ feature.desc }}</p>
        </div>
      </div>
    </div>
  </section>
</template>

<script setup lang="ts">
const features = [
  {
    title: '专业技术团队',
    desc: '拥有多年企业级应用开发经验，技术栈覆盖全面，确保项目高质量交付。',
    bg: 'linear-gradient(135deg, #eff6ff, #dbeafe)',
    icon: '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
  },
  {
    title: '安全可靠',
    desc: '采用业界领先的安全架构和加密技术，全方位保障企业数据安全。',
    bg: 'linear-gradient(135deg, #f0fdf4, #dcfce7)',
    icon: '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
  },
  {
    title: '灵活可扩展',
    desc: '模块化架构设计，支持按需扩展，随业务发展灵活调整系统规模。',
    bg: 'linear-gradient(135deg, #fef3c7, #fde68a)',
    icon: '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>',
  },
  {
    title: '高效运维',
    desc: '完善的监控体系和自动化运维工具，确保系统稳定运行和快速响应。',
    bg: 'linear-gradient(135deg, #fce7f3, #fbcfe8)',
    icon: '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#db2777" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>',
  },
  {
    title: '数据驱动',
    desc: '强大的数据分析能力，帮助企业洞察业务趋势，做出更明智的决策。',
    bg: 'linear-gradient(135deg, #ede9fe, #ddd6fe)',
    icon: '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2"><path d="M18 20V10"/><path d="M12 20V4"/><path d="M6 20v-6"/></svg>',
  },
  {
    title: '全场景覆盖',
    desc: '覆盖企业运营全场景需求，一站式解决方案，降低系统整合成本。',
    bg: 'linear-gradient(135deg, #e0f2fe, #bae6fd)',
    icon: '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#0284c7" stroke-width="2"><rect x="5" y="2" width="14" height="20" rx="2" ry="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg>',
  },
]
</script>

<style scoped>
.features-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 1.5rem;
}

.feature-card {
  padding: 2rem;
  border-radius: var(--radius-lg);
  background: #fff;
  border: 1px solid var(--color-gray-100);
  transition: var(--transition);
}

.feature-card:hover {
  box-shadow: var(--shadow-md);
  transform: translateY(-2px);
  border-color: transparent;
}

.feature-icon {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 48px;
  height: 48px;
  border-radius: var(--radius);
  margin-bottom: 1.25rem;
}

.feature-card h3 {
  font-size: var(--font-size-base);
  font-weight: 600;
  color: var(--color-gray-900);
  margin-bottom: 0.5rem;
}

.feature-card p {
  font-size: var(--font-size-sm);
  color: var(--color-gray-500);
  line-height: 1.7;
}

@media (max-width: 768px) {
  .features-grid {
    grid-template-columns: 1fr;
  }
}

@media (min-width: 769px) and (max-width: 1024px) {
  .features-grid {
    grid-template-columns: repeat(2, 1fr);
  }
}
</style>
