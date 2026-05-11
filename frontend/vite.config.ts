import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
  base: './',
  plugins: [vue()],
  server: {
    port: 5173,
    proxy: {
      '/index.php': {
        target: 'http://127.0.0.1/courtfinance',
        changeOrigin: true
      }
    }
  }
})
