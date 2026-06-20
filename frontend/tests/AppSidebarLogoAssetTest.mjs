import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import assert from 'node:assert'

const __dirname = dirname(fileURLToPath(import.meta.url))
const app = readFileSync(resolve(__dirname, '../src/App.vue'), 'utf8')
const styles = readFileSync(resolve(__dirname, '../src/styles/app.css'), 'utf8')

assert(app.includes("import raspberryLogo from './assets/raspberry-logo.png'"), 'app shell should import the provided raspberry logo asset')
assert(app.includes('<img class="brand-logo" :src="raspberryLogo" alt="树莓软件" />'), 'sidebar brand should render the provided logo image')
assert(!app.includes('<div class="brand-mark">\n          <span class="brand-mark-core" />\n        </div>'), 'sidebar should no longer use the CSS-drawn logo mark')
assert(styles.includes('.brand-logo'), 'sidebar logo image should have stable sizing styles')
assert(styles.includes('.sidebar.is-collapsed .brand-logo'), 'collapsed sidebar should hide the logo consistently with the previous mark')

console.log('App sidebar logo asset test passed')
