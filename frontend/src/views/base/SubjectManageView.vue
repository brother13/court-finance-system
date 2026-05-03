<template>
  <div class="page-header">
    <div>
      <h1>科目</h1>
      <p>按企业会计准则六大类维护科目体系，控制末级、凭证录入和辅助核算挂载。</p>
    </div>
    <div class="page-actions">
      <el-button :icon="Refresh" @click="load">刷新</el-button>
      <el-button v-permission="'base:add'" type="primary" :icon="Plus" @click="openCreate">新增科目</el-button>
    </div>
  </div>

  <div class="standard-workbench">
    <aside class="standard-side-panel">
      <div class="standard-side-title">科目类别</div>
      <button
        v-for="item in subjectTypes"
        :key="item.value"
        class="standard-side-item"
        :class="{ active: typeFilter === item.value }"
        @click="typeFilter = item.value"
      >
        <span>{{ item.label }}</span>
        <strong>{{ countByType(item.value) }}</strong>
      </button>
    </aside>

    <section class="standard-main-panel">
      <div class="search-filter-section compact-filter">
        <div class="filter-row">
          <el-input v-model="keyword" placeholder="搜索科目编码或名称" clearable style="width: 260px" />
          <el-select v-model="entryFilter" placeholder="录入控制" clearable style="width: 150px">
            <el-option label="允许录入" value="1" />
            <el-option label="禁止录入" value="0" />
          </el-select>
          <el-button type="primary" @click="load">查询</el-button>
          <el-button @click="resetFilter">重置</el-button>
        </div>
      </div>

      <div class="panel">
        <div class="panel-body compact">
          <el-table :data="filteredRows" border row-key="subject_code" height="calc(100vh - 310px)">
            <el-table-column prop="subject_code" label="科目编码" width="130" fixed="left" />
            <el-table-column prop="subject_name" label="科目名称" min-width="180" fixed="left" />
            <el-table-column prop="subject_type" label="六大类" width="130">
              <template #default="{ row }">{{ subjectTypeLabel(row.subject_type) }}</template>
            </el-table-column>
            <el-table-column prop="parent_code" label="上级科目" width="120" />
            <el-table-column prop="direction" label="余额方向" width="100">
              <template #default="{ row }">{{ row.direction === 'DEBIT' ? '借方' : '贷方' }}</template>
            </el-table-column>
            <el-table-column label="末级" width="80">
              <template #default="{ row }">
                <el-tag size="small" :type="Number(row.leaf_flag) === 1 ? 'success' : 'info'">
                  {{ Number(row.leaf_flag) === 1 ? '是' : '否' }}
                </el-tag>
              </template>
            </el-table-column>
            <el-table-column label="允许录入" width="100">
              <template #default="{ row }">
                <el-tag size="small" :type="Number(row.voucher_entry_flag ?? row.leaf_flag) === 1 ? 'primary' : 'info'">
                  {{ Number(row.voucher_entry_flag ?? row.leaf_flag) === 1 ? '允许' : '禁止' }}
                </el-tag>
              </template>
            </el-table-column>
            <el-table-column label="状态" width="80">
              <template #default="{ row }">
                <el-tag size="small" :type="Number(row.status) === 1 ? 'success' : 'danger'">
                  {{ Number(row.status) === 1 ? '启用' : '禁用' }}
                </el-tag>
              </template>
            </el-table-column>
            <el-table-column label="辅助核算" min-width="180">
              <template #default="{ row }">
                <el-button v-permission="'base:edit'" link type="primary" @click="openAuxConfig(row)">设置辅助核算</el-button>
              </template>
            </el-table-column>
            <el-table-column label="操作" width="100" fixed="right">
              <template #default="{ row }">
                <el-button v-permission="'base:edit'" link type="primary" @click="openEdit(row)">编辑</el-button>
              </template>
            </el-table-column>
          </el-table>
        </div>
      </div>
    </section>
  </div>

  <el-dialog v-model="dialogVisible" :title="form.subject_id ? '编辑科目' : '新增科目'" width="720px">
    <el-alert
      class="mb-16"
      type="warning"
      :closable="false"
      show-icon
      title="科目已有期初或凭证后，不允许修改科目类别、层级和辅助核算配置。"
    />
    <el-form label-position="top" :model="form">
      <div class="form-grid two">
        <el-form-item label="科目编码" required>
          <el-input v-model.trim="form.subject_code" placeholder="如 1002、220101" />
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
          <el-select v-model="form.parent_code" placeholder="一级科目可为空" filterable clearable>
            <el-option
              v-for="subject in parentOptions"
              :key="subject.subject_code"
              :label="`${subject.subject_code} ${subject.subject_name}`"
              :value="subject.subject_code"
            />
          </el-select>
        </el-form-item>
        <el-form-item label="科目级次">
          <el-input-number v-model="form.level_no" :min="1" :max="9" controls-position="right" />
        </el-form-item>
        <el-form-item label="末级科目">
          <el-switch v-model="isLeaf" active-text="末级" inactive-text="非末级" />
        </el-form-item>
        <el-form-item label="允许录入凭证">
          <el-switch v-model="canEntry" :disabled="!isLeaf" active-text="允许" inactive-text="禁止" />
        </el-form-item>
        <el-form-item label="启用状态">
          <el-switch v-model="enabled" active-text="启用" inactive-text="禁用" />
        </el-form-item>
      </div>
      <el-form-item label="备注">
        <el-input v-model="form.remark" type="textarea" :rows="2" />
      </el-form-item>
    </el-form>
    <template #footer>
      <el-button @click="dialogVisible = false">取消</el-button>
      <el-button v-permission="['base:add', 'base:edit']" type="primary" :loading="saving" @click="save">保存</el-button>
    </template>
  </el-dialog>

  <el-drawer v-model="auxDrawerVisible" title="科目辅助核算设置" size="520px">
    <template v-if="currentSubject">
      <div class="aux-config-head">
        <strong>{{ currentSubject.subject_code }} {{ currentSubject.subject_name }}</strong>
        <p>挂载辅助核算后，凭证分录必须录入对应辅助档案；该科目不再通过下级明细科目拆分同一维度。</p>
      </div>
      <el-alert
        class="mb-16"
        type="info"
        :closable="false"
        title="客户、供应商、职员属于往来类辅助，同一科目只能选择其一；部门、项目、自定义可自由组合。"
      />

      <div class="aux-config-list">
        <label v-for="item in auxTypeOptions" :key="item.aux_type_code" class="aux-config-item">
          <el-checkbox
            :model-value="isAuxChecked(item.aux_type_code)"
            @change="(checked: boolean) => toggleAux(item.aux_type_code, checked)"
          />
          <span>
            <strong>{{ item.aux_type_name }}</strong>
            <small>{{ auxRuleLabel(item.aux_type_code) }}</small>
          </span>
          <el-switch
            :model-value="isAuxRequired(item.aux_type_code)"
            :disabled="!isAuxChecked(item.aux_type_code)"
            active-text="必填"
            inactive-text="可选"
            @change="(checked: boolean) => setAuxRequired(item.aux_type_code, checked)"
          />
        </label>
      </div>
    </template>
    <template #footer>
      <el-button @click="auxDrawerVisible = false">取消</el-button>
      <el-button v-permission="'base:edit'" type="primary" :loading="auxSaving" @click="saveAuxConfig">保存配置</el-button>
    </template>
  </el-drawer>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { Plus, Refresh } from '@element-plus/icons-vue'
import { baseApi } from '../../api/base'

const subjectTypes = [
  { label: '资产', value: 'ASSET', direction: 'DEBIT' },
  { label: '负债', value: 'LIABILITY', direction: 'CREDIT' },
  { label: '共同', value: 'COMMON', direction: 'DEBIT' },
  { label: '所有者权益', value: 'EQUITY', direction: 'CREDIT' },
  { label: '成本', value: 'COST', direction: 'DEBIT' },
  { label: '损益', value: 'PROFIT_LOSS', direction: 'DEBIT' }
]
const interactAuxCodes = ['customer', 'supplier', 'employee']

const rows = ref<any[]>([])
const auxTypes = ref<any[]>([])
const keyword = ref('')
const typeFilter = ref('ASSET')
const entryFilter = ref('')
const dialogVisible = ref(false)
const saving = ref(false)
const auxDrawerVisible = ref(false)
const auxSaving = ref(false)
const currentSubject = ref<any>(null)
const auxConfigItems = ref<any[]>([])
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

const isLeaf = computed({
  get: () => Number(form.leaf_flag) === 1,
  set: (value: boolean) => {
    form.leaf_flag = value ? 1 : 0
    if (!value) form.voucher_entry_flag = 0
  }
})

const canEntry = computed({
  get: () => Number(form.voucher_entry_flag) === 1,
  set: (value: boolean) => {
    form.voucher_entry_flag = value ? 1 : 0
  }
})

const enabled = computed({
  get: () => Number(form.status) === 1,
  set: (value: boolean) => {
    form.status = value ? 1 : 0
  }
})

const parentOptions = computed(() => rows.value.filter((row) => row.subject_code !== form.subject_code))
const auxTypeOptions = computed(() => {
  const standardOrder = ['customer', 'supplier', 'department', 'employee', 'project', 'custom']
  return [...auxTypes.value].sort((a, b) => {
    const ai = standardOrder.indexOf(a.aux_type_code)
    const bi = standardOrder.indexOf(b.aux_type_code)
    return (ai === -1 ? 99 : ai) - (bi === -1 ? 99 : bi)
  })
})

const filteredRows = computed(() => {
  const key = keyword.value.trim()
  return rows.value.filter((row) => {
    const matchType = !typeFilter.value || row.subject_type === typeFilter.value
    const entryValue = String(row.voucher_entry_flag ?? row.leaf_flag)
    const matchEntry = !entryFilter.value || entryValue === entryFilter.value
    const matchKey = !key || `${row.subject_code}${row.subject_name}`.includes(key)
    return matchType && matchEntry && matchKey
  })
})

const countByType = (type: string) => rows.value.filter((row) => row.subject_type === type).length
const subjectTypeLabel = (type: string) => subjectTypes.find((item) => item.value === type)?.label || type

const resetFilter = () => {
  keyword.value = ''
  entryFilter.value = ''
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

const syncDirectionByType = () => {
  const item = subjectTypes.find((type) => type.value === form.subject_type)
  if (item) form.direction = item.direction
}

const load = async () => {
  const [subjects, types] = await Promise.all([baseApi.subjects(), baseApi.auxTypes()])
  rows.value = subjects
  auxTypes.value = types
}

const openCreate = () => {
  resetForm()
  syncDirectionByType()
  dialogVisible.value = true
}

const openEdit = (row: any) => {
  Object.assign(form, {
    voucher_entry_flag: row.voucher_entry_flag ?? row.leaf_flag,
    level_no: row.level_no || 1,
    status: row.status ?? 1,
    ...row
  })
  dialogVisible.value = true
}

const save = async () => {
  if (!form.subject_code || !form.subject_name) {
    ElMessage.warning('请填写科目编码和科目名称')
    return
  }
  if (Number(form.voucher_entry_flag) === 1 && Number(form.leaf_flag) !== 1) {
    ElMessage.warning('非末级科目不允许录入凭证')
    return
  }
  saving.value = true
  try {
    await baseApi.saveSubject(form)
    ElMessage.success('科目已保存')
    dialogVisible.value = false
    await load()
  } finally {
    saving.value = false
  }
}

const openAuxConfig = async (row: any) => {
  currentSubject.value = row
  auxConfigItems.value = await baseApi.subjectConfig(row.subject_code)
  auxDrawerVisible.value = true
}

const isAuxChecked = (code: string) => auxConfigItems.value.some((item) => item.aux_type_code === code && Number(item.del_flag || 0) === 0)
const isAuxRequired = (code: string) => auxConfigItems.value.find((item) => item.aux_type_code === code)?.required_flag !== 0
const auxRuleLabel = (code: string) => {
  if (interactAuxCodes.includes(code)) return '往来类，客户/供应商/职员三选一'
  return '非往来类，可与其他维度组合'
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

const setAuxRequired = (code: string, checked: boolean) => {
  const item = auxConfigItems.value.find((config) => config.aux_type_code === code)
  if (item) item.required_flag = checked ? 1 : 0
}

const saveAuxConfig = async () => {
  if (!currentSubject.value) return
  auxSaving.value = true
  try {
    await baseApi.saveSubjectConfig(currentSubject.value.subject_code, auxConfigItems.value)
    ElMessage.success('辅助核算配置已保存')
    auxDrawerVisible.value = false
  } finally {
    auxSaving.value = false
  }
}

onMounted(load)
</script>
