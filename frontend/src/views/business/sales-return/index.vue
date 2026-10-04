<template>
  <sPageSplit side-title="销售退货">
    <!-- 筛选区 -->
    <div class="filter-bar">
      <div class="filter-item">
        <label>退货单号</label>
        <el-input v-model="filters.return_no" placeholder="输入退货单号" style="width:180px;height:34px" clearable />
      </div>
      <div class="filter-item">
        <label>原订单号</label>
        <el-input v-model="filters.order_no" placeholder="输入原销售订单号" style="width:180px;height:34px" clearable />
      </div>
      <div class="filter-item">
        <label>客户</label>
        <el-select v-model="filters.customer_id" placeholder="全部客户" filterable clearable style="width:200px;height:34px">
          <el-option v-for="c in customers" :key="c.id" :label="c.name" :value="c.id" />
        </el-select>
      </div>
      <div class="filter-item">
        <label>退货仓库</label>
        <el-select v-model="filters.warehouse_id" placeholder="全部仓库" clearable style="width:140px;height:34px">
          <el-option v-for="w in warehouses" :key="w.id" :label="w.name" :value="w.id" />
        </el-select>
      </div>
      <div class="filter-item">
        <label>状态</label>
        <el-select v-model="filters.status" placeholder="全部" clearable style="width:120px;height:34px">
          <el-option label="待审核" value="pending" />
          <el-option label="已审核" value="approved" />
          <el-option label="已取消" value="cancelled" />
        </el-select>
      </div>
      <div class="filter-item">
        <label>退货日期</label>
        <el-date-picker v-model="filters.start_date" type="date" placeholder="开始" value-format="YYYY-MM-DD" style="width:140px;height:34px" />
        <span style="color:#999;margin:0 4px;line-height:34px">至</span>
        <el-date-picker v-model="filters.end_date" type="date" placeholder="结束" value-format="YYYY-MM-DD" style="width:140px;height:34px" />
      </div>
      <el-button type="primary" style="width:80px;height:34px;margin-left:8px" @click="handleSearch">查询</el-button>
      <el-button style="width:80px;height:34px" @click="handleReset">重置</el-button>
      <div style="flex:1" />
      <el-button type="primary" style="width:120px;height:36px;background:#1890ff;border-color:#1890ff" @click="handleCreate">
        <el-icon><Plus /></el-icon>
        新增退货单
      </el-button>
      <el-button style="width:100px;height:36px;color:#52c41a;border-color:#52c41a" @click="handleExport">
        <el-icon><Download /></el-icon>
        导出Excel
      </el-button>
    </div>

    <!-- 列表表格 -->
    <vxe-table
      ref="listTable"
      v-loading="loading"
      :data="tableData"
      :column-config="{ drag: true, resizable: true }"
      :row-config="{ keyField: 'id', isHover: true }"
      border
      show-header-border
      class="return-table"
    >
      <vxe-column title="退货单号" field="return_no" width="180">
        <template #default="{ row }">
          <a style="color:#1890ff;cursor:pointer;text-decoration:none" @click="handleView(row)">{{ row.return_no }}</a>
        </template>
      </vxe-column>
      <vxe-column title="原订单号" field="order_no" width="180">
        <template #default="{ row }">
          <a v-if="row.order_no" style="color:#1890ff;cursor:pointer;text-decoration:none" @click="handleViewOrder(row)">{{ row.order_no }}</a>
          <span v-else>-</span>
        </template>
      </vxe-column>
      <vxe-column title="客户名称" width="160">
        <template #default="{ row }">{{ row.customer_name || row.customer?.name || '-' }}</template>
      </vxe-column>
      <vxe-column title="退货仓库" width="100">
        <template #default="{ row }">{{ row.warehouse?.name || '-' }}</template>
      </vxe-column>
      <vxe-column title="退货日期" field="return_date" width="120" align="center">
        <template #default="{ row }">{{ row.return_date || '-' }}</template>
      </vxe-column>
      <vxe-column title="商品种类" field="total_skus" width="80" align="center" />
      <vxe-column title="退货数量" field="total_qty" width="100" align="right">
        <template #default="{ row }"><span style="color:#f5222d">{{ row.total_qty }}</span></template>
      </vxe-column>
      <vxe-column title="退货金额" field="total_amount" width="120" align="right">
        <template #default="{ row }"><span style="color:#f5222d;font-weight:bold">¥{{ Number(row.total_amount).toFixed(2) }}</span></template>
      </vxe-column>
      <vxe-column title="状态" field="status" width="100" align="center">
        <template #default="{ row }">
          <span v-if="row.status === 'pending'" style="background:#fffbe6;color:#faad14;padding:2px 10px;border-radius:4px;font-size:12px">待审核</span>
          <span v-else-if="row.status === 'approved'" style="background:#f6ffed;color:#52c41a;padding:2px 10px;border-radius:4px;font-size:12px">已审核</span>
          <span v-else style="background:#f5f5f5;color:#999;padding:2px 10px;border-radius:4px;font-size:12px">已取消</span>
        </template>
      </vxe-column>
      <vxe-column title="制单人" width="100" align="center">
        <template #default="{ row }">{{ row.creator?.real_name || row.creator?.username || '-' }}</template>
      </vxe-column>
      <vxe-column title="审核人" width="100" align="center">
        <template #default="{ row }">{{ row.approver?.real_name || row.approver?.username || '-' }}</template>
      </vxe-column>
      <vxe-column title="操作" width="200" align="center" fixed="right">
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

    <!-- 分页 -->
    <div class="pagination-bar">
      <span style="color:#666;font-size:14px">共 {{ pagination.total }} 条</span>
      <el-pagination
        background
        layout="prev, pager, next, jumper"
        :total="pagination.total"
        :page-size="pagination.page_size"
        :current-page="pagination.page"
        @current-change="handlePageChange"
        @size-change="handleSizeChange"
      />
    </div>

    <!-- 新增/编辑弹窗 -->
    <el-dialog
      v-model="dialogVisible"
      :title="dialogTitle"
      width="1000px"
      top="3vh"
      destroy-on-close
      :close-on-click-modal="false"
    >
      <!-- 基本信息区 -->
      <div class="form-section">
        <div class="section-title">基本信息</div>
        <div class="form-row">
          <div class="form-field">
            <label><span class="required">*</span>原销售订单</label>
            <el-select v-model="form.order_id" placeholder="请选择原销售订单" filterable clearable style="width:320px;height:34px" @change="onOrderChange">
              <el-option v-for="o in orders" :key="o.id" :label="`${o.order_no} | ${o.customer?.name || ''} | ${o.order_date} | ¥${Number(o.total_amount).toFixed(2)}`" :value="o.id" />
            </el-select>
          </div>
          <div class="form-field">
            <label>客户</label>
            <el-input :model-value="form.customer_name" disabled style="width:200px;height:34px;background:#f5f5f5" />
          </div>
          <div class="form-field">
            <label><span class="required">*</span>退货仓库</label>
            <el-select v-model="form.warehouse_id" placeholder="选择仓库" style="width:160px;height:34px">
              <el-option v-for="w in warehouses" :key="w.id" :label="w.name" :value="w.id" />
            </el-select>
          </div>
          <div class="form-field">
            <label><span class="required">*</span>退货日期</label>
            <el-date-picker v-model="form.return_date" type="date" value-format="YYYY-MM-DD" style="width:160px;height:34px" />
          </div>
          <div class="form-field">
            <label>退货类型</label>
            <el-select v-model="form.return_type" style="width:140px;height:34px">
              <el-option label="质量问题" value="quality" />
              <el-option label="客户取消" value="cancel" />
              <el-option label="配送损坏" value="damage" />
              <el-option label="其他" value="other" />
            </el-select>
          </div>
          <div class="form-field">
            <label>备注</label>
            <el-input v-model="form.remark" placeholder="可选填退货原因说明" style="width:240px;height:34px" />
          </div>
        </div>
      </div>

      <!-- 退货商品明细区 -->
      <div class="form-section" v-loading="itemsLoading">
        <div class="section-title">退货商品明细</div>
        <div class="item-hint" v-if="form.order_id">
          ⚠️ 请勾选需要退货的商品，修改退货数量（不能超过可退数量），退货金额自动计算
        </div>
        <div v-else style="padding:8px;color:#999;font-size:12px">请先选择原销售订单</div>

        <vxe-table
          v-if="form.order_id && returnItems.length > 0"
          :data="returnItems"
          :row-config="{ keyField: 'product_id', isHover: true }"
          border
          show-header-border
          size="small"
          class="item-table"
        >
          <vxe-column type="checkbox" width="50" align="center" />
          <vxe-column title="商品编码" field="product_code" width="120" />
          <vxe-column title="商品名称" field="product_name" width="200">
            <template #default="{ row }"><b>{{ row.product_name }}</b></template>
          </vxe-column>
          <vxe-column title="规格" field="spec" width="100" />
          <vxe-column title="单位" field="unit" width="60" align="center" />
          <vxe-column title="原订单数量" field="order_qty" width="100" align="right">
            <template #default="{ row }">{{ row.order_qty }}</template>
          </vxe-column>
          <vxe-column title="已退数量" width="80" align="right">
            <template #default="{ row }"><span style="color:#999">{{ row.returned_qty }}</span></template>
          </vxe-column>
          <vxe-column title="可退数量" width="80" align="right">
            <template #default="{ row }"><span style="color:#666">{{ row.returnable_qty }}</span></template>
          </vxe-column>
          <vxe-column title="退货数量" width="120" align="center">
            <template #default="{ row }">
              <el-input-number
                v-model="row.return_qty"
                :min="0"
                :max="row.returnable_qty"
                size="small"
                controls-position="right"
                style="width:80px;height:28px"
                @change="recalcItemAmount(row)"
                :class="{ 'is-error': row.return_qty > row.returnable_qty }"
              />
            </template>
          </vxe-column>
          <vxe-column title="退货单价" width="100" align="right">
            <template #default="{ row }">
              <el-input-number
                v-model="row.return_price"
                :precision="2"
                :min="0"
                size="small"
                controls-position="right"
                style="width:80px;height:28px"
                @change="recalcItemAmount(row)"
              />
            </template>
          </vxe-column>
          <vxe-column title="退货金额" width="120" align="right">
            <template #default="{ row }">
              <span style="color:#f5222d;font-weight:bold">¥{{ Number(row.return_amount).toFixed(2) }}</span>
            </template>
          </vxe-column>
        </vxe-table>

        <!-- 合计行 -->
        <div v-if="returnItems.length > 0" class="item-footer">
          <span class="item-footer-label">合计</span>
          <span class="item-footer-value">已选 {{ selectedItemsCount }} 种商品</span>
          <span class="item-footer-value text-red">退货数量：{{ totalReturnQty }}</span>
          <span class="item-footer-value text-red">退货金额：¥{{ totalReturnAmount.toFixed(2) }}</span>
        </div>
      </div>

      <template #footer>
        <div style="display:flex;align-items:center;justify-content:space-between;width:100%">
          <div v-if="totalReturnQty > 0" style="color:#f5222d;font-size:14px">
            <span>已选 {{ selectedItemsCount }} 种商品</span>
            <span style="margin-left:16px">退货数量合计：{{ totalReturnQty }}</span>
            <span style="margin-left:16px;font-weight:bold">退货金额合计：¥{{ totalReturnAmount.toFixed(2) }}</span>
          </div>
          <div>
            <el-button style="width:80px;height:36px;margin-right:12px" @click="dialogVisible = false">取消</el-button>
            <el-button style="width:100px;height:36px;color:#1890ff;border-color:#1890ff" @click="handleSaveDraft">保存草稿</el-button>
            <el-button type="warning" style="width:100px;height:36px;background:#f0ad4e;border-color:#f0ad4e;color:#fff" @click="handleSubmitClick">提交审核</el-button>
          </div>
        </div>
      </template>
    </el-dialog>

    <!-- 审核弹窗 -->
    <el-dialog v-model="approveVisible" title="退货单审核" width="900px" top="3vh" destroy-on-close>
      <div v-if="currentReturn">
        <div class="section-title">基本信息</div>
        <el-descriptions :column="3" border size="small" style="margin-bottom:16px">
          <el-descriptions-item label="退货单号">{{ currentReturn.return_no }}</el-descriptions-item>
          <el-descriptions-item label="原订单号">{{ currentReturn.order_no }}</el-descriptions-item>
          <el-descriptions-item label="客户">{{ currentReturn.customer_name }}</el-descriptions-item>
          <el-descriptions-item label="退货仓库">{{ currentReturn.warehouse?.name }}</el-descriptions-item>
          <el-descriptions-item label="退货日期">{{ currentReturn.return_date }}</el-descriptions-item>
          <el-descriptions-item label="退货类型">{{ returnTypeLabel(currentReturn.return_type) }}</el-descriptions-item>
          <el-descriptions-item label="制单人">{{ currentReturn.creator?.real_name || '-' }}</el-descriptions-item>
          <el-descriptions-item label="备注" :span="2">{{ currentReturn.remark || '-' }}</el-descriptions-item>
        </el-descriptions>

        <div class="section-title">退货金额汇总</div>
        <div class="summary-cards">
          <div class="summary-card">
            <div class="summary-card-label" style="color:#f5222d">退货商品</div>
            <div class="summary-card-value" style="color:#f5222d">{{ currentReturn.total_skus }} 种</div>
            <div class="summary-card-sub">退货总数量：{{ currentReturn.total_qty }}</div>
          </div>
          <div class="summary-card">
            <div class="summary-card-label" style="color:#f5222d">退货金额</div>
            <div class="summary-card-value" style="color:#f5222d">¥{{ Number(currentReturn.total_amount).toFixed(2) }}</div>
            <div class="summary-card-sub">将冲减应收账款</div>
          </div>
        </div>

        <div class="section-title">退货明细</div>
        <vxe-table :data="currentReturn?.items || []" border show-header-border size="small" style="margin-bottom:16px">
          <vxe-column title="商品名称" field="product_name" width="200" />
          <vxe-column title="规格" field="spec" width="100" />
          <vxe-column title="单位" field="unit" width="60" align="center" />
          <vxe-column title="原订单数量" field="order_qty" width="100" align="right" />
          <vxe-column title="退货数量" width="100" align="right">
            <template #default="{ row }"><span style="color:#f5222d">{{ row.return_qty }}</span></template>
          </vxe-column>
          <vxe-column title="退货单价" field="return_price" width="100" align="right" />
          <vxe-column title="退货金额" width="120" align="right">
            <template #default="{ row }"><span style="color:#f5222d;font-weight:bold">¥{{ Number(row.return_amount).toFixed(2) }}</span></template>
          </vxe-column>
        </vxe-table>

        <div class="section-title">审核意见</div>
        <el-input v-model="approveComment" type="textarea" :rows="3" placeholder="请输入审核意见（可选）" style="margin-bottom:16px" />
      </div>
      <template #footer>
        <el-button style="width:80px;height:36px;margin-right:12px" @click="approveVisible = false">取消</el-button>
        <el-button type="danger" style="width:100px;height:36px;color:#f5222d;border-color:#f5222d" @click="doReject">驳回</el-button>
        <el-button type="success" style="width:100px;height:36px;background:#52c41a;border-color:#52c41e" @click="doApprove">通过审核</el-button>
      </template>
    </el-dialog>

    <!-- 详情弹窗 -->
    <el-dialog v-model="detailVisible" title="退货单详情" width="900px" top="3vh" destroy-on-close>
      <div v-if="detailData">
        <div class="section-title">基本信息</div>
        <el-descriptions :column="3" border size="small" style="margin-bottom:16px">
          <el-descriptions-item label="退货单号">{{ detailData.return_no }}</el-descriptions-item>
          <el-descriptions-item label="原订单号">{{ detailData.order_no }}</el-descriptions-item>
          <el-descriptions-item label="客户">{{ detailData.customer_name }}</el-descriptions-item>
          <el-descriptions-item label="退货仓库">{{ detailData.warehouse?.name }}</el-descriptions-item>
          <el-descriptions-item label="退货日期">{{ detailData.return_date }}</el-descriptions-item>
          <el-descriptions-item label="状态">
            <span v-if="detailData.status === 'pending'" style="background:#fffbe6;color:#faad14;padding:2px 8px;border-radius:4px;font-size:12px">待审核</span>
            <span v-else-if="detailData.status === 'approved'" style="background:#f6ffed;color:#52c41a;padding:2px 8px;border-radius:4px;font-size:12px">已审核</span>
            <span v-else style="background:#f5f5f5;color:#999;padding:2px 8px;border-radius:4px;font-size:12px">已取消</span>
          </el-descriptions-item>
          <el-descriptions-item label="制单人">{{ detailData.creator?.real_name || '-' }}</el-descriptions-item>
          <el-descriptions-item label="审核人">{{ detailData.approver?.real_name || '-' }}</el-descriptions-item>
          <el-descriptions-item label="备注" :span="3">{{ detailData.remark || '-' }}</el-descriptions-item>
        </el-descriptions>

        <div class="section-title">退货明细</div>
        <vxe-table :data="detailData.items || []" border show-header-border size="small">
          <vxe-column title="商品名称" field="product_name" width="200" />
          <vxe-column title="规格" field="spec" width="100" />
          <vxe-column title="单位" field="unit" width="60" align="center" />
          <vxe-column title="原订单数量" field="order_qty" width="100" align="right" />
          <vxe-column title="本次退货数量" width="120" align="right">
            <template #default="{ row }"><span style="color:#f5222d;font-weight:bold">{{ row.return_qty }}</span></template>
          </vxe-column>
          <vxe-column title="退货单价" field="return_price" width="100" align="right" />
          <vxe-column title="退货金额" width="120" align="right">
            <template #default="{ row }"><span style="color:#f5222d;font-weight:bold">¥{{ Number(row.return_amount).toFixed(2) }}</span></template>
          </vxe-column>
        </vxe-table>
      </div>
      <template #footer>
        <el-button style="width:80px;height:36px" @click="detailVisible = false">关闭</el-button>
      </template>
    </el-dialog>
  </sPageSplit>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus, Download } from '@element-plus/icons-vue'
import sPageSplit from '@/components/sPageSplit/index.vue'
import businessApi from '@/api/business'

// ==================== 列表 ====================
const loading = ref(false)
const tableData = ref([])
const customers = ref([])
const warehouses = ref([])

const filters = reactive({
  return_no: '',
  order_no: '',
  customer_id: null,
  warehouse_id: null,
  status: null,
  start_date: '',
  end_date: '',
})

const pagination = reactive({
  page: 1,
  page_size: 20,
  total: 0,
})

const fetchData = async () => {
  loading.value = true
  try {
    const res = await businessApi.salesReturn.list.get({ ...filters, page: pagination.page, page_size: pagination.page_size })
    tableData.value = res.data?.list || []
    pagination.total = res.data?.total || 0
  } catch {
    ElMessage.error('加载失败')
  } finally {
    loading.value = false
  }
}

const fetchCustomers = async () => {
  try {
    const res = await businessApi.customer.list.get({ page_size: 1000 })
    customers.value = res.data?.list || []
  } catch {}
}

const fetchWarehouses = async () => {
  try {
    const res = await businessApi.warehouse.list.get({ page_size: 100 })
    warehouses.value = res.data?.list || []
  } catch {}
}

const handleSearch = () => { pagination.page = 1; fetchData() }
const handleReset = () => {
  Object.assign(filters, { return_no: '', order_no: '', customer_id: null, warehouse_id: null, status: null, start_date: '', end_date: '' })
  handleSearch()
}
const handlePageChange = (page) => { pagination.page = page; fetchData() }
const handleSizeChange = (size) => { pagination.page_size = size; pagination.page = 1; fetchData() }

// ==================== 新增/编辑弹窗 ====================
const dialogVisible = ref(false)
const dialogTitle = ref('')
const isEdit = ref(false)
const editId = ref(null)
const itemsLoading = ref(false)

const form = reactive({
  order_id: null,
  customer_id: null,
  customer_name: '',
  warehouse_id: null,
  return_date: new Date().toISOString().slice(0, 10),
  return_type: 'quality',
  remark: '',
})

const orders = ref([])
const returnItems = ref([])

const selectedItemsCount = computed(() => returnItems.value.filter(i => i.return_qty > 0).length)
const totalReturnQty = computed(() => returnItems.value.reduce((s, i) => s + (i.return_qty || 0), 0))
const totalReturnAmount = computed(() => returnItems.value.reduce((s, i) => s + (i.return_amount || 0), 0))

const loadOrderProducts = async (orderId) => {
  if (!orderId) {
    returnItems.value = []
    return
  }
  itemsLoading.value = true
  try {
    const res = await businessApi.salesReturn.orderProducts.get({ order_id: orderId })
    if (res.code === 200) {
      form.customer_id = res.data.order.customer_id
      form.customer_name = res.data.order.customer_name
      form.warehouse_id = res.data.order.warehouse_id
      returnItems.value = (res.data.items || []).map(i => ({
        ...i,
        return_qty: 0,
        return_price: i.price,
        return_amount: 0,
      }))
    }
  } catch {
    ElMessage.error('加载商品失败')
  } finally {
    itemsLoading.value = false
  }
}

const onOrderChange = async (orderId) => {
  await loadOrderProducts(orderId)
}

const recalcItemAmount = (row) => {
  row.return_amount = parseFloat((row.return_qty * row.return_price).toFixed(2))
}

const handleCreate = () => {
  isEdit.value = false
  dialogTitle.value = '新增销售退货单'
  Object.assign(form, {
    order_id: null, customer_id: null, customer_name: '',
    warehouse_id: null, return_date: new Date().toISOString().slice(0, 10),
    return_type: 'quality', remark: '',
  })
  returnItems.value = []
  dialogVisible.value = true
}

const handleEdit = (row) => {
  isEdit.value = true
  editId.value = row.id
  dialogTitle.value = '编辑退货单'
  Object.assign(form, {
    order_id: row.order_id,
    customer_id: row.customer_id,
    customer_name: row.customer_name,
    warehouse_id: row.warehouse_id,
    return_date: row.return_date,
    return_type: row.return_type,
    remark: row.remark,
  })
  // 加载原订单商品
  loadOrderProducts(row.order_id).then(() => {
    // 恢复已选择的退货商品
    returnItems.value.forEach(item => {
      const existing = row.items?.find(i => i.product_id === item.product_id)
      if (existing) {
        item.return_qty = existing.return_qty
        item.return_price = existing.return_price
        item.return_amount = existing.return_amount
      }
      recalcItemAmount(item)
    })
  })
  dialogVisible.value = true
}

const handleSaveDraft = async () => {
  if (!form.order_id) { ElMessage.warning('请选择原销售订单'); return }
  if (returnItems.value.length === 0) { ElMessage.warning('请添加退货商品'); return }
  if (totalReturnQty.value === 0) { ElMessage.warning('请选择退货数量'); return }

  const payload = {
    order_id: form.order_id,
    warehouse_id: form.warehouse_id,
    return_date: form.return_date,
    return_type: form.return_type,
    remark: form.remark,
    items: returnItems.value.filter(i => i.return_qty > 0).map(i => ({
      product_id: i.product_id,
      return_qty: i.return_qty,
      return_price: i.return_price,
    })),
  }

  try {
    if (isEdit.value && editId.value) {
      await businessApi.salesReturn.update.put(editId.value, payload)
      ElMessage.success('更新成功')
    } else {
      await businessApi.salesReturn.create.post(payload)
      ElMessage.success('创建成功')
    }
    dialogVisible.value = false
    fetchData()
  } catch (e) {
    ElMessage.error(e?.response?.data?.message || '操作失败')
  }
}

const handleSubmitClick = async () => {
  if (!form.order_id) { ElMessage.warning('请选择原销售订单'); return }
  if (returnItems.value.length === 0) { ElMessage.warning('请添加退货商品'); return }
  if (totalReturnQty.value === 0) { ElMessage.warning('请选择退货数量'); return }

  try {
    await ElMessageBox.confirm('确认提交退货单？提交后将进入审核流程', '提示', { type: 'warning' })
  } catch { return }

  const payload = {
    order_id: form.order_id,
    warehouse_id: form.warehouse_id,
    return_date: form.return_date,
    return_type: form.return_type,
    remark: form.remark,
    submit_for_approval: true,
    items: returnItems.value.filter(i => i.return_qty > 0).map(i => ({
      product_id: i.product_id,
      return_qty: i.return_qty,
      return_price: i.return_price,
    })),
  }

  try {
    if (isEdit.value && editId.value) {
      await businessApi.salesReturn.update.put(editId.value, payload)
    } else {
      await businessApi.salesReturn.create.post(payload)
    }
    dialogVisible.value = false
    fetchData()
  } catch (e) {
    ElMessage.error(e?.response?.data?.message || '操作失败')
  }
}

const handleSubmit = async (row) => {
  try {
    await ElMessageBox.confirm('确认提交审核？', '提示')
    await businessApi.salesReturn.submit.post(row.id)
    ElMessage.success('已提交审核')
    fetchData()
  } catch (e) {
    if (e !== 'cancel') ElMessage.error('操作失败')
  }
}

const handleCancel = async (row) => {
  try {
    await ElMessageBox.confirm('确认取消该退货单？', '提示')
    await businessApi.salesReturn.cancel.post(row.id)
    ElMessage.success('已取消')
    fetchData()
  } catch (e) {
    if (e !== 'cancel') ElMessage.error('操作失败')
  }
}

// ==================== 审核弹窗 ====================
const approveVisible = ref(false)
const currentReturn = ref(null)
const approveComment = ref('')

const handleApprove = (row) => {
  currentReturn.value = row
  approveComment.value = ''
  approveVisible.value = true
}

const doApprove = async () => {
  try {
    await ElMessageBox.confirm('确认通过审核？通过后将自动退货入库、冲减应收账款并生成财务凭证', '提示', { type: 'warning' })
    await businessApi.salesReturn.approve.post(currentReturn.value.id, { approval_comment: approveComment.value })
    ElMessage.success('审核通过')
    approveVisible.value = false
    fetchData()
  } catch (e) {
    if (e !== 'cancel') ElMessage.error('操作失败')
  }
}

const doReject = async () => {
  try {
    await businessApi.salesReturn.reject.post(currentReturn.value.id, { approval_comment: approveComment.value })
    ElMessage.success('已驳回')
    approveVisible.value = false
    fetchData()
  } catch (e) {
    ElMessage.error('操作失败')
  }
}

// ==================== 详情弹窗 ====================
const detailVisible = ref(false)
const detailData = ref(null)

const handleView = async (row) => {
  try {
    const res = await businessApi.salesReturn.detail.get(row.id)
    if (res.code === 200) {
      detailData.value = res.data
      detailVisible.value = true
    }
  } catch {
    ElMessage.error('加载详情失败')
  }
}

const handleViewOrder = (row) => {
  if (row.order_id) {
    window.open(`/admin/business/sales-order/${row.order_id}`, '_blank')
  }
}

// ==================== 导出 ====================
const handleExport = async () => {
  const params = new URLSearchParams({
    customer_id: filters.customer_id || '',
    start_date: filters.start_date || '',
    end_date: filters.end_date || '',
  })
  const token = localStorage.getItem('token')
  const res = await fetch(`/admin/business/sales-return/export?${params}`, {
    headers: { Authorization: `Bearer ${token}` },
  })
  if (res.ok) {
    const blob = await res.blob()
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `销售退货单_${new Date().toISOString().slice(0, 10)}.csv`
    a.click()
    URL.revokeObjectURL(url)
  }
}

const returnTypeLabel = (type) => {
  const map = { quality: '质量问题', cancel: '客户取消', damage: '配送损坏', other: '其他' }
  return map[type] || type
}

onMounted(() => {
  fetchData()
  fetchCustomers()
  fetchWarehouses()
})
</script>

<style scoped>
.filter-bar {
  display: flex;
  align-items: flex-end;
  gap: 16px;
  padding: 16px 24px;
  background: #fff;
  border-radius: 4px;
  margin-top: 16px;
  flex-wrap: wrap;
}

.filter-item {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.filter-item label {
  font-size: 14px;
  color: #333;
}

.return-table {
  width: 100%;
}

.pagination-bar {
  height: 50px;
  display: flex;
  justify-content: flex-end;
  align-items: center;
  padding: 0 24px;
}

.form-section {
  padding: 16px 24px;
  border-bottom: 1px solid #f0f0f0;
}

.section-title {
  height: 32px;
  background: #f5f5f5;
  color: #333;
  font-size: 14px;
  padding: 0 12px;
  display: flex;
  align-items: center;
  margin-bottom: 12px;
  border-left: 3px solid #1890ff;
}

.form-row {
  display: flex;
  flex-wrap: wrap;
  gap: 20px;
  align-items: flex-end;
}

.form-field {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.form-field label {
  font-size: 14px;
  color: #333;
}

.required {
  color: #f5222d;
  margin-right: 2px;
}

.item-hint {
  height: 32px;
  background: #fff7e6;
  border: 1px solid #ffe7ba;
  border-radius: 4px;
  padding: 0 12px;
  display: flex;
  align-items: center;
  color: #fa8c16;
  font-size: 14px;
  margin-bottom: 12px;
}

.item-table {
  width: 100%;
}

.item-footer {
  height: 44px;
  background: #fafafa;
  border-top: 2px solid #e8e8e8;
  display: flex;
  align-items: center;
  padding: 0 16px;
  gap: 24px;
}

.item-footer-label {
  font-size: 14px;
  color: #333;
  font-weight: bold;
}

.item-footer-value {
  font-size: 14px;
  color: #333;
}

.text-red { color: #f5222d; font-weight: bold; }

.summary-cards {
  display: flex;
  gap: 16px;
  margin-bottom: 16px;
}

.summary-card {
  width: 180px;
  height: 80px;
  background: #fff2f0;
  border: 1px solid #ffccc7;
  border-radius: 4px;
  padding: 12px 16px;
}

.summary-card-label {
  font-size: 12px;
  margin-bottom: 8px;
}

.summary-card-value {
  font-size: 24px;
  font-weight: bold;
}

.summary-card-sub {
  font-size: 12px;
  color: #999;
}

.is-error :deep(.el-input-number__el-input) {
  border-color: #f5222d !important;
}
</style>
