<template>
  <div class="delivery-list">
    <sTable
      ref="tableRef"
      table-name="business_delivery"
      :data="data"
      :columns="columns"
      :loading="loading"
      :total="total"
      :current-page="pagination.currentPage"
      :page-size="pagination.pageSize"
      :page-sizes="pagination.pageSizes"
      pagination-layout="total, sizes, prev, pager, next, jumper"
      row-key="id"
      height="100%"
      border
      stripe
      @page-change="handlePageChange"
      @page-size-change="handlePageSizeChange"
      @refresh="fetchData"
    >
      <template #btnAdd>
        <el-button type="primary" @click="handleAdd">新建发货单</el-button>
      </template>
      <template #column_no="{ row }">
        <a class="no-link" @click="handleDetail(row)">{{ row.delivery_no }}</a>
      </template>
      <template #column_status="{ row }">
        <el-tag :type="getStatusType(row.status)">{{
          getStatusText(row.status)
        }}</el-tag>
      </template>
      <template #operation_default="{ row }">
        <div class="row-ops">
          <a class="op op-primary" @click="handleDetail(row)">查看</a>
          <a
            v-if="row.status === 0"
            class="op op-primary"
            @click="handleEdit(row)"
            >编辑</a
          >
          <a
            v-if="row.status === 0"
            class="op op-success"
            @click="handleDispatch(row)"
            >发货</a
          >
          <a
            v-if="row.status === 1"
            class="op op-success"
            @click="handleComplete(row)"
            >完成</a
          >
          <a class="op op-primary" @click="handlePrint(row)">打印</a>
          <a
            v-if="row.status === 0"
            class="op op-danger"
            @click="handleDelete(row)"
            >删除</a
          >
        </div>
      </template>
    </sTable>

    <delivery-dialog ref="dialog" @success="fetchData" />

    <!-- 详情弹窗 -->
    <el-dialog v-model="detailVisible" width="760px" top="5vh" destroy-on-close>
      <template #header>
        <div class="detail-head">
          <span>配送单详情</span>
          <el-button v-if="detailData" @click="printDelivery(detailData)">
            <el-icon class="btn-icon"><PrinterOutlined /></el-icon>打印
          </el-button>
        </div>
      </template>
      <el-descriptions :column="2" border size="small" v-if="detailData">
        <el-descriptions-item label="配送单号">{{
          detailData.delivery_no
        }}</el-descriptions-item>
        <el-descriptions-item label="状态">
          <el-tag :type="getStatusType(detailData.status)">{{
            getStatusText(detailData.status)
          }}</el-tag>
        </el-descriptions-item>
        <el-descriptions-item label="客户">{{
          detailData.customer?.name || "-"
        }}</el-descriptions-item>
        <el-descriptions-item label="配送员">{{
          detailData.driver?.name || detailData.vehicle?.driver_name || "-"
        }}</el-descriptions-item>
        <el-descriptions-item label="仓库">{{
          detailData.warehouse?.name || "-"
        }}</el-descriptions-item>
        <el-descriptions-item label="车牌号">{{
          detailData.vehicle?.plate_no || "-"
        }}</el-descriptions-item>
        <el-descriptions-item label="联系电话">{{
          detailData.customer?.phone || "-"
        }}</el-descriptions-item>
        <el-descriptions-item label="送货地址" :span="2">{{
          detailData.customer?.address || "-"
        }}</el-descriptions-item>
        <el-descriptions-item label="发货日期">{{
          detailData.delivery_date || "-"
        }}</el-descriptions-item>
        <el-descriptions-item label="备注" :span="2">{{
          detailData.remark || "-"
        }}</el-descriptions-item>
      </el-descriptions>
      <el-table
        :data="detailData?.items || []"
        size="small"
        border
        style="margin-top: 12px"
      >
        <el-table-column type="index" label="序号" width="60" />
        <el-table-column label="商品编码" width="120">
          <template #default="{ row }">{{ row.product?.code || "-" }}</template>
        </el-table-column>
        <el-table-column label="商品名称" min-width="160">
          <template #default="{ row }">{{ row.product?.name || "-" }}</template>
        </el-table-column>
        <el-table-column label="规格" width="100">
          <template #default="{ row }">{{
            row.product?.spec_display || row.product?.spec || "-"
          }}</template>
        </el-table-column>
        <el-table-column label="单位" width="80">
          <template #default="{ row }">{{
            row.product?.price_unit || "-"
          }}</template>
        </el-table-column>
        <el-table-column
          label="配送数量"
          prop="quantity"
          width="100"
          align="right"
        />
        <el-table-column label="金额" prop="amount" width="100" align="right" />
      </el-table>
    </el-dialog>

    <!-- 单据打印预览 -->
    <PrintPreview
      v-model="preview.visible"
      v-bind="preview"
      :rebuild="preview.rebuild"
    />
  </div>
</template>

<script setup>
import { ref, reactive } from "vue";
import { ElMessage, ElMessageBox } from "element-plus";
import { PrinterOutlined } from "@ant-design/icons-vue";
import sTable from "@/components/sTable/index.vue";
import PrintPreview from "@/components/print-preview/index.vue";
import { useDocumentPrint } from "@/components/print-preview/useDocumentPrint";
import deliveryDialog from "./dialog.vue";
import businessApi from "@/api/business";

const { preview, printDelivery } = useDocumentPrint();

const tableRef = ref(null);
const dialog = ref(null);
const loading = ref(false);
const data = ref([]);
const total = ref(0);
const pagination = reactive({
  currentPage: 1,
  pageSize: 20,
  pageSizes: [10, 20, 50, 100],
});
const detailVisible = ref(false);
const detailData = ref(null);

// 后端只返回关联对象（customer/warehouse/driver/vehicle），无扁平字段，列用点路径取值
const columns = ref([
  {
    field: "delivery_no",
    title: "发货单号",
    width: 150,
    slots: { default: "column_no" },
  },
  { field: "order_no", title: "关联订单", width: 120 },
  { field: "customer.name", title: "客户", width: 120 },
  { field: "warehouse.name", title: "仓库", width: 100 },
  { field: "driver.name", title: "配送员", width: 100 },
  { field: "total_amount", title: "总金额", width: 120, align: "right" },
  { field: "paid_amount", title: "已收款", width: 120, align: "right" },
  { field: "delivery_date", title: "发货日期", width: 120 },
  {
    field: "status",
    title: "状态",
    width: 100,
    slots: { default: "column_status" },
  },
  { field: "remark", title: "备注", showOverflow: true },
  {
    field: "operation",
    title: "操作",
    width: 260,
    fixed: "right",
    slots: { default: "operation_default" },
  },
]);

async function fetchData() {
  loading.value = true;
  try {
    const res = await businessApi.delivery.list.get({
      page: pagination.currentPage,
      per_page: pagination.pageSize,
    });
    if (res.code === 200) {
      const d = res.data || {};
      // 后端 paginate 返回 Laravel 分页结构，list 可能在 data 或 data.data
      data.value = d.data || d.list || [];
      total.value = d.total ?? (d.data ? d.total : 0) ?? 0;
    }
  } catch (e) {
    ElMessage.error("加载失败");
  } finally {
    loading.value = false;
  }
}

function handlePageChange(p) {
  pagination.currentPage = p;
  fetchData();
}
function handlePageSizeChange(s) {
  pagination.pageSize = s;
  pagination.currentPage = 1;
  fetchData();
}

function handleAdd() {
  dialog.value?.open();
}

function handleEdit(row) {
  dialog.value?.open(row.id);
}

async function handleDetail(row) {
  try {
    const res = await businessApi.delivery.detail.get(row.id);
    if (res.code === 200) {
      detailData.value = res.data;
      detailVisible.value = true;
    }
  } catch {
    ElMessage.error("加载详情失败");
  }
}

async function handleDelete(row) {
  try {
    await ElMessageBox.confirm("确认删除该发货单？", "提示", {
      type: "warning",
    });
    const delApi = businessApi.delivery.delete;
    const res = await delApi.delete(row.id);
    if (res.code === 200) {
      ElMessage.success("删除成功");
      fetchData();
    }
  } catch {
    /* 用户取消或接口失败，不弹错 */
  }
}

function handleDispatch(row) {
  businessApi.delivery.dispatch.post(row.id).then(() => {
    ElMessage.success("发货成功");
    fetchData();
  });
}

function handleComplete(row) {
  businessApi.delivery.complete.post(row.id).then(() => {
    ElMessage.success("完成成功");
    fetchData();
  });
}

function getStatusType(status) {
  const map = { 0: "warning", 1: "primary", 2: "success", 3: "danger" };
  return map[status] || "";
}

function getStatusText(status) {
  const map = { 0: "待发货", 1: "已发货", 2: "已完成", 3: "已取消" };
  return map[status] || "未知";
}

fetchData();
</script>

<style scoped>
.delivery-list {
  height: 100%;
}
.no-link {
  color: #409eff;
  cursor: pointer;
}
.row-ops {
  display: flex;
  gap: 12px;
  justify-content: center;
  flex-wrap: wrap;
}
.row-ops .op {
  cursor: pointer;
  font-size: 13px;
}
.row-ops .op-primary {
  color: #409eff;
}
.row-ops .op-success {
  color: #67c23a;
}
.row-ops .op-danger {
  color: #f56c6c;
}
.detail-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.detail-head .btn-icon {
  font-size: 14px;
  vertical-align: -2px;
  margin-right: 4px;
}
</style>
