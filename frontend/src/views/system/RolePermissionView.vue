<template>
  <div class="page-header">
    <div>
      <h1>角色权限配置</h1>
      <p>按模块为角色配置菜单和操作权限，保存时提交当前勾选的全量权限码。</p>
    </div>
    <div class="page-actions">
      <el-button :icon="Refresh" @click="loadAll">刷新</el-button>
      <el-button v-permission="'system:role:assign_permission'" type="primary" :icon="Check" :disabled="!selectedRoleId" :loading="saving" @click="save">
        保存权限
      </el-button>
    </div>
  </div>

  <div class="rbac-layout">
    <aside class="rbac-side panel">
      <div class="panel-header">
        <strong>角色</strong>
      </div>
      <div class="panel-body compact">
        <el-radio-group v-model="selectedRoleId" class="role-radio-list">
          <el-radio v-for="role in roles" :key="role.role_id" :label="role.role_id">
            {{ role.role_name }}
            <small>{{ role.role_code }}</small>
          </el-radio>
        </el-radio-group>
      </div>
    </aside>

    <section class="rbac-main panel">
      <div class="panel-header">
        <strong>{{ selectedRole?.role_name || '请选择角色' }}</strong>
        <span class="muted">{{ selectedRole?.view_scope === 'SELF' ? '仅本人制单' : '全部数据' }}</span>
      </div>
      <div class="panel-body compact">
        <el-empty v-if="!selectedRoleId" description="请选择左侧角色" />
        <el-checkbox-group v-else v-model="checkedCodes" class="permission-groups">
          <section v-for="module in orderedModules" :key="module" class="permission-group">
            <div class="permission-group-title">
              <strong>{{ moduleLabel(module) }}</strong>
              <el-button link type="primary" @click="toggleModule(module)">全选/反选</el-button>
            </div>
            <div class="permission-checks">
              <el-checkbox v-for="permission in grouped[module]" :key="permission.permission_code" :label="permission.permission_code">
                <span>{{ permission.permission_name }}</span>
                <small>{{ permission.permission_code }}</small>
              </el-checkbox>
            </div>
          </section>
        </el-checkbox-group>
      </div>
    </section>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { ElMessage } from 'element-plus'
import { Check, Refresh } from '@element-plus/icons-vue'
import { permissionApi } from '../../api/permission'
import { roleApi } from '../../api/role'
import type { Permission, Role } from '../../types/api'

const route = useRoute()
const roles = ref<Role[]>([])
const permissions = ref<Permission[]>([])
const selectedRoleId = ref('')
const checkedCodes = ref<string[]>([])
const saving = ref(false)

const grouped = computed<Record<string, Permission[]>>(() => {
  const result: Record<string, Permission[]> = {}
  permissions.value.forEach((permission) => {
    const module = permission.module_code || 'other'
    if (!result[module]) result[module] = []
    result[module].push(permission)
  })
  return result
})

const orderedModules = computed(() => {
  const order = ['dashboard', 'voucher', 'book', 'base', 'period', 'system']
  return Object.keys(grouped.value).sort((a, b) => {
    const ai = order.indexOf(a)
    const bi = order.indexOf(b)
    return (ai < 0 ? 99 : ai) - (bi < 0 ? 99 : bi)
  })
})

const selectedRole = computed(() => roles.value.find((role) => role.role_id === selectedRoleId.value))

const moduleLabel = (module: string) =>
  ({
    dashboard: '首页',
    voucher: '凭证',
    book: '账簿',
    base: '基础数据',
    period: '期末',
    system: '系统管理'
  }[module] || module)

const loadAll = async () => {
  const [roleRows, permissionPayload] = await Promise.all([roleApi.list(), permissionApi.list()])
  roles.value = roleRows
  permissions.value = permissionPayload.items || []
  if (!selectedRoleId.value) {
    selectedRoleId.value = String(route.query.role_id || roles.value[0]?.role_id || '')
  }
  await loadRolePermissions()
}

const loadRolePermissions = async () => {
  if (!selectedRoleId.value) {
    checkedCodes.value = []
    return
  }
  const role = await roleApi.info(selectedRoleId.value)
  checkedCodes.value = role.permission_codes || []
}

const toggleModule = (module: string) => {
  const moduleCodes = grouped.value[module]?.map((permission) => permission.permission_code) || []
  const allChecked = moduleCodes.every((code) => checkedCodes.value.includes(code))
  checkedCodes.value = allChecked
    ? checkedCodes.value.filter((code) => !moduleCodes.includes(code))
    : Array.from(new Set([...checkedCodes.value, ...moduleCodes]))
}

const save = async () => {
  if (!selectedRoleId.value) return
  saving.value = true
  try {
    await roleApi.assignPermissions(selectedRoleId.value, checkedCodes.value)
    ElMessage.success('权限已保存')
    await loadAll()
  } finally {
    saving.value = false
  }
}

watch(selectedRoleId, loadRolePermissions)
onMounted(loadAll)
</script>
