<template>
  <el-dialog
    :model-value="dialogVisible"
    :title="dialogTitle"
    width="460px"
    :close-on-click-modal="canCloseDialog"
    :close-on-press-escape="canCloseDialog"
    :show-close="canCloseDialog"
    class="finance-dialog"
    @close="closeManualDialog"
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
      <el-button v-if="canCloseDialog" @click="closeManualDialog">取消</el-button>
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
const props = defineProps<{
  manualVisible?: boolean
}>()
const emit = defineEmits<{
  (event: 'update:manualVisible', value: boolean): void
}>()
const formRef = ref<FormInstance>()
const saving = ref(false)
const form = reactive({
  oldPassword: '',
  newPassword: '',
  confirmPassword: ''
})

const isAuthPage = computed(() => route.path === '/login' || route.path === '/select-account-set')
const forceVisible = computed(() => context.mustChangePassword && !isAuthPage.value)
const dialogVisible = computed(() => forceVisible.value || Boolean(props.manualVisible))
const canCloseDialog = computed(() => !forceVisible.value)
const dialogTitle = computed(() => forceVisible.value ? '首次登录修改密码' : '修改密码')

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

const resetForm = () => {
  form.oldPassword = ''
  form.newPassword = ''
  form.confirmPassword = ''
  formRef.value?.clearValidate()
}

const closeManualDialog = () => {
  if (!canCloseDialog.value) return
  emit('update:manualVisible', false)
  resetForm()
}

const submit = async () => {
  await formRef.value?.validate()
  saving.value = true
  try {
    await userApi.changePassword(form.oldPassword, form.newPassword)
    context.markPasswordChanged()
    emit('update:manualVisible', false)
    resetForm()
    ElMessage.success('密码已修改')
  } finally {
    saving.value = false
  }
}
</script>
