<template>
  <nav class="breadcrumb">
    <div class="container">
      <NuxtLink to="/">首页</NuxtLink>
      <template v-for="(item, index) in items" :key="index">
        <span class="separator">/</span>
        <NuxtLink v-if="item.to && index < items.length - 1" :to="item.to">{{ item.label }}</NuxtLink>
        <span v-else class="current">{{ item.label }}</span>
      </template>
    </div>
  </nav>
</template>

<script setup lang="ts">
defineProps<{
  items: Array<{ label: string; to?: string }>
}>()
</script>

<style scoped>
.breadcrumb {
  padding: 1rem 0;
  border-bottom: 1px solid var(--color-gray-100);
}

.breadcrumb .container {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-size: var(--font-size-sm);
}

.breadcrumb a {
  color: var(--color-gray-500);
}

.breadcrumb a:hover {
  color: var(--color-primary);
}

.separator {
  color: var(--color-gray-300);
}

.current {
  color: var(--color-gray-800);
  font-weight: 500;
}
</style>
