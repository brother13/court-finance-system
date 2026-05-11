import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import assert from 'assert'

const __dirname = dirname(fileURLToPath(import.meta.url))
const http = readFileSync(resolve(__dirname, '../src/api/http.ts'), 'utf8')
const router = readFileSync(resolve(__dirname, '../src/router/index.ts'), 'utf8')
const vite = readFileSync(resolve(__dirname, '../vite.config.ts'), 'utf8')

assert(http.includes("import.meta.env.VITE_API_BASE || '../index.php'"), 'single-site deploy should default API requests to backend/public/index.php')
assert(!http.includes("baseURL: 'http://127.0.0.1:8080/'"), 'deployed frontend should not hard-code localhost API base')
assert(router.includes('createWebHashHistory'), 'single-site deploy should use hash history so refresh works under /app/')
assert(!router.includes('createWebHistory()'), 'single-site deploy should not require Apache history fallback rewrites')
assert(vite.includes("base: './'"), 'Vite build should use relative asset paths for backend/public/app')
assert(vite.includes("'/index.php'"), 'dev server should proxy /index.php to local PHP backend')

console.log('Single site deploy test passed')
