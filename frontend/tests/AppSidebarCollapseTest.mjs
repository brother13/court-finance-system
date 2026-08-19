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
assert(app.includes('ref="sidebarMenuRef"'), 'sidebar should expose the menu instance for closing the oldest expanded group')
assert(!app.includes(':key="sidebarMenuKey"'), 'submenu clicks should not remount the entire menu')
assert(!app.includes(':default-openeds="openedSidebarMenus"'), 'submenu tracking should not be reapplied as remount defaults')
assert(app.includes('@open="handleSidebarMenuOpen"'), 'sidebar should handle submenu open events')
assert(app.includes('@close="handleSidebarMenuClose"'), 'sidebar should handle submenu close events')
assert(app.includes('const maxOpenedSidebarMenus = 2'), 'sidebar should cap expanded submenu groups at two')
assert(app.includes('nextOpenedMenus.length > maxOpenedSidebarMenus'), 'sidebar should detect when more than two groups are opened')
assert(app.includes('sidebarMenuRef.value?.close(oldestIndex)'), 'sidebar should close the oldest group without remounting the menu')
assert(app.includes('toggleSidebar'), 'sidebar should expose a toggle action')
assert(app.includes('收起菜单') && app.includes('展开菜单'), 'toggle should describe collapse and expand states')
assert(styles.includes('.sidebar.is-collapsed'), 'collapsed sidebar should have dedicated styles')
assert(styles.includes('.sidebar-toggle'), 'sidebar toggle should have stable button styles')
assert(styles.includes('width: 30px'), 'sidebar toggle should use compact government-system icon-button sizing')
assert(styles.includes('.app-shell > .el-container'), 'app shell should constrain the right content container height')
assert(styles.includes('overflow: auto'), 'main content should own page scrolling inside the app shell')
assert(styles.includes('.nav-menu::-webkit-scrollbar'), 'sidebar menu should explicitly hide native scrollbar tracks')
assert(styles.includes('scrollbar-width: none'), 'sidebar menu should hide Firefox scrollbars')
assert(!app.includes('width="240px"'), 'sidebar should not keep the old 240px fixed width')

console.log('App sidebar collapse test passed')
