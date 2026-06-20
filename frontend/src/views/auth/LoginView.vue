<template>
  <main class="login-page">
    <div class="login-shell">
      <header class="login-identity">
        <div class="login-brand-row">
          <span class="login-logo-frame">
            <img class="login-brand-logo" :src="raspberryLogo" alt="树莓软件" />
          </span>
          <strong>树莓法衡</strong>
        </div>
        <small class="login-identity-subtitle">法院专项资金核算与凭证管理平台</small>
      </header>

      <section class="login-card">
        <div class="login-card-header">
          <h1>账户登录</h1>
        </div>

        <el-form
          ref="formRef"
          :model="form"
          :rules="rules"
          label-position="top"
          class="login-form"
          @keyup.enter="submit"
        >
          <el-form-item label="用户单位" prop="unit_id">
            <el-select
              v-model="form.unit_id"
              placeholder="请选择用户单位"
              filterable
              :loading="unitLoading"
              class="login-control"
            >
              <template #prefix>
                <el-icon><OfficeBuilding /></el-icon>
              </template>
              <el-option
                v-for="unit in units"
                :key="unit.unit_id"
                :label="unit.unit_name"
                :value="unit.unit_id"
              >
                <span>{{ unit.unit_name }}</span>
                <small class="unit-code">{{ unit.unit_code }}</small>
              </el-option>
            </el-select>
          </el-form-item>

          <el-form-item label="用户名" prop="username">
            <el-input v-model.trim="form.username" placeholder="请输入用户名" class="login-control">
              <template #prefix>
                <el-icon><User /></el-icon>
              </template>
            </el-input>
          </el-form-item>

          <el-form-item label="密码" prop="password">
            <el-input
              v-model="form.password"
              placeholder="请输入密码"
              show-password
              type="password"
              class="login-control"
            >
              <template #prefix>
                <el-icon><Lock /></el-icon>
              </template>
            </el-input>
          </el-form-item>

          <el-button type="primary" class="login-submit" :loading="submitting" @click="submit">
            登录系统
            <el-icon class="login-submit-icon"><Right /></el-icon>
          </el-button>
        </el-form>
      </section>
    </div>

    <footer class="login-page-footer">
      Copyright © 2026 树莓（沈阳）软件科技发展有限公司 技术支持
    </footer>
  </main>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage, type FormInstance, type FormRules } from 'element-plus'
import { Lock, OfficeBuilding, Right, User } from '@element-plus/icons-vue'
import { authApi } from '../../api/auth'
import { useContextStore } from '../../stores/context'
import type { Unit } from '../../types/api'
import raspberryLogo from '../../assets/raspberry-logo.png'

const router = useRouter()
const context = useContextStore()

const formRef = ref<FormInstance>()
const unitLoading = ref(false)
const submitting = ref(false)
const units = ref<Unit[]>([])
const form = reactive({
  unit_id: '',
  username: '',
  password: ''
})

const rules = computed<FormRules>(() => ({
  unit_id: [{ required: true, message: '请选择用户单位', trigger: 'change' }],
  username: [{ required: true, message: '请输入用户名', trigger: 'blur' }],
  password: [{ required: true, message: '请输入密码', trigger: 'blur' }]
}))

const loadUnits = async () => {
  unitLoading.value = true
  try {
    units.value = await authApi.units()
    if (!form.unit_id && units.value.length > 0) {
      form.unit_id = units.value[0].unit_id
    }
  } catch {
    units.value = []
    ElMessage.error('单位列表加载失败，请确认后端服务已启动')
  } finally {
    unitLoading.value = false
  }
}

const submit = async () => {
  await formRef.value?.validate()
  submitting.value = true
  try {
    const user = await authApi.login(form)
    context.setAuth(user)
    ElMessage.success('登录成功')
    router.replace('/select-account-set')
  } catch {
    // The API layer has already shown the business error message.
  } finally {
    submitting.value = false
  }
}

onMounted(loadUnits)
</script>
