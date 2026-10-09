<template>
  <sPageSplit side-title="商品组装单">
    <!-- 筛选区 -->
    <div class="filter-bar">
      <div class="filter-item">
        <label>组装单号</label>
        <el-input v-model="filters.assembly_no" placeholder="输入组装单号" style="width:180px;height:34px" clearable />
      </div>
      <div class="filter-item">
        <label>父件商品</label>
        <el-input v-model="filters.parent_product_name" placeholder="输入父件商品名称" style="width:180px;height:34px" clearable />
      </div>
      <div class="filter-item">
        <label>仓库</label>
        <el-select v-model="filters.warehouse_id" placeholder="全部仓库" clearable style="width:140px;height:34px">
          <el-option v-for="w in warehouses" :key="w.id" :label="w.name" :value="w.id" />
        </el-select>
      </div>
      <div class="filter-item">
        <label>状态</label>
        <el-select v-model="filters.status" placeholder="全部" clearable style="width:120px;height:34px">
          <el-option label="草稿" value="draft" />
          <el-option label="待审核" value="pending" />
          <el-option label="已审核" value="approved" />
          <el-option label="已取消" value="cancelled" />
        </el-select>
      </div>
      <div class="filter-item">
        <label>制单人</label>
        <el-select v-model="filters.created_by" placeholder="全部人员" clearable style="width:140px;height:34px">
          <el-option v-for="u in users" :key="u.id" :label="u.name" :value="u.id" />
        </el-select>
      </div>
      <div class="filter-item">
        <label>组装日期</label>
        <el-date-picker v-model="filters.start_date" type="date" placeholder="开始" value-format="YYYY-MM-DD" style="width:140px;height:34px" />
        <span style="color:#999;margin:0 4px;line-height:34px">至</span>
        <el-date-picker v-model="filters.end_date" type="date" placeholder="结束" value-format="YYYY-MM-DD" style="width:140px;height:34px" />
      </div>
      <el-button type="primary" style="width:80px;height:34px;margin-left:8px" @click="handleSearch">查询</el-button>
      <el-button style="width:80px;height:34px" @click="handleReset">重置</el-button>
      <div style="flex:1" />
      <el-button type="primary" style="width:120px;height:36px;background:#52c41a;border-color:#52c41a" @click="handleCreate">
        <el-icon><Plus /></el-icon>新增组装单
      </el-button>
      <el-button style="width:100px;height:36px;background:#52c41a;border-color:#52c41a" :disabled="!selectedRows.length" @click="handleBatchApprove">
        批量审核
      </el-button>
      <el-button style="width:80px;height:36px;color:#52c41a;border-color:#52c41a" @click="handleExport">
        <el-icon><Download /></el-icon>导出
      </el-button>
    </div>

    <!-- 列表 -->
    <vxe-table ref="listTable" v-loading="loading" :data="tableData" :row-config="{ keyField: 'id', isHover: true }" :stripe="true" border show-header-footer class="return-table" @checkbox-change="onCheckboxChange" @checkbox-all="onCheckboxChange">
      <vxe-column type="checkbox" width="40" align="center" />
      <vxe-column title="组装单号" field="assembly_no" width="180">
        <template #default="{ row }"><a style="color:#1890ff;cursor:pointer" @click="handleView(row)">{{ row.assembly_no }}</a></template>
      </vxe-column>
      <vxe-column title="父件商品" width="240">
        <template #default="{ row }"><span>{{ row.parent_product_name || '-' }}</span> <span style="color:#f5222d;font-size:12px;margin-left:6px">×{{ row.quantity }}</span></template>
      </vxe-column>
      <vxe-column title="子件种类" width="90" align="center">
        <template #default="{ row }">{{ row.items_count ?? row.items?.length ?? 0 }} 种</template>
      </vxe-column>
      <vxe-column title="总成本" field="total_cost" width="120" align="right">
        <template #default="{ row }"><span style="color:#f5222d;font-weight:bold">¥{{ Number(row.total_cost).toFixed(2) }}</span></template>
      </vxe-column>
      <vxe-column title="单位成本" field="unit_cost" width="100" align="right">
        <template #default="{ row }">¥{{ Number(row.unit_cost).toFixed(2) }}</template>
      </vxe-column>
      <vxe-column title="仓库" width="100" align="center">
        <template #default="{ row }">{{ row.warehouse?.name || '-' }}</template>
      </vxe-column>
      <vxe-column title="状态" field="status" width="90" align="center">
        <template #default="{ row }">
          <span v-if="row.status === 'pending'" style="background:#fffbe6;color:#faad14;padding:2px 10px;border-radius:4px;font-size:12px">待审核</span>
          <span v-else-if="row.status === 'approved'" style="background:#f6ffed;color:#52c41a;padding:2px 10px;border-radius:4px;font-size:12px">已审核</span>
          <span v-else-if="row.status === 'cancelled'" style="background:#f5f5f5;color:#999;text-decoration:line-through;padding:2px 10px;border-radius:4px;font-size:12px">已取消</span>
          <span v-else style="background:#f5f5f5;color:#999;padding:2px 10px;border-radius:4px;font-size:12px">草稿</span>
        </template>
      </vxe-column>
      <vxe-column title="操作" width="180" align="center" fixed="right">
        <template #default="{ row }">
          <span v-if="row.status === 'draft'">
            <el-button link type="primary" size="small" @click="handleEdit(row)">编辑</el-button>
            <span style="color:#e8e8e8">|</span>
            <el-button link type="success" size="small" @click="handleSubmit(row)">提交</el-button>
            <span style="color:#e8e8e8">|</span>
            <el-button link type="danger" size="small" @click="handleCancel(row)">取消</el-button>
          </span>
          <span v-else-if="row.status === 'pending'">
            <el-button link type="success" size="small" @click="handleApprove(row)">审核</el-button>
            <span style="color:#e8e8e8">|</span>
            <el-button link type="danger" size="small" @click="handleReject(row)">驳回</el-button>
          </span>
          <span v-else>
            <el-button link type="primary" size="small" @click="handleView(row)">查看</el-button>
          </span>
        </template>
      </vxe-column>
    </vxe-table>

    <div class="pagination-bar">
      <span style="color:#666;font-size:14px">共 {{ pagination.total }} 条</span>
      <el-pagination background layout="prev, pager, next, jumper" :total="pagination.total" :page-size="pagination.page_size" :current-page="pagination.page" @current-change="handlePageChange" @size-change="handleSizeChange" />
    </div>

    <!-- 新增/编辑弹窗 -->
    <el-dialog v-model="dialogVisible" :title="dialogTitle" width="1000px" top="3vh" destroy-on-close :close-on-click-modal="false">
      <div class="form-section">
        <div class="section-title">基本信息</div>
        <div class="form-row">
          <div class="form-field">
            <label><span class="required">*</span>父件商品</label>
            <el-select v-model="form.parent_product_id" placeholder="选择父件商品" filterable clearable style="width:280px;height:34px" @change="onParentChange">
              <el-option v-for="p in products" :key="p.id" :label="`${p.name}${p.spec ? ' | '+p.spec : ''}`" :value="p.id" />
            </el-select>
          </div>
          <div class="form-field">
            <label><span class="required">*</span>仓库</label>
            <el-select v-model="form.warehouse_id" placeholder="选择仓库" style="width:160px;height:34px" @change="onWarehouseChange">
              <el-option v-for="w in warehouses" :key="w.id" :label="w.name" :value="w.id" />
            </el-select>
          </div>
          <div class="form-field">
            <label><span class="required">*</span>组装数量</label>
            <el-input-number v-model="form.quantity" :min="1" style="width:120px;height:34px" @change="recalcItems" />
          </div>
          <div class="form-field">
            <label><span class="required">*</span>组装日期</label>
            <el-date-picker v-model="form.assembly_date" type="date" value-format="YYYY-MM-DD" style="width:140px;height:34px" />
          </div>
          <div class="form-field">
            <label>备注</label>
            <el-input v-model="form.remark" placeholder="可选" style="width:240px;height:34px" />
          </div>
        </div>
        <div v-if="parentStock !== null" class="parent-card">
          <span>父件当前库存：<b style="color:#52c41a">{{ parentStock }}</b></span>
        </div>
      </div>

      <div class="form-section">
        <div class="section-title">
          <span>子件明细</span>
          <div style="margin-left:auto">
            <el-button size="small" type="primary" @click="handleImportBom">从 BOM 导入</el-button>
            <el-button size="small" @click="handleAddItem">添加子件</el-button>
          </div>
        </div>
        <vxe-table :data="form.items" :row-config="{ keyField: 'product_id', isHover: true }" border show-header-border size="small" class="item-table">
          <vxe-column title="商品名称" width="220">
            <template #default="{ row }">
              <el-select v-model="row.product_id" placeholder="选择子件" filterable size="small" style="width:200px" @change="(v) => onItemProductChange(row, v)">
                <el-option v-for="p in products" :key="p.id" :label="p.name" :value="p.id" />
              </el-select>
            </template>
          </vxe-column>
          <vxe-column title="规格" width="100"><template #default="{ row }">{{ row.spec || '-' }}</template></vxe-column>
          <vxe-column title="当前库存" width="100" align="center">
            <template #default="{ row }">
              <span :style="{ color: row.current_stock < row.total_usage ? '#f5222d' : '#52c41a', fontWeight: 'bold' }">{{ row.current_stock }}</span>
              <div v-if="row.current_stock < row.total_usage" style="font-size:11px;color:#f5222d">缺 {{ row.total_usage - row.current_stock }}</div>
            </template>
          </vxe-column>
          <vxe-column title="单位用量" width="110" align="center">
            <template #default="{ row }"><el-input-number v-model="row.unit_usage" :min="1" size="small" controls-position="right" style="width:90px" @change="recalcRow(row)" /></template>
          </vxe-column>
          <vxe-column title="总用量" width="90" align="center"><template #default="{ row }"><b>{{ row.total_usage }}</b></template></vxe-column>
          <vxe-column title="单位成本" width="110" align="right">
            <template #default="{ row }"><el-input-number v-model="row.unit_cost" :precision="2" :min="0" size="small" controls-position="right" style="width:90px" @change="recalcRow(row)" /></template>
          </vxe-column>
          <vxe-column title="总成本" width="110" align="right"><template #default="{ row }"><span style="color:#f5222d;font-weight:bold">¥{{ Number(row.total_cost).toFixed(2) }}</span></template></vxe-column>
          <vxe-column title="操作" width="70" align="center"><template #default="{ $rowIndex }"><el-button link type="danger" size="small" @click="form.items.splice($rowIndex, 1)">删除</el-button></template></vxe-column>
        </vxe-table>
        <div v-if="form.items.length" class="item-footer">
          <span style="font-weight:bold">合计</span>
          <span>子件 {{ form.items.length }} 种</span>
          <span style="color:#f5222d;font-weight:bold;margin-left:auto">总成本：¥{{ totalCost.toFixed(2) }}</span>
        </div>
      </div>

      <template #footer>
        <el-button style="width:80px;height:36px" @click="dialogVisible = false">取消</el-button>
        <el-button style="width:100px;height:36px;color:#1890ff;border-color:#1890ff" @click="handleSaveDraft">保存草稿</el-button>
        <el-button type="success" style="width:100px;height:36px;background:#52c41a;border-color:#52c41a" @click="handleSubmitClick">提交审核</el-button>
      </template>
    </el-dialog>

    <!-- 审核弹窗 -->
    <el-dialog v-model="approveVisible" title="组装单审核" width="900px" top="3vh" destroy-on-close>
      <div v-if="current">
        <div class="section-title">基本信息</div>
        <el-descriptions :column="3" border size="small" style="margin-bottom:16px">
          <el-descriptions-item label="组装单号">{{ current.assembly_no }}</el-descriptions-item>
          <el-descriptions-item label="父件商品">{{ current.parent_product_name }}</el-descriptions-item>
          <el-descriptions-item label="仓库">{{ current.warehouse?.name }}</el-descriptions-item>
          <el-descriptions-item label="组装数量">{{ current.quantity }}</el-descriptions-item>
          <el-descriptions-item label="总成本">¥{{ Number(current.total_cost).toFixed(2) }}</el-descriptions-item>
          <el-descriptions-item label="单位成本">¥{{ Number(current.unit_cost).toFixed(2) }}</el-descriptions-item>
        </el-descriptions>
        <div class="section-title">子件明细</div>
        <vxe-table :data="current.items || []" border show-header-border size="small" style="margin-bottom:16px">
          <vxe-column title="商品名称" field="product_name" width="220" />
          <vxe-column title="规格" field="spec" width="100" />
          <vxe-column title="单位用量" field="unit_usage" width="100" align="center" />
          <vxe-column title="总用量" field="total_usage" width="100" align="center" />
          <vxe-column title="单位成本" field="unit_cost" width="100" align="right" />
          <vxe-column title="总成本" width="120" align="right"><template #default="{ row }">¥{{ Number(row.total_cost).toFixed(2) }}</template></vxe-column>
        </vxe-table>
        <div class="section-title">审核意见</div>
        <el-input v-model="approveComment" type="textarea" :rows="3" placeholder="可选" style="margin-bottom:16px" />
      </div>
      <template #footer>
        <el-button style="width:80px;height:36px;margin-right:12px" @click="approveVisible = false">取消</el-button>
        <el-button type="danger" style="width:100px;height:36px" @click="doReject">驳回</el-button>
        <el-button type="success" style="width:100px;height:36px;background:#52c41a" @click="doApprove">通过审核</el-button>
      </template>
    </el-dialog>

    <!-- 详情弹窗 -->
    <el-dialog v-model="detailVisible" title="组装单详情" width="900px" top="3vh" destroy-on-close>
      <div v-if="detail">
        <div class="section-title">基本信息</div>
        <el-descriptions :column="3" border size="small" style="margin-bottom:16px">
          <el-descriptions-item label="组装单号">{{ detail.assembly_no }}</el-descriptions-item>
          <el-descriptions-item label="父件商品">{{ detail.parent_product_name }}</el-descriptions-item>
          <el-descriptions-item label="仓库">{{ detail.warehouse?.name }}</el-descriptions-item>
          <el-descriptions-item label="组装数量">{{ detail.quantity }}</el-descriptions-item>
          <el-descriptions-item label="状态">{{ statusLabel(detail.status) }}</el-descriptions-item>
          <el-descriptions-item label="组装日期">{{ detail.assembly_date }}</el-descriptions-item>
          <el-descriptions-item label="总成本">¥{{ Number(detail.total_cost).toFixed(2) }}</el-descriptions-item>
          <el-descriptions-item label="单位成本">¥{{ Number(detail.unit_cost).toFixed(2) }}</el-descriptions-item>
          <el-descriptions-item label="审核人">{{ detail.approver?.real_name || detail.approver?.name || '-' }}</el-descriptions-item>
        </el-descriptions>
        <div class="section-title">子件明细</div>
        <vxe-table :data="detail.items || []" border show-header-border size="small">
          <vxe-column title="商品名称" field="product_name" width="220" />
          <vxe-column title="规格" field="spec" width="100" />
          <vxe-column title="单位用量" field="unit_usage" width="100" align="center" />
          <vxe-column title="总用量" field="total_usage" width="100" align="center" />
          <vxe-column title="单位成本" field="unit_cost" width="100" align="right" />
          <vxe-column title="总成本" width="120" align="right"><template #default="{ row }">¥{{ Number(row.total_cost).toFixed(2) }}</template></vxe-column>
        </vxe-table>
      </div>
      <template #footer><el-button @click="detailVisible = false">关闭</el-button></template>
    </el-dialog>
  </sPageSplit>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus, Download } from '@element-plus/icons-vue'
import sPageSplit from '@/components/sPageSplit/index.vue'
import businessApi from '@/api/business'
import authApi from '@/api/auth'

const loading = ref(false)
const tableData = ref([])
const warehouses = ref([])
const products = ref([])
const users = ref([])
const stockMap = ref({})
const selectedRows = ref([])
const listTable = ref(null)

const filters = reactive({ assembly_no: '', parent_product_name: '', warehouse_id: null, created_by: null, status: null, start_date: '', end_date: '' })
const pagination = reactive({ page: 1, page_size: 20, total: 0 })

const fetchData = async () => {
  loading.value = true
  try {
    const res = await businessApi.assembly.list.get({ ...filters, page: pagination.page, page_size: pagination.page_size })
    tableData.value = res.data?.list || res.data?.data || []
    pagination.total = res.data?.total || 0
  } catch { ElMessage.error('加载失败') } finally { loading.value = false }
}
const fetchWarehouses = async () => { try { const res = await businessApi.warehouse.list.get({type:"normal",  page_size: 100 }); warehouses.value = res.data?.list || res.data || [] } catch {} }
const fetchProducts = async () => { try { const res = await businessApi.product.list.get({ page_size: 1000, is_active: 1 }); products.value = res.data?.list || res.data?.data || [] } catch {} }
const fetchUsers = async () => { try { const res = await authApi.user.list.get({ page_size: 200 }); users.value = res.data?.list || res.data?.data || [] } catch {} }
const fetchStockMap = async (wid) => {
  if (!wid) { stockMap.value = {}; return }
  try { const res = await businessApi.stock.list.get({ warehouse_id: wid, page_size: 1000 }); const list = res.data?.list || res.data?.data || []; stockMap.value = {}; list.forEach(s => { stockMap.value[s.product_id] = s.quantity }) } catch {}
}

const handleSearch = () => { pagination.page = 1; fetchData() }
const handleReset = () => { Object.assign(filters, { assembly_no: '', parent_product_name: '', warehouse_id: null, created_by: null, status: null, start_date: '', end_date: '' }); handleSearch() }
const handlePageChange = (p) => { pagination.page = p; fetchData() }
const handleSizeChange = (s) => { pagination.page_size = s; pagination.page = 1; fetchData() }
const onCheckboxChange = () => { selectedRows.value = listTable.value?.getCheckboxRecords?.() || [] }

const dialogVisible = ref(false)
const dialogTitle = ref('')
const isEdit = ref(false)
const editId = ref(null)
const parentStock = ref(null)

const form = reactive({ parent_product_id: null, warehouse_id: null, quantity: 1, assembly_date: new Date().toISOString().slice(0, 10), remark: '', items: [] })
const totalCost = computed(() => form.items.reduce((s, i) => s + (Number(i.total_cost) || 0), 0))

const onWarehouseChange = (wid) => { fetchStockMap(wid); refreshStocks() }
const onParentChange = (pid) => {
  const p = products.value.find(x => x.id === pid)
  parentStock.value = pid ? (stockMap.value[pid] ?? 0) : null
  if (pid) handleImportBom(pid)
}
const refreshStocks = () => {
  parentStock.value = form.parent_product_id ? (stockMap.value[form.parent_product_id] ?? 0) : null
  form.items.forEach(r => { r.current_stock = stockMap.value[r.product_id] ?? 0 })
}
const onItemProductChange = (row, pid) => {
  const p = products.value.find(x => x.id === pid)
  row.product_name = p?.name || ''
  row.spec = p?.spec || ''
  row.unit_cost = Number(p?.cost_price) || 0
  row.current_stock = stockMap.value[pid] ?? 0
  recalcRow(row)
}
const recalcRow = (row) => { row.total_usage = (row.unit_usage || 0) * (form.quantity || 1); row.total_cost = Number((row.total_usage * (row.unit_cost || 0)).toFixed(2)) }
const recalcItems = () => { form.items.forEach(recalcRow); refreshStocks() }

const handleAddItem = () => { form.items.push({ product_id: null, product_name: '', spec: '', current_stock: 0, unit_usage: 1, total_usage: form.quantity, unit_cost: 0, total_cost: 0 }) }
const handleImportBom = async (pid) => {
  const parentId = pid || form.parent_product_id
  if (!parentId) { ElMessage.warning('请先选择父件商品'); return }
  try {
    const res = await businessApi.assembly.bomByProduct.get({ product_id: parentId })
    if (res.code === 200 && res.data?.items?.length) {
      form.items = res.data.items.map(i => ({ product_id: i.product_id, product_name: i.product_name, spec: i.spec, current_stock: stockMap.value[i.product_id] ?? 0, unit_usage: i.unit_usage, total_usage: i.unit_usage * form.quantity, unit_cost: i.unit_cost, total_cost: Number((i.unit_usage * form.quantity * i.unit_cost).toFixed(2)) }))
      ElMessage.success(`已导入 ${res.data.items.length} 个子件`)
    } else { ElMessage.info('该商品未配置组装 BOM，请手动添加子件') }
  } catch { ElMessage.error('加载 BOM 失败') }
}

const handleCreate = () => {
  isEdit.value = false; dialogTitle.value = '新增商品组装单'
  Object.assign(form, { parent_product_id: null, warehouse_id: null, quantity: 1, assembly_date: new Date().toISOString().slice(0, 10), remark: '', items: [] })
  parentStock.value = null; dialogVisible.value = true
}
const handleEdit = async (row) => {
  isEdit.value = true; editId.value = row.id; dialogTitle.value = '编辑组装单'
  const res = await businessApi.assembly.detail.get(row.id)
  if (res.code === 200) {
    const d = res.data
    Object.assign(form, { parent_product_id: d.parent_product_id, warehouse_id: d.warehouse_id, quantity: d.quantity, assembly_date: d.assembly_date?.slice(0, 10), remark: d.remark || '', items: (d.items || []).map(i => ({ product_id: i.product_id, product_name: i.product_name, spec: i.spec, current_stock: stockMap.value[i.product_id] ?? 0, unit_usage: i.unit_usage, total_usage: i.total_usage, unit_cost: Number(i.unit_cost), total_cost: Number(i.total_cost) })) })
    await fetchStockMap(d.warehouse_id); refreshStocks(); dialogVisible.value = true
  }
}

const buildPayload = (submit) => ({
  parent_product_id: form.parent_product_id, warehouse_id: form.warehouse_id, quantity: form.quantity,
  assembly_date: form.assembly_date, remark: form.remark, submit_for_approval: submit,
  items: form.items.filter(i => i.product_id).map(i => ({ product_id: i.product_id, unit_usage: i.unit_usage, unit_cost: i.unit_cost })),
})
const handleSaveDraft = async () => {
  if (!form.parent_product_id) return ElMessage.warning('请选择父件商品')
  if (!form.items.length) return ElMessage.warning('请添加子件')
  const shortage = form.items.find(i => i.current_stock < i.total_usage)
  if (shortage) return ElMessage.warning(`子件「${shortage.product_name}」库存不足`)
  try {
    if (isEdit.value) await businessApi.assembly.update.put(editId.value, buildPayload(false))
    else await businessApi.assembly.create.post(buildPayload(false))
    ElMessage.success('保存成功'); dialogVisible.value = false; fetchData()
  } catch (e) { ElMessage.error(e?.response?.data?.message || '操作失败') }
}
const handleSubmitClick = async () => {
  if (!form.parent_product_id) return ElMessage.warning('请选择父件商品')
  if (!form.items.length) return ElMessage.warning('请添加子件')
  const shortage = form.items.find(i => i.current_stock < i.total_usage)
  if (shortage) return ElMessage.warning(`子件「${shortage.product_name}」库存不足，无法提交`)
  try {
    if (isEdit.value) await businessApi.assembly.update.put(editId.value, buildPayload(true))
    else await businessApi.assembly.create.post(buildPayload(true))
    ElMessage.success('已提交审核'); dialogVisible.value = false; fetchData()
  } catch (e) { ElMessage.error(e?.response?.data?.message || '操作失败') }
}
const handleSubmit = async (row) => { try { await ElMessageBox.confirm('确认提交审核？', '提示'); await businessApi.assembly.submit.post(row.id); ElMessage.success('已提交'); fetchData() } catch (e) { if (e !== 'cancel') ElMessage.error('操作失败') } }
const handleCancel = async (row) => { try { await ElMessageBox.confirm('确认取消该组装单？', '提示'); await businessApi.assembly.cancel.post(row.id); ElMessage.success('已取消'); fetchData() } catch (e) { if (e !== 'cancel') ElMessage.error('操作失败') } }
const handleBatchApprove = async () => {
  const ids = selectedRows.value.map(r => r.id)
  if (!ids.length) { ElMessage.warning('请勾选待审核的组装单'); return }
  try {
    await ElMessageBox.confirm(`批量审核 ${ids.length} 张组装单？通过后将逐一执行子件出库、父件入库并结转成本，任一单据库存不足将整体回滚`, '提示', { type: 'warning' })
    const res = await businessApi.assembly.batchApprove.post({ ids })
    if (res.code === 200) {
      ElMessage.success(`批量审核完成：成功 ${res.data.approved} 张，跳过 ${res.data.skipped.length} 张`)
      onCheckboxChange(); fetchData()
    }
  } catch (e) { if (e !== 'cancel') ElMessage.error(e?.response?.data?.message || '批量审核失败') }
}

const approveVisible = ref(false)
const current = ref(null)
const approveComment = ref('')
const handleApprove = (row) => { current.value = row; approveComment.value = ''; approveVisible.value = true }
const doApprove = async () => { try { await ElMessageBox.confirm('确认通过审核？将自动子件出库、父件入库并结转成本', '提示', { type: 'warning' }); await businessApi.assembly.approve.post(current.value.id, { approval_comment: approveComment.value }); ElMessage.success('审核通过'); approveVisible.value = false; fetchData() } catch (e) { if (e !== 'cancel') ElMessage.error('操作失败') } }
const doReject = async () => { try { await businessApi.assembly.reject.post(current.value.id, { approval_comment: approveComment.value }); ElMessage.success('已驳回'); approveVisible.value = false; fetchData() } catch { ElMessage.error('操作失败') } }

const detailVisible = ref(false)
const detail = ref(null)
const handleView = async (row) => { try { const res = await businessApi.assembly.detail.get(row.id); if (res.code === 200) { detail.value = res.data; detailVisible.value = true } } catch { ElMessage.error('加载失败') } }

const handleExport = async () => {
  const params = new URLSearchParams({ warehouse_id: filters.warehouse_id || '', start_date: filters.start_date || '', end_date: filters.end_date || '' })
  const token = localStorage.getItem('laradmin_token') || localStorage.getItem('token')
  const res = await fetch(`/admin/business/assembly/export?${params}`, { headers: { Authorization: `Bearer ${token}` } })
  if (res.ok) { const blob = await res.blob(); const url = URL.createObjectURL(blob); const a = document.createElement('a'); a.href = url; a.download = `组装单_${new Date().toISOString().slice(0, 10)}.csv`; a.click(); URL.revokeObjectURL(url) }
}

const statusLabel = (s) => ({ draft: '草稿', pending: '待审核', approved: '已审核', cancelled: '已取消' }[s] || s)

onMounted(() => { fetchData(); fetchWarehouses(); fetchProducts(); fetchUsers() })
</script>

<style scoped>
.filter-bar { display: flex; align-items: flex-end; gap: 16px; padding: 16px 24px; background: #fff; border-radius: 4px; margin-top: 16px; flex-wrap: wrap; }
.filter-item { display: flex; flex-direction: column; gap: 4px; }
.filter-item label { font-size: 14px; color: #333; }
.return-table { width: 100%; }
.pagination-bar { height: 50px; display: flex; justify-content: flex-end; align-items: center; padding: 0 24px; gap: 16px; }
.form-section { padding: 16px 24px; border-bottom: 1px solid #f0f0f0; }
.section-title { height: 32px; background: #f6ffed; color: #333; font-size: 14px; padding: 0 12px; display: flex; align-items: center; margin-bottom: 12px; border-left: 3px solid #52c41a; }
.form-row { display: flex; flex-wrap: wrap; gap: 20px; align-items: flex-end; }
.form-field { display: flex; flex-direction: column; gap: 4px; }
.form-field label { font-size: 14px; color: #333; }
.required { color: #f5222d; margin-right: 2px; }
.parent-card { margin-top: 12px; padding: 10px 16px; background: #f6ffed; border: 1px solid #b7eb8f; border-radius: 4px; font-size: 14px; }
.item-table { width: 100%; }
.item-footer { height: 44px; background: #fafafa; border-top: 2px solid #e8e8e8; display: flex; align-items: center; padding: 0 16px; gap: 24px; }
</style>
