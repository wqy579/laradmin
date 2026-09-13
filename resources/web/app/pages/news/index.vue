<template>
  <div>
    <PageBanner title="新闻资讯" subtitle="了解我们的最新动态和行业见解" />
    <Breadcrumb :items="[{ label: '新闻资讯' }]" />

    <section class="section">
      <div class="container">
        <div class="news-layout">
          <aside class="news-sidebar">
            <h3>栏目分类</h3>
            <ul class="category-list">
              <li>
                <button :class="{ active: !currentCategory }" @click="currentCategory = undefined">
                  全部
                </button>
              </li>
              <li v-for="cat in categories" :key="cat.id">
                <button :class="{ active: currentCategory === cat.id }" @click="currentCategory = cat.id">
                  {{ cat.name }}
                </button>
              </li>
            </ul>
          </aside>

          <div class="news-main">
            <DataState :loading="pending" :empty="!pending && (!data?.list || data.list.length === 0)">
              <div class="news-list">
                <article
                  v-for="item in data?.list || []"
                  :key="item.id"
                  class="news-item"
                  @click="navigateTo(`/news/${item.id}`)"
                >
                  <div v-if="item.cover" class="news-item-image">
                    <img :src="item.cover" :alt="item.title" loading="lazy" />
                  </div>
                  <div class="news-item-body">
                    <div class="news-item-meta">
                      <span v-if="item.categories?.length" class="news-category">
                        {{ item.categories[0].name }}
                      </span>
                      <time>{{ formatDate(item.created_at) }}</time>
                    </div>
                    <h2>{{ item.title }}</h2>
                    <p>{{ item.summary }}</p>
                    <span class="news-read-more">
                      阅读全文
                      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M5 12h14M12 5l7 7-7 7" />
                      </svg>
                    </span>
                  </div>
                </article>
              </div>
            </DataState>

            <Pagination
              v-if="data?.total"
              :total="data.total"
              :current-page="currentPage"
              :page-size="pageSize"
              @change="handlePageChange"
            />
          </div>
        </div>
      </div>
    </section>
  </div>
</template>

<script setup lang="ts">
const { $api } = useNuxtApp()

const currentPage = ref(1)
const pageSize = 10
const currentCategory = ref<number | undefined>(undefined)

const { data: categories } = await useAsyncData('news-categories', () =>
  $api.getCategories('article')
)

const { data, pending } = await useAsyncData(
  () => `news-list-${currentPage.value}-${currentCategory.value}`,
  () =>
    $api.getContentList('article', {
      page: currentPage.value,
      page_size: pageSize,
      ...(currentCategory.value ? { category_id: currentCategory.value } : {}),
    }),
  { watch: [currentPage, currentCategory] }
)

function handlePageChange(page: number) {
  currentPage.value = page
  window.scrollTo({ top: 300, behavior: 'smooth' })
}

function formatDate(date: string) {
  if (!date) return ''
  return new Date(date).toLocaleDateString('zh-CN', { year: 'numeric', month: 'long', day: 'numeric' })
}

watch(currentCategory, () => {
  currentPage.value = 1
})

useHead({
  title: '新闻资讯 - LarAdmin',
  meta: [
    { name: 'description', content: '了解 LarAdmin 的最新动态和行业见解。' },
  ],
})
</script>

<style scoped>
.news-layout {
  display: grid;
  grid-template-columns: 220px 1fr;
  gap: 2.5rem;
}

.news-sidebar h3 {
  font-size: var(--font-size-base);
  font-weight: 600;
  color: var(--color-gray-900);
  margin-bottom: 1rem;
}

.category-list {
  list-style: none;
}

.category-list button {
  display: block;
  width: 100%;
  text-align: left;
  padding: 0.5rem 0.75rem;
  font-size: var(--font-size-sm);
  color: var(--color-gray-600);
  background: none;
  border: none;
  border-radius: var(--radius);
  cursor: pointer;
  transition: var(--transition);
}

.category-list button:hover {
  color: var(--color-primary);
  background: var(--color-primary-50);
}

.category-list button.active {
  color: var(--color-primary);
  background: var(--color-primary-50);
  font-weight: 500;
}

.news-item {
  display: flex;
  gap: 1.5rem;
  padding: 1.5rem 0;
  border-bottom: 1px solid var(--color-gray-100);
  cursor: pointer;
  transition: var(--transition);
}

.news-item:hover {
  background: var(--color-gray-50);
  margin: 0 -1rem;
  padding: 1.5rem 1rem;
  border-radius: var(--radius);
}

.news-item-image {
  flex-shrink: 0;
  width: 240px;
  aspect-ratio: 16 / 10;
  border-radius: var(--radius);
  overflow: hidden;
  background: var(--color-gray-100);
}

.news-item-image img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.news-item-body {
  flex: 1;
  min-width: 0;
}

.news-item-meta {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  margin-bottom: 0.5rem;
  font-size: var(--font-size-xs);
  color: var(--color-gray-400);
}

.news-category {
  color: var(--color-primary);
  font-weight: 500;
}

.news-item-body h2 {
  font-size: var(--font-size-lg);
  font-weight: 600;
  color: var(--color-gray-900);
  margin-bottom: 0.5rem;
  line-height: 1.4;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.news-item-body p {
  font-size: var(--font-size-sm);
  color: var(--color-gray-500);
  line-height: 1.7;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
  margin-bottom: 0.75rem;
}

.news-read-more {
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
  font-size: var(--font-size-sm);
  font-weight: 500;
  color: var(--color-primary);
}

@media (max-width: 768px) {
  .news-layout {
    grid-template-columns: 1fr;
  }

  .news-sidebar {
    padding-bottom: 1rem;
    border-bottom: 1px solid var(--color-gray-100);
  }

  .category-list {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
  }

  .news-item {
    flex-direction: column;
  }

  .news-item-image {
    width: 100%;
  }
}
</style>
