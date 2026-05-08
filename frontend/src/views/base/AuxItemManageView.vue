<template>
  <div class="page-header">
    <div>
      <h1>辅助核算项</h1>
      <p>维护辅助核算维度类型及其档案。标准维度固定，自定义维度可扩展。</p>
    </div>
    <div class="page-actions">
      <el-button :icon="Refresh" @click="loadAll">刷新</el-button>
      <el-button v-permission="'base:add'" type="primary" :icon="Plus" @click="openTypeCreate">新增自定义维度</el-button>
    </div>
  </div>

  <div class="aux-ledger-layout">
    <aside class="aux-dimension-panel">
      <div class="aux-dimension-section">
        <div class="aux-dimension-title">标准辅助维度</div>
        <button
          v-for="item in standardTypes"
          :key="item.code"
          class="aux-dimension-item"
          :class="{ active: selectedTypeCode === item.code }"
          @click="selectType(item.code)"
        >
          <span>
            <strong>{{ item.name }}</strong>
            <small>{{ item.rule }}</small>
          </span>
          <el-tag size="small" :type="item.intercourse ? 'warning' : 'success'">
            {{ item.intercourse ? '往来' : '非往来' }}
          </el-tag>
        </button>
      </div>

      <div class="aux-dimension-section">
        <div class="aux-dimension-title">自定义辅助维度</div>
        <div
          v-for="item in customTypes"
          :key="item.aux_type_code"
          class="aux-dimension-item"
          :class="{ active: selectedTypeCode === item.aux_type_code }"
          role="button"
          tabindex="0"
          @click="selectType(item.aux_type_code)"
          @keydown.enter="selectType(item.aux_type_code)"
        >
          <span>
            <strong>{{ item.aux_type_name }}</strong>
            <small>{{ item.aux_type_code }}</small>
          </span>
          <div class="aux-dimension-actions">
            <el-tag size="small" type="info">自定义</el-tag>
            <el-button
              v-permission="'base:delete'"
              link
              type="danger"
              :icon="DeleteIcon"
              title="删除自定义维度"
              aria-label="删除自定义维度"
              @click.stop="confirmTypeDelete(item)"
            />
          </div>
        </div>
        <el-empty v-if="customTypes.length === 0" description="暂无自定义维度" :image-size="72" />
      </div>
    </aside>

    <section class="aux-archive-panel">
      <div class="aux-rule-card">
        <div>
          <span class="aux-rule-kicker">{{ selectedMeta?.category || '辅助维度' }}</span>
          <h2>{{ selectedTypeName }}</h2>
          <p>{{ selectedMeta?.purpose || selectedType?.remark || '自定义辅助维度，用于扩展科目和凭证分录的核算口径。' }}</p>
        </div>
        <div class="aux-rule-tags">
          <el-tag :type="isSelectedStandard ? 'success' : 'info'">{{ isSelectedStandard ? '系统标准' : '自定义' }}</el-tag>
          <el-tag :type="selectedMeta?.intercourse ? 'warning' : 'primary'">
            {{ selectedMeta?.intercourse ? '往来类互斥' : '可组合维度' }}
          </el-tag>
          <el-tag>{{ selectedType?.value_source === 'MANUAL' ? '手工录入' : '档案选择' }}</el-tag>
        </div>
      </div>

      <div class="panel">
        <div class="panel-header">
          <strong>{{ selectedTypeName }}档案</strong>
          <div class="page-actions">
            <el-button v-if="selectedType" v-permission="'base:edit'" @click="openTypeEdit">编辑维度</el-button>
            <el-button v-permission="'base:add'" type="success" :icon="Upload" :loading="archiveImporting" @click="chooseArchiveImportFile">批量导入</el-button>
            <el-button v-permission="'base:add'" type="primary" :icon="Plus" @click="openArchiveCreate">新增档案</el-button>
          </div>
        </div>
        <div class="panel-body">
          <div class="filter-row aux-editor-toolbar">
            <el-input v-model="keyword" placeholder="搜索档案编码或名称" clearable style="width: 260px" @keyup.enter="loadArchives" />
            <el-select v-model="statusFilter" clearable placeholder="状态" style="width: 120px" @change="loadArchives">
              <el-option label="启用" value="1" />
              <el-option label="停用" value="0" />
            </el-select>
            <el-button type="primary" @click="loadArchives">查询</el-button>
            <el-button @click="resetArchiveFilter">重置</el-button>
            <el-dropdown split-button type="success" @click="exportCurrentPage">
              <el-icon><Download /></el-icon>导出当前页
              <template #dropdown>
                <el-dropdown-item @click="exportAll">导出全部</el-dropdown-item>
              </template>
            </el-dropdown>
          </div>

          <el-table :data="filteredArchives" border height="calc(100vh - 460px)">
            <el-table-column prop="archive_code" :label="`${selectedTypeName}编码`" width="180" />
            <el-table-column prop="archive_name" :label="`${selectedTypeName}名称`" min-width="220" />
            <el-table-column prop="status" label="状态" width="90">
              <template #default="{ row }">
                <el-tag size="small" :type="Number(row.status) === 1 ? 'success' : 'info'">
                  {{ Number(row.status) === 1 ? '启用' : '停用' }}
                </el-tag>
              </template>
            </el-table-column>
            <el-table-column prop="remark" label="备注" min-width="220" />
            <el-table-column label="操作" width="150" fixed="right">
              <template #default="{ row }">
                <el-button v-permission="'base:edit'" link type="primary" @click="openArchiveEdit(row)">编辑</el-button>
                <el-button v-permission="'base:delete'" link type="danger" @click="confirmArchiveDelete(row)">删除</el-button>
              </template>
            </el-table-column>
          </el-table>

          <div class="pagination-bar">
            <el-pagination
              v-model:current-page="archivePage"
              v-model:page-size="archivePageSize"
              :page-sizes="[20, 50, 100, 200]"
              :total="archiveTotal"
              layout="total, sizes, prev, pager, next"
              @size-change="loadArchives"
              @current-change="loadArchives"
            />
          </div>
        </div>
      </div>
    </section>
  </div>

  <el-dialog v-model="typeDialogVisible" :title="typeForm.aux_type_id ? '编辑辅助维度' : '新增自定义辅助维度'" width="560px">
    <el-alert
      class="mb-16"
      type="warning"
      :closable="false"
      show-icon
      title="客户、供应商、职员是标准往来类维度，同一科目挂载时只能三选一；自定义维度默认可组合。"
    />
    <el-form label-position="top" :model="typeForm">
      <div class="form-grid two">
        <el-form-item label="维度编码" required>
          <el-input v-model.trim="typeForm.aux_type_code" :disabled="isEditingStandardType" placeholder="如 contract_no" />
        </el-form-item>
        <el-form-item label="维度名称" required>
          <el-input v-model.trim="typeForm.aux_type_name" placeholder="如 合同号" />
        </el-form-item>
        <el-form-item label="取值方式">
          <el-select v-model="typeForm.value_source">
            <el-option label="档案选择" value="ARCHIVE" />
            <el-option label="手工录入" value="MANUAL" />
          </el-select>
        </el-form-item>
        <el-form-item label="状态">
          <el-switch v-model="typeEnabled" active-text="启用" inactive-text="停用" />
        </el-form-item>
      </div>
      <el-form-item label="说明">
        <el-input v-model="typeForm.remark" type="textarea" :rows="2" />
      </el-form-item>
    </el-form>
    <template #footer>
      <el-button @click="typeDialogVisible = false">取消</el-button>
      <el-button v-permission="['base:add', 'base:edit']" type="primary" :loading="typeSaving" @click="saveType">保存</el-button>
    </template>
  </el-dialog>

  <el-dialog v-model="archiveDialogVisible" :title="archiveForm.archive_id ? `编辑${selectedTypeName}档案` : `新增${selectedTypeName}档案`" width="560px">
    <el-form label-position="top" :model="archiveForm">
      <div class="form-grid two">
        <el-form-item label="所属维度">
          <el-input :model-value="selectedTypeName" disabled />
        </el-form-item>
        <el-form-item label="状态">
          <el-switch v-model="archiveEnabled" active-text="启用" inactive-text="停用" />
        </el-form-item>
        <el-form-item :label="`${selectedTypeName}编码`"">
          <el-input v-model.trim="archiveForm.archive_code" placeholder="留空则自动生成序号（从1开始）" />
        </el-form-item>
        <el-form-item :label="`${selectedTypeName}名称`" required>
          <el-input v-model.trim="archiveForm.archive_name" placeholder="请输入档案名称" />
        </el-form-item>
      </div>
      <el-form-item label="备注">
        <el-input v-model="archiveForm.remark" type="textarea" :rows="2" />
      </el-form-item>
    </el-form>
    <template #footer>
      <el-button @click="archiveDialogVisible = false">取消</el-button>
      <el-button v-permission="['base:add', 'base:edit']" type="primary" :loading="archiveSaving" @click="saveArchive">保存</el-button>
    </template>
  </el-dialog>

  <!-- 档案导入文件输入（放在组件根层级，避免 v-permission 干扰 ref 绑定） -->
  <input ref="archiveImportInputRef" type="file" accept=".xlsx" style="display: none" @change="handleArchiveImportFile" />
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Delete as DeleteIcon, Download, Plus, Refresh, Upload } from '@element-plus/icons-vue'
import { baseApi } from '../../api/base'
import * as XLSX from 'xlsx'

const standardTypes = [
  { code: 'customer', name: '客户', intercourse: true, category: '标准往来维度', rule: '与供应商/职员互斥', purpose: '用于应收、预收、收入等科目的客户往来核算。' },
  { code: 'supplier', name: '供应商', intercourse: true, category: '标准往来维度', rule: '与客户/职员互斥', purpose: '用于应付、预付、采购等科目的供应商往来核算。' },
  { code: 'department', name: '部门', intercourse: false, category: '标准管理维度', rule: '可组合挂载', purpose: '用于费用归集、部门考核、部门利润分析。' },
  { code: 'employee', name: '职员', intercourse: true, category: '标准往来维度', rule: '个人往来，三选一', purpose: '用于个人借款、报销、工资等职员维度核算。' },
  { code: 'project', name: '项目', intercourse: false, category: '标准项目维度', rule: '可组合挂载', purpose: '用于项目成本、收入、利润的全周期核算。' }
]
const standardCodes = standardTypes.map((item) => item.code)

const types = ref<any[]>([])
const archives = ref<any[]>([])
const archiveTotal = ref(0)
const archivePage = ref(1)
const archivePageSize = ref(50)
const selectedTypeCode = ref('customer')
const keyword = ref('')
const statusFilter = ref('')
const typeDialogVisible = ref(false)
const archiveDialogVisible = ref(false)
const typeSaving = ref(false)
const archiveSaving = ref(false)
const archiveImporting = ref(false)
const archiveImportInputRef = ref<HTMLInputElement | null>(null)

const typeForm = reactive<any>({
  aux_type_id: '',
  aux_type_code: '',
  aux_type_name: '',
  value_source: 'ARCHIVE',
  status: 1,
  remark: ''
})
const archiveForm = reactive<any>({
  archive_id: '',
  aux_type_code: '',
  archive_code: '',
  archive_name: '',
  status: 1,
  remark: ''
})

const selectedType = computed(() => types.value.find((item) => item.aux_type_code === selectedTypeCode.value))
const selectedMeta = computed(() => standardTypes.find((item) => item.code === selectedTypeCode.value))
const selectedTypeName = computed(() => selectedType.value?.aux_type_name || selectedMeta.value?.name || selectedTypeCode.value)
const isSelectedStandard = computed(() => standardCodes.includes(selectedTypeCode.value))
const customTypes = computed(() => types.value.filter((item) => !standardCodes.includes(item.aux_type_code)))
const isEditingStandardType = computed(() => standardCodes.includes(typeForm.aux_type_code))
const filteredArchives = computed(() => {
  return archives.value.filter((row) => !statusFilter.value || String(row.status) === statusFilter.value)
})

const typeEnabled = computed({
  get: () => Number(typeForm.status) === 1,
  set: (value: boolean) => {
    typeForm.status = value ? 1 : 0
  }
})
const archiveEnabled = computed({
  get: () => Number(archiveForm.status) === 1,
  set: (value: boolean) => {
    archiveForm.status = value ? 1 : 0
  }
})

const ensureStandardTypes = () => {
  standardTypes.forEach((standard) => {
    if (!types.value.some((item) => item.aux_type_code === standard.code)) {
      types.value.push({
        aux_type_code: standard.code,
        aux_type_name: standard.name,
        value_source: 'ARCHIVE',
        status: 1,
        remark: standard.purpose
      })
    }
  })
}

const loadTypes = async () => {
  types.value = await baseApi.auxTypes()
  ensureStandardTypes()
}

let lastArchiveRequestId = 0

const loadArchives = async () => {
  if (!selectedTypeCode.value) {
    archives.value = []
    archiveTotal.value = 0
    return
  }
  const requestId = ++lastArchiveRequestId
  const result = await baseApi.auxArchives(selectedTypeCode.value, keyword.value.trim(), archivePage.value, archivePageSize.value)
  if (requestId !== lastArchiveRequestId) {
    return
  }
  archives.value = result.items || []
  archiveTotal.value = result.total || 0
}

const loadAll = async () => {
  await loadTypes()
  await loadArchives()
}

const selectType = async (code: string) => {
  selectedTypeCode.value = code
  keyword.value = ''
  statusFilter.value = ''
  archivePage.value = 1
  await loadArchives()
}

const resetArchiveFilter = async () => {
  keyword.value = ''
  statusFilter.value = ''
  archivePage.value = 1
  await loadArchives()
}

const exportCurrentPage = () => {
  exportToExcel(filteredArchives.value, `${selectedTypeName.value}档案_第${archivePage.value}页`)
}

const exportAll = async () => {
  archiveImporting.value = true
  try {
    const result = await baseApi.auxArchives(selectedTypeCode.value, keyword.value.trim(), 1, 99999)
    exportToExcel(result.items || [], `${selectedTypeName.value}档案_全部`)
  } catch (err: any) {
    ElMessage.error('导出失败：' + (err.message || '未知错误'))
  } finally {
    archiveImporting.value = false
  }
}

const exportToExcel = (data: any[], filename: string) => {
  const headers = ['编码', '名称', '状态', '备注']
  const rows = data.map((row) => [
    row.archive_code,
    row.archive_name,
    Number(row.status) === 1 ? '启用' : '停用',
    row.remark || '',
  ])
  const sheet = XLSX.utils.aoa_to_sheet([headers, ...rows])
  const wb = XLSX.utils.book_new()
  XLSX.utils.book_append_sheet(wb, sheet, '档案')
  XLSX.writeFile(wb, `${filename}.xlsx`)
}

const resetTypeForm = () => {
  Object.assign(typeForm, {
    aux_type_id: '',
    aux_type_code: '',
    aux_type_name: '',
    value_source: 'ARCHIVE',
    status: 1,
    remark: ''
  })
}

const resetArchiveForm = () => {
  Object.assign(archiveForm, {
    archive_id: '',
    aux_type_code: selectedTypeCode.value,
    archive_code: '',
    archive_name: '',
    status: 1,
    remark: ''
  })
}

const openTypeCreate = () => {
  resetTypeForm()
  typeDialogVisible.value = true
}

const openTypeEdit = () => {
  resetTypeForm()
  Object.assign(typeForm, selectedType.value || {
    aux_type_code: selectedTypeCode.value,
    aux_type_name: selectedTypeName.value,
    value_source: 'ARCHIVE',
    status: 1,
    remark: selectedMeta.value?.purpose || ''
  })
  typeDialogVisible.value = true
}

const openArchiveCreate = () => {
  resetArchiveForm()
  archiveDialogVisible.value = true
}

const openArchiveEdit = (row: any) => {
  Object.assign(archiveForm, row)
  archiveForm.aux_type_code = selectedTypeCode.value
  archiveDialogVisible.value = true
}

const saveType = async () => {
  if (!typeForm.aux_type_code || !typeForm.aux_type_name) {
    ElMessage.warning('请填写辅助维度编码和名称')
    return
  }
  typeSaving.value = true
  try {
    await baseApi.saveAuxType(typeForm)
    ElMessage.success('辅助维度已保存')
    typeDialogVisible.value = false
    selectedTypeCode.value = typeForm.aux_type_code
    await loadAll()
  } finally {
    typeSaving.value = false
  }
}

const confirmTypeDelete = async (row: any) => {
  await ElMessageBox.confirm(`确认删除自定义辅助维度 ${row.aux_type_name}？该维度下未使用的档案也会一并删除。`, '删除辅助维度', {
    type: 'warning',
    confirmButtonText: '删除',
    cancelButtonText: '取消'
  })
  await baseApi.deleteAuxType(row.aux_type_id)
  ElMessage.success('辅助维度已删除')
  selectedTypeCode.value = standardTypes[0].code
  await loadAll()
}

const saveArchive = async () => {
  archiveForm.aux_type_code = selectedTypeCode.value
  if (!archiveForm.archive_name) {
    ElMessage.warning('请填写档案名称')
    return
  }
  archiveSaving.value = true
  try {
    await baseApi.saveAuxArchive(archiveForm)
    ElMessage.success('辅助档案已保存')
    archiveDialogVisible.value = false
    await loadArchives()
  } finally {
    archiveSaving.value = false
  }
}

const confirmArchiveDelete = async (row: any) => {
  await ElMessageBox.confirm(`确认删除档案 ${row.archive_name}？已被期初或凭证使用的档案不能删除。`, '删除辅助档案', {
    type: 'warning',
    confirmButtonText: '删除',
    cancelButtonText: '取消'
  })
  await baseApi.deleteAuxArchive(row.archive_id)
  ElMessage.success('辅助档案已删除')
  await loadArchives()
}

const chooseArchiveImportFile = () => {
  if (!archiveImportInputRef.value) return
  archiveImportInputRef.value.value = ''
  archiveImportInputRef.value.click()
}

const handleArchiveImportFile = async (event: Event) => {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file) return
  if (!file.name.toLowerCase().endsWith('.xlsx')) {
    ElMessage.warning('请选择 .xlsx 格式的文件')
    return
  }

  archiveImporting.value = true
  try {
    const data = await file.arrayBuffer()
    const workbook = XLSX.read(data, { type: 'array' })
    const sheet = workbook.Sheets[workbook.SheetNames[0]]
    const rows = XLSX.utils.sheet_to_json(sheet, { header: 1, raw: false }) as any[][]

    if (rows.length < 2) {
      ElMessage.warning('Excel 数据为空')
      return
    }

    const headers = rows[0] as string[]
    // 查找名称列索引（支持 "案号名称"、"收据号名称"、"名称" 等）
    let nameColIdx = headers.findIndex((h) => h && String(h).includes('名称'))
    if (nameColIdx === -1) {
      // 如果没有找到包含"名称"的列，尝试第二列
      nameColIdx = 1
    }

    const names: string[] = []
    for (let i = 1; i < rows.length; i++) {
      const row = rows[i]
      if (!row || row.length === 0) continue
      const name = String(row[nameColIdx] || '').trim()
      if (name) {
        names.push(name)
      }
    }

    if (names.length === 0) {
      ElMessage.warning('未解析到有效的档案名称')
      return
    }

    const result = await baseApi.importAuxArchives(selectedTypeCode.value, names)
    const msg = `导入完成：成功 ${result.success} 条，跳过 ${result.skipped} 条（已存在）`
    if (result.success > 0) {
      ElMessage.success(msg)
      await loadArchives()
    } else {
      ElMessage.info(msg)
    }
  } catch (err: any) {
    ElMessage.error('导入失败：' + (err.message || '未知错误'))
  } finally {
    archiveImporting.value = false
  }
}

onMounted(loadAll)
</script>
