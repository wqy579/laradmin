import { reactive } from "vue";
import { ElMessage } from "element-plus";
import businessApi from "@/api/business";
import { useUserStore } from "@/stores/modules/user";
import {
  buildOrderDocHtml,
  buildOrderBatchDocHtml,
  buildDeliveryDocHtml,
  buildReceiveDocHtml,
} from "@/utils/printTemplates";

const SETTINGS_KEY = "print_settings";

function loadSettings() {
  try {
    const raw = localStorage.getItem(SETTINGS_KEY);
    return { companyName: "", ...(raw ? JSON.parse(raw) : {}) };
  } catch {
    return { companyName: "" };
  }
}

/** 打印人：取当前登录用户名，缺失时回退 admin */
function currentUser() {
  const store = useUserStore();
  const info = store.userInfo || {};
  return info.username || info.real_name || info.name || "admin";
}

/**
 * 单据打印：加载详情 → 生成打印文档 → 打开预览弹窗。
 *
 * 用法：
 *   const { preview, printOrder } = useDocumentPrint()
 *   // 模板：<PrintPreview v-model="preview.visible" v-bind="preview" :rebuild="preview.rebuild" />
 *   // 操作按钮：@click="printOrder(row)"
 *
 * preview 是 reactive 对象，直接 v-bind 即可把 title/docHtml/loading 传给弹窗。
 * 公司名走 localStorage 持久化，修改后立即重渲染预览，无需重新加载单据。
 */
export function useDocumentPrint() {
  const preview = reactive({
    visible: false,
    loading: false,
    title: "打印预览",
    docHtml: "",
    /** 打印设置里改公司名后回调，用新值重渲染当前单据 */
    rebuild: null,
  });

  // 当前待打印单据的快照，供 rebuild 用
  let current = null;

  /** 把打印设置里的公司名注入单据（公司名优先级：设置 > 单据自带 > 默认） */
  function withCompany(record) {
    if (!record) return record;
    const companyName = loadSettings().companyName;
    return companyName ? { ...record, company_name: companyName } : record;
  }

  function rebuild(companyName) {
    if (!current) return;
    // 批量打印时 record 是数组，逐张注入公司名
    if (Array.isArray(current.record)) {
      current.record = current.record.map((r) =>
        companyName ? { ...r, company_name: companyName } : r,
      );
    } else if (companyName) {
      current.record = { ...current.record, company_name: companyName };
    }
    preview.docHtml = current.build(current.record);
  }

  function open(build, record, title) {
    current = { build, record };
    preview.rebuild = rebuild;
    preview.title = title;
    preview.docHtml = build(record);
    preview.visible = true;
  }

  async function run(title, load, build) {
    preview.visible = true;
    preview.loading = true;
    preview.title = title;
    try {
      const data = await load();
      const record = withCompany(data);
      open(build, record, title);
    } catch (e) {
      preview.visible = false;
      preview.loading = false;
      ElMessage.error(e?.response?.data?.message || "单据加载失败");
    } finally {
      preview.loading = false;
    }
  }

  /** 打印人注入：打印接口返回的记录需要带上当前操作者 */
  function markPrintUser(record) {
    return { ...(record || {}), print_user: currentUser() };
  }

  /**
   * 传列表行（仅 id）或已加载的完整记录都可以：
   * 有 id 且缺明细时拉接口，已是完整记录（如详情弹窗的数据）就直接用。
   */
  function needsLoad(record) {
    return (
      record &&
      !Array.isArray(record) &&
      !record.items &&
      !record.order_items &&
      !record.orderItems
    );
  }

  /** 订单打印：走 print 接口（后端累计打印次数并返回全量加载的订单） */
  const printOrder = (row) =>
    run(
      "订单打印预览",
      async () => {
        if (needsLoad(row)) {
          const res = await businessApi.salesOrder.print.post(row.id);
          return markPrintUser(res.data);
        }
        return markPrintUser(row);
      },
      buildOrderDocHtml,
    );

  /** 配送单打印：配送员/车牌需走 detail 接口加载关联 */
  const printDelivery = (row) =>
    run(
      "配送单打印预览",
      async () => {
        if (needsLoad(row)) {
          const res = await businessApi.delivery.detail.get(row.id);
          return res.data;
        }
        return row;
      },
      buildDeliveryDocHtml,
    );

  /** 收款单打印：核销明细随 detail 接口返回 */
  const printReceive = (row) =>
    run(
      "收款单打印预览",
      async () => {
        if (needsLoad(row)) {
          const res = await businessApi.receive.detail.get(row.id);
          return res.data;
        }
        return row;
      },
      buildReceiveDocHtml,
    );

  /** 批量订单打印：多张连续分页，一次打印 */
  async function batchPrintOrders(rows) {
    const list = rows || [];
    if (!list.length) return 0;
    preview.visible = true;
    preview.loading = true;
    preview.title = `订单打印预览（${list.length} 单）`;
    const orders = [];
    for (const row of list) {
      try {
        const res = await businessApi.salesOrder.print.post(row.id);
        if (res.data)
          orders.push(withCompany({ ...res.data, print_user: currentUser() }));
      } catch {
        /* 单笔失败跳过，不阻断整批 */
      }
    }
    preview.loading = false;
    if (!orders.length) {
      preview.visible = false;
      ElMessage.warning("没有可打印的订单");
      return 0;
    }
    open(buildOrderBatchDocHtml, orders, preview.title);
    return orders.length;
  }

  return { preview, printOrder, printDelivery, printReceive, batchPrintOrders };
}
