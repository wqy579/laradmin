<template>
  <section class="carousel" @mouseenter="pause" @mouseleave="resume" @touchstart="onTouchStart" @touchend="onTouchEnd">
    <div class="carousel-track" :style="{ transform: `translateX(-${activeIndex * 100}%)` }">
      <div v-for="(slide, i) in slides" :key="i" class="carousel-slide" :style="{ background: slide.bg }">
        <div class="slide-bg">
          <div class="slide-shape slide-shape-1" :style="{ background: slide.accent }"></div>
          <div class="slide-shape slide-shape-2" :style="{ background: shapeColor(slide.accent, 0.5) }"></div>
        </div>
        <div class="container slide-content">
          <span class="badge" :style="{ background: slide.badgeBg, color: slide.accent }">{{ slide.tag }}</span>
          <h1 v-html="slide.title"></h1>
          <p>{{ slide.desc }}</p>
          <div class="slide-actions">
            <NuxtLink :to="slide.primaryLink" class="btn btn-primary btn-lg" :style="{ background: slide.accent, borderColor: slide.accent }">
              {{ slide.primaryText }}
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M12 5l7 7-7 7" /></svg>
            </NuxtLink>
            <NuxtLink :to="slide.secondaryLink" class="btn btn-outline btn-lg" :style="{ color: slide.accent, borderColor: slide.accent }">{{ slide.secondaryText }}</NuxtLink>
          </div>
        </div>
      </div>
    </div>

    <button class="carousel-arrow carousel-prev" @click="prev" aria-label="Previous">
      <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 18l-6-6 6-6" /></svg>
    </button>
    <button class="carousel-arrow carousel-next" @click="next" aria-label="Next">
      <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18l6-6-6-6" /></svg>
    </button>

    <div class="carousel-dots">
      <button v-for="(_, i) in slides" :key="i" class="dot" :class="{ active: i === activeIndex }" @click="goTo(i)" :aria-label="`Slide ${i + 1}`"></button>
    </div>
  </section>
</template>

<script setup lang="ts">
const slides = [
  {
    title: '构建<strong>数字化</strong>未来<br/>赋能企业增长',
    desc: '我们提供专业的企业级应用解决方案，助力企业数字化转型，提升运营效率，驱动业务创新。',
    tag: '专业 · 可靠 · 创新',
    accent: '#2563eb',
    badgeBg: 'rgba(37,99,235,0.1)',
    bg: 'linear-gradient(135deg, #f0f7ff 0%, #e8f0fe 50%, #f5f3ff 100%)',
    primaryText: '了解产品',
    primaryLink: '/products',
    secondaryText: '联系我们',
    secondaryLink: '/contact',
  },
  {
    title: 'SentCMS 内容管理<br/>灵活高效的内容引擎',
    desc: '强大的 CMS 内容管理系统，支持自定义模型、栏目管理、多端发布，为企业内容运营提供全方位支持。',
    tag: 'SentCMS',
    accent: '#7c3aed',
    badgeBg: 'rgba(124,58,237,0.1)',
    bg: 'linear-gradient(135deg, #faf5ff 0%, #ede9fe 50%, #f5f3ff 100%)',
    primaryText: '了解详情',
    primaryLink: '/products#sentcms',
    secondaryText: '免费试用',
    secondaryLink: '/contact',
  },
  {
    title: 'LarAdmin 企业级<br/>后台管理框架',
    desc: '基于 Laravel + Vue3 的现代化后台管理系统，开箱即用的权限管理、模块化架构，让开发效率提升 300%。',
    tag: 'LarAdmin',
    accent: '#0891b2',
    badgeBg: 'rgba(8,145,178,0.1)',
    bg: 'linear-gradient(135deg, #ecfeff 0%, #e0f2fe 50%, #f0f9ff 100%)',
    primaryText: '立即体验',
    primaryLink: '/products#laradmin',
    secondaryText: '查看案例',
    secondaryLink: '/cases',
  },
]

const activeIndex = ref(0)
let timer: ReturnType<typeof setInterval> | null = null

function next() {
  activeIndex.value = (activeIndex.value + 1) % slides.length
}

function prev() {
  activeIndex.value = (activeIndex.value - 1 + slides.length) % slides.length
}

function goTo(i: number) {
  activeIndex.value = i
}

function startAutoplay() {
  timer = setInterval(next, 5000)
}

function pause() {
  if (timer) { clearInterval(timer); timer = null }
}

function resume() {
  pause()
  startAutoplay()
}

function shapeColor(base: string, opacity: number) {
  return base.replace(')', `,${opacity})`).replace('rgb', 'rgba')
}

let touchX = 0
function onTouchStart(e: TouchEvent) { touchX = e.touches[0].clientX }
function onTouchEnd(e: TouchEvent) {
  const diff = touchX - e.changedTouches[0].clientX
  if (Math.abs(diff) > 50) { diff > 0 ? next() : prev() }
}

onMounted(startAutoplay)
onUnmounted(pause)
</script>

<style scoped>
.carousel {
  position: relative;
  overflow: hidden;
}

.carousel-track {
  display: flex;
  transition: transform 0.6s cubic-bezier(0.25, 0.46, 0.45, 0.94);
}

.carousel-slide {
  position: relative;
  min-width: 100%;
  min-height: calc(100vh - var(--header-height));
  display: flex;
  align-items: center;
  overflow: hidden;
}

.slide-bg {
  position: absolute;
  inset: 0;
  pointer-events: none;
}

.slide-shape {
  position: absolute;
  border-radius: 50%;
  opacity: 0.12;
}

.slide-shape-1 {
  width: 600px;
  height: 600px;
  top: -200px;
  right: -100px;
  filter: blur(80px);
}

.slide-shape-2 {
  width: 400px;
  height: 400px;
  bottom: -100px;
  left: -100px;
  filter: blur(60px);
}

.slide-content {
  position: relative;
  z-index: 1;
  max-width: 700px;
  padding: 4rem 1.5rem;
}

.slide-content h1 {
  font-size: 3.5rem;
  font-weight: 800;
  color: var(--color-gray-900);
  line-height: 1.15;
  letter-spacing: -0.02em;
  margin-bottom: 1.25rem;
}

.slide-content h1 :deep(strong) {
  background: linear-gradient(135deg, var(--color-primary), #7c3aed);
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  background-clip: text;
}

.slide-content p {
  font-size: var(--font-size-lg);
  color: var(--color-gray-500);
  line-height: 1.8;
  margin-bottom: 2rem;
  max-width: 520px;
}

.slide-actions {
  display: flex;
  gap: 1rem;
  flex-wrap: wrap;
}

.carousel-arrow {
  position: absolute;
  top: 50%;
  transform: translateY(-50%);
  z-index: 10;
  width: 48px;
  height: 48px;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.85);
  backdrop-filter: blur(8px);
  border: 1px solid rgba(0, 0, 0, 0.06);
  color: var(--color-gray-700);
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: var(--transition);
  box-shadow: var(--shadow);
}

.carousel-arrow:hover {
  background: #fff;
  box-shadow: var(--shadow-md);
}

.carousel-prev { left: 2rem; }
.carousel-next { right: 2rem; }

.carousel-dots {
  position: absolute;
  bottom: 2rem;
  left: 50%;
  transform: translateX(-50%);
  display: flex;
  gap: 8px;
  z-index: 10;
}

.carousel-dots .dot {
  width: 8px;
  height: 8px;
  border-radius: 4px;
  background: var(--color-gray-300);
  border: none;
  cursor: pointer;
  transition: all 0.3s ease;
  padding: 0;
}

.carousel-dots .dot.active {
  width: 24px;
  background: var(--color-primary);
}

@media (max-width: 768px) {
  .slide-content {
    padding: 2rem 1.5rem;
  }

  .slide-content h1 {
    font-size: var(--font-size-3xl);
  }

  .carousel-arrow {
    display: none;
  }
}
</style>
