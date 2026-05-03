<template>
  <div class="toolbar">
    <el-button :icon="Refresh" @click="load">刷新</el-button>
  </div>
  <div class="panel">
    <div class="panel-body">
      <el-table :data="rows" border height="calc(100vh - 170px)">
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
