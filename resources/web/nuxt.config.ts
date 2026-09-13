export default defineNuxtConfig({
  compatibilityDate: '2025-07-15',
  devtools: { enabled: true },

  app: {
    head: {
      charset: 'utf-8',
      viewport: 'width=device-width, initial-scale=1',
      link: [
        { rel: 'icon', type: 'image/x-icon', href: '/favicon.ico' },
        { rel: 'preconnect', href: 'https://fonts.googleapis.com' },
        { rel: 'preconnect', href: 'https://fonts.gstatic.com', crossorigin: '' },
        { rel: 'stylesheet', href: 'https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap' },
      ],
    },
  },

  css: ['~/assets/css/main.css'],

  components: [
    { path: '~/components/common', prefix: '' },
    { path: '~/components/home', prefix: '' },
  ],

  modules: ['@nuxt/image'],

  runtimeConfig: {
    public: {
      apiBase: process.env.NUXT_PUBLIC_API_BASE || 'http://localhost:8000',
      amapKey: process.env.NUXT_PUBLIC_AMAP_KEY || '',
    },
  },

  image: {
    quality: 80,
    formats: ['webp'],
  },
  server: {
	port: process.env.PORT || 3001,
	host: '0.0.0.0'
  }
})
