import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import assert from 'assert'

const __dirname = dirname(fileURLToPath(import.meta.url))
const context = readFileSync(resolve(__dirname, '../src/stores/context.ts'), 'utf8')

assert(context.includes('normalizeMustChangePassword'), 'context store should normalize must_change_password values explicitly')
assert(context.includes("normalized === '1' || normalized === 'true'"), 'string "0" should not be treated as a forced password change')
assert(!context.includes('Boolean(auth.must_change_password)'), 'saved auth must_change_password should not use truthy coercion')
assert(!context.includes('Boolean(user.must_change_password)'), 'login auth must_change_password should not use truthy coercion')
assert(context.includes('must_change_password: false'), 'password change completion should clear persisted snake_case flag')
assert(context.includes('mustChangePassword: false'), 'password change completion should clear persisted camelCase flag')

console.log('Context must_change_password normalization test passed')
