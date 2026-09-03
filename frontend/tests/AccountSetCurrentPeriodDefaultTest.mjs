import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import assert from 'assert'

const __dirname = dirname(fileURLToPath(import.meta.url))
const source = readFileSync(resolve(__dirname, '../src/stores/context.ts'), 'utf8')

assert(source.includes('savedPeriodForAccountSet'), 'context store should derive the default period from the selected account set')
assert(
  source.includes("accountSet?.current_period || ''"),
  'context store default period should prefer the account-set current period (latest voucher/open period) over the calendar month'
)
assert(
  source.includes('const period = savedPeriodForAccountSet(auth)'),
  'context store state initialization should delegate period selection to the account-set-aware helper'
)
assert(
  source.includes('accountSet?.enabled_period') && source.includes('currentMonthPeriod()'),
  'context store default period should fall back through enabled period and then the current calendar month'
)

console.log('Account set current period default test passed')
