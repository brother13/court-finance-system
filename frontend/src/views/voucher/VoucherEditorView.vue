<template>
  <div class="page-header">
    <div>
      <h1>{{ pageTitle }}</h1>
    </div>
    <div class="page-actions">
      <el-button v-if="isViewMode || isEditMode" :icon="ArrowLeft" @click="router.push('/vouchers')">返回列表</el-button>
      <template v-if="!isReadonly">
        <el-button v-if="!isEditMode" :icon="Refresh" @click="resetVoucher">新增凭证</el-button>
        <el-button v-if="context.hasAnyPermission(['voucher:add', 'voucher:edit'])" type="primary" :icon="DocumentChecked" :loading="saving" @click="submit">保存凭证</el-button>
      </template>
    </div>
  </div>

  <div class="panel voucher-u8-panel">
    <div class="voucher-u8-title">
      <h2>记账凭证</h2>
      <span>{{ context.unitName || '当前单位' }} · {{ form.period }}</span>
    </div>

    <div class="voucher-head-grid">
      <label>
        <span>凭证字</span>
        <el-select v-model="form.voucherWord" :disabled="isReadonly" style="width: 92px">
          <el-option v-for="word in voucherWords" :key="word" :label="word" :value="word" />
        </el-select>
      </label>
      <label>
        <span>凭证号</span>
        <el-input :model-value="form.voucherNo ? `第 ${form.voucherNo} 号` : '自动生成'" disabled style="width: 120px" />
      </label>
      <label>
        <span>日期</span>
        <el-date-picker v-model="form.voucherDate" type="date" value-format="YYYY-MM-DD" :disabled="isReadonly" style="width: 150px" />
      </label>
      <label>
        <span>附单据数</span>
        <el-input-number v-model="form.attachmentCount" :min="0" :precision="0" :disabled="isReadonly" controls-position="right" style="width: 110px" />
      </label>
      <label>
        <span>制单人</span>
        <el-input :model-value="form.preparedBy || context.displayName" disabled style="width: 120px" />
      </label>
      <label>
        <span>审核人</span>
        <el-input :model-value="form.auditBy" disabled placeholder="未审核" style="width: 110px" />
      </label>
      <label>
        <span>记账人</span>
        <el-input :model-value="form.postedBy" disabled placeholder="未记账" style="width: 110px" />
      </label>
    </div>

    <div class="voucher-entry-wrap">
      <table class="voucher-entry-table">
        <thead>
          <tr>
            <th class="entry-index">序号</th>
            <th class="entry-summary">摘要</th>
            <th class="entry-subject">科目编码 / 科目名称</th>
            <th class="entry-aux">辅助核算</th>
            <th class="entry-money">借方金额</th>
            <th class="entry-money">贷方金额</th>
            <th class="entry-action">操作</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(row, index) in form.details" :key="row.uid">
            <td class="entry-index">{{ index + 1 }}</td>
            <td>
              <el-input
                v-model="row.summary"
                class="paper-cell-control"
                :disabled="isReadonly"
                @blur="applySummaryShortcut(row, index)"
              />
            </td>
            <td>
              <el-select
                v-model="row.subjectCode"
                filterable
                clearable
                placeholder="输入编码或 F7 选择科目"
                class="paper-cell-control"
                style="width: 100%"
                :disabled="isReadonly"
                @change="handleSubjectChange(row)"
              >
                <el-option
                  v-for="subject in terminalVoucherSubjects"
                  :key="subject.subject_code"
                  :label="`${subject.subject_code} ${subject.subject_name}`"
                  :value="subject.subject_code"
                />
              </el-select>
            </td>
            <td>
              <button
                v-if="lineAuxConfigs(row).length"
                type="button"
                class="aux-pill-button"
                :class="{ warning: !isLineAuxComplete(row) }"
                @click="openAuxDialog(row)"
              >
                {{ auxSummary(row) || '录入辅助核算' }}
              </button>
              <span v-else class="muted">—</span>
            </td>
            <td>
              <el-input
                v-model="row.debitText"
                class="paper-cell-control paper-money-input"
                placeholder="0.00"
                :disabled="isReadonly"
                @input="handleDebitInput(row)"
                @blur="handleDebitBlur(row, index)"
              />
            </td>
            <td>
              <el-input
                v-model="row.creditText"
                class="paper-cell-control paper-money-input"
                placeholder="0.00"
                :disabled="isReadonly"
                @input="handleCreditInput(row)"
                @blur="normalizeAmount(row, 'CREDIT')"
              />
            </td>
            <td class="entry-action">
              <template v-if="!isReadonly">
                <el-button link type="primary" @click="insertLine(index)">插行</el-button>
                <el-button link type="danger" :disabled="form.details.length <= 2" @click="removeLine(index)">删行</el-button>
              </template>
              <span v-else class="muted">查看</span>
            </td>
          </tr>
        </tbody>
        <tfoot>
          <tr>
            <td colspan="4">
              <strong>合计</strong>
              <span class="voucher-uppercase">{{ uppercaseAmount }}</span>
            </td>
            <td class="entry-money-total">{{ money(totalDebit) }}</td>
            <td class="entry-money-total">{{ money(totalCredit) }}</td>
            <td />
          </tr>
        </tfoot>
      </table>
    </div>

    <div class="voucher-footer">
      <div :class="['balance-indicator', isBalanced ? '' : 'unbalanced']">
        <span>
          <el-icon><component :is="isBalanced ? CircleCheck : WarningFilled" /></el-icon>
          {{ isBalanced ? (isReadonly ? '借贷平衡' : '借贷平衡，可以保存') : `借贷不平衡，差额 ${money(Math.abs(difference))}` }}
        </span>
        <small>分录 {{ validLineCount }} 行 · 状态：{{ statusText(form.status) }}</small>
      </div>
      <div v-if="!isReadonly" class="paper-actions-buttons">
        <el-button :icon="Plus" @click="addLine">增行</el-button>
        <el-button @click="fillBalanceForLastLine">当前行找平</el-button>
        <el-button v-if="context.hasAnyPermission(['voucher:add', 'voucher:edit'])" type="success" :icon="DocumentChecked" :loading="saving" @click="submit">保存凭证</el-button>
      </div>
    </div>
  </div>

  <el-dialog v-model="auxDialogVisible" :title="isReadonly ? '辅助核算查看' : '辅助核算录入'" width="560px" :close-on-click-modal="false">
    <template v-if="currentLine">
      <el-alert
        v-if="!isReadonly"
        class="mb-16"
        type="warning"
        :closable="false"
        show-icon
        title="当前科目已挂辅助核算，必填维度未录入前不能继续录金额。"
      />
      <el-form label-position="top">
        <el-form-item
          v-for="config in lineAuxConfigs(currentLine)"
          :key="config.aux_type_code"
          :label="auxTypeName(config.aux_type_code) + (Number(config.required_flag) === 1 ? ' *' : '')"
          :required="Number(config.required_flag) === 1"
        >
          <el-select
            v-if="auxArchives[config.aux_type_code]?.length"
            v-model="currentLine.auxValues[config.aux_type_code]"
            filterable
            clearable
            :disabled="isReadonly"
            placeholder="请选择辅助档案"
          >
            <el-option
              v-for="archive in auxArchives[config.aux_type_code]"
              :key="archive.archive_code"
              :label="`${archive.archive_code} ${archive.archive_name}`"
              :value="archive.archive_code"
            />
          </el-select>
          <el-input v-else v-model.trim="currentLine.auxValues[config.aux_type_code]" :disabled="isReadonly" placeholder="请输入辅助信息" />
        </el-form-item>
      </el-form>
    </template>
    <template #footer>
      <el-button @click="auxDialogVisible = false">{{ isReadonly ? '关闭' : '取消' }}</el-button>
      <el-button v-if="!isReadonly" type="primary" @click="confirmAuxDialog">确定</el-button>
    </template>
  </el-dialog>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import { ArrowLeft, CircleCheck, DocumentChecked, Plus, Refresh, WarningFilled } from '@element-plus/icons-vue'
import { baseApi } from '../../api/base'
import { voucherApi } from '../../api/voucher'
import { useContextStore } from '../../stores/context'
import type { Subject, SubjectAuxConfig, Voucher } from '../../types/api'

interface VoucherLine {
  uid: string
  summary: string
  subjectCode: string
  debitText: string
  creditText: string
  auxValues: Record<string, string>
  auxLabels: Record<string, string>
}

const router = useRouter()
const route = useRoute()
const context = useContextStore()
const subjects = ref<Subject[]>([])
const auxConfigMap = reactive<Record<string, SubjectAuxConfig[]>>({})
const auxArchives = reactive<Record<string, any[]>>({})
const auxDialogVisible = ref(false)
const currentLine = ref<VoucherLine | null>(null)
const saving = ref(false)
const voucherWords = ['记', '收', '付', '转']
const DEFAULT_VOUCHER_LINE_COUNT = 6

const todayInPeriod = () => {
  const today = new Date()
  const y = today.getFullYear()
  const m = `${today.getMonth() + 1}`.padStart(2, '0')
  const d = `${today.getDate()}`.padStart(2, '0')
  const text = `${y}-${m}-${d}`
  return text.startsWith(context.period) ? text : `${context.period}-01`
}

const createLine = (): VoucherLine => ({
  uid: `${Date.now()}-${Math.random()}`,
  summary: '',
  subjectCode: '',
  debitText: '',
  creditText: '',
  auxValues: {},
  auxLabels: {}
})

const createVoucherLines = (count = DEFAULT_VOUCHER_LINE_COUNT) => {
  return Array.from({ length: count }, () => createLine())
}

const form = reactive({
  voucherWord: '记',
  voucherNo: 0,
  period: context.period,
  voucherDate: todayInPeriod(),
  attachmentCount: 0,
  preparedBy: '',
  auditBy: '',
  postedBy: '',
  status: 'SUBMITTED',
  details: createVoucherLines()
})

const pageMode = computed(() => String(route.meta.mode || (route.params.voucherId ? 'view' : 'new')))
const isViewMode = computed(() => pageMode.value === 'view')
const isEditMode = computed(() => pageMode.value === 'edit')
const editableStatuses = ['DRAFT', 'SUBMITTED']
const isEditableStatus = computed(() => !form.status || editableStatuses.includes(form.status))
const isReadonly = computed(() => isViewMode.value || (isEditMode.value && !isEditableStatus.value))
const pageTitle = computed(() => (isViewMode.value ? '凭证查看' : isEditMode.value ? '凭证编辑' : '凭证录入'))

const statusText = (status: string) =>
  ({
    DRAFT: '草稿',
    SUBMITTED: '未审核',
    AUDITED: '已审核',
    POSTED: '已记账',
    PRINTED: '已打印',
    VOIDED: '已作废'
  }[status] || status || '草稿')

const terminalVoucherSubjects = computed(() => subjects.value.filter((subject: any) => {
  return Number(subject.leaf_flag) === 1 && Number(subject.voucher_entry_flag ?? subject.leaf_flag) === 1 && Number((subject as any).status ?? 1) === 1
}))
const totalDebit = computed(() => form.details.reduce((sum, line) => sum + parseAmount(line.debitText), 0))
const totalCredit = computed(() => form.details.reduce((sum, line) => sum + parseAmount(line.creditText), 0))
const difference = computed(() => Number((totalDebit.value - totalCredit.value).toFixed(2)))
const isBalanced = computed(() => difference.value === 0)
const validLineCount = computed(() => form.details.filter((line) => line.subjectCode || line.summary || parseAmount(line.debitText) || parseAmount(line.creditText)).length)
const uppercaseAmount = computed(() => toChineseAmount(Math.max(totalDebit.value, totalCredit.value)))

const parseAmount = (value: string) => {
  const normalized = String(value || '').replace(/,/g, '').trim()
  if (!normalized || normalized === '=') return 0
  const amount = Number(normalized)
  return Number.isFinite(amount) ? amount : 0
}

const money = (value: number) => value.toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

const loadNextNo = async () => {
  const result = await voucherApi.nextNo(form.period)
  form.voucherNo = result.voucher_no
}

const loadSubjectConfig = async (subjectCode: string) => {
  if (!subjectCode || auxConfigMap[subjectCode]) return
  const configs = await baseApi.subjectConfig(subjectCode)
  auxConfigMap[subjectCode] = configs
  await Promise.all(configs.map((config) => loadAuxArchives(config.aux_type_code)))
}

const loadAuxArchives = async (code: string) => {
  if (auxArchives[code]) return
  const result = await baseApi.auxArchives(code)
  auxArchives[code] = result.items || []
}

const lineAuxConfigs = (line: VoucherLine | null) => {
  return line?.subjectCode ? auxConfigMap[line.subjectCode] || [] : []
}

const handleSubjectChange = async (line: VoucherLine) => {
  await loadSubjectConfig(line.subjectCode)
  const configs = lineAuxConfigs(line)
  const nextValues: Record<string, string> = {}
  const nextLabels: Record<string, string> = {}
  configs.forEach((config) => {
    nextValues[config.aux_type_code] = line.auxValues[config.aux_type_code] || ''
    nextLabels[config.aux_type_code] = line.auxLabels[config.aux_type_code] || ''
  })
  line.auxValues = nextValues
  line.auxLabels = nextLabels
  if (configs.length > 0) {
    blurActiveElement()
    openAuxDialog(line)
  }
}

const openAuxDialog = (line: VoucherLine) => {
  blurActiveElement()
  currentLine.value = line
  auxDialogVisible.value = true
}

const confirmAuxDialog = () => {
  if (currentLine.value && !isLineAuxComplete(currentLine.value)) {
    ElMessage.warning('请完整填写必填辅助核算')
    return
  }
  auxDialogVisible.value = false
  blurActiveElement()
}

const blurActiveElement = () => {
  window.setTimeout(() => {
    const active = document.activeElement
    if (active instanceof HTMLElement) active.blur()
  }, 0)
}

const isLineAuxComplete = (line: VoucherLine) => {
  return lineAuxConfigs(line).every((config) => Number(config.required_flag) !== 1 || Boolean(line.auxValues[config.aux_type_code]))
}

const auxSummary = (line: VoucherLine) => {
  return Object.entries(line.auxValues)
    .filter(([, value]) => value)
    .map(([code, value]) => `${auxTypeName(code)}:${auxDisplayValue(code, value, line)}`)
    .join(' / ')
}

const auxDisplayValue = (code: string, value: string, line?: VoucherLine) => {
  const archive = (auxArchives[code] || []).find((item) => item.archive_code === value)
  return archive?.archive_name || line?.auxLabels[code] || value
}

const auxTypeName = (code: string) => {
  const labels: Record<string, string> = {
    customer: '客户',
    supplier: '供应商',
    department: '部门',
    employee: '职员',
    project: '项目',
    custom: '自定义',
    case_no: '案号',
    receipt_no: '收据号',
    party_name: '当事人',
    supplier_id: '供应商'
  }
  return labels[code] || code
}

const applySummaryShortcut = (row: VoucherLine, index: number) => {
  if (row.summary === '//' && form.details[0]) row.summary = form.details[0].summary
  if (row.summary === '..' && form.details[index - 1]) row.summary = form.details[index - 1].summary
}

const normalizeAmount = (row: VoucherLine, side: 'DEBIT' | 'CREDIT') => {
  const key = side === 'DEBIT' ? 'debitText' : 'creditText'
  if (row[key] === '=') {
    const debitWithout = totalDebit.value - parseAmount(row.debitText)
    const creditWithout = totalCredit.value - parseAmount(row.creditText)
    const diff = Number((debitWithout - creditWithout).toFixed(2))
    if (side === 'DEBIT') row.debitText = diff < 0 ? Math.abs(diff).toFixed(2) : ''
    if (side === 'CREDIT') row.creditText = diff > 0 ? Math.abs(diff).toFixed(2) : ''
    return
  }
  const value = parseAmount(row[key])
  row[key] = value !== 0 ? value.toFixed(2) : ''
}

const handleDebitInput = (row: VoucherLine) => {
  if (row.debitText.trim()) {
    row.creditText = ''
  }
}

const handleCreditInput = (row: VoucherLine) => {
  if (row.creditText.trim()) {
    row.debitText = ''
  }
}

const handleDebitBlur = (row: VoucherLine, index: number) => {
  normalizeAmount(row, 'DEBIT')
  fillNextCreditLine(row, index)
}

const fillNextCreditLine = (row: VoucherLine, index: number) => {
  const debit = parseAmount(row.debitText)
  if (debit === 0) return
  if (!form.details[index + 1]) {
    form.details.push(createLine())
  }
  const next = form.details[index + 1]
  const nextHasBusiness = Boolean(next.subjectCode || parseAmount(next.debitText) || parseAmount(next.creditText))
  if (nextHasBusiness) return
  next.summary = next.summary || row.summary
  next.debitText = ''
  next.creditText = debit.toFixed(2)
}

const addLine = () => form.details.push(createLine())
const insertLine = (index: number) => form.details.splice(index + 1, 0, createLine())
const removeLine = (index: number) => form.details.splice(index, 1)

const fillBalanceForLastLine = () => {
  const row = form.details[form.details.length - 1]
  if (!row) return
  if (difference.value > 0) {
    row.debitText = ''
    row.creditText = Math.abs(difference.value).toFixed(2)
  } else if (difference.value < 0) {
    row.creditText = ''
    row.debitText = Math.abs(difference.value).toFixed(2)
  }
}

const resetVoucher = async () => {
  form.voucherWord = '记'
  form.period = context.period
  form.voucherDate = todayInPeriod()
  form.attachmentCount = 0
  form.preparedBy = ''
  form.auditBy = ''
  form.postedBy = ''
  form.status = 'SUBMITTED'
  form.details = createVoucherLines()
  await loadNextNo()
}

const validateBeforeSave = () => {
  if (!form.voucherDate.startsWith(form.period)) return '凭证日期必须在当前会计期间内'
  const validRows = form.details.filter((line) => line.summary || line.subjectCode || parseAmount(line.debitText) || parseAmount(line.creditText))
  if (validRows.length < 2) return '凭证明细至少两行'
  for (let i = 0; i < validRows.length; i++) {
    const line = validRows[i]
    if (!line.summary.trim()) return `第 ${i + 1} 行摘要不能为空`
    if (!line.subjectCode) return `第 ${i + 1} 行科目不能为空`
    const subject = terminalVoucherSubjects.value.find((item) => item.subject_code === line.subjectCode)
    if (!subject) return `第 ${i + 1} 行科目不是末级或不允许录入`
    const debit = parseAmount(line.debitText)
    const credit = parseAmount(line.creditText)
    if (debit > 0 && credit > 0) return `第 ${i + 1} 行借贷金额不能同时填写`
    if (debit === 0 && credit === 0) return `第 ${i + 1} 行金额不能为空`
    if (!isLineAuxComplete(line)) return `第 ${i + 1} 行辅助核算未填写完整`
  }
  if (!isBalanced.value) return '借贷金额不平衡'
  return ''
}

const toPayload = (): Voucher => {
  const validRows = form.details.filter((line) => line.summary || line.subjectCode || parseAmount(line.debitText) || parseAmount(line.creditText))
  const payload = {
    period: form.period,
    voucher_date: form.voucherDate,
    voucher_word: form.voucherWord,
    attachment_count: form.attachmentCount,
    summary: validRows[0]?.summary || '',
    source_type: 'MANUAL',
    details: validRows.map((line) => ({
      subject_code: line.subjectCode,
      summary: line.summary,
      debit_amount: parseAmount(line.debitText),
      credit_amount: parseAmount(line.creditText),
      aux_values: Object.entries(line.auxValues)
        .filter(([, value]) => value !== '')
        .map(([code, value]) => ({
          aux_type_code: code,
          aux_value: value,
          aux_label: auxDisplayValue(code, value, line)
        })) as any
    }))
  } as Voucher
  if (isEditMode.value && route.params.voucherId) {
    payload.voucher_id = String(route.params.voucherId)
    payload.status = form.status || 'SUBMITTED'
  }
  return payload
}

const submit = async () => {
  const message = validateBeforeSave()
  if (message) {
    ElMessage.error(message)
    return
  }
  saving.value = true
  try {
    const payload = toPayload()
    if (isEditMode.value) {
      await voucherApi.save(payload)
    } else {
      await voucherApi.submit(payload)
    }
    ElMessage.success(isEditMode.value ? '凭证修改已保存' : '凭证已保存，状态：未审核')
    await router.push('/vouchers')
  } finally {
    saving.value = false
  }
}

const toChineseAmount = (amount: number) => {
  if (!amount) return '零元整'
  const digits = ['零', '壹', '贰', '叁', '肆', '伍', '陆', '柒', '捌', '玖']
  const units = ['', '拾', '佰', '仟']
  const sections = ['', '万', '亿']
  const [yuanText, centText = ''] = amount.toFixed(2).split('.')
  const sectionToChinese = (section: string) => {
    let result = ''
    let zero = false
    const chars = section.split('').reverse()
    chars.forEach((char, index) => {
      const num = Number(char)
      if (num === 0) {
        zero = result.length > 0
      } else {
        if (zero) result = digits[0] + result
        result = digits[num] + units[index] + result
        zero = false
      }
    })
    return result
  }
  const yuanSections = []
  for (let i = yuanText.length; i > 0; i -= 4) {
    yuanSections.unshift(yuanText.slice(Math.max(0, i - 4), i))
  }
  const yuan = yuanSections
    .map((section, index) => sectionToChinese(section) + (sectionToChinese(section) ? sections[yuanSections.length - 1 - index] : ''))
    .join('')
  const jiao = Number(centText[0] || 0)
  const fen = Number(centText[1] || 0)
  return `${yuan || '零'}元${jiao ? digits[jiao] + '角' : ''}${fen ? digits[fen] + '分' : jiao ? '' : '整'}`
}

onMounted(async () => {
  subjects.value = await baseApi.subjects()
  const voucherId = route.params.voucherId as string | undefined
  const period = (route.params.period as string | undefined) || form.period
  if (voucherId) {
    await loadVoucherDetail(period, voucherId)
  } else {
    await loadNextNo()
  }
})

async function loadVoucherDetail(period: string, voucherId: string) {
  const data = (await voucherApi.detail(period, voucherId)) as any
  form.voucherWord = data.voucher_word || data.voucherWord || '记'
  form.voucherNo = Number(data.voucher_no || data.voucherNo || 0)
  form.period = data.period || period
  form.voucherDate = data.voucher_date || data.voucherDate || todayInPeriod()
  form.attachmentCount = Number(data.attachment_count || data.attachmentCount || 0)
  form.preparedBy = data.prepared_by_name || data.preparedByName || data.prepared_by || data.preparedBy || ''
  form.auditBy = data.audit_by_name || data.auditByName || data.audit_by || data.auditBy || ''
  form.postedBy = data.posted_by_name || data.postedByName || data.posted_by || data.postedBy || ''
  form.status = data.status || ''

  const detailRows = (data.details || []) as any[]
  const lines: VoucherLine[] = []
  for (const item of detailRows) {
    const subjectCode = item.subject_code || item.subjectCode || ''
    const debit = Number(item.debit_amount || item.debitAmount || 0)
    const credit = Number(item.credit_amount || item.creditAmount || 0)
    const auxArr = (item.aux_values || item.auxValues || []) as any[]
    const auxValues: Record<string, string> = {}
    const auxLabels: Record<string, string> = {}
    auxArr.forEach((aux: any) => {
      const code = aux.aux_type_code || aux.auxTypeCode
      const value = aux.aux_value || aux.auxValue
      const label = aux.aux_label || aux.auxLabel
      if (code) auxValues[code] = value || ''
      if (code) auxLabels[code] = label || ''
    })
    if (subjectCode) {
      await loadSubjectConfig(subjectCode)
    }
    lines.push({
      uid: `${item.detail_id || item.detailId || Date.now()}-${Math.random()}`,
      summary: item.summary || '',
      subjectCode,
      debitText: debit !== 0 ? debit.toFixed(2) : '',
      creditText: credit !== 0 ? credit.toFixed(2) : '',
      auxValues,
      auxLabels
    })
  }
  form.details = lines.length > 0 ? lines : [createLine(), createLine()]
}
</script>
