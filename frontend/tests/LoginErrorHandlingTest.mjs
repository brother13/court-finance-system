import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import assert from 'assert'

const __dirname = dirname(fileURLToPath(import.meta.url))
const loginView = readFileSync(resolve(__dirname, '../src/views/auth/LoginView.vue'), 'utf8')

assert(loginView.includes('const loadUnits = async () => {'), 'login view should define unit loading')
assert(loginView.includes('const submit = async () => {'), 'login view should define login submit')

assert(
  /const loadUnits = async \(\) => \{[\s\S]*?catch\s*(\(|\{)/.test(loginView),
  'unit loading should catch API failures so startup errors do not become unhandled Vue errors'
)

assert(
  /const submit = async \(\) => \{[\s\S]*?catch\s*(\(|\{)/.test(loginView),
  'login submit should catch API failures so invalid credentials do not become unhandled Vue errors'
)

console.log('Login error handling test passed')
