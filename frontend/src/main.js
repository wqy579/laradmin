/* deploy fix v3 */
import { createApp } from 'vue'
import App from './App.vue'
import boot from './boot'

const app = createApp(App)
boot(app)
app.mount('#app')


