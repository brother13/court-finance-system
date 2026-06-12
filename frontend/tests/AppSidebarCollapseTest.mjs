import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import assert from 'assert'

const __dirname = dirname(fileURLToPath(import.meta.url))
const app = readFileSync(resolve(__dirname, '../src/App.vue'), 'utf8')
const styles = readFileSync(resolve(__dirname, '../src/styles/app.css'), 'utf8')

assert(app.includes(':width="sidebarWidth"'), 'app shell should bind sidebar width so it can shrink and collapse')
assert(app.includes('const sidebarCollapsed = ref(false)'), 'sidebar should keep the left rail expanded after login')
assert(app.includes("const sidebarWidth = computed(() => sidebarCollapsed.value ? '64px' : '200px')"), 'sidebar should expand to 200px and collapse to 64px')
assert(app.includes(':collapse="sidebarCollapsed"'), 'Element Plus menu should receive collapsed state')
assert(!app.includes(':default-openeds='), 'sidebar should not force all menu groups to open by default')
assert(app.includes('toggleSidebar'), 'sidebar should expose a toggle action')
assert(app.includes('收起菜单') && app.includes('展开菜单'), 'toggle should describe collapse and expand states')
assert(styles.includes('.sidebar.is-collapsed'), 'collapsed sidebar should have dedicated styles')
assert(styles.includes('.sidebar-toggle'), 'sidebar toggle should have stable button styles')
assert(styles.includes('width: 32px'), 'sidebar toggle should use compact icon-button sizing')
assert(styles.includes('.app-shell > .el-container'), 'app shell should constrain the right content container height')
assert(styles.includes('overflow: auto'), 'main content should own page scrolling inside the app shell')
assert(!app.includes('width="240px"'), 'sidebar should not keep the old 240px fixed width')

console.log('App sidebar collapse test passed')
