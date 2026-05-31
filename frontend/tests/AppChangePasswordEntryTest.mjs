import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import assert from 'assert'

const __dirname = dirname(fileURLToPath(import.meta.url))
const app = readFileSync(resolve(__dirname, '../src/App.vue'), 'utf8')
const dialog = readFileSync(resolve(__dirname, '../src/components/ForceChangePasswordDialog.vue'), 'utf8')

assert(app.includes('changePasswordDialogVisible'), 'app shell should track active change-password dialog state')
assert(app.includes('title="修改密码"'), 'topbar should expose a change-password action for ordinary users')
assert(app.includes('@click="openChangePasswordDialog"'), 'change-password action should open the dialog')
assert(app.includes(':manual-visible="changePasswordDialogVisible"'), 'app should pass manual dialog visibility into password dialog')
assert(app.includes('@update:manual-visible="changePasswordDialogVisible = $event"'), 'password dialog should be able to close manual mode')

assert(dialog.includes('manualVisible'), 'password dialog should support manual opening beyond forced first-login mode')
assert(dialog.includes('emit(\'update:manualVisible\''), 'password dialog should emit manual visibility updates')
assert(dialog.includes('canCloseDialog'), 'manual password dialog should be closeable while forced first-login remains blocking')
assert(dialog.includes('取消'), 'manual password dialog should offer a cancel action')

console.log('App change password entry test passed')
