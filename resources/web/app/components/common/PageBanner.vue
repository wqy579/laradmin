<template>
  <section class="page-banner">
    <div class="container">
      <h1>{{ title }}</h1>
      <p v-if="subtitle">{{ subtitle }}</p>
    </div>
  </section>
</template>

<script setup lang="ts">
defineProps<{
  title: string
  subtitle?: string
}>()
</script>

<style scoped>
.page-banner {
  background: linear-gradient(135deg, var(--color-primary-800) 0%, var(--color-primary) 100%);
  color: #fff;
  padding: 4rem 0 3rem;
  position: relative;
  overflow: hidden;
}

.page-banner::before {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.05'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
}

.page-banner .container {
  position: relative;
  z-index: 1;
}

.page-banner h1 {
  font-size: var(--font-size-4xl);
  font-weight: 700;
  margin-bottom: 0.5rem;
}

.page-banner p {
  font-size: var(--font-size-lg);
  opacity: 0.85;
}

@media (max-width: 768px) {
  .page-banner {
    padding: 3rem 0 2rem;
  }

  .page-banner h1 {
    font-size: var(--font-size-2xl);
  }
}
</style>
