<template>
  <div>
    <PageBanner title="关于我们" subtitle="了解南昌腾速科技有限公司的使命、愿景与价值观" />
    <Breadcrumb :items="[{ label: '关于我们' }]" />

    <section class="section">
      <div class="container">
        <div class="about-intro">
          <div class="intro-text">
            <span class="badge">关于南昌腾速科技有限公司</span>
            <h2>致力于企业级应用<br/>技术创新</h2>
            <p>
              南昌腾速科技有限公司是一家专注于企业级应用开发的技术企业，坐落于江西省南昌市高新开发区火炬五路科创城。
              我们以 Laravel 生态为核心，结合现代前端技术，为客户提供高性能、高可用的全栈解决方案。
            </p>
            <p>
              公司拥有一支经验丰富的技术团队，业务涵盖企业信息化、电商平台、内容管理、
              数据分析等多个领域。我们相信技术的力量可以为企业带来真正的价值。
            </p>
          </div>
          <div class="intro-stats">
            <div class="stat-card">
              <span class="stat-number">100+</span>
              <span class="stat-label">服务客户</span>
            </div>
            <div class="stat-card">
              <span class="stat-number">500+</span>
              <span class="stat-label">完成项目</span>
            </div>
            <div class="stat-card">
              <span class="stat-number">99.9%</span>
              <span class="stat-label">系统可用性</span>
            </div>
            <div class="stat-card">
              <span class="stat-number">24/7</span>
              <span class="stat-label">技术支持</span>
            </div>
          </div>
        </div>
      </div>
    </section>

    <section class="section" style="background: var(--color-gray-50)">
      <div class="container">
        <div class="section-header">
          <span class="badge">核心价值观</span>
          <h2>我们的价值观</h2>
        </div>
        <div class="values-grid">
          <div class="value-card" v-for="value in values" :key="value.title">
            <div class="value-icon" v-html="value.icon"></div>
            <h3>{{ value.title }}</h3>
            <p>{{ value.desc }}</p>
          </div>
        </div>
      </div>
    </section>

    <section class="section">
      <div class="container">
        <div class="section-header">
          <span class="badge">发展历程</span>
          <h2>我们的历程</h2>
        </div>
        <div class="timeline">
          <div class="timeline-item" v-for="item in timeline" :key="item.year">
            <div class="timeline-marker"></div>
            <div class="timeline-content">
              <span class="timeline-year">{{ item.year }}</span>
              <h3>{{ item.title }}</h3>
              <p>{{ item.desc }}</p>
            </div>
          </div>
        </div>
      </div>
    </section>
  </div>
</template>

<script setup lang="ts">
const values = [
  { title: '客户至上', desc: '始终以客户需求为导向，提供超出期望的服务体验。', icon: '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--color-primary)" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>' },
  { title: '追求卓越', desc: '对每一行代码精益求精，追求技术与服务的极致。', icon: '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--color-primary)" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>' },
  { title: '持续创新', desc: '拥抱变化，不断探索前沿技术，引领行业发展。', icon: '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--color-primary)" stroke-width="2"><path d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/></svg>' },
  { title: '团队协作', desc: '开放沟通，相互信任，共同成长，打造高效团队。', icon: '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--color-primary)" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>' },
]

const timeline = [
  { year: '2020', title: '公司成立', desc: '南昌腾速科技有限公司正式成立，开始企业级应用开发之旅。' },
  { year: '2021', title: '产品发布', desc: '推出首个企业级后台管理框架，获得市场认可。' },
  { year: '2023', title: '团队扩展', desc: '团队规模扩大到 50 人，服务客户超过 100 家。' },
  { year: '2025', title: '全面升级', desc: '产品全面升级至 Laravel + Vue3 技术栈，性能提升 300%。' },
]

useHead({
  title: '关于我们 - 南昌腾速科技有限公司',
  meta: [
    { name: 'description', content: '了解南昌腾速科技有限公司的使命、愿景与价值观，我们致力于企业级应用技术创新。' },
  ],
})
</script>

<style scoped>
.about-intro {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 4rem;
  align-items: center;
}

.intro-text h2 {
  font-size: var(--font-size-3xl);
  font-weight: 700;
  color: var(--color-gray-900);
  margin-bottom: 1.5rem;
  line-height: 1.3;
}

.intro-text p {
  color: var(--color-gray-600);
  line-height: 1.8;
  margin-bottom: 1rem;
}

.intro-text .badge {
  margin-bottom: 1rem;
}

.intro-stats {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 1.5rem;
}

.stat-card {
  padding: 1.5rem;
  background: var(--color-gray-50);
  border-radius: var(--radius-lg);
  text-align: center;
}

.stat-number {
  display: block;
  font-size: var(--font-size-3xl);
  font-weight: 700;
  color: var(--color-primary);
  margin-bottom: 0.25rem;
}

.stat-label {
  font-size: var(--font-size-sm);
  color: var(--color-gray-500);
}

.values-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 1.5rem;
}

.value-card {
  padding: 2rem;
  background: #fff;
  border-radius: var(--radius-lg);
  border: 1px solid var(--color-gray-100);
  text-align: center;
  transition: var(--transition);
}

.value-card:hover {
  box-shadow: var(--shadow-md);
  transform: translateY(-2px);
}

.value-icon {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 48px;
  height: 48px;
  margin: 0 auto 1rem;
  background: var(--color-primary-50);
  border-radius: var(--radius);
}

.value-card h3 {
  font-size: var(--font-size-base);
  font-weight: 600;
  color: var(--color-gray-900);
  margin-bottom: 0.5rem;
}

.value-card p {
  font-size: var(--font-size-sm);
  color: var(--color-gray-500);
  line-height: 1.7;
}

.timeline {
  max-width: 640px;
  margin: 0 auto;
  position: relative;
  padding-left: 2rem;
}

.timeline::before {
  content: '';
  position: absolute;
  left: 7px;
  top: 0;
  bottom: 0;
  width: 2px;
  background: var(--color-gray-200);
}

.timeline-item {
  position: relative;
  padding-bottom: 2.5rem;
}

.timeline-item:last-child {
  padding-bottom: 0;
}

.timeline-marker {
  position: absolute;
  left: -2rem;
  top: 4px;
  width: 16px;
  height: 16px;
  border-radius: 50%;
  background: var(--color-primary);
  border: 3px solid var(--color-primary-100);
}

.timeline-year {
  font-size: var(--font-size-sm);
  font-weight: 600;
  color: var(--color-primary);
  margin-bottom: 0.25rem;
  display: block;
}

.timeline-content h3 {
  font-size: var(--font-size-lg);
  font-weight: 600;
  color: var(--color-gray-900);
  margin-bottom: 0.25rem;
}

.timeline-content p {
  font-size: var(--font-size-sm);
  color: var(--color-gray-500);
  line-height: 1.7;
}

@media (max-width: 768px) {
  .about-intro {
    grid-template-columns: 1fr;
    gap: 2rem;
  }

  .values-grid {
    grid-template-columns: repeat(2, 1fr);
  }
}
</style>
