import { createApp } from 'vue'
import { createPinia } from 'pinia'
import ElementPlus from 'element-plus'
import zhCn from 'element-plus/es/locale/lang/zh-cn'
import 'element-plus/dist/index.css'
import 'dayjs/locale/zh-cn'
import './styles/app.css'
import App from './App.vue'
import router from './router'
import { installPermissionDirective } from './directives/permission'

const app = createApp(App)
  .use(createPinia())
  .use(router)
  .use(ElementPlus, { locale: zhCn })

installPermissionDirective(app)
app.mount('#app')
