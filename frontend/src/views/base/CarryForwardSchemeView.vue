<template>
  <div class="page-header">
    <div>
      <h1>收支结转方案</h1>
      <p>维护食堂、工会等需要收支结转的账套规则；案款和诉讼费默认按本科目余额结转。</p>
    </div>
    <div class="page-actions">
      <el-button :icon="Refresh" @click="loadAll">刷新</el-button>
      <el-button v-permission="'base:edit'" type="primary" :icon="Check" :loading="saving" @click="saveScheme">保存方案</el-button>
    </div>
  </div>

  <el-alert
    class="mb-16"
    type="warning"
    show-icon
    :closable="false"
    title="案款和诉讼费无需启用本方案；确需损益或收支结转的账套，再启用并维护结转规则。"
  />

  <div class="carry-layout">
    <section class="panel carry-form-panel">
      <div class="panel-header">
        <strong>方案参数</strong>
        <el-tag :type="form.enabled_flag === 1 ? 'success' : 'info'">{{ form.enabled_flag === 1 ? '已启用' : '未启用' }}</el-tag>
      </div>
      <div class="panel-body">
        <el-form label-position="top" :model="form">
          <div class="form-grid two">
            <el-form-item label="方案名称" required>
              <el-input v-model.trim="form.scheme_name" placeholder="如 年终收支结转方案" />
            </el-form-item>
            <el-form-item label="启用状态">
              <el-switch v-model="form.enabled_flag" :active-value="1" :inactive-value="0" active-text="启用" inactive-text="停用" />
            </el-form-item>
            <el-form-item label="默认结转目标科目" required>
              <el-select v-model="form.target_subject_code" filterable clearable placeholder="选择非损益类末级科目">
                <el-option
                  v-for="subject in targetSubjects"
                  :key="subject.subject_code || subject.subjectCode"
                  :label="subjectLabel(subject)"
                  :value="subject.subject_code || subject.subjectCode"
                />
              </el-select>
            </el-form-item>
            <el-form-item label="凭证摘要模板">
              <el-input v-model.trim="form.summary_template" placeholder="如 年终收支结转" />
            </el-form-item>
          </div>
          <el-form-item label="备注">
            <el-input v-model="form.remark" type="textarea" :rows="3" placeholder="记录适用账套、制度口径或审批依据" />
          </el-form-item>
        </el-form>
      </div>
    </section>

    <section class="panel">
      <div class="panel-header">
        <strong>结转规则</strong>
        <div class="page-actions">
          <el-button v-permission="'base:edit'" type="primary" plain :icon="Plus" @click="addRule">新增规则</el-button>
        </div>
      </div>
      <div class="panel-body compact">
        <el-table :data="form.rules" border height="calc(100vh - 460px)">
          <el-table-column label="来源收支科目" min-width="240">
            <template #default="{ row }">
              <el-select v-model="row.source_subject_code" filterable placeholder="选择损益类科目">
                <el-option
                  v-for="subject in profitLossSubjects"
                  :key="subject.subject_code || subject.subjectCode"
                  :label="subjectLabel(subject)"
                  :value="subject.subject_code || subject.subjectCode"
                />
              </el-select>
            </template>
          </el-table-column>
          <el-table-column label="目标科目" min-width="240">
            <template #default="{ row }">
              <el-select v-model="row.target_subject_code" filterable clearable placeholder="为空则使用默认目标科目">
                <el-option
                  v-for="subject in targetSubjects"
                  :key="subject.subject_code || subject.subjectCode"
                  :label="subjectLabel(subject)"
                  :value="subject.subject_code || subject.subjectCode"
                />
              </el-select>
            </template>
          </el-table-column>
          <el-table-column label="摘要" min-width="220">
            <template #default="{ row }">
              <el-input v-model.trim="row.summary" placeholder="为空则使用摘要模板" />
            </template>
          </el-table-column>
          <el-table-column label="操作" width="90" align="center">
            <template #default="{ $index }">
              <el-button v-permission="'base:edit'" link type="danger" @click="removeRule($index)">删除</el-button>
            </template>
          </el-table-column>
        </el-table>
      </div>
    </section>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { Check, Plus, Refresh } from '@element-plus/icons-vue'
import { baseApi } from '../../api/base'

const subjects = ref<any[]>([])
const saving = ref(false)
const form = reactive({
  scheme_id: '',
  scheme_name: '收支结转方案',
  enabled_flag: 0,
  target_subject_code: '',
  summary_template: '年终收支结转',
  remark: '',
  rules: [] as any[]
})

const subjectCode = (subject: any) => subject.subject_code || subject.subjectCode || ''
const subjectType = (subject: any) => subject.subject_type || subject.subjectType || ''
const subjectName = (subject: any) => subject.subject_name || subject.subjectName || ''
const leafFlag = (subject: any) => Number(subject.leaf_flag ?? subject.leafFlag ?? 0)
const entryFlag = (subject: any) => Number(subject.voucher_entry_flag ?? subject.voucherEntryFlag ?? 0)
const statusFlag = (subject: any) => Number(subject.status ?? 0)

const entrySubjects = computed(() =>
  subjects.value.filter((subject) => leafFlag(subject) === 1 && entryFlag(subject) === 1 && statusFlag(subject) === 1)
)
const profitLossSubjects = computed(() => entrySubjects.value.filter((subject) => subjectType(subject) === 'PROFIT_LOSS'))
const targetSubjects = computed(() => entrySubjects.value.filter((subject) => subjectType(subject) !== 'PROFIT_LOSS'))

const subjectLabel = (subject: any) => `${subjectCode(subject)} ${subjectName(subject)}`

const loadAll = async () => {
  const [scheme, subjectRows] = await Promise.all([baseApi.carryForwardScheme(), baseApi.subjects()])
  subjects.value = subjectRows || []
  Object.assign(form, {
    scheme_id: scheme.scheme_id || '',
    scheme_name: scheme.scheme_name || '收支结转方案',
    enabled_flag: Number(scheme.enabled_flag || 0),
    target_subject_code: scheme.target_subject_code || '',
    summary_template: scheme.summary_template || '年终收支结转',
    remark: scheme.remark || '',
    rules: Array.isArray(scheme.rules) ? scheme.rules : []
  })
}

const addRule = () => {
  form.rules.push({
    source_subject_code: '',
    target_subject_code: '',
    summary: ''
  })
}

const removeRule = (index: number) => {
  form.rules.splice(index, 1)
}

const saveScheme = async () => {
  saving.value = true
  try {
    await baseApi.saveCarryForwardScheme({ ...form, rules: form.rules })
    ElMessage.success('收支结转方案已保存')
    await loadAll()
  } finally {
    saving.value = false
  }
}

onMounted(loadAll)
</script>

<style scoped>
.carry-layout {
  display: grid;
  grid-template-columns: 380px minmax(0, 1fr);
  gap: 16px;
  align-items: start;
}

.carry-form-panel {
  position: sticky;
  top: 12px;
}

@media (max-width: 980px) {
  .carry-layout {
    grid-template-columns: 1fr;
  }

  .carry-form-panel {
    position: static;
  }
}
</style>
