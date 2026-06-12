<template>
  <main class="login-page">
    <section class="login-brand-panel">
      <div class="login-brand">
        <div class="brand-mark login-brand-mark">
          <span class="brand-mark-core" />
        </div>
        <div>
          <strong>树莓法衡专项账务系统</strong>
          <small>法院专项资金核算与凭证管理平台</small>
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
          <el-icon><Connection /></el-icon>
          <span>一张网对接</span>
          <strong>案款收退数据一键导入并一键生成凭证</strong>
          <p>对接法院“一张网”业务系统，减少手工录入和重复制单。</p>
        </div>
        <div>
          <el-icon><Coin /></el-icon>
          <span>辅助核算</span>
          <strong>执行款支持案号+单据号辅助核算</strong>
          <p>生成凭证时可同步生成并设置辅助核算项。</p>
        </div>
        <div>
          <el-icon><CircleCheck /></el-icon>
          <span>案款扩展业务</span>
          <strong>支持不明款与案款分配生成凭证</strong>
          <p>不明款、案款分配数据可纳入统一凭证生成流程。</p>
        </div>
        <div>
          <el-icon><Coin /></el-icon>
          <span>按收费方式制证</span>
          <strong>自动区分来账方式并匹配科目</strong>
          <p>系统可自动区分 POS、转账、不明款，可根据收费方式设置科目生成凭证。</p>
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
      </div>
    </section>
  </main>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage, type FormInstance, type FormRules } from 'element-plus'
import { CircleCheck, Coin, Connection, Key, Lock, OfficeBuilding, Right, User } from '@element-plus/icons-vue'
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
