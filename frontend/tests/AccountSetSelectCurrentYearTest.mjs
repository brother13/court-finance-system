import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import assert from 'assert'

const __dirname = dirname(fileURLToPath(import.meta.url))
const view = readFileSync(resolve(__dirname, '../src/views/auth/AccountSetSelectView.vue'), 'utf8')

assert(view.includes('currentCalendarYear'), 'account set selection should derive the default year from the current calendar date')
assert(view.includes('defaultSelectedYear'), 'account set selection should use a dedicated helper for default year selection')
assert(view.includes('item.available_years?.includes(currentCalendarYear())'), 'current year should be selected when it exists in the account set available years')
assert(!view.includes('selectedYear.value = item.enabled_year || (item.available_years?.[0] ?? 0)'), 'account set selection should not default directly to enabled year')

console.log('Account set select current year test passed')
