/**
 * 单据打印模板生成器。
 *
 * 导出函数各返回一个「自包含的完整 HTML 文档」字符串（含 @page 纸张规则），
 * 供两个用途复用：
 *   1. 打印预览弹窗：作为 iframe 的 srcdoc 渲染出可缩放的纸张预览；
 *   2. 实际打印：经 printDocHtml() 写入隐藏 iframe 后调用其 print()。
 *
 * 预览缩放是对 iframe 元素做 CSS transform，不影响 iframe 内部文档；
 * 打印则用独立的全尺寸 iframe，避免缩放污染打印结果。
 *
 * 纸张统一 A4 纵向：210mm × 297mm，上下边距 20mm、左右边距 15mm（任务规格）。
 * 字段取值均对齐后端 show/print 接口的真实返回结构（见各函数注释）。
 */

const DEFAULT_COMPANY = "批发商贸系统";

/** 统一的 A4 纵向纸张与公共排版样式 */
const PAGE_STYLE = `
@page { size: 210mm 297mm; margin: 20mm 15mm; }
* { margin: 0; padding: 0; box-sizing: border-box; }
html, body { width: 210mm; }
body {
  font-family: SimSun, "宋体", serif;
  font-size: 12px;
  color: #000000;
  line-height: 1.5;
  -webkit-print-color-adjust: exact;
  print-color-adjust: exact;
}
.page { width: 180mm; min-height: 257mm; position: relative; }
/* 顶部：公司名 + 单据标题 + 右上角单号/日期 */
.head { position: relative; padding-bottom: 2mm; border-bottom: 0.5mm solid #000; }
.company { font-size: 20px; font-weight: bold; text-align: center; color: #000000; font-family: SimSun, "宋体", serif; }
.doc-title { font-size: 16px; font-weight: bold; text-align: center; color: #000000; margin-top: 10px; }
.head-meta { position: absolute; top: 0; right: 0; text-align: right; font-size: 12px; color: #333333; line-height: 1.8; }
/* 信息区 */
.info { margin-top: 3mm; font-size: 12px; }
.info-row { display: flex; justify-content: space-between; line-height: 22px; }
.info-row .left { display: flex; gap: 30mm; }
/* 明细表格 */
.items { width: 100%; border-collapse: collapse; border: 1px solid #000000; margin-top: 3mm; table-layout: fixed; }
.items th { background: #F0F0F0; font-size: 12px; font-weight: bold; text-align: center; height: 24px; border: 1px solid #000000; padding: 0 2px; }
.items td { font-size: 12px; text-align: center; height: 24px; border: 1px solid #000000; padding: 0 2px; word-break: break-all; }
.items .num { text-align: right; }
.items .total-row td { background: #F0F0F0; font-weight: bold; }
/* 底部 */
.foot { margin-top: 4mm; font-size: 12px; }
.foot-row { display: flex; justify-content: space-between; line-height: 26px; }
.remark { font-size: 12px; line-height: 20px; }
.print-info { font-size: 10px; color: #888; text-align: right; margin-top: 3mm; }
`;

/** HTML 转义，防止单据内容里的 < & 破坏模板结构 */
function esc(v) {
  return String(v ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#39;");
}

/** 金额格式化：千分位 + 两位小数 */
function money(v) {
  const n = Number(v);
  if (!isFinite(n)) return "0.00";
  return n.toLocaleString("zh-CN", {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });
}

function pad2(n) {
  return String(n).padStart(2, "0");
}

const CN_NUM = ["零", "壹", "贰", "叁", "肆", "伍", "陆", "柒", "捌", "玖"];
const CN_UNIT = ["", "拾", "佰", "仟"];
const CN_SECTION = ["", "万", "亿"];

/**
 * 金额转人民币大写（如 199 → 壹佰玖拾玖元整）。
 * 支持到亿级、两位小数，负数加「负」前缀。
 */
export function numberToChineseUpper(value) {
  const raw = Number(value);
  if (!isFinite(raw) || raw === 0) return "零元整";
  const negative = raw < 0;
  const n = Math.round(Math.abs(raw) * 100) / 100;
  const integer = Math.floor(n);
  const cents = Math.round((n - integer) * 100);
  let intPart = "";
  if (integer > 0) {
    const groups = [];
    let rest = integer;
    while (rest > 0) {
      groups.push(rest % 10000);
      rest = Math.floor(rest / 10000);
    }
    const segs = [];
    for (let g = groups.length - 1; g >= 0; g--) {
      const num = groups[g];
      if (num === 0) continue;
      let seg = "";
      let zeroPending = false;
      for (let i = 3; i >= 0; i--) {
        const d = Math.floor(num / Math.pow(10, i)) % 10;
        if (d === 0) {
          if (seg && !zeroPending) zeroPending = true;
        } else {
          if (zeroPending) {
            seg += "零";
            zeroPending = false;
          }
          seg += CN_NUM[d] + CN_UNIT[i];
        }
      }
      segs.push(seg + CN_SECTION[g]);
    }
    intPart = segs.join("");
  }
  let result = (negative ? "负" : "") + (intPart ? intPart + "元" : "");
  if (cents === 0) result += "整";
  else {
    const jiao = Math.floor(cents / 10);
    const fen = cents % 10;
    if (jiao > 0) result += CN_NUM[jiao] + "角";
    if (fen > 0) {
      if (jiao === 0) result += "零";
      result += CN_NUM[fen] + "分";
    }
  }
  return result;
}

/** 大写金额：人民币壹佰玖拾玖元整 */
function upperAmount(v) {
  return "人民币" + numberToChineseUpper(v);
}

/** 打印日期：YYYY-MM-DD */
function printDate(d = new Date()) {
  return `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())}`;
}

/** 产品字段归一化：后端 products 无 sku 字段，编码取 code，规格优先 spec_display */
function productText(product) {
  const p = product || {};
  return {
    code: p.code || p.barcode_large || "-",
    name: p.name || "-",
    spec: p.spec_display || p.spec || "-",
    unit: p.price_unit || p.barcode_medium_unit || p.price_unit_small || "-",
  };
}

/** 组装完整 HTML 文档外壳 */
function docShell(title, pagesHtml) {
  return `<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<title>${esc(title)}</title>
<style>${PAGE_STYLE}</style>
</head>
<body>
${pagesHtml}
</body>
</html>`;
}

/** 包裹单页；批量打印时除最后一页外都强制分页 */
function pageWrap(inner, { last = false } = {}) {
  return last
    ? `<div class="page">${inner}</div>`
    : `<div class="page" style="page-break-after:always">${inner}</div>`;
}

/** 顶部区块：公司名 + 单据标题 + 右上角两行元信息 */
function headBlock(record, { title, meta }) {
  const company = record.company_name || DEFAULT_COMPANY;
  return `
<div class="head">
	<div class="company">${esc(company)}</div>
	<div class="doc-title">${esc(title)}</div>
	<div class="head-meta">
		<div>${esc(meta[0])}</div>
		<div>${esc(meta[1])}</div>
	</div>
</div>`;
}

/**
 * 订单单页内容。
 *
 * 后端 SalesOrderController::print 返回 SalesOrder，已 load
 * customer / warehouse / salesman / items.product。
 * salesman_name 是 sales_orders 表真实列。
 * 明细是「大/中/小」三档规格，打印时取首个有数量的档位展开为一行。
 */
export function orderPageInner(order) {
  const items = (order.items || []).map((item) => {
    const p = productText(item.product);
    const hasLarge = Number(item.qty_large) > 0;
    const hasMedium = !hasLarge && Number(item.qty_medium) > 0;
    const unit = hasLarge
      ? p.unit
      : hasMedium
        ? item.product?.barcode_medium_unit || "盒"
        : item.product?.price_unit_small || "袋";
    const price = hasLarge
      ? item.price_large
      : hasMedium
        ? item.price_medium
        : item.price_small;
    const qty = hasLarge
      ? item.qty_large
      : hasMedium
        ? item.qty_medium
        : item.qty_small;
    return { ...p, unit, qty, price, amount: item.amount };
  });
  const rows = items
    .map(
      (it, i) => `<tr>
			<td>${i + 1}</td>
			<td>${esc(it.code)}</td>
			<td>${esc(it.name)}</td>
			<td>${esc(it.spec)}</td>
			<td>${esc(it.unit)}</td>
			<td class="num">${money(it.qty)}</td>
			<td class="num">${money(it.price)}</td>
			<td class="num">${money(it.amount)}</td>
		</tr>`,
    )
    .join("");
  const totalQty = items.reduce((s, it) => s + Number(it.qty || 0), 0);
  const totalAmount =
    order.total_amount ??
    items.reduce((s, it) => s + Number(it.amount || 0), 0);
  const customer = order.customer || {};

  return `${headBlock(order, {
    title: "销售订单",
    meta: [`订单号：${order.order_no}`, `打印日期：${printDate()}`],
  })}
<div class="info">
	<div class="info-row">
		<div class="left"><span>客户名称：${esc(customer.name || "-")}</span></div>
		<div class="left" style="margin-left:auto"><span>业务员：${esc(order.salesman_name || "-")}</span></div>
	</div>
	<div class="info-row">
		<div class="left"><span>联系电话：${esc(customer.phone || "-")}</span></div>
	</div>
	<div class="info-row">
		<div class="left"><span>送货地址：${esc(customer.address || "-")}</span></div>
	</div>
</div>
<table class="items">
	<colgroup>
		<col style="width:30px"><col style="width:80px"><col style="width:180px"><col style="width:80px">
		<col style="width:40px"><col style="width:60px"><col style="width:70px"><col style="width:80px">
	</colgroup>
	<thead><tr>
		<th>序号</th><th>商品编码</th><th>商品名称</th><th>规格</th>
		<th>单位</th><th>数量</th><th>单价</th><th>金额</th>
	</tr></thead>
	<tbody>
		${rows || '<tr><td colspan="8">无明细</td></tr>'}
		<tr class="total-row">
			<td colspan="5" style="text-align:right">合计</td>
			<td class="num">${money(totalQty)}</td>
			<td></td>
			<td class="num">${money(totalAmount)}</td>
		</tr>
	</tbody>
</table>
<div class="remark">备注：${esc(order.remark || "")}</div>
<div class="foot">
	<div class="foot-row">
		<span>客户签字：____________</span>
		<span>审核人：____________</span>
	</div>
	<div class="foot-row">
		<span></span>
		<span>制单人：${esc(order.print_user || "admin")}</span>
	</div>
</div>
<div class="print-info">打印日期 ${printDate()}</div>`;
}

/** 订单打印单：单张 */
export function buildOrderDocHtml(order) {
  return docShell("销售订单", pageWrap(orderPageInner(order), { last: true }));
}

/** 订单打印单：多张连续分页（批量打印用） */
export function buildOrderBatchDocHtml(orders) {
  const pages = (orders || []).map((o, i, arr) =>
    pageWrap(orderPageInner(o), { last: i === arr.length - 1 }),
  );
  return docShell("销售订单", pages.join(""));
}

/**
 * 配送单单页内容。
 *
 * 后端 DeliveryController::show 返回 Delivery，已 load
 * customer / warehouse / vehicle / driver(Employee) / items.product。
 * 关键：deliveries 表无 driver_name / 车牌扁平列，
 * 配送员取 driver.name，车牌取 vehicle.plate_no；
 * delivery_items 只有 quantity / unit_price / amount，
 * 「实收数量 / 差异数量」后端不存在，留空供配送员现场手写。
 */
export function deliveryPageInner(delivery) {
  const items = delivery.items || [];
  const rows = items
    .map((it, i) => {
      const p = productText(it.product);
      return `<tr>
			<td>${i + 1}</td>
			<td>${esc(p.code)}</td>
			<td>${esc(p.name)}</td>
			<td>${esc(p.spec)}</td>
			<td>${esc(p.unit)}</td>
			<td class="num">${money(it.quantity)}</td>
			<td></td>
			<td></td>
		</tr>`;
    })
    .join("");
  const customer = delivery.customer || {};
  const driverName =
    delivery.driver?.name || delivery.vehicle?.driver_name || "";

  return `${headBlock(delivery, {
    title: "配送单",
    meta: [
      `配送单号：${delivery.delivery_no}`,
      `配送日期：${delivery.delivery_date || "-"}`,
    ],
  })}
<div class="info">
	<div class="info-row">
		<div class="left"><span>客户名称：${esc(customer.name || "-")}</span></div>
		<div class="left" style="margin-left:auto"><span>配送员：${esc(driverName || "-")}</span></div>
	</div>
	<div class="info-row">
		<div class="left"><span>联系电话：${esc(customer.phone || "-")}</span></div>
		<div class="left" style="margin-left:auto"><span>车牌号：${esc(delivery.vehicle?.plate_no || "-")}</span></div>
	</div>
	<div class="info-row">
		<div class="left"><span>送货地址：${esc(customer.address || "-")}</span></div>
	</div>
</div>
<table class="items">
	<colgroup>
		<col style="width:30px"><col style="width:70px"><col style="width:150px"><col style="width:70px">
		<col style="width:40px"><col style="width:62px"><col style="width:62px"><col style="width:62px">
	</colgroup>
	<thead><tr>
		<th>序号</th><th>商品编码</th><th>商品名称</th><th>规格</th>
		<th>单位</th><th>配送数量</th><th>实收数量</th><th>差异数量</th>
	</tr></thead>
	<tbody>
		${rows || '<tr><td colspan="8">无明细</td></tr>'}
	</tbody>
</table>
<div class="remark">备注：${esc(delivery.remark || "")}</div>
<div class="foot">
	<div class="foot-row">
		<span>客户签收：____________</span>
		<span>签收日期：____________</span>
	</div>
	<div class="foot-row">
		<span></span>
		<span>配送员：____________</span>
	</div>
</div>
<div class="print-info">打印日期 ${printDate()}</div>`;
}

/** 配送单打印：单张 */
export function buildDeliveryDocHtml(delivery) {
  return docShell(
    "配送单",
    pageWrap(deliveryPageInner(delivery), { last: true }),
  );
}

/**
 * 收款单单页内容。
 *
 * 后端 ReceiveController::show 返回 receives 行 + 附加两个键：
 *   cash_flow   —— 该收款对应的 cash_flows 流水
 *   order_items —— 核销明细，每项固定为
 *                  { order_id, order_no, pay_amount, paid_before, receivable_before }
 * receivable_before = 核销前该订单应收额（total_amount - paid_before），
 * 直接对应打印列「应收金额」；后端未存订单日期与行备注，留空。
 */
export function receivePageInner(receive) {
  const items = receive.order_items || receive.items || [];
  const rows = items
    .map(
      (it, i) => `<tr>
			<td>${i + 1}</td>
			<td>${esc(it.order_no || "-")}</td>
			<td></td>
			<td class="num">${money(it.receivable_before ?? it.receivable_amount)}</td>
			<td class="num">${money(it.paid_before ?? it.received_amount)}</td>
			<td class="num">${money(it.pay_amount ?? it.amount)}</td>
			<td></td>
		</tr>`,
    )
    .join("");
  const customer = receive.customer || {};

  return `${headBlock(receive, {
    title: "收款单",
    meta: [
      `收款单号：${receive.receive_no}`,
      `收款日期：${receive.receive_date || "-"}`,
    ],
  })}
<div class="info">
	<div class="info-row">
		<div class="left"><span>客户名称：${esc(customer.name || "-")}</span></div>
		<div class="left" style="margin-left:auto"><span>联系电话：${esc(customer.phone || "-")}</span></div>
	</div>
	<div class="info-row">
		<div class="left"><span>收款方式：${esc(receive.payment_method || "-")}</span></div>
	</div>
	<div class="info-row" style="align-items:center">
		<div class="left" style="font-size:14px;font-weight:bold">
			<span>大写：${esc(upperAmount(receive.amount))}</span>
		</div>
		<div class="left" style="font-size:14px;font-weight:bold;margin-left:auto">
			<span>小写：¥${money(receive.amount)}</span>
		</div>
	</div>
</div>
<table class="items">
	<colgroup>
		<col style="width:36px"><col style="width:130px"><col style="width:80px"><col style="width:80px">
		<col style="width:80px"><col style="width:80px"><col>
	</colgroup>
	<thead><tr>
		<th>序号</th><th>订单号</th><th>订单日期</th><th>应收金额</th>
		<th>已收金额</th><th>本次核销</th><th>备注</th>
	</tr></thead>
	<tbody>
		${rows || '<tr><td colspan="7">无核销明细</td></tr>'}
	</tbody>
</table>
<div class="remark">备注：${esc(receive.remark || "")}</div>
<div class="foot">
	<div class="foot-row">
		<span>客户签字：____________</span>
		<span>制单人：${esc(receive.print_user || "admin")}</span>
	</div>
	<div class="foot-row">
		<span></span>
		<span>出纳签字：____________</span>
	</div>
</div>
<div class="print-info">打印日期 ${printDate()}</div>`;
}

/** 收款单打印：单张 */
export function buildReceiveDocHtml(receive) {
  return docShell(
    "收款单",
    pageWrap(receivePageInner(receive), { last: true }),
  );
}

/**
 * 把打印模板 HTML 写入隐藏 iframe 并触发浏览器打印。
 * 用独立 iframe 而非预览 iframe，保证打印结果是未缩放的全尺寸纸张。
 */
export function printDocHtml(docHtml) {
  const iframe = document.createElement("iframe");
  iframe.style.position = "fixed";
  iframe.style.right = "0";
  iframe.style.bottom = "0";
  iframe.style.width = "0";
  iframe.style.height = "0";
  iframe.style.border = "0";
  document.body.appendChild(iframe);
  const doc = iframe.contentWindow.document;
  doc.open();
  doc.write(docHtml);
  doc.close();
  iframe.contentWindow.focus();
  // 留一帧等字体/样式就绪，否则 Chrome 可能写出空白页
  setTimeout(() => {
    iframe.contentWindow.print();
    setTimeout(() => document.body.removeChild(iframe), 1000);
  }, 300);
}
