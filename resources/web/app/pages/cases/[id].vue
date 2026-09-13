<template>
  <div>
    <PageBanner title="案例展示" subtitle="精选客户案例" />
    <Breadcrumb :items="[{ label: '案例展示', to: '/cases' }, { label: caseData?.title || '详情' }]" />

    <section class="section">
      <div class="container">
        <DataState :loading="pending" error="">
          <div v-if="caseData" class="case-detail">
            <div class="case-hero">
              <div v-if="caseData.cover" class="case-image">
                <img :src="caseData.cover" :alt="caseData.title" />
              </div>
              <div class="case-info">
                <span v-if="caseData.categories?.length" class="badge">
                  {{ caseData.categories[0].name }}
                </span>
                <h1>{{ caseData.title }}</h1>
                <p v-if="caseData.summary">{{ caseData.summary }}</p>
              </div>
            </div>

            <div class="case-content" v-html="caseData.content"></div>

            <footer class="case-footer">
              <div v-if="caseData.categories?.length" class="case-tags">
                <span class="tag" v-for="cat in caseData.categories" :key="cat.id">{{ cat.name }}</span>
              </div>
              <NuxtLink to="/cases" class="btn btn-outline">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <path d="M19 12H5M12 19l-7-7 7-7" />
                </svg>
                返回列表
              </NuxtLink>
            </footer>
          </div>
        </DataState>
      </div>
    </section>
  </div>
</template>

<script setup lang="ts">
const route = useRoute()
const { $api } = useNuxtApp()

const { data: caseData, pending } = await useAsyncData(
  () => `case-detail-${route.params.id}`,
  () => $api.getContentDetail('product', route.params.id as string)
)

useHead(() => ({
  title: caseData.value ? `${caseData.value.title} - LarAdmin` : '加载中...',
  meta: [
    { name: 'description', content: caseData.value?.summary || '' },
  ],
}))
</script>

<style scoped>
.case-detail {
  max-width: 900px;
  margin: 0 auto;
}

.case-hero {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 2.5rem;
  margin-bottom: 3rem;
  align-items: start;
}

.case-image {
  border-radius: var(--radius-lg);
  overflow: hidden;
  background: var(--color-gray-100);
}

.case-image img {
  width: 100%;
  aspect-ratio: 4 / 3;
  object-fit: cover;
}

.case-info .badge {
  margin-bottom: 0.75rem;
}

.case-info h1 {
  font-size: var(--font-size-3xl);
  font-weight: 700;
  color: var(--color-gray-900);
  margin-bottom: 1rem;
  line-height: 1.3;
}

.case-info p {
  font-size: var(--font-size-base);
  color: var(--color-gray-600);
  line-height: 1.8;
}

.case-content {
  font-size: var(--font-size-base);
  line-height: 1.9;
  color: var(--color-gray-700);
  padding-top: 2rem;
  border-top: 1px solid var(--color-gray-200);
}

.case-content :deep(h1),
.case-content :deep(h2),
.case-content :deep(h3),
.case-content :deep(h4) {
  color: var(--color-gray-900);
  margin: 1.5em 0 0.5em;
  font-weight: 600;
}

.case-content :deep(p) {
  margin-bottom: 1em;
}

.case-content :deep(img) {
  max-width: 100%;
  border-radius: var(--radius);
  margin: 1.5em 0;
}

.case-content :deep(table) {
  width: 100%;
  border-collapse: collapse;
  margin: 1.5em 0;
}

.case-content :deep(th),
.case-content :deep(td) {
  padding: 0.75rem 1rem;
  border: 1px solid var(--color-gray-200);
  text-align: left;
}

.case-content :deep(th) {
  background: var(--color-gray-50);
  font-weight: 600;
}

.case-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-top: 3rem;
  padding-top: 2rem;
  border-top: 1px solid var(--color-gray-200);
}

.case-tags {
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
  .case-hero {
    grid-template-columns: 1fr;
    gap: 1.5rem;
  }

  .case-info h1 {
    font-size: var(--font-size-2xl);
  }

  .case-footer {
    flex-direction: column;
    gap: 1rem;
    align-items: flex-start;
  }
}
</style>
