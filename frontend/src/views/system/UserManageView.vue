<template>
  <div class="page-header">
    <div>
      <h1>用户管理</h1>
      <p>维护登录用户、角色归属和可访问账套，禁用用户不会删除历史业务数据。</p>
    </div>
    <div class="page-actions">
      <el-button :icon="Refresh" @click="load">刷新</el-button>
      <el-button v-permission="'system:user:add'" type="primary" :icon="Plus" @click="openCreate">新增用户</el-button>
    </div>
  </div>

  <div class="search-filter-section">
    <el-input v-model.trim="filter.keyword" placeholder="用户名 / 姓名 / 单位" clearable style="width: 260px" />
    <el-select v-model="filter.status" placeholder="全部状态" clearable style="width: 140px">
      <el-option label="启用" :value="1" />
      <el-option label="禁用" :value="0" />
    </el-select>
    <el-button type="primary" :icon="Search" @click="load">查询</el-button>
    <el-button :icon="RefreshLeft" @click="resetFilter">重置</el-button>
  </div>

  <div class="panel">
    <div class="panel-header">
      <strong>用户列表</strong>
      <span class="muted">共 {{ total }} 条记录</span>
    </div>
    <div class="panel-body compact">
      <el-table v-loading="loading" :data="rows" height="calc(100vh - 330px)">
        <el-table-column prop="username" label="用户名" width="130" />
        <el-table-column prop="real_name" label="姓名" width="130" />
        <el-table-column prop="unit_name" label="单位" min-width="180" />
        <el-table-column prop="role_names" label="角色" min-width="180" show-overflow-tooltip />
        <el-table-column prop="account_set_names" label="账套" min-width="220" show-overflow-tooltip />
        <el-table-column prop="last_login_time" label="最后登录" width="170" />
        <el-table-column prop="status" label="状态" width="90" align="center">
          <template #default="{ row }">
            <el-tag :type="Number(row.status) === 1 ? 'success' : 'info'" size="small">
              {{ Number(row.status) === 1 ? '启用' : '禁用' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="280" fixed="right" align="center">
          <template #default="{ row }">
            <el-button v-permission="'system:user:edit'" link type="primary" @click="openEdit(row)">编辑</el-button>
            <el-button v-permission="'system:user:edit'" link type="warning" @click="toggle(row)">
              {{ Number(row.status) === 1 ? '禁用' : '启用' }}
            </el-button>
            <el-button v-permission="'system:user:reset_password'" link type="success" @click="resetPassword(row)">重置密码</el-button>
            <el-button v-permission="'system:user:delete'" link type="danger" @click="remove(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
    </div>
  </div>

  <el-dialog v-model="dialogVisible" :title="editingId ? '编辑用户' : '新增用户'" width="720px" class="finance-dialog" :close-on-click-modal="false">
    <el-tabs v-model="activeTab">
      <el-tab-pane label="基本信息" name="base">
        <el-form ref="formRef" :model="form" :rules="rules" label-position="top">
          <el-row :gutter="16">
            <el-col :span="12">
              <el-form-item label="用户名" prop="username">
                <el-input v-model.trim="form.username" :disabled="Boolean(editingId)" />
              </el-form-item>
            </el-col>
            <el-col :span="12">
              <el-form-item label="真实姓名" prop="real_name">
                <el-input v-model.trim="form.real_name" />
              </el-form-item>
            </el-col>
            <el-col :span="12">
              <el-form-item label="单位" prop="unit_id">
                <el-select v-model="form.unit_id" filterable style="width: 100%">
                  <el-option v-for="unit in units" :key="unit.unit_id" :label="unit.unit_name" :value="unit.unit_id" />
                </el-select>
              </el-form-item>
            </el-col>
            <el-col v-if="!editingId" :span="12">
              <el-form-item label="初始密码" prop="init_password">
                <el-input v-model="form.init_password" type="password" show-password />
              </el-form-item>
            </el-col>
            <el-col :span="12">
              <el-form-item label="手机">
                <el-input v-model.trim="form.mobile" />
              </el-form-item>
            </el-col>
            <el-col :span="12">
              <el-form-item label="邮箱">
                <el-input v-model.trim="form.email" />
              </el-form-item>
            </el-col>
          </el-row>
        </el-form>
      </el-tab-pane>
      <el-tab-pane label="角色分配" name="roles">
        <el-checkbox-group v-model="form.role_ids" class="check-grid">
          <el-checkbox v-for="role in roles" :key="role.role_id" :label="role.role_id">{{ role.role_name }}</el-checkbox>
        </el-checkbox-group>
      </el-tab-pane>
      <el-tab-pane label="账套分配" name="accountSets">
        <el-checkbox-group v-model="form.account_set_ids" class="check-grid">
          <el-checkbox v-for="item in accountSets" :key="item.account_set_id" :label="item.account_set_id">
            {{ item.set_name }}
          </el-checkbox>
        </el-checkbox-group>
      </el-tab-pane>
    </el-tabs>
    <template #footer>
      <el-button @click="dialogVisible = false">取消</el-button>
      <el-button type="primary" :loading="saving" @click="save">保存</el-button>
    </template>
  </el-dialog>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox, type FormInstance, type FormRules } from 'element-plus'
import { Plus, Refresh, RefreshLeft, Search } from '@element-plus/icons-vue'
import { authApi } from '../../api/auth'
import { roleApi } from '../../api/role'
import { userApi } from '../../api/user'
import type { AccountSet, ManagedUser, Role, Unit } from '../../types/api'

const loading = ref(false)
const saving = ref(false)
const dialogVisible = ref(false)
const activeTab = ref('base')
const editingId = ref('')
const formRef = ref<FormInstance>()
const rows = ref<ManagedUser[]>([])
const total = ref(0)
const units = ref<Unit[]>([])
const roles = ref<Role[]>([])
const accountSets = ref<AccountSet[]>([])
const filter = reactive({ keyword: '', status: '' as '' | number })
const form = reactive({
  username: '',
  real_name: '',
  unit_id: '',
  init_password: '',
  mobile: '',
  email: '',
  role_ids: [] as string[],
  account_set_ids: [] as string[]
})

const rules = computed<FormRules>(() => ({
  username: [{ required: true, message: '请输入用户名', trigger: 'blur' }],
  real_name: [{ required: true, message: '请输入真实姓名', trigger: 'blur' }],
  unit_id: [{ required: true, message: '请选择单位', trigger: 'change' }],
  init_password: editingId.value ? [] : [{ required: true, min: 6, message: '初始密码至少 6 位', trigger: 'blur' }]
}))

const load = async () => {
  loading.value = true
  try {
    const page = await userApi.page(filter)
    rows.value = page.items || []
    total.value = Number(page.total || 0)
  } finally {
    loading.value = false
  }
}

const loadOptions = async () => {
  const [unitRows, roleRows, accountRows] = await Promise.all([authApi.units(), roleApi.list(), authApi.accountSets()])
  units.value = unitRows
  roles.value = roleRows
  accountSets.value = accountRows
}

const resetForm = () => {
  Object.assign(form, {
    username: '',
    real_name: '',
    unit_id: units.value[0]?.unit_id || '',
    init_password: '',
    mobile: '',
    email: '',
    role_ids: [],
    account_set_ids: []
  })
}

const openCreate = async () => {
  editingId.value = ''
  activeTab.value = 'base'
  resetForm()
  dialogVisible.value = true
}

const openEdit = async (row: ManagedUser) => {
  editingId.value = row.user_id
  activeTab.value = 'base'
  const detail = await userApi.info(row.user_id)
  Object.assign(form, {
    username: detail.username,
    real_name: detail.real_name,
    unit_id: detail.unit_id,
    init_password: '',
    mobile: detail.mobile || '',
    email: detail.email || '',
    role_ids: detail.role_ids || [],
    account_set_ids: detail.account_set_ids || []
  })
  dialogVisible.value = true
}

const save = async () => {
  await formRef.value?.validate()
  saving.value = true
  try {
    if (editingId.value) {
      await userApi.edit({ user_id: editingId.value, ...form })
      await userApi.assignRoles(editingId.value, form.role_ids)
      await userApi.assignAccountSets(editingId.value, form.account_set_ids)
    } else {
      await userApi.add(form)
    }
    ElMessage.success('保存成功')
    dialogVisible.value = false
    await load()
  } finally {
    saving.value = false
  }
}

const toggle = async (row: ManagedUser) => {
  await userApi.toggleStatus(row.user_id, Number(row.status) === 1 ? 0 : 1)
  ElMessage.success('状态已更新')
  await load()
}

const resetPassword = async (row: ManagedUser) => {
  const result = await ElMessageBox.prompt(`为 ${row.real_name} 设置新密码`, '重置密码', {
    inputType: 'password',
    inputPlaceholder: '至少 6 位',
    inputPattern: /^.{6,}$/,
    inputErrorMessage: '密码至少 6 位'
  })
  await userApi.resetPassword(row.user_id, result.value)
  ElMessage.success('密码已重置')
}

const remove = async (row: ManagedUser) => {
  await ElMessageBox.confirm(`确认删除用户 ${row.real_name}？历史业务数据不会删除。`, '删除用户', { type: 'warning' })
  await userApi.delete(row.user_id)
  ElMessage.success('删除成功')
  await load()
}

const resetFilter = async () => {
  filter.keyword = ''
  filter.status = ''
  await load()
}

onMounted(async () => {
  await loadOptions()
  await load()
})
</script>
