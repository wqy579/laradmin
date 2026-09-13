<template>
  <div>
    <PageBanner title="新闻资讯" subtitle="了解我们的最新动态和行业见解" />
    <Breadcrumb :items="[{ label: '新闻资讯', to: '/news' }, { label: article?.title || '详情' }]" />

    <section class="section">
      <div class="container">
        <DataState :loading="pending" error="">
          <article v-if="article" class="article-detail">
            <header class="article-header">
              <div class="article-meta">
                <span v-if="article.categories?.length" class="badge">
                  {{ article.categories[0].name }}
                </span>
                <time>{{ formatDate(article.created_at) }}</time>
              </div>
              <h1>{{ article.title }}</h1>
            </header>

            <div v-if="article.summary" class="article-summary">
              <p>{{ article.summary }}</p>
            </div>

            <div class="article-content" v-html="article.content"></div>

            <footer class="article-footer">
              <div v-if="article.categories?.length" class="article-tags">
                <span class="tag" v-for="cat in article.categories" :key="cat.id">{{ cat.name }}</span>
              </div>
              <NuxtLink to="/news" class="btn btn-outline">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <path d="M19 12H5M12 19l-7-7 7-7" />
                </svg>
                返回列表
              </NuxtLink>
            </footer>
          </article>
        </DataState>
      </div>
    </section>
  </div>
</template>

<script setup lang="ts">
const route = useRoute()
const { $api } = useNuxtApp()

const { data: article, pending } = await useAsyncData(
  () => `news-detail-${route.params.id}`,
  () => $api.getContentDetail('article', route.params.id as string)
)

function formatDate(date: string) {
  if (!date) return ''
  return new Date(date).toLocaleDateString('zh-CN', { year: 'numeric', month: 'long', day: 'numeric' })
}

useHead(() => ({
  title: article.value ? `${article.value.title} - LarAdmin` : '加载中...',
  meta: [
    { name: 'description', content: article.value?.summary || '' },
  ],
}))
</script>

<style scoped>
.article-detail {
  max-width: 800px;
  margin: 0 auto;
}

.article-header {
  margin-bottom: 2rem;
}

.article-meta {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  margin-bottom: 1rem;
  font-size: var(--font-size-sm);
  color: var(--color-gray-400);
}

.article-header h1 {
  font-size: var(--font-size-3xl);
  font-weight: 700;
  color: var(--color-gray-900);
  line-height: 1.3;
}

.article-summary {
  padding: 1.25rem;
  background: var(--color-primary-50);
  border-left: 4px solid var(--color-primary);
  border-radius: 0 var(--radius) var(--radius) 0;
  margin-bottom: 2rem;
}

.article-summary p {
  font-size: var(--font-size-base);
  color: var(--color-gray-700);
  line-height: 1.8;
}

.article-content {
  font-size: var(--font-size-base);
  line-height: 1.9;
  color: var(--color-gray-700);
}

.article-content :deep(h1),
.article-content :deep(h2),
.article-content :deep(h3),
.article-content :deep(h4) {
  color: var(--color-gray-900);
  margin: 1.5em 0 0.5em;
  font-weight: 600;
}

.article-content :deep(p) {
  margin-bottom: 1em;
}

.article-content :deep(img) {
  max-width: 100%;
  border-radius: var(--radius);
  margin: 1.5em 0;
}

.article-content :deep(a) {
  color: var(--color-primary);
  text-decoration: underline;
}

.article-content :deep(blockquote) {
  padding: 1rem 1.5rem;
  border-left: 4px solid var(--color-primary);
  background: var(--color-gray-50);
  margin: 1.5em 0;
  border-radius: 0 var(--radius) var(--radius) 0;
}

.article-content :deep(ul),
.article-content :deep(ol) {
  padding-left: 1.5rem;
  margin-bottom: 1em;
}

.article-content :deep(li) {
  margin-bottom: 0.25em;
}

.article-content :deep(pre) {
  padding: 1.25rem;
  background: var(--color-gray-900);
  color: var(--color-gray-100);
  border-radius: var(--radius);
  overflow-x: auto;
  margin: 1.5em 0;
}

.article-content :deep(code) {
  font-size: 0.9em;
}

.article-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-top: 3rem;
  padding-top: 2rem;
  border-top: 1px solid var(--color-gray-200);
}

.article-tags {
  display: flex;
  gap: 0.5rem;
  flex-wrap: wrap;
}

.tag {
  display: inline-flex;
  padding: 0.25rem 0.75rem;
  font-size: var(--font-size-xs);
  color: var(--color-gray-500);
  background: var(--color-gray-100);
  border-radius: 9999px;
}

@media (max-width: 768px) {
  .article-header h1 {
    font-size: var(--font-size-2xl);
  }

  .article-footer {
    flex-direction: column;
    gap: 1rem;
    align-items: flex-start;
  }
}
</style>
