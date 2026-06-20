import { existsSync, readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import assert from 'node:assert'

const __dirname = dirname(fileURLToPath(import.meta.url))
const html = readFileSync(resolve(__dirname, '../index.html'), 'utf8')
const faviconPath = resolve(__dirname, '../public/favicon.png')

assert(html.includes('<link rel="icon" type="image/png" href="/favicon.png" />'), 'index.html should point the browser tab favicon to the raspberry logo')
assert(existsSync(faviconPath), 'public favicon image should exist for Vite to serve at /favicon.png')

console.log('App favicon test passed')
