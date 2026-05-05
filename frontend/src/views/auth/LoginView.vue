<template>
  <main class="login-page">
    <section class="login-brand-panel">
      <div class="login-brand">
        <div class="brand-mark login-brand-mark">
          <span class="brand-mark-core" />
        </div>
        <div>
          <strong>法院专项账务</strong>
          <small>Court Special Accounting System</small>
        </div>
      </div>

      <div class="login-hero">
        <span class="login-kicker">
          <el-icon><CircleCheck /></el-icon>
          单院部署 · 专项资金核算
        </span>
        <h1>
          <span>凭证、账簿、辅助核算</span>
          <span class="login-hero-gradient">一体化处理</span>
        </h1>
        <p>面向法院案款、诉讼费等专项账务场景，提供清晰、可追溯的记账工作台。</p>
      </div>

      <div class="login-status-grid">
        <div>
          <el-icon><Coin /></el-icon>
          <span>账务引擎</span>
          <strong>双分录</strong>
        </div>
        <div>
          <el-icon><Calendar /></el-icon>
          <span>期间</span>
          <strong>{{ context.period }}</strong>
        </div>
        <div>
          <el-icon><Connection /></el-icon>
          <span>部署</span>
          <strong>内网独立</strong>
        </div>
      </div>
    </section>

    <section class="login-form-panel">
      <div class="login-card">
        <div class="login-card-header">
          <span class="login-card-icon">
            <el-icon><Key /></el-icon>
          </span>
          <div>
            <h2>用户登录</h2>
            <p>请选择用户单位并输入账号密码</p>
          </div>
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

        <div class="login-footnote">
          <span>
            <el-icon><InfoFilled /></el-icon>
            初始账号: <strong>admin</strong>
          </span>
          <span>
            <el-icon><Key /></el-icon>
            初始密码: <strong>123456</strong>
          </span>
        </div>
      </div>
    </section>
  </main>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage, type FormInstance, type FormRules } from 'element-plus'
import { Calendar, CircleCheck, Coin, Connection, InfoFilled, Key, Lock, OfficeBuilding, Right, User } from '@element-plus/icons-vue'
import { authApi } from '../../api/auth'
import { useContextStore } from '../../stores/context'
import type { Unit } from '../../types/api'

const router = useRouter()
const route = useRoute()
const context = useContextStore()

const formRef = ref<FormInstance>()
const unitLoading = ref(false)
const submitting = ref(false)
const units = ref<Unit[]>([])
const form = reactive({
  unit_id: '',
  username: 'admin',
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
  } finally {
    submitting.value = false
  }
}

onMounted(loadUnits)
</script>
