import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = dirname(fileURLToPath(import.meta.url))
const component = readFileSync(resolve(__dirname, '../src/views/base/AuxItemManageView.vue'), 'utf8')

assert(
  component.includes('@click="openArchiveCreate">新增档案</el-button>'),
  '新增档案按钮应绑定 openArchiveCreate'
)
assert(
  /const openArchiveCreate = \(\) => \{\s*resetArchiveForm\(\)\s*archiveDialogVisible\.value = true\s*\}/.test(component),
  '新增档案应先重置表单再打开弹窗'
)
assert(
  component.includes('<el-dialog v-model="archiveDialogVisible"'),
  '新增档案弹窗应由 archiveDialogVisible 控制'
)
assert(
  !component.includes(':label="`${selectedTypeName}编码`""'),
  '档案编码表单项不应包含多余引号属性'
)

console.log('Aux item archive create test passed')
