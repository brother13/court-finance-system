import { createApp } from 'vue'
import { createPinia } from 'pinia'
import ElementPlus from 'element-plus'
import 'element-plus/dist/index.css'
import './styles/app.css'
import App from './App.vue'
import router from './router'
import { installPermissionDirective } from './directives/permission'

const app = createApp(App)
  .use(createPinia())
  .use(router)
  .use(ElementPlus)

installPermissionDirective(app)
app.mount('#app')
