<template>
  <div class="page-header">
    <div>
      <h1>审计日志</h1>
      <p>查看系统关键操作记录，用于追溯用户、业务对象和来源地址。</p>
    </div>
    <div class="page-actions">
      <el-button :icon="Refresh" @click="load">刷新</el-button>
    </div>
  </div>

  <div class="panel management-panel">
    <div class="panel-header">
      <strong>日志列表</strong>
      <span class="muted">共 {{ rows.length }} 条记录</span>
    </div>
    <div class="panel-body compact">
      <el-table :data="rows" border height="calc(100vh - 250px)">
        <el-table-column prop="created_time" label="时间" width="170" />
        <el-table-column prop="biz_type" label="业务类型" width="120" />
        <el-table-column prop="biz_id" label="业务ID" min-width="260" />
        <el-table-column prop="operation" label="操作" width="120" />
        <el-table-column prop="operator_id" label="操作人" width="120" />
        <el-table-column prop="operator_ip" label="IP" width="140" />
      </el-table>
    </div>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { Refresh } from '@element-plus/icons-vue'
import { apiAction } from '../../api/http'

const rows = ref<any[]>([])

const load = async () => {
  const page: any = await apiAction('/log/list')
  rows.value = page.items || []
}

onMounted(load)
</script>
