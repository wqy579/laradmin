<template>
  <div class="report-salesman-order">
    <!-- 筛选卡 -->
    <div class="search-card">
      <el-form :inline="true" :model="searchForm">
        <!-- 申报日期起止 -->
        <el-date-picker
          v-model="searchForm.apply_date_start"
          type="daterange"
          value-format="YYYY-MM-DD"
          placeholder="申报日期起"
          style="width: 120px"
          @change="onDate"
        />
        <el-date-picker
          v-model="searchForm.apply_date_end"
          type="daterange"
          value-format="YYYY-MM-DD"
          placeholder="申报日期止"
          style="width: 120px"
          @change="onDate"
        />
        <!-- 事业部 -->
        <el-select
          v-model="searchForm.department"
          placeholder="事业部"
          size="small"
          style="width: 160px"
        >
          <el-option label="全部" value=""/>
          <el-option label="事业部 A" value="A"/>
          <el-option label="事业部 B" value="B"/>
        </el-select>
        <!-- 拣货员 -->
        <el-select
          v-model="searchForm.picker_assign"
          placeholder="拣货员"
          size="small"
          style="width: 160px"
        >
          <el-option label="全部" value=""/>
          <el-option label="拣货员 1" value="1"/>
          <el-option label="拣货员 2" value="2"/>
        </el-select>
        <!-- 订单类型 -->
        <el-select
          v-model="searchForm.order_type"
          placeholder="订单类型"
          size="small"
          style="width: 120px"
        >
          <el-option label="普通" value="1"/>
          <el-option label="车销" value="2"/>
          <el-option label="预售" value="3"/>
        </el-select>
        <!-- 订单状态 -->
        <el-select
          v-model="searchForm.order_status"
          placeholder="订单状态"
          size="small"
          style="width: 120px"
        >
          <el-option label="已完成成功" value="1"/>
          <el-option label="待审核" value="2"/>
          <el-option label="审核中" value="3"/>
          <el-option label="已驳回" value="4"/>
        </el-select>
        <!-- 销售类型 -->
        <el-select
          v-model="searchForm.sales_type"
          placeholder="销售类型"
          size="small"
          style="width: 120px"
        >
          <el-option label="自营" value="1"/>
          <el-option label="代销" value="2"/>
        </el-select>
        <!-- 单据来源 -->
        <el-select
          v-model="searchForm.source"
          placeholder="单据来源"
          size="small"
          style="width: 140px"
        >
          <el-option label="系统录入" value="1"/>
          <el-option label="手工录入" value="2"/>
        </el-select>
        <!-- 业务员 -->
        <el-select
          v-model="searchForm.salesman"
          placeholder="业务员"
          size="small"
          style="width: 160px"
        >
          <el-option label="张三" value="张三"/>
          <el-option label="李四" value="李四"/>
          <el-option label="王五" value="王五"/>
          <el-option label="赵六" value="赵六"/>
          <el-option label="刘七" value="刘七"/>
        </el-select>

        <el-button type="primary" size="small" @click="refresh">查询</el-button>
        <el-button size="small" @click="resetSearch">重置</el-button>
        <el-button size="small" @click="exportData">导出Excel</el-button>
      </el-form>
    </div>

    <!-- 三视图 -->
    <el-tabs @tab-change="onTabChange">
      <el-tab-pane label="商品明细" name="items">
        <sTable
          ref="tableRefA"
          tableName="report_salesman_order_items"
          :data="itemsData"
          :columns="columnsA"
          :loading="loadingA"
          :currentPage="paginationPropsA.currentPage"
          :pageSize="paginationPropsA.pageSize"
          :page-sizes="[10, 20, 50, 100]"
          :pagination-layout="paginationPropsA.paginationLayout"
          :empty-text="emptyTextA"
          @refresh="handleARefresh"
        />
      </el-tab-pane>

      <el-tab-pane label="客户商品汇总" name="customerItem">
        <sTable
          ref="tableRefB"
          tableName="report_salesman_order_customer"
          :data="customerItemData"
          :columns="columnsB"
          :loading="loadingB"
          :currentPage="paginationPropsB.currentPage"
          :pageSize="paginationPropsB.pageSize"
          :page-sizes="[10, 20, 50, 100]"
          :pagination-layout="paginationPropsB.paginationLayout"
          :empty-text="emptyTextB"
        />
      </el-tab-pane>

      <el-tab-pane label="业务员订单汇总" name="salesman">
        <sTable
          ref="tableRefC"
          tableName="report_salesman_order_salesman"
          :data="tabCData"
          :columns="columnsC"
          :loading="loadingC"
          :currentPage="paginationPropsA.currentPage"
          :pageSize="paginationPropsA.pageSize"
          :page-sizes="[10, 20, 50, 100]"
          :pagination-layout="paginationPropsA.paginationLayout"
          :empty-text="emptyTextC"
        />

        <!-- 列表/图表切换 -->
        <el-row v-if="showChart" style="margin-top: 20px;">
          <el-segment
            v-model="chartMode"
            activecolor="#409EFF"
            textcolor="#909399"
            style="margin-bottom: 20px"
          >
            <el-button>列表</el-button>
            <el-button>图表</el-button>
          </el-segment>
          <el-divider />
          <div class="chart-container" ref="chartRef">
            <div v-if="loadingC" style="padding: 60px; text-align: center; color: #909399;">加载中...</div>
            <div v-else-if="chartState === 'loaded'">
              <el-button style="padding: 8px 16px; background: #409EFF; color: white; border-radius: 4px; border: none;" @click="resetChart">
                重置图表
              </el-button>
            </div>
          </div>
        </el-row>

        <!-- 双击行 → 订单明细列表 -->
        <el-dialog
          v-model="detailVisible"
          width="80%"
          title="订单明细"
          :close-on-click-modal="false"
        >
          <el-table
            ref="itemTableRef"
            :data="detailData"
            border
            :stripe="false"
            style="width: 100%"
            @row-click="handleRowClick"
          >
            <el-table-column prop="seq" label="序号" min-width="80" align="center" />
            <el-table-column prop="apply_date" label="申报日期" min-width="140" align="center" />
            <el-table-column prop="salesman" label="业务员" min-width="120" align="center" />
            <el-table-column prop="customer" label="客户" min-width="160" align="center" />
            <el-table-column prop="product_code" label="商品编码" min-width="120" align="right" />
            <el-table-column prop="product_name" label="商品名称" min-width="200" align="center" show-overflow-tooltip />
            <el-table-column prop="brand" label="品牌" min-width="120" align="center" />
            <el-table-column prop="qty" label="数量" min-width="80" align="right" />
            <el-table-column prop="unit_price" label="单价" min-width="100" align="right" />
            <el-table-column prop="amount" label="金额" min-width="100" align="right" />
            <el-table-column prop="status" label="订单状态" min-width="100" align="center" />
          </el-table>
        </el-dialog>
      </el-tab-pane>
    </el-tabs>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'
import { ElMessage, ElMessageBox } from 'element-plus'
import { useTable } from '@/hooks/useTable'
import { usePermission } from '@/composables/usePermission'
import { ElNotification } from 'element-plus'
import * as echarts from 'echarts'
import { saveBlob } from '@/utils/fileDownload'
import sTable from '@/components/sTable/index.vue'
import perfApi from '@/api/perf'

const { t } = useI18n()

// ── 状态 ──
const searchForm = reactive({
  apply_date_start: '',
  apply_date_end: '',
  department: '',
  picker_assign: '',
  order_type: '',
  order_status: '',
  sales_type: '',
  source: '',
  salesman: '',
})

const chartMode = ref('列表')
const showChart = ref(true)
const chartState = ref('idle')
const detailVisible = ref(false)
const detailData = ref([])

// ── 初始化 Tab C 数据（使用 mock 数据，包含合计行） ──
const itemsData = [
  { seq: 1, apply_date: '2026-01-10', salesman: '张三', customer: '客户 A', product_code: 'P101', product_name: '产品 101', brand: '品牌 1', qty: 150, unit_price: 8.5, amount: 1275, status: '已完成成功' },
  { seq: 2, apply_date: '2026-01-15', salesman: '李四', customer: '客户 A', product_code: 'P101', product_name: '产品 101', brand: '品牌 1', qty: 100, unit_price: 8.5, amount: 850, status: '已完成成功' },
  { seq: 3, apply_date: '2026-01-20', salesman: '张三', customer: '客户 B', product_code: 'P102', product_name: '产品 102', brand: '品牌 2', qty: 200, unit_price: 12, amount: 2400, status: '已完成成功' },
  { seq: 4, apply_date: '2026-01-25', salesman: '李四', customer: '客户 B', product_code: 'P102', product_name: '产品 102', brand: '品牌 2', qty: 150, unit_price: 12, amount: 1800, status: '待审核' },
  { seq: 5, apply_date: '2026-02-01', salesman: '王五', customer: '客户 A', product_code: 'P103', product_name: '产品 103', brand: '品牌 3', qty: 80, unit_price: 25, amount: 2000, status: '已完成成功' },
  { seq: 6, apply_date: '2026-02-05', salesman: '张三', customer: '客户 C', product_code: 'P104', product_name: '产品 104', brand: '品牌 4', qty: 120, unit_price: 18, amount: 2160, status: '已完成成功' },
  { seq: 7, apply_date: '2026-02-10', salesman: '李四', customer: '客户 C', product_code: 'P104', product_name: '产品 104', brand: '品牌 4', qty: 100, unit_price: 18, amount: 1800, status: '待审核' },
  { seq: 8, apply_date: '2026-02-15', salesman: '王五', customer: '客户 D', product_code: 'P105', product_name: '产品 105', brand: '品牌 5', qty: 200, unit_price: 30, amount: 6000, status: '已完成成功' },
  { seq: 9, apply_date: '2026-02-20', salesman: '赵六', customer: '客户 A', product_code: 'P101', product_name: '产品 101', brand: '品牌 1', qty: 100, unit_price: 8.5, amount: 850, status: '已完成成功' },
  { seq: 10, apply_date: '2026-03-01', salesman: '刘七', customer: '客户 B', product_code: 'P102', product_name: '产品 102', brand: '品牌 2', qty: 120, unit_price: 12, amount: 1440, status: '待审核' },
  { seq: 11, apply_date: '2026-03-05', salesman: '张三', customer: '客户 C', product_code: 'P103', product_name: '产品 103', brand: '品牌 3', qty: 80, unit_price: 25, amount: 2000, status: '已完成成功' },
  { seq: 12, apply_date: '2026-03-10', salesman: '李四', customer: '客户 D', product_code: 'P105', product_name: '产品 105', brand: '品牌 5', qty: 50, unit_price: 30, amount: 1500, status: '已完成成功' },
  { seq: 13, apply_date: '2026-03-15', salesman: '王五', customer: '客户 E', product_code: 'P101', product_name: '产品 101', brand: '品牌 1', qty: 100, unit_price: 8.5, amount: 850, status: '已完成成功' },
  { seq: 14, apply_date: '2026-03-20', salesman: '赵六', customer: '客户 A', product_code: 'P104', product_name: '产品 104', brand: '品牌 4', qty: 100, unit_price: 18, amount: 1800, status: '待审核' },
  { seq: 15, apply_date: '2026-03-25', salesman: '刘七', customer: '客户 B', product_code: 'P105', product_name: '产品 105', brand: '品牌 5', qty: 100, unit_price: 30, amount: 3000, status: '已完成成功' },
  { seq: 16, apply_date: '2026-04-01', salesman: '张三', customer: '客户 C', product_code: 'P101', product_name: '产品 101', brand: '品牌 1', qty: 120, unit_price: 8.5, amount: 1020, status: '已完成成功' },
  { seq: 17, apply_date: '2026-04-05', salesman: '李四', customer: '客户 D', product_code: 'P104', product_name: '产品 104', brand: '品牌 4', qty: 80, unit_price: 18, amount: 1440, status: '待审核' },
  { seq: 18, apply_date: '2026-04-10', salesman: '王五', customer: '客户 E', product_code: 'P102', product_name: '产品 102', brand: '品牌 2', qty: 100, unit_price: 12, amount: 1200, status: '已完成成功' },
  { seq: 19, apply_date: '2026-04-15', salesman: '赵六', customer: '客户 C', product_code: 'P105', product_name: '产品 105', brand: '品牌 5', qty: 80, unit_price: 30, amount: 2400, status: '已完成成功' },
  { seq: 20, apply_date: '2026-04-20', salesman: '刘七', customer: '客户 A', product_code: 'P103', product_name: '产品 103', brand: '品牌 3', qty: 100, unit_price: 25, amount: 2500, status: '已完成成功' },
  { seq: 21, apply_date: '2026-05-01', salesman: '张三', customer: '客户 B', product_code: 'P101', product_name: '产品 101', brand: '品牌 1', qty: 150, unit_price: 8.5, amount: 1275, status: '已完成成功' },
  { seq: 22, apply_date: '2026-05-05', salesman: '李四', customer: '客户 C', product_code: 'P102', product_name: '产品 102', brand: '品牌 2', qty: 120, unit_price: 12, amount: 1440, status: '待审核' },
  { seq: 23, apply_date: '2026-05-10', salesman: '王五', customer: '客户 D', product_code: 'P105', product_name: '产品 105', brand: '品牌 5', qty: 200, unit_price: 30, amount: 6000, status: '已完成成功' },
  { seq: 24, apply_date: '2026-05-15', salesman: '赵六', customer: '客户 A', product_code: 'P103', product_name: '产品 103', brand: '品牌 3', qty: 80, unit_price: 25, amount: 2000, status: '待审核' },
  { seq: 25, apply_date: '2026-05-20', salesman: '刘七', customer: '客户 E', product_code: 'P101', product_name: '产品 101', brand: '品牌 1', qty: 100, unit_price: 8.5, amount: 850, status: '已完成成功' },
  { seq: 26, apply_date: '2026-06-01', salesman: '张三', customer: '客户 B', product_code: 'P104', product_name: '产品 104', brand: '品牌 4', qty: 80, unit_price: 18, amount: 1440, status: '已完成成功' },
  { seq: 27, apply_date: '2026-06-05', salesman: '李四', customer: '客户 C', product_code: 'P105', product_name: '产品 105', brand: '品牌 5', qty: 100, unit_price: 30, amount: 3000, status: '待审核' },
  { seq: 28, apply_date: '2026-06-10', salesman: '王五', customer: '客户 A', product_code: 'P102', product_name: '产品 102', brand: '品牌 2', qty: 150, unit_price: 12, amount: 1800, status: '已完成成功' },
  { seq: 29, apply_date: '2026-06-15', salesman: '赵六', customer: '客户 D', product_code: 'P101', product_name: '产品 101', brand: '品牌 1', qty: 100, unit_price: 8.5, amount: 850, status: '待审核' },
  { seq: 30, apply_date: '2026-06-20', salesman: '刘七', customer: '客户 E', product_code: 'P103', product_name: '产品 103', brand: '品牌 3', qty: 80, unit_price: 25, amount: 2000, status: '已完成成功' },
]

const customerItemData = [
  { salesman: '张三', customer: '客户 A', product_name: '产品 101', qty: 250, amount: 2125 },
  { salesman: '张三', customer: '客户 A', product_name: '产品 102', qty: 150, amount: 1800 },
  { salesman: '李四', customer: '客户 A', product_name: '产品 101', qty: 250, amount: 2125 },
  { salesman: '李四', customer: '客户 A', product_name: '产品 102', qty: 150, amount: 1800 },
  { salesman: '王五', customer: '客户 A', product_name: '产品 101', qty: 100, amount: 850 },
  { salesman: '王五', customer: '客户 A', product_name: '产品 102', qty: 150, amount: 1800 },
  { salesman: '王五', customer: '客户 A', product_name: '产品 103', qty: 80, amount: 2000 },
  { salesman: '赵六', customer: '客户 A', product_name: '产品 101', qty: 100, amount: 850 },
  { salesman: '刘七', customer: '客户 A', product_name: '产品 103', qty: 100, amount: 2500 },
  { salesman: '赵六', customer: '客户 B', product_name: '产品 101', qty: 250, amount: 2125 },
  { salesman: '张三', customer: '客户 B', product_name: '产品 102', qty: 200, amount: 2400 },
  { salesman: '张三', customer: '客户 B', product_name: '产品 103', qty: 80, amount: 2000 },
  { salesman: '李四', customer: '客户 B', product_name: '产品 102', qty: 150, amount: 1800 },
  { salesman: '李四', customer: '客户 B', product_name: '产品 103', qty: 100, amount: 2000 },
  { salesman: '王五', customer: '客户 B', product_name: '产品 102', qty: 100, amount: 1200 },
  { salesman: '赵六', customer: '客户 B', product_name: '产品 104', qty: 100, amount: 1800 },
  { salesman: '刘七', customer: '客户 B', product_name: '产品 104', qty: 120, amount: 2160 },
]

const salesmanAgg = [
  { sales_name: '张三', order_count: 9, goods_item_count: 16, order_amount: 16675, revenue: 16675, rank: 1, sales_item_count: 8 },
  { sales_name: '李四', order_count: 8, goods_item_count: 13, order_amount: 13125, revenue: 13125, rank: 2, sales_item_count: 7 },
  { sales_name: '王五', order_count: 7, goods_item_count: 10, order_amount: 13600, revenue: 13600, rank: 3, sales_item_count: 6 },
  { sales_name: '赵六', order_count: 6, goods_item_count: 8, order_amount: 12800, revenue: 12800, rank: 4, sales_item_count: 4 },
  { sales_name: '刘七', order_count: 5, goods_item_count: 8, order_amount: 13200, revenue: 13200, rank: 5, sales_item_count: 3 },
]

const tabCData = ref([...salesmanAgg])
// 补充合计行
const totalRow = {
  sales_name: '合计',
  order_count: 5,
  goods_item_count: 16,
  order_amount: 46600,
  revenue: 100,
  rank: '1',
}
tabCData.value.push(totalRow)

// ── API ──
const { tableRefA, dataA, loadingA, paginationPropsA, refreshA } = useTable({
  apiObj: { get: (params) => perfApi.salesmanOrder.items.get(params) },
  searchForm,
  pageSize: 30,
})

const { tableRefB, dataB, loadingB, paginationPropsB } = useTable({
  apiObj: { get: (params) => perfApi.salesmanOrder.customer.get(params) },
  searchForm,
  pageSize: 30,
})

// ── Tab A 商品明细 ──
const columnsA = [
  { prop: 'seq', title: '序号', width: 50, minWidth: 50, align: 'center' },
  { prop: 'apply_date', title: '申报日期', width: 110, minWidth: 110, align: 'center' },
  { prop: 'salesman', title: '业务员', width: 100, align: 'center' },
  { prop: 'customer', title: '客户', width: 160, align: 'center' },
  { prop: 'product_code', title: '商品编码', width: 120, align: 'right' },
  { prop: 'product_name', title: '商品名称', width: 180, align: 'center' },
  { prop: 'brand', title: '品牌', width: 100, align: 'center' },
  { prop: 'qty', title: '数量', width: 80, align: 'right' },
  { prop: 'unit_price', title: '单价', width: 100, align: 'right' },
  { prop: 'amount', title: '金额', width: 130, align: 'right' },
  { prop: 'status', title: '订单状态', width: 90, align: 'center' },
]

const emptyTextA = '暂无数据'

// ── Tab B 客户商品汇总 ──
const columnsB = [
  { prop: 'salesman', title: '业务员', width: 100, align: 'center' },
  { prop: 'customer', title: '客户', width: 160, align: 'center' },
  { prop: 'product_name', title: '商品名称', width: 180, align: 'center' },
  { prop: 'qty', title: '数量汇总', width: 100, align: 'right' },
  { prop: 'amount', title: '金额汇总', width: 130, align: 'right' },
  { prop: 'order_count', title: '订单数', width: 80, align: 'right' },
]

const emptyTextB = '暂无数据'

// ── Tab C 业务员订单汇总 ──
const columnsC = [
  { prop: 'sales_name', title: '业务员', width: 100, align: 'center' },
  { prop: 'order_count', title: '订单数', width: 80, align: 'right' },
  { prop: 'goods_item_count', title: '商品件数', width: 100, align: 'right' },
  { prop: 'order_amount', title: '订单金额', width: 150, align: 'right' },
  { prop: 'revenue', title: '占比', width: 90, align: 'right', slot: 'progressDefault' },
  { prop: 'rank', title: '排名', width: 60, align: 'center' },
]

const emptyTextC = '暂无数据'

// ── 行点击（双击） → 订单明细列表 ──
const onRowClick = (row) => {
  detailData.value = { ...row }
  detailVisible.value = true
}

// ── 导出 Excel ──
const exportData = async () => {
  if (tabCData.value.length === 0) {
    ElMessage.warning('业务员订单汇总暂无数据')
    return
  }
  try {
    await nextTick()
    const res = await perfApi.salesmanOrder.export({})
    ElMessage.success(`导出成功，共 ${tabCData.value.length} 条记录`)
  } catch (e) {
    ElMessage.error(e?.message || '导出失败')
  }
}

// ── 查询刷新 ──
const refresh = async () => {
  await nextTick()
  await refreshA()
  await refreshB()
  // Tab C 不依赖 useTable，dataC 不存在
  // 此处无需调用 refreshC
  ElMessage.success('查询完成')
}

const resetSearch = async () => {
  searchForm.apply_date_start = ''
  searchForm.apply_date_end = ''
  searchForm.department = ''
  searchForm.picker_assign = ''
  searchForm.order_type = ''
  searchForm.order_status = ''
  searchForm.sales_type = ''
  searchForm.source = ''
  searchForm.salesman = ''
  await nextTick()
  await refreshA()
  await refreshB()
  // Tab C data 已初始化，无需刷新
}

// ── 图表初始化 ──
const chartRef = ref(null)
let chartInstance = ref(null)
const chartOption = computed(() => ({
  title: { text: '业务员订单金额 · 横向柱状图', left: 20, top: 8, textStyle: { fontSize: 13, color: '#303133' } },
  tooltip: { trigger: 'axis' },
  legend: { data: ['业务员订单金额'], right: 20, top: 8, textStyle: { fontSize: 12, color: '#606266' } },
  grid: { left: 50, right: 30, top: 50, bottom: 30 },
  xAxis: { type: 'category', data: tabCData.value.map((r) => r.sales_name), axisLabel: { fontSize: 12, color: '#909399' } },
  yAxis: { type: 'value' },
  series: [
    { name: '业务员订单金额', type: 'bar', data: tabCData.value.map((r) => r.order_amount), itemStyle: { color: '#409EFF' }, barWidth: '100%' },
  ],
}))

const loadChart = async () => {
  await nextTick()
  if (!chartRef.value) return
  chartState.value = 'loading'
  try {
    if (!chartInstance.value) {
      chartInstance.value = echarts.init(chartRef.value)
      chartInstance.value.setOption(chartOption.value)
    }
  } catch (e) {}
  chartState.value = 'loaded'
}

// ── 重新初始化图表 ──
watch(showChart, (val) => {
  if (val && chartRef.value) {
    loadChart()
  }
})

onMounted(async () => {
  await nextTick()
  // 首次查询触发
  await refreshA()
  await refreshB()
  // Tab C 数据已由 mock 初始化，图表在 showChart 后自动加载
})
</script>

<style scoped>
.report-salesman-order {
  height: 100%;
  display: flex;
  flex-direction: column;
}

.search-card {
  padding: 16px 20px;
  background: #fff;
  border-bottom: 1px solid #ebeef5;
}

.search-card .el-form {
  margin-bottom: 16px;
}

.search-card .el-form :deep(.el-input) {
  width: 100%;
}

.search-card .el-form :deep(.el-select) {
  width: 100%;
}

.chart-container {
  width: 100%;
  min-height: 360px;
}

.chart-title {
  font-size: 13px;
  color: #606266;
  margin-bottom: 16px;
}

@media (max-width: 768px) {
  .chart-container {
    min-height: 260px;
  }
}
</style>