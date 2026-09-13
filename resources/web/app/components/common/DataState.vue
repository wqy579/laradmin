<template>
  <div v-if="loading" class="loading-state">
    <div class="spinner"></div>
    <p>加载中...</p>
  </div>
  <div v-else-if="error" class="error-state">
    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="var(--color-gray-300)" stroke-width="1.5">
      <circle cx="12" cy="12" r="10" />
      <path d="M12 8v4M12 16h.01" />
    </svg>
    <p>{{ error }}</p>
    <button class="btn btn-outline" @click="$emit('retry')">重新加载</button>
  </div>
  <div v-else-if="empty" class="empty-state">
    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="var(--color-gray-300)" stroke-width="1.5">
      <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
      <polyline points="14 2 14 8 20 8" />
      <line x1="16" y1="13" x2="8" y2="13" />
      <line x1="16" y1="17" x2="8" y2="17" />
    </svg>
    <p>暂无内容</p>
  </div>
  <slot v-else />
</template>

<script setup lang="ts">
defineProps<{
  loading?: boolean
  error?: string
  empty?: boolean
}>()

defineEmits(['retry'])
</script>

<style scoped>
.loading-state,
.error-state,
.empty-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 1rem;
  padding: 4rem 2rem;
  text-align: center;
  color: var(--color-gray-400);
}

.spinner {
  width: 36px;
  height: 36px;
  border: 3px solid var(--color-gray-200);
  border-top-color: var(--color-primary);
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}

@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}

p {
  font-size: var(--font-size-sm);
}
</style>
