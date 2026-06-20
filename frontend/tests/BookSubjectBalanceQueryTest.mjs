import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import assert from 'assert'

const __dirname = dirname(fileURLToPath(import.meta.url))
const view = readFileSync(resolve(__dirname, '../src/views/books/SubjectBalanceView.vue'), 'utf8')
const api = readFileSync(resolve(__dirname, '../src/api/books.ts'), 'utf8')
const styles = readFileSync(resolve(__dirname, '../src/styles/app.css'), 'utf8')

assert(view.includes('会计期间'), 'subject balance should expose accounting period range filters')
assert(view.includes('起始科目'), 'subject balance should expose starting subject filter')
assert(view.includes('结束科目'), 'subject balance should expose ending subject filter')
assert(view.includes('科目级别'), 'subject balance should expose subject level range filter')
assert(view.includes('el-popover'), 'subject balance filters should open from a dropdown popover')
assert(view.includes('balance-filter-button'), 'subject balance should use a compact filter dropdown button above the table')
assert(view.includes('filterSummaryText'), 'subject balance filter button should summarize the selected period range')
assert(view.includes('balance-filter-popover'), 'subject balance should render filter controls in the dropdown panel')
assert(view.includes('filter-popover-actions'), 'subject balance dropdown should keep query/reset actions inside the panel')
assert(!view.includes('<aside class="balance-query-panel">'), 'subject balance should not reserve a left-side filter panel')
const filterTemplate = view.slice(view.indexOf('<el-popover'), view.indexOf('</el-popover>'))
const filterSelectCount = (filterTemplate.match(/<el-select/g) || []).length
const nonTeleportedSelectCount = (filterTemplate.match(/:teleported="false"/g) || []).length
assert(filterSelectCount > 0, 'subject balance filter dropdown should contain select controls')
assert.strictEqual(
  nonTeleportedSelectCount,
  filterSelectCount,
  'subject balance filter selects should not close the condition panel after choosing an option'
)
assert(view.includes('balanceDisplayRows'), 'subject balance should add grouped subtotal rows like the reference report')
assert(view.includes('row-class-name'), 'subject balance should style subtotal and total rows distinctly')
assert(
  view.includes('width: 100%') || styles.includes('.period-range .el-select') && styles.includes('width: 100%'),
  'subject balance range controls should not shrink inside form item flex content'
)
assert(view.includes('collapsedSubjectCodes'), 'subject balance should track collapsed parent subjects')
assert(view.includes('toggleSubjectCollapse'), 'subject balance should allow clicking parent subjects to expand or collapse children')
assert(view.includes('visibleSubjectRows'), 'subject balance should render only rows not hidden by collapsed ancestors')
assert(view.includes('hasChildSubject'), 'subject balance should only show expand controls for subjects with children')
assert(view.includes("await import('xlsx')"), 'subject balance should dynamically import xlsx only when exporting')
assert(view.includes('XLSX.writeFile'), 'subject balance should write an Excel file')
assert(view.includes('printReport'), 'subject balance should provide browser print action')
assert(!view.includes('createReportPrintPdf'), 'subject balance print should not generate image-based PDF in the browser')
assert(view.includes('booksApi.subjectBalancePdf'), 'subject balance print should call backend PDF endpoint')
assert(view.includes('URL.createObjectURL'), 'subject balance print should open a generated PDF blob')
assert(view.includes('window.open'), 'subject balance print should preview PDF in browser')
assert(view.includes(':loading="printing"'), 'subject balance print button should show a loading state while backend PDF is generated')
assert(view.includes('if (printing.value) return'), 'subject balance should prevent duplicate print clicks while loading')
assert(view.includes('printing.value = false'), 'subject balance should clear print loading state after PDF generation')
assert(!view.includes('导出功能开发中'), 'subject balance export should not be a placeholder')
assert(api.includes('start_period'), 'subject balance API wrapper should send start period')
assert(api.includes('end_period'), 'subject balance API wrapper should send end period')
assert(api.includes('subject_start_code'), 'subject balance API wrapper should send starting subject code')
assert(api.includes('subject_end_code'), 'subject balance API wrapper should send ending subject code')
assert(api.includes('level_start'), 'subject balance API wrapper should send starting subject level')
assert(api.includes('level_end'), 'subject balance API wrapper should send ending subject level')
assert(api.includes("apiBlobAction('/book/subjectBalancePdf'"), 'books API should expose backend subject balance PDF endpoint')

console.log('Book subject balance query test passed')
