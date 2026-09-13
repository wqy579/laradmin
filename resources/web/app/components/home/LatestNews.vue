<template>
  <section class="latest section" style="background: var(--color-gray-50)">
    <div class="container">
      <div class="section-header">
        <span class="badge">最新动态</span>
        <h2>新闻与资讯</h2>
        <p>了解我们的最新动态和行业见解</p>
      </div>

      <DataState :loading="pending" :empty="!pending && (!data?.list || data.list.length === 0)" error="">
        <div class="news-grid">
          <ContentCard
            v-for="item in data?.list || []"
            :key="item.id"
            :title="item.title || ''"
            :desc="item.summary || ''"
            :image="item.cover || ''"
            :date="formatDate(item.created_at)"
            @click="navigateTo(`/news/${item.id}`)"
          />
        </div>
      </DataState>

      <div v-if="data?.list && data.list.length > 0" class="section-action">
        <NuxtLink to="/news" class="btn btn-outline">
          查看全部
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M5 12h14M12 5l7 7-7 7" />
          </svg>
        </NuxtLink>
      </div>
    </div>
  </section>
</template>

<script setup lang="ts">
const { $api } = useNuxtApp()

const { data, pending } = await useAsyncData('home-news', () =>
  $api.getContentList('article', { page_size: 3 })
)

function formatDate(date: string) {
  if (!date) return ''
  return new Date(date).toLocaleDateString('zh-CN', { year: 'numeric', month: 'long', day: 'numeric' })
}
</script>

<style scoped>
.news-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 1.5rem;
}

.section-action {
  text-align: center;
  margin-top: 2.5rem;
}

@media (max-width: 768px) {
  .news-grid {
    grid-template-columns: 1fr;
  }
}

@media (min-width: 769px) and (max-width: 1024px) {
  .news-grid {
    grid-template-columns: repeat(2, 1fr);
  }
}
</style>
