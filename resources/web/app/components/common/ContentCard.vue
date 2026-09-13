<template>
  <div class="content-card" @click="$emit('click')">
    <div v-if="image" class="card-image">
      <img :src="image" :alt="title" loading="lazy" />
    </div>
    <div class="card-body">
      <span v-if="tag" class="badge">{{ tag }}</span>
      <h3 class="card-title">{{ title }}</h3>
      <p v-if="desc" class="card-desc">{{ desc }}</p>
      <div class="card-footer">
        <span v-if="date" class="card-date">{{ date }}</span>
        <span class="card-arrow">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M5 12h14M12 5l7 7-7 7" />
          </svg>
        </span>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
defineProps<{
  title: string
  desc?: string
  image?: string
  tag?: string
  date?: string
}>()

defineEmits(['click'])
</script>

<style scoped>
.content-card {
  background: #fff;
  border-radius: var(--radius-lg);
  overflow: hidden;
  border: 1px solid var(--color-gray-100);
  transition: var(--transition);
  cursor: pointer;
}

.content-card:hover {
  box-shadow: var(--shadow-lg);
  transform: translateY(-4px);
  border-color: transparent;
}

.card-image {
  aspect-ratio: 16 / 10;
  overflow: hidden;
  background: var(--color-gray-100);
}

.card-image img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  transition: transform 0.5s ease;
}

.content-card:hover .card-image img {
  transform: scale(1.05);
}

.card-body {
  padding: 1.25rem;
}

.card-title {
  font-size: var(--font-size-base);
  font-weight: 600;
  color: var(--color-gray-900);
  margin-bottom: 0.5rem;
  line-height: 1.4;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.card-desc {
  font-size: var(--font-size-sm);
  color: var(--color-gray-500);
  line-height: 1.6;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
  margin-bottom: 0.75rem;
}

.card-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.card-date {
  font-size: var(--font-size-xs);
  color: var(--color-gray-400);
}

.card-arrow {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  border-radius: 50%;
  background: var(--color-primary-50);
  color: var(--color-primary);
  transition: var(--transition);
}

.content-card:hover .card-arrow {
  background: var(--color-primary);
  color: #fff;
}
</style>
