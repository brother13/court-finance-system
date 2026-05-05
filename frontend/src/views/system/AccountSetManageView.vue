<template>
  <div class="page-header">
    <div>
      <h1>账套管理</h1>
      <p>维护法院专项资金账套、启用年月和凭证打印配置，新建账套会自动生成启用年度会计期间。</p>
    </div>
    <div class="page-actions">
      <el-button :icon="Refresh" @click="load">刷新</el-button>
      <el-button v-permission="'system:account_set:add'" type="primary" :icon="Plus" @click="openCreate">新建账套</el-button>
    </div>
  </div>

  <div class="panel">
    <div class="panel-header">
      <strong>账套列表</strong>
      <span class="muted">共 {{ total }} 个账套</span>
    </div>
    <div class="panel-body compact">
      <el-table v-loading="loading" :data="rows" height="calc(100vh - 330px)">
        <el-table-column label="" width="120" align="center">
          <template #default="{ row }">
            <el-tag v-if="Number(row.is_current) === 1" type="primary" effect="light">
              <el-icon><Check /></el-icon>
              当前
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="set_name" label="单位名称" min-width="320" show-overflow-tooltip />
        <el-table-column prop="current_period_label" label="当前记账年月" width="150" />
        <el-table-column prop="enabled_period_label" label="账套启用年月" width="150" />
        <el-table-column prop="finance_manager" label="财务主管" width="150">
          <template #default="{ row }">{{ row.finance_manager || '-' }}</template>
        </el-table-column>
        <el-table-column prop="paper_size" label="纸张大小" width="130" />
        <el-table-column label="操作" width="120" fixed="right" align="center">
          <template #default="{ row }">
            <el-button v-permission="'system:account_set:edit'" link type="primary" @click="openEdit(row)">编辑</el-button>
          </template>
        </el-table-column>
      </el-table>
    </div>
  </div>

  <el-dialog v-model="dialogVisible" :title="editingId ? '账套信息' : '账套信息'" width="920px" class="finance-dialog account-set-dialog" :close-on-click-modal="false">
    <el-form ref="formRef" :model="form" :rules="rules" label-position="right" label-width="150px" class="account-set-form">
      <el-row :gutter="32">
        <el-col :span="12">
          <el-form-item label="单位名称" prop="set_name">
            <el-input v-model.trim="form.set_name" />
          </el-form-item>
        </el-col>
        <el-col :span="12">
          <el-form-item label="账套启用年月" prop="enabled_period">
            <el-date-picker
              v-model="form.enabled_period"
              type="month"
              value-format="YYYY-MM"
              format="YYYY年MM月"
              placeholder="请选择年月"
              :disabled="Boolean(editingId)"
              style="width: 100%"
            />
          </el-form-item>
        </el-col>
        <el-col :span="12">
          <el-form-item label="业务类型" prop="biz_type">
            <el-select v-model="form.biz_type" style="width: 100%">
              <el-option label="案款" value="CASE_FUND" />
              <el-option label="诉讼费" value="LITIGATION_FEE" />
              <el-option label="食堂" value="CANTEEN" />
              <el-option label="工会" value="UNION" />
            </el-select>
          </el-form-item>
        </el-col>
        <el-col :span="12">
          <el-form-item label="会计主管">
            <el-input v-model.trim="form.finance_manager" />
          </el-form-item>
        </el-col>
        <el-col :span="12">
          <el-form-item label="凭证导入自动编号">
            <el-select v-model="form.voucher_import_auto_no" style="width: 100%">
              <el-option label="是" :value="1" />
              <el-option label="否" :value="0" />
            </el-select>
          </el-form-item>
        </el-col>
        <el-col :span="12">
          <el-form-item label="凭证纸张尺寸">
            <el-select v-model="form.paper_size" style="width: 100%">
              <el-option label="A5" value="A5" />
              <el-option label="A4" value="A4" />
            </el-select>
          </el-form-item>
        </el-col>
        <el-col :span="12">
          <el-form-item label="凭证打印分录条数">
            <el-select v-model="form.voucher_print_line_count" style="width: 100%">
              <el-option label="8条" :value="8" />
              <el-option label="10条" :value="10" />
              <el-option label="12条" :value="12" />
            </el-select>
          </el-form-item>
        </el-col>
      </el-row>
    </el-form>
    <template #footer>
      <el-button @click="dialogVisible = false">取消</el-button>
      <el-button type="success" :loading="saving" @click="save">{{ editingId ? '保存账套' : '创建账套' }}</el-button>
    </template>
  </el-dialog>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, type FormInstance, type FormRules } from 'element-plus'
import { Check, Plus, Refresh } from '@element-plus/icons-vue'
import { accountSetApi } from '../../api/accountSet'
import type { AccountSet } from '../../types/api'

const loading = ref(false)
const saving = ref(false)
const dialogVisible = ref(false)
const editingId = ref('')
const rows = ref<AccountSet[]>([])
const total = ref(0)
const formRef = ref<FormInstance>()

const form = reactive({
  set_name: '',
  biz_type: 'CASE_FUND',
  enabled_period: '',
  finance_manager: '',
  paper_size: 'A5',
  voucher_import_auto_no: 1,
  voucher_print_line_count: 8
})

const rules = computed<FormRules>(() => ({
  set_name: [{ required: true, message: '请输入单位名称', trigger: 'blur' }],
  biz_type: [{ required: true, message: '请选择业务类型', trigger: 'change' }],
  enabled_period: [{ required: true, message: '请选择账套启用年月', trigger: 'change' }]
}))

const load = async () => {
  loading.value = true
  try {
    const page = await accountSetApi.page()
    rows.value = page.items || []
    total.value = Number(page.total || rows.value.length || 0)
  } finally {
    loading.value = false
  }
}

const resetForm = () => {
  Object.assign(form, {
    set_name: '',
    biz_type: 'CASE_FUND',
    enabled_period: '',
    finance_manager: '',
    paper_size: 'A5',
    voucher_import_auto_no: 1,
    voucher_print_line_count: 8
  })
}

const openCreate = () => {
  editingId.value = ''
  resetForm()
  dialogVisible.value = true
}

const openEdit = (row: AccountSet) => {
  editingId.value = row.account_set_id
  Object.assign(form, {
    set_name: row.set_name,
    biz_type: row.biz_type,
    enabled_period: row.enabled_period,
    finance_manager: row.finance_manager || '',
    paper_size: row.paper_size || 'A5',
    voucher_import_auto_no: Number(row.voucher_import_auto_no ?? 1),
    voucher_print_line_count: Number(row.voucher_print_line_count || 8)
  })
  dialogVisible.value = true
}

const save = async () => {
  await formRef.value?.validate()
  saving.value = true
  try {
    if (editingId.value) {
      await accountSetApi.edit({ account_set_id: editingId.value, ...form })
    } else {
      await accountSetApi.add(form)
    }
    ElMessage.success('保存成功')
    dialogVisible.value = false
    await load()
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>
