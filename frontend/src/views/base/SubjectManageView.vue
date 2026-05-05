<template>
  <div class="subject-board">
    <div class="subject-tabs">
      <button
        v-for="item in subjectTypes"
        :key="item.value"
        class="subject-tab"
        :class="{ active: typeFilter === item.value }"
        @click="typeFilter = item.value"
      >
        {{ item.label }}
      </button>
    </div>

    <div class="subject-toolbar">
      <span class="subject-tip">*提示：按 Ctrl + F 键并输入科目编码或者科目名称可以查找科目。</span>
      <div class="subject-actions">
        <el-checkbox v-model="showDisabled">显示停用科目</el-checkbox>
        <el-input v-model="keyword" placeholder="科目编码 / 科目名称" clearable class="subject-search" />
        <el-button v-permission="'base:add'" type="primary" :icon="Plus" @click="openCreate()">新增科目</el-button>
        <el-button :icon="Setting" @click="openCodeRuleDialog">编码设置</el-button>
        <input ref="importInputRef" type="file" accept=".xls" class="subject-file-input" @change="handleSubjectImportFile" />
        <el-button :icon="Upload" :loading="importing" @click="chooseSubjectImportFile">导入</el-button>
        <el-button :icon="Download" :loading="exporting" @click="exportSubjects">导出</el-button>
        <el-button :icon="Refresh" @click="load">刷新</el-button>
      </div>
    </div>

    <div class="subject-table-shell">
      <el-table
        :data="treeRows"
        border
        default-expand-all
        row-key="subject_code"
        height="calc(100vh - 230px)"
        :tree-props="{ children: 'children' }"
      >
        <el-table-column prop="subject_code" label="科目编码" width="220" fixed="left" />
        <el-table-column prop="subject_name" label="科目名称" min-width="210" fixed="left" />
        <el-table-column label="助记码" min-width="180">
          <template #default="{ row }">{{ row.mnemonic_code || row.help_code || '' }}</template>
        </el-table-column>
        <el-table-column label="余额方向" width="110" align="center">
          <template #default="{ row }">{{ row.direction === 'DEBIT' ? '借' : '贷' }}</template>
        </el-table-column>
        <el-table-column label="状态" width="100" align="center">
          <template #default="{ row }">{{ Number(row.status) === 1 ? '正常' : '停用' }}</template>
        </el-table-column>
        <el-table-column label="操作" width="150" fixed="right" align="center">
          <template #default="{ row }">
            <el-button v-permission="'base:edit'" link type="primary" @click="openEdit(row)">编辑</el-button>
            <el-button v-permission="'base:delete'" link type="danger" @click="confirmDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
    </div>
  </div>

  <el-dialog v-model="dialogVisible" :title="form.subject_id ? '编辑科目' : '新增科目'" width="720px">
    <el-alert
      class="mb-16"
      type="warning"
      :closable="false"
      show-icon
      title="科目已有期初或凭证后，不允许修改科目类别、层级和辅助核算配置。"
    />
    <el-alert class="mb-16" type="info" :closable="false" show-icon :title="codeRuleHint" />
    <el-form label-position="top" :model="form">
      <div class="form-grid two">
        <el-form-item label="科目编码" required>
          <el-input v-model.trim="form.subject_code" :placeholder="subjectCodePlaceholder" />
        </el-form-item>
        <el-form-item label="科目名称" required>
          <el-input v-model.trim="form.subject_name" placeholder="如 银行存款" />
        </el-form-item>
        <el-form-item label="科目类别" required>
          <el-select v-model="form.subject_type" @change="syncDirectionByType">
            <el-option v-for="item in subjectTypes" :key="item.value" :label="item.label" :value="item.value" />
          </el-select>
        </el-form-item>
        <el-form-item label="余额方向">
          <el-select v-model="form.direction">
            <el-option label="借方" value="DEBIT" />
            <el-option label="贷方" value="CREDIT" />
          </el-select>
        </el-form-item>
        <el-form-item label="上级科目">
          <el-select v-model="form.parent_code" placeholder="一级科目可为空" filterable clearable @change="syncParentMeta">
            <el-option
              v-for="subject in parentOptions"
              :key="subject.subject_code"
              :label="`${subject.subject_code} ${subject.subject_name}`"
              :value="subject.subject_code"
            />
          </el-select>
        </el-form-item>
        <el-form-item label="科目级次">
          <el-input-number v-model="form.level_no" :min="1" :max="9" controls-position="right" disabled />
        </el-form-item>
        <el-form-item label="启用状态">
          <el-switch v-model="enabled" active-text="启用" inactive-text="停用" />
        </el-form-item>
      </div>
      <el-form-item label="备注">
        <el-input v-model="form.remark" type="textarea" :rows="2" />
      </el-form-item>
      <div class="subject-form-aux">
        <div class="aux-checkbox-row">
          <el-checkbox :model-value="auxEnabled" @change="toggleAuxEnabled">辅助核算</el-checkbox>
          <el-checkbox
            v-if="auxEnabled"
            v-for="item in auxTypeOptions"
            :key="item.aux_type_code"
            :model-value="isAuxChecked(item.aux_type_code)"
            @change="(checked: boolean) => toggleAux(item.aux_type_code, checked)"
          >
            {{ item.aux_type_name }}
          </el-checkbox>
        </div>
      </div>
    </el-form>
    <template #footer>
      <el-button @click="dialogVisible = false">取消</el-button>
      <el-button v-permission="['base:add', 'base:edit']" type="primary" :loading="saving" @click="save">保存</el-button>
    </template>
  </el-dialog>

  <el-dialog v-model="codeRuleVisible" title="编码设置" width="700px" class="code-rule-dialog">
    <div class="code-rule-editor">
      <span>科目编码规则：</span>
      <template v-for="(segment, index) in codeRuleSegments" :key="index">
        <strong v-if="index === 0">{{ segment }}</strong>
        <el-input-number v-else v-model="codeRuleSegments[index]" :min="1" :max="9" controls-position="right" />
        <em v-if="index < codeRuleSegments.length - 1">-</em>
      </template>
      <el-button circle :icon="Plus" @click="addCodeRuleSegment" />
      <el-button v-if="codeRuleSegments.length > 1" circle :icon="Delete" @click="removeCodeRuleSegment" />
    </div>
    <template #footer>
      <el-button @click="codeRuleVisible = false">取消</el-button>
      <el-button v-permission="'base:edit'" type="success" :loading="codeRuleSaving" @click="saveCodeRule">保存</el-button>
    </template>
  </el-dialog>

</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Delete, Download, Plus, Refresh, Setting, Upload } from '@element-plus/icons-vue'
import { baseApi } from '../../api/base'
import type { Subject, SubjectCodeRule } from '../../types/api'

const subjectTypes = [
  { label: '资产', value: 'ASSET', direction: 'DEBIT' },
  { label: '负债', value: 'LIABILITY', direction: 'CREDIT' },
  { label: '权益', value: 'EQUITY', direction: 'CREDIT' },
  { label: '成本', value: 'COST', direction: 'DEBIT' },
  { label: '损益', value: 'PROFIT_LOSS', direction: 'DEBIT' }
]
const interactAuxCodes = ['customer', 'supplier', 'employee']
const defaultCodeRule: SubjectCodeRule = { rule: '4-2-2-2', segments: [4, 2, 2, 2], lengths: [4, 6, 8, 10] }

const rows = ref<Subject[]>([])
const auxTypes = ref<any[]>([])
const codeRule = ref<SubjectCodeRule>({ ...defaultCodeRule })
const codeRuleSegments = ref<number[]>([4, 2, 2, 2])
const keyword = ref('')
const typeFilter = ref('ASSET')
const showDisabled = ref(false)
const dialogVisible = ref(false)
const saving = ref(false)
const codeRuleVisible = ref(false)
const codeRuleSaving = ref(false)
const importing = ref(false)
const exporting = ref(false)
const auxEnabled = ref(false)
const auxConfigItems = ref<any[]>([])
const importInputRef = ref<HTMLInputElement | null>(null)
const form = reactive<any>({
  subject_id: '',
  subject_code: '',
  subject_name: '',
  parent_code: '',
  direction: 'DEBIT',
  subject_type: 'ASSET',
  level_no: 1,
  leaf_flag: 1,
  voucher_entry_flag: 1,
  status: 1,
  remark: ''
})

const enabled = computed({
  get: () => Number(form.status) === 1,
  set: (value: boolean) => {
    form.status = value ? 1 : 0
  }
})

const rowsByCode = computed(() => {
  const map = new Map<string, Subject>()
  rows.value.forEach((row) => {
    if (row.subject_code) map.set(row.subject_code, row)
  })
  return map
})

const treeRows = computed(() => {
  const key = keyword.value.trim()
  const candidates = rows.value.filter((row) => {
    const matchType = !typeFilter.value || row.subject_type === typeFilter.value
    const matchStatus = showDisabled.value || Number(row.status) === 1
    return matchType && matchStatus
  })
  const includeCodes = new Set<string>()
  candidates.forEach((row) => {
    const text = `${row.subject_code || ''}${row.subject_name || ''}`
    const matchKey = !key || text.includes(key)
    if (!matchKey || !row.subject_code) return
    includeCodes.add(row.subject_code)
    let parentCode = row.parent_code || ''
    while (parentCode) {
      const parent = rowsByCode.value.get(parentCode)
      if (!parent || parent.subject_type !== row.subject_type) break
      includeCodes.add(parent.subject_code || '')
      parentCode = parent.parent_code || ''
    }
  })
  const visibleRows = key ? candidates.filter((row) => includeCodes.has(row.subject_code || '')) : candidates
  return buildSubjectTree(visibleRows)
})

const parentOptions = computed(() => {
  return rows.value.filter((row) => {
    if (!row.subject_code || row.subject_code === form.subject_code) return false
    if (row.subject_type !== form.subject_type) return false
    return codeLevel(row.subject_code) > 0 && codeLevel(row.subject_code) < codeRule.value.segments.length
  })
})

const auxTypeOptions = computed(() => {
  const standardOrder = ['customer', 'supplier', 'department', 'employee', 'project', 'custom']
  return [...auxTypes.value].sort((a, b) => {
    const ai = standardOrder.indexOf(a.aux_type_code)
    const bi = standardOrder.indexOf(b.aux_type_code)
    return (ai === -1 ? 99 : ai) - (bi === -1 ? 99 : bi)
  })
})

const codeRuleHint = computed(() => {
  const parentCode = form.parent_code || ''
  if (!parentCode) return `当前编码规则：${codeRule.value.rule}；一级科目编码长度应为 ${codeRule.value.lengths[0]} 位。`
  const expected = expectedCodeLength(parentCode)
  return `当前编码规则：${codeRule.value.rule}；下级科目必须以 ${parentCode} 开头，编码长度应为 ${expected || '-'} 位。`
})

const subjectCodePlaceholder = computed(() => {
  if (!form.parent_code) return `如 ${'1'.padEnd(codeRule.value.lengths[0], '0')}`
  const expected = expectedCodeLength(form.parent_code)
  return expected ? `${form.parent_code}${'01'.padEnd(Math.max(expected - form.parent_code.length, 1), '0')}` : '当前父级不可继续新增'
})

const buildSubjectTree = (items: Subject[]) => {
  const sorted = [...items].sort((a, b) => String(a.subject_code).localeCompare(String(b.subject_code)))
  const nodeMap = new Map<string, Subject>()
  const roots: Subject[] = []
  sorted.forEach((row) => {
    nodeMap.set(row.subject_code || '', { ...row, children: [] })
  })
  sorted.forEach((row) => {
    const node = nodeMap.get(row.subject_code || '')
    if (!node) return
    const parent = row.parent_code ? nodeMap.get(row.parent_code) : null
    if (parent) {
      parent.children = parent.children || []
      parent.children.push(node)
    } else {
      roots.push(node)
    }
  })
  return roots
}

const codeLevel = (code: string) => {
  const index = codeRule.value.lengths.indexOf(code.length)
  return index === -1 ? 0 : index + 1
}

const expectedCodeLength = (parentCode: string) => {
  const parentIndex = codeRule.value.lengths.indexOf(parentCode.length)
  return parentIndex === -1 ? 0 : codeRule.value.lengths[parentIndex + 1] || 0
}

const hasChildSubjects = (subjectCode: string) => {
  return rows.value.some((row) => row.parent_code === subjectCode && row.subject_id !== form.subject_id)
}

const normalizeSegments = (segments: number[]) => {
  const normalized = segments.map((item) => Number(item)).filter((item) => Number.isInteger(item) && item > 0 && item <= 9)
  return normalized.length > 0 ? normalized.slice(0, 9) : [4, 2, 2, 2]
}

const lengthsFromSegments = (segments: number[]) => {
  let total = 0
  return segments.map((segment) => {
    total += segment
    return total
  })
}

const validateFormCode = () => {
  const code = String(form.subject_code || '')
  const parentCode = String(form.parent_code || '')
  if (!/^\d+$/.test(code)) return '科目编码只能输入数字'
  if (!parentCode) {
    return code.length === codeRule.value.lengths[0] ? '' : `一级科目编码长度应为${codeRule.value.lengths[0]}位`
  }
  const expected = expectedCodeLength(parentCode)
  if (!expected) return '当前上级科目已达到编码规则最大级次'
  if (code.length !== expected || !code.startsWith(parentCode)) {
    return `科目编码长度应为${expected}位，且必须以上级科目编码${parentCode}开头`
  }
  return ''
}

const syncLevelByCode = () => {
  const code = String(form.subject_code || '')
  form.level_no = codeLevel(code) || (form.parent_code ? codeLevel(form.parent_code) + 1 : 1)
  const isTerminal = !hasChildSubjects(code)
  form.leaf_flag = isTerminal ? 1 : 0
  form.voucher_entry_flag = isTerminal ? 1 : 0
}

const syncParentMeta = () => {
  const parent = rowsByCode.value.get(form.parent_code || '')
  if (!parent) {
    syncLevelByCode()
    return
  }
  form.subject_type = parent.subject_type
  form.direction = parent.direction
  form.level_no = codeLevel(parent.subject_code || '') + 1
}

const syncDirectionByType = () => {
  const item = subjectTypes.find((type) => type.value === form.subject_type)
  if (item) form.direction = item.direction
}

const resetForm = () => {
  Object.assign(form, {
    subject_id: '',
    subject_code: '',
    subject_name: '',
    parent_code: '',
    direction: 'DEBIT',
    subject_type: typeFilter.value || 'ASSET',
    level_no: 1,
    leaf_flag: 1,
    voucher_entry_flag: 1,
    status: 1,
    remark: ''
  })
}

const load = async () => {
  const [subjects, types] = await Promise.all([baseApi.subjects(), baseApi.auxTypes()])
  rows.value = subjects
  auxTypes.value = types
  try {
    codeRule.value = await baseApi.subjectCodeRule()
  } catch (error) {
    codeRule.value = { ...defaultCodeRule }
  }
}

const openCreate = (parent?: Subject) => {
  resetForm()
  auxEnabled.value = false
  auxConfigItems.value = []
  if (parent) {
    form.parent_code = parent.subject_code || ''
    form.subject_type = parent.subject_type || typeFilter.value
    form.direction = parent.direction || form.direction
    form.level_no = codeLevel(parent.subject_code || '') + 1
  } else {
    syncDirectionByType()
  }
  dialogVisible.value = true
}

const openEdit = async (row: any) => {
  Object.assign(form, {
    voucher_entry_flag: row.voucher_entry_flag ?? row.leaf_flag,
    level_no: row.level_no || codeLevel(row.subject_code || '') || 1,
    status: row.status ?? 1,
    ...row
  })
  auxConfigItems.value = row.subject_code ? await baseApi.subjectConfig(row.subject_code) : []
  auxEnabled.value = auxConfigItems.value.length > 0
  dialogVisible.value = true
}

const save = async () => {
  if (!form.subject_code || !form.subject_name) {
    ElMessage.warning('请填写科目编码和科目名称')
    return
  }
  const codeError = validateFormCode()
  if (codeError) {
    ElMessage.warning(codeError)
    return
  }
  if (Number(form.voucher_entry_flag) === 1 && Number(form.leaf_flag) !== 1) {
    ElMessage.warning('非末级科目不允许录入凭证')
    return
  }
  syncLevelByCode()
  saving.value = true
  try {
    await baseApi.saveSubject(form)
    await baseApi.saveSubjectConfig(form.subject_code, auxEnabled.value ? auxConfigItems.value : [])
    ElMessage.success('科目已保存')
    dialogVisible.value = false
    await load()
  } finally {
    saving.value = false
  }
}

const confirmDelete = async (row: Subject) => {
  if (!row.subject_id) {
    ElMessage.warning('科目ID不能为空')
    return
  }
  try {
    await ElMessageBox.confirm(
      `确认删除科目 ${row.subject_code || ''} ${row.subject_name || ''}？已有期初、凭证或下级科目的科目不允许删除。`,
      '删除科目',
      {
        type: 'warning',
        confirmButtonText: '删除',
        cancelButtonText: '取消',
        confirmButtonClass: 'el-button--danger'
      }
    )
    await baseApi.deleteSubject(row.subject_id)
    ElMessage.success('科目已删除')
    await load()
  } catch (error) {
    if (error !== 'cancel' && error !== 'close') throw error
  }
}

const openCodeRuleDialog = () => {
  codeRuleSegments.value = [...codeRule.value.segments]
  codeRuleVisible.value = true
}

const addCodeRuleSegment = () => {
  codeRuleSegments.value.push(2)
}

const removeCodeRuleSegment = () => {
  if (codeRuleSegments.value.length > 1) codeRuleSegments.value.pop()
}

const saveCodeRule = async () => {
  const segments = normalizeSegments(codeRuleSegments.value)
  codeRuleSaving.value = true
  try {
    codeRule.value = await baseApi.saveSubjectCodeRule(segments.join('-'))
    codeRuleSegments.value = [...codeRule.value.segments]
    ElMessage.success('编码规则已保存')
    codeRuleVisible.value = false
  } finally {
    codeRuleSaving.value = false
  }
}

const chooseSubjectImportFile = () => {
  if (!importInputRef.value) return
  importInputRef.value.value = ''
  importInputRef.value.click()
}

const handleSubjectImportFile = async (event: Event) => {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file) return
  if (!file.name.toLowerCase().endsWith('.xls')) {
    ElMessage.warning('请选择 .xls 格式的科目文件')
    return
  }
  importing.value = true
  try {
    const contentBase64 = await readFileBase64(file)
    const result = await baseApi.importSubjects(file.name, contentBase64)
    ElMessage.success(`导入成功：新增${result.created}个，更新${result.updated}个`)
    await load()
  } finally {
    importing.value = false
  }
}

const readFileBase64 = (file: File) => {
  return new Promise<string>((resolve, reject) => {
    const reader = new FileReader()
    reader.onload = () => {
      const result = String(reader.result || '')
      resolve(result.includes(',') ? result.split(',')[1] : result)
    }
    reader.onerror = () => reject(reader.error)
    reader.readAsDataURL(file)
  })
}

const exportSubjects = async () => {
  exporting.value = true
  try {
    const file = await baseApi.exportSubjects()
    const bytes = Uint8Array.from(atob(file.content_base64), (char) => char.charCodeAt(0))
    const blob = new Blob([bytes], { type: file.mime || 'application/vnd.ms-excel' })
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = file.filename || '科目数据.xls'
    link.click()
    URL.revokeObjectURL(url)
  } finally {
    exporting.value = false
  }
}

const isAuxChecked = (code: string) => auxConfigItems.value.some((item) => item.aux_type_code === code && Number(item.del_flag || 0) === 0)

const toggleAuxEnabled = (checked: boolean) => {
  auxEnabled.value = checked
  if (!checked) auxConfigItems.value = []
}

const toggleAux = (code: string, checked: boolean) => {
  if (checked) {
    if (interactAuxCodes.includes(code) && auxConfigItems.value.some((item) => interactAuxCodes.includes(item.aux_type_code))) {
      ElMessage.warning('客户、供应商、职员三者只能选择其一')
      return
    }
    auxConfigItems.value.push({
      aux_type_code: code,
      required_flag: 1,
      verification_flag: 0
    })
    return
  }
  auxConfigItems.value = auxConfigItems.value.filter((item) => item.aux_type_code !== code)
}

onMounted(load)
</script>

<style scoped>
.subject-board {
  background: #fff;
  border: 1px solid var(--border-soft);
  border-radius: 4px;
  overflow: hidden;
}

.subject-tabs {
  height: 52px;
  display: flex;
  align-items: flex-end;
  gap: 28px;
  padding: 0 24px;
  border-bottom: 1px solid var(--border-light);
}

.subject-tab {
  height: 52px;
  border: 0;
  border-bottom: 3px solid transparent;
  background: transparent;
  color: var(--text-main);
  font: inherit;
  font-weight: 600;
  cursor: pointer;
}

.subject-tab.active {
  color: var(--brand-blue);
  border-bottom-color: var(--brand-blue);
}

.subject-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 18px 24px 10px;
}

.subject-tip {
  color: var(--text-mute);
  white-space: nowrap;
}

.subject-actions {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  justify-content: flex-end;
}

.subject-search {
  width: 220px;
}

.subject-file-input {
  display: none;
}

.subject-table-shell {
  padding: 0 24px 24px;
}

.subject-form-aux {
  margin-top: 8px;
  padding: 14px 0 2px;
  border-top: 1px solid var(--border-light);
}

.aux-checkbox-row {
  display: flex;
  align-items: center;
  gap: 10px 20px;
  flex-wrap: wrap;
  min-height: 40px;
}

.aux-checkbox-row :deep(.el-checkbox) {
  height: 28px;
  margin-right: 0;
}

.aux-checkbox-row :deep(.el-checkbox__label) {
  font-size: 14px;
  font-weight: 400;
  color: var(--text-main);
}

.code-rule-editor {
  min-height: 118px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 16px;
  font-size: 24px;
}

.code-rule-editor span {
  font-size: 24px;
}

.code-rule-editor strong {
  font-weight: 500;
}

.code-rule-editor em {
  color: var(--text-main);
  font-style: normal;
}

.code-rule-editor :deep(.el-input-number) {
  width: 92px;
}

.code-rule-dialog :deep(.el-dialog__footer) {
  text-align: center;
  background: #f5f5f5;
}

@media (max-width: 1100px) {
  .subject-toolbar {
    align-items: flex-start;
    flex-direction: column;
  }

  .subject-tip {
    white-space: normal;
  }

  .subject-actions {
    justify-content: flex-start;
  }
}
</style>
