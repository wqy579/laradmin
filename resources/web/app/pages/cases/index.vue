<template>
  <div>
    <PageBanner title="案例展示" subtitle="精选客户案例，见证数字化转型的力量" />
    <Breadcrumb :items="[{ label: '案例展示' }]" />

    <section class="section">
      <div class="container">
        <div class="cases-layout">
          <aside class="cases-sidebar">
            <h3>案例分类</h3>
            <ul class="category-list">
              <li>
                <button :class="{ active: !currentCategory }" @click="currentCategory = undefined">
                  全部案例
                </button>
              </li>
              <li v-for="cat in categories" :key="cat.id">
                <button :class="{ active: currentCategory === cat.id }" @click="currentCategory = cat.id">
                  {{ cat.name }}
                </button>
              </li>
            </ul>
          </aside>

          <div class="cases-main">
            <DataState :loading="pending" :empty="!pending && (!data?.list || data.list.length === 0)">
              <div class="cases-grid">
                <ContentCard
                  v-for="item in data?.list || []"
                  :key="item.id"
                  :title="item.title || ''"
                  :desc="item.summary || ''"
                  :image="item.cover || ''"
                  :tag="item.categories?.[0]?.name"
                  @click="navigateTo(`/cases/${item.id}`)"
                />
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
const pageSize = 12
const currentCategory = ref<number | undefined>(undefined)

const { data: categories } = await useAsyncData('case-categories', () =>
  $api.getCategories('product')
)

const { data, pending } = await useAsyncData(
  () => `case-list-${currentPage.value}-${currentCategory.value}`,
  () =>
    $api.getContentList('product', {
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

watch(currentCategory, () => {
  currentPage.value = 1
})

useHead({
  title: '案例展示 - LarAdmin',
  meta: [
    { name: 'description', content: '精选 LarAdmin 客户案例，见证数字化转型的力量。' },
  ],
})
</script>

<style scoped>
.cases-layout {
  display: grid;
  grid-template-columns: 220px 1fr;
  gap: 2.5rem;
}

.cases-sidebar h3 {
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

.cases-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 1.5rem;
}

@media (max-width: 768px) {
  .cases-layout {
    grid-template-columns: 1fr;
  }

  .cases-sidebar {
    padding-bottom: 1rem;
    border-bottom: 1px solid var(--color-gray-100);
  }

  .category-list {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
  }

  .cases-grid {
    grid-template-columns: 1fr;
  }
}

@media (min-width: 769px) and (max-width: 1024px) {
  .cases-grid {
    grid-template-columns: repeat(2, 1fr);
  }
}
</style>
