import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import assert from 'assert'

const __dirname = dirname(fileURLToPath(import.meta.url))
const main = readFileSync(resolve(__dirname, '../src/main.ts'), 'utf8')

assert(main.includes("element-plus/es/locale/lang/zh-cn"), 'Element Plus should import zh-cn locale')
assert(main.includes("dayjs/locale/zh-cn"), 'Date picker should load dayjs zh-cn locale')
assert(main.includes('.use(ElementPlus, { locale: zhCn })'), 'Element Plus should be installed with zh-cn locale')

console.log('Element Plus locale test passed')
