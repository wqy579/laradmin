<template>
  <section class="cta">
    <div class="container">
      <div class="cta-inner">
        <div class="cta-content">
          <h2>准备好开始了吗？</h2>
          <p>立即联系我们，获取专业的解决方案和定制化服务。</p>
        </div>
        <div class="cta-actions">
          <NuxtLink to="/contact" class="btn btn-primary btn-lg">
            立即咨询
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M5 12h14M12 5l7 7-7 7" />
            </svg>
          </NuxtLink>
          <NuxtLink to="/products" class="btn" style="color: rgba(255,255,255,0.9)">
            了解产品
          </NuxtLink>
        </div>
      </div>
    </div>
  </section>
</template>

<style scoped>
.cta {
  background: linear-gradient(135deg, var(--color-primary-800), var(--color-primary));
  padding: 5rem 0;
}

.cta-inner {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 2rem;
}

.cta-content h2 {
  font-size: var(--font-size-3xl);
  font-weight: 700;
  color: #fff;
  margin-bottom: 0.5rem;
}

.cta-content p {
  font-size: var(--font-size-lg);
  color: rgba(255, 255, 255, 0.8);
}

.cta-actions {
  display: flex;
  gap: 1rem;
  flex-shrink: 0;
}

.cta .btn-primary {
  background: #fff;
  color: var(--color-primary);
  border-color: #fff;
}

.cta .btn-primary:hover {
  background: rgba(255, 255, 255, 0.9);
}

@media (max-width: 768px) {
  .cta-inner {
    flex-direction: column;
    text-align: center;
  }

  .cta-content h2 {
    font-size: var(--font-size-2xl);
  }
}
</style>
