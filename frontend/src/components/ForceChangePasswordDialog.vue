<template>
  <el-dialog
    :model-value="context.mustChangePassword && !isAuthPage"
    title="首次登录修改密码"
    width="460px"
    :close-on-click-modal="false"
    :close-on-press-escape="false"
    :show-close="false"
    class="finance-dialog"
  >
    <el-form ref="formRef" :model="form" :rules="rules" label-position="top">
      <el-form-item label="旧密码" prop="oldPassword">
        <el-input v-model="form.oldPassword" type="password" show-password autocomplete="current-password" />
      </el-form-item>
      <el-form-item label="新密码" prop="newPassword">
        <el-input v-model="form.newPassword" type="password" show-password autocomplete="new-password" />
      </el-form-item>
      <el-form-item label="确认新密码" prop="confirmPassword">
        <el-input v-model="form.confirmPassword" type="password" show-password autocomplete="new-password" />
      </el-form-item>
    </el-form>
    <template #footer>
      <el-button type="primary" :loading="saving" @click="submit">确认修改</el-button>
    </template>
  </el-dialog>
</template>

<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import { ElMessage, type FormInstance, type FormRules } from 'element-plus'
import { userApi } from '../api/user'
import { useContextStore } from '../stores/context'

const route = useRoute()
const context = useContextStore()
const formRef = ref<FormInstance>()
const saving = ref(false)
const form = reactive({
  oldPassword: '',
  newPassword: '',
  confirmPassword: ''
})

const isAuthPage = computed(() => route.path === '/login' || route.path === '/select-account-set')

const rules = computed<FormRules>(() => ({
  oldPassword: [{ required: true, message: '请输入旧密码', trigger: 'blur' }],
  newPassword: [
    { required: true, message: '请输入新密码', trigger: 'blur' },
    {
      validator: (_rule, value: string, callback) => {
        if (value === form.oldPassword) {
          callback(new Error('新密码不能与旧密码相同'))
        } else if (!/^(?=.*[A-Za-z])(?=.*\d).{8,}$/.test(value || '')) {
          callback(new Error('至少 8 位，且必须包含字母和数字'))
        } else {
          callback()
        }
      },
      trigger: 'blur'
    }
  ],
  confirmPassword: [
    { required: true, message: '请再次输入新密码', trigger: 'blur' },
    {
      validator: (_rule, value: string, callback) => {
        value === form.newPassword ? callback() : callback(new Error('两次输入的新密码不一致'))
      },
      trigger: 'blur'
    }
  ]
}))

const submit = async () => {
  await formRef.value?.validate()
  saving.value = true
  try {
    await userApi.changePassword(form.oldPassword, form.newPassword)
    context.markPasswordChanged()
    form.oldPassword = ''
    form.newPassword = ''
    form.confirmPassword = ''
    ElMessage.success('密码已修改')
  } finally {
    saving.value = false
  }
}
</script>
