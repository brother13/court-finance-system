import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import assert from 'assert'

const __dirname = dirname(fileURLToPath(import.meta.url))
const view = readFileSync(resolve(__dirname, '../src/views/auth/AccountSetSelectView.vue'), 'utf8')

assert(view.includes('sortedAvailableYears'), 'account set selection should use one helper for displayed and default years')
assert(view.includes('defaultSelectedYear'), 'account set selection should use a dedicated helper for default year selection')
assert(view.includes('v-for="year in sortedAvailableYears(item)"'), 'available years should render in normalized ascending order')
assert(view.includes('.sort((left, right) => left - right)'), 'available years should sort numerically from small to large')
assert(view.includes('years[years.length - 1] ?? item.enabled_year ?? 0'), 'the last displayed year should be selected by default')
assert(!view.includes('currentCalendarYear'), 'the current calendar year should no longer override the last available year')

console.log('Account set select year order test passed')
