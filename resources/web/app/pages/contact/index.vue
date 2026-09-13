<template>
  <div>
    <PageBanner title="联系我们" subtitle="我们期待与您的合作" />
    <Breadcrumb :items="[{ label: '联系我们' }]" />

    <section class="section">
      <div class="container">
        <div class="contact-grid">
          <div class="contact-info">
            <h2>联系方式</h2>
            <p class="contact-desc">如有任何问题或合作意向，欢迎通过以下方式联系我们。</p>

            <div class="info-list">
              <div class="info-item">
                <div class="info-icon">
                  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z" />
                    <circle cx="12" cy="10" r="3" />
                  </svg>
                </div>
                <div>
                  <h4>公司地址</h4>
                  <p>江西省南昌市高新开发区火炬五路科创城</p>
                </div>
              </div>

              <div class="info-item">
                <div class="info-icon">
                  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z" />
                  </svg>
                </div>
                <div>
                  <h4>联系电话</h4>
                  <p>18970867739</p>
                </div>
              </div>

              <div class="info-item">
                <div class="info-icon">
                  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z" />
                    <polyline points="22,6 12,13 2,6" />
                  </svg>
                </div>
                <div>
                  <h4>电子邮箱</h4>
                  <p>molong@tensent.cn</p>
                </div>
              </div>

              <div class="info-item">
                <div class="info-icon">
                  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10" />
                    <polyline points="12 6 12 12 16 14" />
                  </svg>
                </div>
                <div>
                  <h4>工作时间</h4>
                  <p>周一至周五 9:00 - 18:00</p>
                </div>
              </div>
            </div>
          </div>

          <div class="contact-form-card">
            <h3>发送消息</h3>
            <form @submit.prevent="handleSubmit">
              <div class="form-row">
                <div class="form-group">
                  <label>姓名</label>
                  <input v-model="form.name" type="text" placeholder="请输入您的姓名" required />
                </div>
                <div class="form-group">
                  <label>电话</label>
                  <input v-model="form.phone" type="tel" placeholder="请输入您的电话" />
                </div>
              </div>
              <div class="form-group">
                <label>邮箱</label>
                <input v-model="form.email" type="email" placeholder="请输入您的邮箱" required />
              </div>
              <div class="form-group">
                <label>留言内容</label>
                <textarea v-model="form.message" rows="5" placeholder="请输入您想说的内容..." required></textarea>
              </div>
              <button type="submit" class="btn btn-primary btn-lg" :disabled="submitting">
                {{ submitting ? '发送中...' : '发送消息' }}
              </button>
            </form>
          </div>
        </div>
      </div>
    </section>

    <section class="map-section">
      <div id="map-container"></div>
    </section>
  </div>
</template>

<script setup lang="ts">
const config = useRuntimeConfig()

const form = reactive({
  name: '',
  phone: '',
  email: '',
  message: '',
})

const submitting = ref(false)

async function handleSubmit() {
  submitting.value = true
  try {
    await new Promise((resolve) => setTimeout(resolve, 1000))
    alert('消息已发送，我们会尽快与您联系！')
    form.name = ''
    form.phone = ''
    form.email = ''
    form.message = ''
  } finally {
    submitting.value = false
  }
}

useHead({
  title: '联系我们 - LarAdmin',
  meta: [
    { name: 'description', content: '联系 LarAdmin，获取专业的解决方案和定制化服务。' },
  ],
})

onMounted(() => {
  const script = document.createElement('script')
  script.src = `https://webapi.amap.com/maps?v=2.0&key=${config.public.amapKey || ''}&plugin=AMap.Geocoder`
  script.onload = () => {
    // eslint-disable-next-line no-undef
    const map = new AMap.Map('map-container', {
      zoom: 16,
      center: [115.924, 28.686],
      viewMode: '2D',
    })
    // eslint-disable-next-line no-undef
    const geocoder = new AMap.Geocoder()
    geocoder.getLocation('南昌市高新开发区火炬五路科创城', (status, result) => {
      let position = [115.9285, 28.6863]
      if (status === 'complete' && result.geocodes.length > 0) {
        const geo = result.geocodes[0]
        position = [geo.location.getLng() + 0.0045, geo.location.getLat() + 0.00027]
      }
      map.setCenter(position)
      // eslint-disable-next-line no-undef
      const marker = new AMap.Marker({
        position,
        title: '南昌腾速科技有限公司',
      })
      // eslint-disable-next-line no-undef
      const infoWindow = new AMap.InfoWindow({
        content: `<div style="padding:8px 12px;min-width:200px;">
          <h4 style="margin:0 0 6px;font-size:15px;color:#333;">南昌腾速科技有限公司</h4>
          <p style="margin:0 0 4px;font-size:13px;color:#666;">📍 江西省南昌市高新开发区火炬五路科创城</p>
          <p style="margin:0 0 4px;font-size:13px;color:#666;">📞 18970867739</p>
          <p style="margin:0;font-size:13px;color:#666;">📧 molong@tensent.cn</p>
        </div>`,
        offset: new AMap.Pixel(0, -36),
      })
      infoWindow.open(map, position)
      map.add(marker)
    })
  }
  document.head.appendChild(script)
})
</script>

<style scoped>
.contact-grid {
  display: grid;
  grid-template-columns: 1fr 1.2fr;
  gap: 3rem;
}

.contact-info h2 {
  font-size: var(--font-size-2xl);
  font-weight: 700;
  color: var(--color-gray-900);
  margin-bottom: 0.75rem;
}

.contact-desc {
  color: var(--color-gray-500);
  margin-bottom: 2rem;
}

.info-list {
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
}

.info-item {
  display: flex;
  gap: 1rem;
}

.info-icon {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 44px;
  height: 44px;
  flex-shrink: 0;
  background: var(--color-primary-50);
  color: var(--color-primary);
  border-radius: var(--radius);
}

.info-item h4 {
  font-size: var(--font-size-sm);
  font-weight: 600;
  color: var(--color-gray-900);
  margin-bottom: 0.125rem;
}

.info-item p {
  font-size: var(--font-size-sm);
  color: var(--color-gray-500);
}

.contact-form-card {
  padding: 2rem;
  background: #fff;
  border: 1px solid var(--color-gray-200);
  border-radius: var(--radius-lg);
}

.contact-form-card h3 {
  font-size: var(--font-size-lg);
  font-weight: 600;
  color: var(--color-gray-900);
  margin-bottom: 1.5rem;
}

.form-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 1rem;
}

.form-group {
  margin-bottom: 1rem;
}

.form-group label {
  display: block;
  font-size: var(--font-size-sm);
  font-weight: 500;
  color: var(--color-gray-700);
  margin-bottom: 0.375rem;
}

.form-group input,
.form-group textarea {
  width: 100%;
  padding: 0.625rem 0.875rem;
  font-size: var(--font-size-sm);
  color: var(--color-gray-800);
  background: var(--color-gray-50);
  border: 1px solid var(--color-gray-200);
  border-radius: var(--radius);
  transition: var(--transition);
  font-family: inherit;
}

.form-group input:focus,
.form-group textarea:focus {
  outline: none;
  border-color: var(--color-primary);
  box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
  background: #fff;
}

.form-group textarea {
  resize: vertical;
}

.contact-form-card .btn {
  width: 100%;
}

.map-section {
  background: var(--color-gray-100);
}

#map-container {
  width: 100%;
  height: 650px;
}

@media (max-width: 768px) {
  .contact-grid {
    grid-template-columns: 1fr;
  }

  .form-row {
    grid-template-columns: 1fr;
  }
}
</style>
