<template>
  <sPageSplit side-title="收款管理">
    <!-- 筛选区 -->
    <div class="filter-bar">
      <el-select v-model="filters.customer_id" placeholder="客户" clearable filterable style="width:200px;height:32px">
        <el-option v-for="c in customers" :key="c.id" :label="c.name" :value="c.id" />
      </el-select>
      <el-select v-model="filters.status" placeholder="状态" clearable style="width:120px;height:32px;margin-left:8px">
        <el-option label="全部" :value="null" />
        <el-option label="待审核" :value="0" />
        <el-option label="已审核" :value="1" />
        <el-option label="已红冲" :value="2" />
      </el-select>
      <el-date-picker v-model="filters.start_date" type="date" placeholder="开始日期" value-format="YYYY-MM-DD" style="width:140px;height:32px;margin-left:8px" />
      <span style="color:#999;margin:0 4px;line-height:32px">~</span>
      <el-date-picker v-model="filters.end_date" type="date" placeholder="结束日期" value-format="YYYY-MM-DD" style="width:140px;height:32px" />
      <el-button type="primary" style="width:80px;height:32px;margin-left:8px" @click="handleSearch">查询</el-button>
      <el-button style="width:80px;height:32px;margin-left:4px" @click="handleReset">重置</el-button>
      <div style="flex:1" />
      <el-button type="success" style="width:120px;height:32px;background:#52c41a;border-color:#52c41a" @click="handleCreate">+ 新增收款</el-button>
    </div>

    <!-- 列表 -->
    <vxe-table
      ref="listTable"
      v-loading="loading"
      :data="tableData"
      :column-config="{ drag: true, resizable: true }"
      :row-config="{ keyField: 'id', isHover: true }"
      :scroll-y="{ enabled: true, gt: 0 }"
      height="auto"
      border
      show-header-border
      class="receive-table"
    >
      <vxe-column title="收款单号" field="receive_no" width="180" fixed="left">
        <template #default="{ row }">
          <a style="color:#1890ff;cursor:pointer;text-decoration:none" @click="handleView(row)">{{ row.receive_no }}</a>
        </template>
      </vxe-column>
      <vxe-column title="客户" field="customer.name" width="150">
        <template #default="{ row }">{{ row.customer?.name || '-' }}</template>
      </vxe-column>
      <vxe-column title="收款金额" field="amount" width="120" align="right">
        <template #default="{ row }">
          <span style="color:#52c41a;font-weight:bold">¥{{ Number(row.amount).toFixed(2) }}</span>
        </template>
      </vxe-column>
      <vxe-column title="收款日期" field="receive_date" width="120" align="center">
        <template #default="{ row }">{{ row.receive_date || '-' }}</template>
      </vxe-column>
      <vxe-column title="支付方式" field="payment_method" width="100" align="center">
        <template #default="{ row }">{{ row.payment_method || '现金' }}</template>
      </vxe-column>
      <vxe-column title="状态" field="status" width="100" align="center">
        <template #default="{ row }">
          <span v-if="row.status === 1" style="background:#52c41a;color:#fff;padding:2px 8px;border-radius:10px;font-size:12px">已审核</span>
          <span v-else-if="row.status === 0" style="background:#faad14;color:#fff;padding:2px 8px;border-radius:10px;font-size:12px">待审核</span>
          <span v-else style="background:#999;color:#fff;padding:2px 8px;border-radius:10px;font-size:12px">已红冲</span>
        </template>
      </vxe-column>
      <vxe-column title="备注" field="remark" min-width="150" show-overflow />
      <vxe-column title="操作" width="180" fixed="right" align="center">
        <template #default="{ row }">
          <el-button link type="primary" size="small" @click="handleView(row)">查看</el-button>
          <el-button v-if="row.status === 0" link type="primary" size="small" @click="handleEdit(row)">编辑</el-button>
          <el-button v-if="row.status === 0" link type="success" size="small" @click="handleApprove(row)">审核</el-button>
          <el-button v-if="row.status === 2" link type="danger" size="small" @click="handleRedFlush(row)">红冲</el-button>
          <el-button v-if="row.status === 0" link type="danger" size="small" @click="handleDelete(row)">删除</el-button>
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

    <!-- 新增/编辑收款弹窗 -->
    <el-dialog
      v-model="dialogVisible"
      :title="dialogTitle"
      width="900px"
      top="3vh"
      destroy-on-close
      :close-on-click-modal="false"
    >
      <!-- 区域一：基本信息 -->
      <div class="section-title">基本信息</div>
      <div class="form-row">
        <div class="form-field">
          <label><span class="required">*</span>客户</label>
          <el-select v-model="form.customer_id" placeholder="请选择客户" filterable clearable style="width:240px;height:34px" @change="onCustomerChange">
            <el-option v-for="c in customers" :key="c.id" :label="c.name" :value="c.id" />
          </el-select>
        </div>
        <div class="form-field">
          <label>收款日期</label>
          <el-date-picker v-model="form.receive_date" type="date" value-format="YYYY-MM-DD" style="width:240px;height:34px" />
        </div>
      </div>
      <div class="form-row">
        <div class="form-field">
          <label><span class="required">*</span>支付方式</label>
          <el-select v-model="form.payment_method" style="width:240px;height:34px">
            <el-option label="现金" value="现金" />
            <el-option label="银行转账" value="银行转账" />
            <el-option label="微信" value="微信" />
            <el-option label="支付宝" value="支付宝" />
            <el-option label="POS刷卡" value="POS刷卡" />
          </el-select>
        </div>
        <div class="form-field">
          <label>摘要</label>
          <el-input v-model="form.remark" style="width:240px;height:34px" placeholder="请输入摘要" />
        </div>
      </div>
      <div class="summary-box">
        <span class="summary-item" style="color:#f5222d">应收款：¥{{ receivableTotal }}</span>
        <span class="summary-item">预收款：¥{{ prepayTotal }}</span>
      </div>

      <!-- 区域二：待收款单据 -->
      <div class="section-title">待收款单据</div>
      <div class="order-toolbar">
        <el-button size="small" style="width:120px;height:32px;color:#1890ff;border-color:#1890ff" @click="openOrderSelectDialog">选择欠款明细</el-button>
        <el-button size="small" style="width:140px;height:32px;background:#1890ff;color:#fff;border:none" @click="loadAllPending">加载全部欠款明细</el-button>
      </div>
      <p v-if="form.customer_id && pendingOrders.length === 0" style="color:#faad14;font-size:12px;margin:0 0 8px 0">该客户暂无未收款订单</p>
      <p v-else-if="!form.customer_id" style="color:#999;font-size:12px;margin:0 0 8px 0">请先选择客户</p>
      <p v-else style="color:#faad14;font-size:12px;margin:0 0 8px 0">勾选需要核销的订单，修改本次收款金额，合计自动汇总</p>

      <vxe-table
        v-if="form.customer_id"
        ref="orderTableRef"
        :data="pendingOrders"
        :row-config="{ keyField: 'id', isHover: true }"
        border
        show-header-border
        size="small"
        class="order-table"
        @checkbox-change="onOrderChange"
        @checkbox-all="onOrderChange"
      >
        <vxe-column type="checkbox" width="40" align="center" />
        <vxe-column title="订单号" field="order_no" width="180">
          <template #default="{ row }">{{ row.order_no }}</template>
        </vxe-column>
        <vxe-column title="单据日期" field="order_date" width="120" align="center">
          <template #default="{ row }">{{ row.order_date }}</template>
        </vxe-column>
        <vxe-column title="应收金额" field="total_amount" width="110" align="right">
          <template #default="{ row }"><span style="color:#f5222d">{{ Number(row.total_amount).toFixed(2) }}</span></template>
        </vxe-column>
        <vxe-column title="已收金额" width="110" align="right">
          <template #default="{ row }"><span style="color:#52c41a">{{ Number(row.paid_amount || 0).toFixed(2) }}</span></template>
        </vxe-column>
        <vxe-column title="待收金额" width="110" align="right">
          <template #default="{ row }"><span style="color:#f5222d;font-weight:bold">{{ Number(row.unpaid_amount).toFixed(2) }}</span></template>
        </vxe-column>
        <vxe-column title="本次收款" width="120" align="right">
          <template #default="{ row }">
            <el-input-number
              v-model="row._pay_amount"
              :precision="2"
              :min="0"
              :max="row.unpaid_amount"
              size="small"
              controls-position="right"
              style="width:100px;height:28px"
              @change="recalcTotal"
            />
          </template>
        </vxe-column>
      </vxe-table>

      <!-- 区域三：收款信息 -->
      <div class="section-title">收款信息</div>
      <div class="form-row">
        <div class="form-field">
          <label>收款金额</label>
          <div style="display:flex;align-items:center;width:240px;height:34px">
            <el-input-number v-model="form.amount" :precision="2" :min="0" size="default" controls-position="right" style="flex:1;height:34px" @change="onAmountChange" />
            <span style="margin-left:8px;color:#666;font-size:14px">元</span>
          </div>
        </div>
        <div class="form-field">
          <label>优惠</label>
          <el-input-number v-model="form.discount" :precision="2" :min="0" size="default" controls-position="right" style="width:240px;height:34px" @change="recalcTotal" />
        </div>
      </div>
      <div class="form-row">
        <div class="form-field">
          <label>收款合计</label>
          <div style="width:240px;height:34px;line-height:34px;padding-right:12px;text-align:right;color:#f5222d;font-weight:bold;font-size:16px;background:#f5f5f5;border:1px solid #e4e4e4;border-radius:4px">
            ¥{{ receiveTotal.toFixed(2) }}
          </div>
        </div>
        <div class="form-field">
          <label><span class="required">*</span>现金流量项目</label>
          <el-select v-model="form.cash_flow_item" style="width:240px;height:34px">
            <el-option label="销售商品收到的现金" value="销售商品收到的现金" />
            <el-option label="收到其他与经营活动有关的现金" value="收到其他与经营活动有关的现金" />
          </el-select>
        </div>
      </div>

      <template #footer>
        <el-checkbox v-model="form.auto_print" style="margin-right:20px;color:#333;font-size:14px">按单形成上交款</el-checkbox>
        <el-checkbox v-model="form.print_receipt" style="margin-right:20px;color:#333;font-size:14px">同时打印单据</el-checkbox>
        <el-button style="width:80px;height:36px;border-color:#d9d9d9;color:#666" @click="dialogVisible = false">取消</el-button>
        <el-button type="warning" style="width:80px;height:36px;background:#f0ad4e;border-color:#f0ad4e;color:#fff" @click="handleSubmit">提交</el-button>
      </template>
    </el-dialog>

    <!-- 选择欠款明细弹窗 -->
    <el-dialog
      v-model="orderSelectVisible"
      title="选择欠款订单"
      width="800px"
      top="5vh"
      destroy-on-close
    >
      <div style="margin-bottom:12px;display:flex;gap:8px;align-items:center">
        <el-input v-model="orderSearchKeyword" placeholder="输入订单号搜索" style="width:300px;height:32px" clearable />
        <el-button type="primary" size="small" style="width:80px;height:32px" @click="searchOrders">搜索</el-button>
        <el-button size="small" style="margin-left:auto;width:80px;height:32px" @click="orderSelectVisible = false">取消</el-button>
        <el-button type="primary" size="small" style="width:80px;height:32px" @click="confirmOrderSelect">确定</el-button>
      </div>
      <vxe-table
        ref="orderSelectTableRef"
        :data="orderSearchResults"
        :row-config="{ keyField: 'id', isHover: true }"
        border
        show-header-border
        size="small"
        @checkbox-change="onOrderSelectChange"
        @checkbox-all="onOrderSelectChange"
      >
        <vxe-column type="checkbox" width="40" align="center" />
        <vxe-column title="订单号" field="order_no" width="180" />
        <vxe-column title="单据日期" field="order_date" width="120" align="center">
          <template #default="{ row }">{{ row.order_date }}</template>
        </vxe-column>
        <vxe-column title="应收金额" field="total_amount" width="110" align="right">
          <template #default="{ row }"><span style="color:#f5222d">{{ Number(row.total_amount).toFixed(2) }}</span></template>
        </vxe-column>
        <vxe-column title="已收金额" width="110" align="right">
          <template #default="{ row }"><span style="color:#52c41a">{{ Number(row.paid_amount || 0).toFixed(2) }}</span></template>
        </vxe-column>
        <vxe-column title="待收金额" width="110" align="right">
          <template #default="{ row }"><span style="color:#f5222d;font-weight:bold">{{ Number(row.unpaid_amount).toFixed(2) }}</span></template>
        </vxe-column>
        <vxe-column title="本次收款" width="120" align="right">
          <template #default="{ row }">
            <span style="color:#1890ff">{{ Number(row.unpaid_amount).toFixed(2) }}</span>
          </template>
        </vxe-column>
      </vxe-table>
    </el-dialog>

    <!-- 收款单详情弹窗 -->
    <el-dialog v-model="detailVisible" title="收款单详情" width="700px" top="5vh" destroy-on-close>
      <template #default>
        <div v-if="detailData">
          <div class="section-title">基本信息</div>
          <el-descriptions :column="3" border size="small" style="margin-bottom:16px">
            <el-descriptions-item label="收款单号">{{ detailData.receive_no }}</el-descriptions-item>
            <el-descriptions-item label="客户">{{ detailData.customer?.name || '-' }}</el-descriptions-item>
            <el-descriptions-item label="收款日期">{{ detailData.receive_date }}</el-descriptions-item>
            <el-descriptions-item label="支付方式">{{ detailData.payment_method || '现金' }}</el-descriptions-item>
            <el-descriptions-item label="状态">
              <span v-if="detailData.status === 1" style="background:#52c41a;color:#fff;padding:2px 8px;border-radius:10px;font-size:12px">已审核</span>
              <span v-else-if="detailData.status === 0" style="background:#faad14;color:#fff;padding:2px 8px;border-radius:10px;font-size:12px">待审核</span>
              <span v-else style="background:#999;color:#fff;padding:2px 8px;border-radius:10px;font-size:12px">已红冲</span>
            </el-descriptions-item>
            <el-descriptions-item label="经手人">{{ detailData.handler?.name || '-' }}</el-descriptions-item>
            <el-descriptions-item label="审核人" :span="3">{{ detailData.approved_by ? '已审核' : '-' }}</el-descriptions-item>
            <el-descriptions-item label="备注" :span="3">{{ detailData.remark || '-' }}</el-descriptions-item>
          </el-descriptions>

          <div class="section-title">核销明细</div>
          <vxe-table v-if="detailItems.length" :data="detailItems" border show-header-border size="small" style="margin-bottom:12px">
            <vxe-column title="订单号" field="order_no" width="180" />
            <vxe-column title="应收金额" width="110" align="right">
              <template #default="{ row }"><span style="color:#f5222d">{{ Number(row.total_amount).toFixed(2) }}</span></template>
            </vxe-column>
            <vxe-column title="已收金额" width="110" align="right">
              <template #default="{ row }"><span style="color:#52c41a">{{ Number(row.paid_before || 0).toFixed(2) }}</span></template>
            </vxe-column>
            <vxe-column title="本次核销" width="110" align="right">
              <template #default="{ row }"><span style="color:#1890ff;font-weight:bold">{{ Number(row.pay_amount || 0).toFixed(2) }}</span></template>
            </vxe-column>
          </vxe-table>
          <p v-else style="color:#999;font-size:12px;margin-bottom:12px">暂无核销明细</p>

          <div class="section-title">收款汇总</div>
          <el-descriptions :column="3" border size="small">
            <el-descriptions-item label="收款金额">
              <span style="color:#52c41a;font-weight:bold">¥{{ Number(detailData.amount).toFixed(2) }}</span>
            </el-descriptions-item>
            <el-descriptions-item label="优惠">¥{{ Number(detailData.discount || 0).toFixed(2) }}</el-descriptions-item>
            <el-descriptions-item label="收款合计">
              <span style="color:#f5222d;font-weight:bold">¥{{ receiveTotal.toFixed(2) }}</span>
            </el-descriptions-item>
          </el-descriptions>
        </div>
      </template>
      <template #footer>
        <el-button style="width:80px;height:36px" @click="detailVisible = false">关闭</el-button>
      </template>
    </el-dialog>
  </sPageSplit>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import sTable from '@/components/sTable/index.vue'
import businessApi from '@/api/business'

// ==================== 列表 ====================
const loading = ref(false)
const tableData = ref([])
const customers = ref([])

const filters = reactive({
  customer_id: null,
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
    const res = await businessApi.receive.list.get({ ...filters, page: pagination.page, page_size: pagination.page_size })
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

const handleSearch = () => { pagination.page = 1; fetchData() }
const handleReset = () => {
  Object.assign(filters, { customer_id: null, status: null, start_date: '', end_date: '' })
  handleSearch()
}
const handlePageChange = (page) => { pagination.page = page; fetchData() }
const handleSizeChange = (size) => { pagination.page_size = size; pagination.page = 1; fetchData() }

// ==================== 新增/编辑弹窗 ====================
const dialogVisible = ref(false)
const dialogTitle = ref('')
const isEdit = ref(false)

const form = reactive({
  id: null,
  customer_id: null,
  receive_date: new Date().toISOString().slice(0, 10),
  payment_method: '现金',
  remark: '',
  amount: 0,
  discount: 0,
  cash_flow_item: '销售商品收到的现金',
  auto_print: false,
  print_receipt: false,
  sales_order_ids: [],
  sales_order_items: [],
})

// 待收款单据
const pendingOrders = ref([])
const selectedOrders = ref([])

// 应收/预收汇总
const receivableTotal = ref('0.00')
const prepayTotal = ref('0.00')

// 收款合计（收款金额 + 优惠）
const receiveTotal = computed(() => {
  return (Number(form.amount) || 0) + (Number(form.discount) || 0)
})

const onCustomerChange = async (customerId) => {
  pendingOrders.value = []
  selectedOrders.value = []
  form.sales_order_ids = []
  form.sales_order_items = []
  form.amount = 0
  if (!customerId) return
  await loadPendingOrders(customerId)
}

const loadPendingOrders = async (customerId) => {
  try {
    const res = await businessApi.receive.unpaidOrders.get({ customer_id: customerId })
    if (res.code === 200) {
      pendingOrders.value = (res.data?.list || []).map(o => ({
        ...o,
        _pay_amount: o.unpaid_amount,
      }))
      recalcTotal()
    }
  } catch {}
}

const loadAllPending = async () => {
  if (!form.customer_id) { ElMessage.warning('请先选择客户'); return }
  await loadPendingOrders(form.customer_id)
}

const onOrderChange = () => {
  // vxe-table 的选中状态不会自动同步到 row._checked，需要从表格实例获取
  const tableRef = orderTableRef.value
  const checkedRows = tableRef ? tableRef.getCheckboxRecords() : []
  // 标记选中状态，供 recalcTotal 使用
  pendingOrders.value.forEach(o => {
    o._checked = checkedRows.some(r => r.id === o.id)
  })
  selectedOrders.value = checkedRows
  recalcTotal()
}

const recalcTotal = () => {
  // 优先从表格实例获取选中行，确保与 vxe-table 状态一致
  const tableRef = orderTableRef.value
  const checkedRows = tableRef ? tableRef.getCheckboxRecords() : selectedOrders.value
  const total = checkedRows.reduce((s, o) => s + (Number(o._pay_amount) || 0), 0)
  form.amount = parseFloat(total.toFixed(2))
}

const onAmountChange = () => {
  // 手动修改金额时保持
}

const handleSubmit = async () => {
  if (!form.customer_id) { ElMessage.warning('请选择客户'); return }
  if (!form.payment_method) { ElMessage.warning('请选择支付方式'); return }
  if (!form.cash_flow_item) { ElMessage.warning('请选择现金流量项目'); return }

  // 构建核销明细
  const items = selectedOrders.value.map(o => ({
    order_id: o.id,
    pay_amount: parseFloat(o._pay_amount.toFixed(2)),
  }))

  const payload = {
    customer_id: form.customer_id,
    receive_date: form.receive_date,
    payment_method: form.payment_method,
    remark: form.remark,
    amount: form.amount,
    discount: form.discount || 0,
    sales_order_items: items,
  }

  try {
    if (isEdit.value) {
      await businessApi.receive.update.put(form.id, payload)
      ElMessage.success('更新成功')
    } else {
      await businessApi.receive.create.post(payload)
      ElMessage.success('创建成功')
    }
    dialogVisible.value = false
    fetchData()
  } catch (e) {
    ElMessage.error(e?.response?.data?.message || '操作失败')
  }
}

const handleCreate = () => {
  isEdit.value = false
  dialogTitle.value = '新增收款'
  Object.assign(form, {
    id: null, customer_id: null, receive_date: new Date().toISOString().slice(0, 10),
    payment_method: '现金', remark: '', amount: 0, discount: 0,
    cash_flow_item: '销售商品收到的现金', auto_print: false, print_receipt: false,
    sales_order_ids: [], sales_order_items: [],
  })
  pendingOrders.value = []
  selectedOrders.value = []
  dialogVisible.value = true
}

const handleEdit = (row) => {
  isEdit.value = true
  dialogTitle.value = '编辑收款'
  Object.assign(form, {
    id: row.id,
    customer_id: row.customer_id,
    receive_date: row.receive_date,
    payment_method: row.payment_method,
    remark: row.remark,
    amount: parseFloat(row.amount),
    discount: parseFloat(row.discount || 0),
    cash_flow_item: '销售商品收到的现金',
    auto_print: false, print_receipt: false,
    sales_order_ids: [], sales_order_items: [],
  })
  // 加载该客户待收款
  loadPendingOrders(row.customer_id).then(() => {
    // 恢复已选中的订单
    if (row.sales_order_id) {
      const order = pendingOrders.value.find(o => o.id === row.sales_order_id)
      if (order) {
        order._checked = true
        order._pay_amount = parseFloat(row.amount)
        selectedOrders.value = [order]
        form.amount = parseFloat(row.amount)
      }
    }
  })
  dialogVisible.value = true
}

const handleApprove = async (row) => {
  try {
    await ElMessageBox.confirm('确定审核该收款单?', '提示')
    await businessApi.receive.approve.post(row.id)
    ElMessage.success('审核成功')
    fetchData()
  } catch (e) {
    if (e !== 'cancel') ElMessage.error('操作失败')
  }
}

const handleRedFlush = async (row) => {
  try {
    const { value: reason } = await ElMessageBox.prompt('请输入红冲原因', '红冲收款单', {
      confirmButtonText: '确认红冲',
      cancelButtonText: '取消',
      inputPlaceholder: '红冲原因（可选）',
      inputValidator: v => true,
    })
    await businessApi.receive.redFlush.post(row.id, { reason: reason || '手动红冲' })
    ElMessage.success('红冲成功')
    fetchData()
  } catch (e) {
    if (e !== 'cancel') ElMessage.error('红冲失败')
  }
}

const handleDelete = async (row) => {
  try {
    await ElMessageBox.confirm('确定删除该收款单?', '提示')
    await businessApi.receive.delete.delete(row.id)
    ElMessage.success('删除成功')
    fetchData()
  } catch (e) {
    if (e !== 'cancel') ElMessage.error('操作失败')
  }
}

// ==================== 选择欠款明细弹窗 ====================
const orderSelectVisible = ref(false)
const orderSearchKeyword = ref('')
const orderSearchResults = ref([])
const orderTableRef = ref(null)
const orderSelectTableRef = ref(null)
const selectedOrderIds = ref([])

const openOrderSelectDialog = async () => {
  if (!form.customer_id) { ElMessage.warning('请先选择客户'); return }
  orderSearchKeyword.value = ''
  orderSearchResults.value = []
  selectedOrderIds.value = []
  orderSelectVisible.value = true
  // 自动加载该客户全部欠款
  try {
    const res = await businessApi.receive.unpaidOrders.get({ customer_id: form.customer_id })
    if (res.code === 200) {
      orderSearchResults.value = (res.data?.list || []).map(o => ({ ...o, _checked: false }))
    }
  } catch {}
}

const searchOrders = async () => {
  if (!form.customer_id) return
  try {
    const res = await businessApi.receive.unpaidOrders.get({ customer_id: form.customer_id })
    if (res.code === 200) {
      let list = (res.data?.list || []).map(o => ({ ...o, _checked: false }))
      if (orderSearchKeyword.value) {
        const kw = orderSearchKeyword.value.toLowerCase()
        list = list.filter(o => o.order_no.toLowerCase().includes(kw))
      }
      orderSearchResults.value = list
      selectedOrderIds.value = []
    }
  } catch {}
}

const onOrderSelectChange = () => {
  // vxe-table 的选中状态不会自动同步到 row._checked，需要从表格实例获取
  const tableRef = orderSelectTableRef.value
  const checkedRows = tableRef ? tableRef.getCheckboxRecords() : []
  orderSearchResults.value.forEach(o => {
    o._checked = checkedRows.some(r => r.id === o.id)
  })
  selectedOrderIds.value = checkedRows.map(o => o.id)
}

const confirmOrderSelect = () => {
  if (selectedOrderIds.value.length === 0) {
    ElMessage.warning('请选择至少一个订单')
    return
  }
  // 将选中的订单合并到待收款列表
  const existingIds = new Set(pendingOrders.value.map(o => o.id))
  const newOrders = orderSearchResults.value
    .filter(o => selectedOrderIds.value.includes(o.id) && !existingIds.has(o.id))
    .map(o => ({ ...o, _pay_amount: o.unpaid_amount, _checked: true }))

  // 更新已有订单
  const updateMap = new Map(selectedOrderIds.value.map(id => {
    const o = orderSearchResults.value.find(x => x.id === id)
    return [id, o]
  }))
  pendingOrders.value.forEach(o => {
    const update = updateMap.get(o.id)
    if (update) {
      o.unpaid_amount = update.unpaid_amount
      o.total_amount = update.total_amount
      o.paid_amount = update.paid_amount
      o._pay_amount = update.unpaid_amount
      o._checked = true
    }
  })
  pendingOrders.value.push(...newOrders)
  selectedOrders.value = pendingOrders.value.filter(o => o._checked)
  recalcTotal()
  orderSelectVisible.value = false
}

// ==================== 详情弹窗 ====================
const detailVisible = ref(false)
const detailData = ref(null)
const detailItems = ref([])

const handleView = async (row) => {
  try {
    const res = await businessApi.receive.detail.get(row.id)
    if (res.code === 200) {
      detailData.value = res.data
      // 使用服务端返回的核销明细
      detailItems.value = (res.data?.order_items || []).map(item => ({
        order_no: item.order_no || '',
        total_amount: item.total_amount || 0,
        paid_before: item.paid_before ?? (Number(item.total_amount) - Number(item.pay_amount)),
        pay_amount: item.pay_amount || 0,
      }))
      detailVisible.value = true
    }
  } catch {
    ElMessage.error('加载详情失败')
  }
}

onMounted(() => {
  fetchData()
  fetchCustomers()
})
</script>

<style scoped>
.filter-bar {
  display: flex;
  align-items: center;
  height: 50px;
  background: #fff;
  border-bottom: 1px solid #e4e7ed;
  padding: 0 16px;
  gap: 0;
}

.receive-table {
  width: 100%;
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
  gap: 32px;
  margin-bottom: 12px;
  align-items: center;
}

.form-field {
  display: flex;
  align-items: center;
  gap: 8px;
}

.form-field label {
  width: 80px;
  text-align: right;
  color: #333;
  font-size: 14px;
  flex-shrink: 0;
}

.required {
  color: #f5222d;
  margin-right: 2px;
}

.summary-box {
  display: flex;
  gap: 24px;
  padding: 8px 12px;
  background: #fafafa;
  border: 1px solid #f0f0f0;
  border-radius: 4px;
  margin-bottom: 16px;
}

.order-toolbar {
  margin-bottom: 12px;
  display: flex;
  gap: 8px;
}

.order-table {
  width: 100%;
  margin-bottom: 8px;
}

.pagination-bar {
  height: 50px;
  border-top: 1px solid #f0f0f0;
  display: flex;
  align-items: center;
  justify-content: flex-end;
  padding: 0 16px;
  gap: 16px;
}
</style>
