import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import assert from 'assert'

const __dirname = dirname(fileURLToPath(import.meta.url))
const component = readFileSync(resolve(__dirname, '../src/views/voucher/VoucherEditorView.vue'), 'utf8')

assert(component.includes('VOUCHER_DRAFT_STORAGE_PREFIX'), 'new voucher editor should define a scoped draft storage prefix')
assert(component.includes('draftStorageKey'), 'new voucher editor should scope draft cache by account set, period, and user')
assert(component.includes('sessionStorage.setItem(draftStorageKey.value'), 'new voucher editor should persist unsaved form data in session storage')
assert(component.includes('sessionStorage.removeItem(draftStorageKey.value'), 'new voucher editor should clear the cached draft after reset or successful save')
assert(component.includes('restoreCachedNewVoucherDraft'), 'new voucher editor should restore a cached draft before requesting a fresh voucher number')
assert(component.includes('watch(') && component.includes('persistNewVoucherDraft'), 'new voucher editor should watch form changes and persist new-voucher drafts')

console.log('Voucher editor draft cache test passed')
