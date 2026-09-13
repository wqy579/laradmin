<template>
  <div>
    <HeroSection />
    <FeaturesSection />
    <LatestNews />
    <CtaSection />
  </div>
</template>

<script setup lang="ts">
useHead({
  title: 'LarAdmin - 构建数字化未来，赋能企业增长',
  meta: [
    { name: 'description', content: 'LarAdmin 提供专业的企业级应用解决方案，助力企业数字化转型，提升运营效率，驱动业务创新。' },
    { property: 'og:title', content: 'LarAdmin - 构建数字化未来' },
    { property: 'og:description', content: '专业企业级应用解决方案，助力企业数字化转型' },
    { property: 'og:type', content: 'website' },
  ],
})
</script>
