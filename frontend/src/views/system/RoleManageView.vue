<template>
  <div class="page-header">
    <div>
      <h1>角色管理</h1>
      <p>维护角色基本信息、数据范围和启停状态，系统预置角色受保护。</p>
    </div>
    <div class="page-actions">
      <el-button :icon="Refresh" @click="load">刷新</el-button>
      <el-button v-permission="'system:role:add'" type="primary" :icon="Plus" @click="openCreate">新增角色</el-button>
    </div>
  </div>

  <div class="search-filter-section">
    <el-input v-model.trim="filter.keyword" placeholder="角色编码 / 名称" clearable style="width: 240px" />
    <el-select v-model="filter.status" placeholder="全部状态" clearable style="width: 140px">
      <el-option label="启用" :value="1" />
      <el-option label="禁用" :value="0" />
    </el-select>
    <el-button type="primary" :icon="Search" @click="load">查询</el-button>
    <el-button :icon="RefreshLeft" @click="resetFilter">重置</el-button>
  </div>

  <div class="panel">
    <div class="panel-header">
      <strong>角色列表</strong>
      <span class="muted">共 {{ rows.length }} 个角色</span>
    </div>
    <div class="panel-body compact">
      <el-table v-loading="loading" :data="rows" height="calc(100vh - 330px)">
        <el-table-column prop="role_code" label="角色编码" width="180" />
        <el-table-column prop="role_name" label="角色名" width="160" />
        <el-table-column prop="description" label="描述" min-width="220" show-overflow-tooltip />
        <el-table-column prop="view_scope" label="数据范围" width="130" align="center">
          <template #default="{ row }">{{ row.view_scope === 'SELF' ? '仅本人制单' : '全部' }}</template>
        </el-table-column>
        <el-table-column prop="permission_count" label="权限数" width="90" align="center" />
        <el-table-column prop="user_count" label="用户数" width="90" align="center" />
        <el-table-column prop="is_system" label="系统预置" width="100" align="center">
          <template #default="{ row }">
            <el-tag :type="Number(row.is_system) === 1 ? 'warning' : 'info'" size="small">
              {{ Number(row.is_system) === 1 ? '是' : '否' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="status" label="状态" width="90" align="center">
          <template #default="{ row }">
            <el-tag :type="Number(row.status) === 1 ? 'success' : 'info'" size="small">
              {{ Number(row.status) === 1 ? '启用' : '禁用' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="260" fixed="right" align="center">
          <template #default="{ row }">
            <el-button v-permission="'system:role:edit'" link type="primary" @click="openEdit(row)">编辑</el-button>
            <el-button v-permission="'system:role:assign_permission'" link type="success" @click="configure(row)">配置权限</el-button>
            <el-button
              v-if="Number(row.is_system) !== 1 && Number(row.user_count || 0) === 0"
              v-permission="'system:role:delete'"
              link
              type="danger"
              @click="remove(row)"
            >
              删除
            </el-button>
          </template>
        </el-table-column>
      </el-table>
    </div>
  </div>

  <el-dialog v-model="dialogVisible" :title="form.role_id ? '编辑角色' : '新增角色'" width="560px" class="finance-dialog" :close-on-click-modal="false">
    <el-form ref="formRef" :model="form" :rules="rules" label-position="top">
      <el-form-item label="角色编码" prop="role_code">
        <el-input v-model.trim="form.role_code" :disabled="Boolean(form.role_id && form.is_system)" />
      </el-form-item>
      <el-form-item label="角色名称" prop="role_name">
        <el-input v-model.trim="form.role_name" />
      </el-form-item>
      <el-form-item label="描述">
        <el-input v-model.trim="form.description" type="textarea" :rows="3" />
      </el-form-item>
      <el-form-item label="数据范围">
        <el-select v-model="form.view_scope" style="width: 100%">
          <el-option label="全部" value="ALL" />
          <el-option label="仅本人制单" value="SELF" />
        </el-select>
      </el-form-item>
      <el-form-item label="状态">
        <el-switch v-model="form.status" :active-value="1" :inactive-value="0" active-text="启用" inactive-text="禁用" />
      </el-form-item>
    </el-form>
    <template #footer>
      <el-button @click="dialogVisible = false">取消</el-button>
      <el-button type="primary" :loading="saving" @click="save">保存</el-button>
    </template>
  </el-dialog>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage, ElMessageBox, type FormInstance, type FormRules } from 'element-plus'
import { Plus, Refresh, RefreshLeft, Search } from '@element-plus/icons-vue'
import { roleApi } from '../../api/role'
import type { Role } from '../../types/api'

const router = useRouter()
const loading = ref(false)
const saving = ref(false)
const dialogVisible = ref(false)
const formRef = ref<FormInstance>()
const rows = ref<Role[]>([])
const filter = reactive({ keyword: '', status: '' as '' | number })
const form = reactive({
  role_id: '',
  role_code: '',
  role_name: '',
  description: '',
  view_scope: 'ALL' as 'ALL' | 'SELF',
  status: 1,
  is_system: 0
})

const rules = computed<FormRules>(() => ({
  role_code: [{ required: true, message: '请输入角色编码', trigger: 'blur' }],
  role_name: [{ required: true, message: '请输入角色名称', trigger: 'blur' }]
}))

const load = async () => {
  loading.value = true
  try {
    rows.value = await roleApi.list(filter)
  } finally {
    loading.value = false
  }
}

const resetForm = () => {
  Object.assign(form, {
    role_id: '',
    role_code: '',
    role_name: '',
    description: '',
    view_scope: 'ALL',
    status: 1,
    is_system: 0
  })
}

const openCreate = () => {
  resetForm()
  dialogVisible.value = true
}

const openEdit = (row: Role) => {
  Object.assign(form, {
    role_id: row.role_id,
    role_code: row.role_code,
    role_name: row.role_name,
    description: row.description || '',
    view_scope: row.view_scope,
    status: Number(row.status),
    is_system: Number(row.is_system)
  })
  dialogVisible.value = true
}

const save = async () => {
  await formRef.value?.validate()
  saving.value = true
  try {
    if (form.role_id) {
      await roleApi.edit(form)
    } else {
      await roleApi.add(form)
    }
    ElMessage.success('保存成功')
    dialogVisible.value = false
    await load()
  } finally {
    saving.value = false
  }
}

const configure = (row: Role) => {
  router.push({ path: '/system/role-permissions', query: { role_id: row.role_id } })
}

const remove = async (row: Role) => {
  await ElMessageBox.confirm(`确认删除角色 ${row.role_name}？`, '删除角色', { type: 'warning' })
  await roleApi.delete(row.role_id)
  ElMessage.success('删除成功')
  await load()
}

const resetFilter = async () => {
  filter.keyword = ''
  filter.status = ''
  await load()
}

onMounted(load)
</script>
