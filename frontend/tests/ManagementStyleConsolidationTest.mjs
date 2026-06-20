import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import assert from 'assert'

const __dirname = dirname(fileURLToPath(import.meta.url))
const styles = readFileSync(resolve(__dirname, '../src/styles/app.css'), 'utf8')

const globalRequirements = [
  '.management-filter-section {',
  '.management-filter-section .filter-actions {',
  '.management-filter-section .manage-search {',
  '.management-panel .panel-header {',
  '.management-panel .el-button.is-link.el-button--danger:hover',
  '.finance-dialog .el-dialog__header {',
  '.finance-dialog .el-dialog__footer {',
  '.form-section-title {'
]

for (const requirement of globalRequirements) {
  assert(styles.includes(requirement), `global app styles should define ${requirement}`)
}

const userView = readFileSync(resolve(__dirname, '../src/views/system/UserManageView.vue'), 'utf8')
const roleView = readFileSync(resolve(__dirname, '../src/views/system/RoleManageView.vue'), 'utf8')
const accountSetView = readFileSync(resolve(__dirname, '../src/views/system/AccountSetManageView.vue'), 'utf8')
const auditLogView = readFileSync(resolve(__dirname, '../src/views/system/AuditLogView.vue'), 'utf8')
const subjectView = readFileSync(resolve(__dirname, '../src/views/base/SubjectManageView.vue'), 'utf8')
const auxView = readFileSync(resolve(__dirname, '../src/views/base/AuxItemManageView.vue'), 'utf8')
const openingView = readFileSync(resolve(__dirname, '../src/views/base/OpeningBalanceView.vue'), 'utf8')
const permissionView = readFileSync(resolve(__dirname, '../src/views/system/RolePermissionView.vue'), 'utf8')

for (const [name, view] of [
  ['UserManageView.vue', userView],
  ['RoleManageView.vue', roleView]
]) {
  assert(view.includes('management-filter-section'), `${name} should use the shared management filter section`)
  assert(view.includes('class="filter-field manage-search"'), `${name} should use shared search width class`)
  assert(view.includes('class="filter-field manage-status"'), `${name} should use shared status width class`)
  assert(!view.includes('style="width: 260px"'), `${name} should not keep inline search width`)
  assert(!view.includes('style="width: 240px"'), `${name} should not keep inline search width`)
  assert(!view.includes('style="width: 140px"'), `${name} should not keep inline status width`)
}

for (const [name, view] of [
  ['UserManageView.vue', userView],
  ['RoleManageView.vue', roleView],
  ['AccountSetManageView.vue', accountSetView],
  ['AuditLogView.vue', auditLogView],
  ['AuxItemManageView.vue', auxView],
  ['OpeningBalanceView.vue', openingView],
  ['RolePermissionView.vue', permissionView]
]) {
  assert(view.includes('management-panel'), `${name} should use the shared management panel`)
}

assert(auditLogView.includes('<div class="page-header">'), 'audit log should have the same page header structure as other system pages')
assert(subjectView.includes('class="finance-dialog"'), 'subject dialogs should use the shared finance dialog styling')
assert(auxView.includes('class="finance-dialog"'), 'auxiliary dialogs should use the shared finance dialog styling')
assert(!accountSetView.includes('.form-section-title {'), 'account set form section title should be global')

console.log('Management style consolidation test passed')
