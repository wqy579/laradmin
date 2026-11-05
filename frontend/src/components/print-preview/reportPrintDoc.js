/**
 * 报表族的打印 / 预览 / 导出文档生成。
 *
 * 与单据打印（printTemplates.js）分开：单据是「一张单」，报表是「一张宽表」，
 * 版式与分页逻辑完全不同，共用模板只会两边都将就。
 *
 * PDF / JPG 不引第三方库：本仓前端依赖极少，且 CI 用 npm ci 严格校验 lock，
 * 为两个导出按钮加 html2canvas + jspdf 不划算。这里用
 *   HTML → SVG foreignObject → canvas 光栅化 → JPG（canvas 直接编码）/ PDF（JPEG 塞进 XObject）
 * 中文由浏览器字体引擎直接画进位图，不需要向 PDF 内嵌 CJK 字体。
 */

import {
  isSimpleTemplate,
  loadPrintSettings,
  marginsOf,
  pageCss,
  paperSizeOf,
} from "./printSettings";
import { printDocHtml } from "@/utils/printTemplates";
import { saveBlob } from "@/utils/fileDownload";
import { applyPrintSettings } from "./applyPrintSettings";

/** mm → CSS px（96dpi），与浏览器排版一致 */
const MM_TO_PX = 96 / 25.4;
/** 光栅化倍率：2 倍下 A4 横向约 3600px 宽，字够清晰又不至于内存爆掉 */
const RASTER_SCALE = 2;
/** 每页行数：针式纸/连续导出时按行切页，避免单页图过长 */
const ROWS_PER_PAGE = 25;

function esc(v) {
  return String(v ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#39;");
}

const CELL_STYLES = {
  money: "text-align:right;",
  number: "text-align:right;",
  int: "text-align:right;",
  percent: "text-align:right;",
  rate: "text-align:right;",
};

function isNumeric(col) {
  return ["money", "number", "int", "percent", "rate"].includes(col.type);
}

function formatCell(col, row) {
  const raw = row?.[col.prop];
  if (raw === null || raw === undefined) return "";
  if (col.type === "money") return "¥" + Number(raw || 0).toFixed(2);
  if (col.type === "number") return Number(raw || 0).toFixed(2);
  if (col.type === "int") return String(Math.round(Number(raw || 0)));
  if (col.type === "percent" || col.type === "rate")
    return Number(raw || 0).toFixed(2) + "%";
  if (col.type === "tag") return col.tag_map?.[raw] ?? raw;
  return String(raw);
}

/** 表格片段（不含 html 外壳），样式全部内联：光栅化时外部样式表不会被加载 */
function tableFragment(columns, rows) {
  const head = columns
    .map(
      (c) =>
        `<th style="background:#f0f0f0;border:0.5px solid #000;padding:2px 4px;font-size:12px;font-weight:bold;text-align:center;">${esc(c.label)}</th>`,
    )
    .join("");
  const body = rows
    .map(
      (row) =>
        `<tr>${columns
          .map(
            (c) =>
              `<td style="border:0.5px solid #000;padding:2px 4px;font-size:12px;${
                CELL_STYLES[c.type] || ""
              }">${esc(formatCell(c, row))}</td>`,
          )
          .join("")}</tr>`,
    )
    .join("");
  return `<table style="border-collapse:collapse;width:100%;table-layout:fixed;"><thead><tr>${head}</tr></thead><tbody>${body}</tbody></table>`;
}

function chunkRows(rows, size) {
  if (rows.length <= size) return [rows];
  const pages = [];
  for (let i = 0; i < rows.length; i += size) pages.push(rows.slice(i, i + size));
  return pages;
}

/** 单页正文：抬头（标准模板才有）+ 表格 + 页码 */
function pageInner({ title, columns, rows, settings, pageNo, pageCount }) {
  const company = isSimpleTemplate(settings)
    ? ""
    : `<div style="font-size:18px;font-weight:bold;text-align:center;">${esc(
        settings.companyName || "批发商贸系统",
      )}</div>`;
  const heading = isSimpleTemplate(settings)
    ? ""
    : `<div style="font-size:15px;font-weight:bold;text-align:center;margin:6px 0;">${esc(
        title,
      )}</div>`;
  const footer =
    pageCount > 1
      ? `<div style="font-size:10px;color:#888;text-align:right;margin-top:4px;">第 ${pageNo} / ${pageCount} 页</div>`
      : "";
  return `${company}${heading}${tableFragment(columns, rows)}${footer}`;
}

/**
 * 报表打印文档：按纸张设置分页。
 * 份数不在这里处理 —— 由 applyPrintSettings 统一复制正文，
 * 否则预览路径会「文档复制一次、预览再复制一次」。
 */
export function buildReportDocHtml({
  title = "报表",
  columns = [],
  rows = [],
  settings = loadPrintSettings(),
}) {
  const size = paperSizeOf(settings);
  const pages = chunkRows(rows, ROWS_PER_PAGE);
  let body = "";
  pages.forEach((chunk, i) => {
    const inner = pageInner({
      title,
      columns,
      rows: chunk,
      settings,
      pageNo: i + 1,
      pageCount: pages.length,
    });
    body +=
      i === pages.length - 1
        ? `<div class="pg">${inner}</div>`
        : `<div class="pg" style="page-break-after:always">${inner}</div>`;
  });
  // .pg 用「纸张减边距」的内容宽，写死整纸宽会顶出 @page 边距
  const m = marginsOf(settings);
  const contentW = Math.max(10, size.w - m.l - m.r);
  const contentH = Math.max(10, size.h - m.t - m.b);
  return `<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><title>${esc(
    title,
  )}</title><style>${pageCss(
    settings,
  )}*{margin:0;padding:0;box-sizing:border-box;}html,body{font-family:SimSun,"宋体",serif;color:#000;line-height:1.5;-webkit-print-color-adjust:exact;print-color-adjust:exact;}.pg{width:${contentW}mm;min-height:${contentH}mm;}</style></head><body>${body}</body></html>`;
}

/** 报表打印：走与单据打印相同的隐藏 iframe 通道，份数设置在这里生效 */
export function printReportDoc(options) {
  const settings = options.settings || loadPrintSettings();
  const html = buildReportDocHtml({ ...options, settings });
  printDocHtml(applyPrintSettings(html, settings));
}

/** 安全文件名：去掉路径分隔符等浏览器不接受的字符 */
function safeName(name) {
  return String(name || "报表").replace(/[\\/:*?"<>|]/g, "_");
}

/**
 * HTML 片段 → canvas。
 * 用 foreignObject 让浏览器自己排版 HTML，再把 SVG 画到 canvas；
 * 片段必须是合法 XML（本模块生成的表格满足：无未闭合空元素）。
 */
function rasterize(innerHtml, widthMm, heightMm) {
  const wPx = Math.round(widthMm * MM_TO_PX * RASTER_SCALE);
  const hPx = Math.round(heightMm * MM_TO_PX * RASTER_SCALE);
  const svg =
    `<svg xmlns="http://www.w3.org/2000/svg" width="${wPx}" height="${hPx}">` +
    `<foreignObject width="100%" height="100%">` +
    `<div xmlns="http://www.w3.org/1999/xhtml" style="width:${widthMm}mm;height:${heightMm}mm;padding:6mm;box-sizing:border-box;background:#ffffff;font-family:SimSun,serif;color:#000;">${innerHtml}</div>` +
    `</foreignObject></svg>`;
  const url = "data:image/svg+xml;charset=utf-8," + encodeURIComponent(svg);
  return new Promise((resolve, reject) => {
    const img = new Image();
    img.onload = () => {
      const canvas = document.createElement("canvas");
      canvas.width = wPx;
      canvas.height = hPx;
      const ctx = canvas.getContext("2d");
      // JPEG 没有透明通道，不铺白底的话空白处会变黑
      ctx.fillStyle = "#ffffff";
      ctx.fillRect(0, 0, wPx, hPx);
      ctx.drawImage(img, 0, 0, wPx, hPx);
      resolve(canvas);
    };
    img.onerror = () => reject(new Error("报表渲染失败"));
    img.src = url;
  });
}

function canvasToJpeg(canvas, quality = 0.92) {
  return new Promise((resolve, reject) => {
    canvas.toBlob(
      (blob) => (blob ? resolve(blob) : reject(new Error("图片生成失败"))),
      "image/jpeg",
      quality,
    );
  });
}

function blobToBytes(blob) {
  return blob.arrayBuffer().then((buf) => new Uint8Array(buf));
}

function textBytes(s) {
  return new TextEncoder().encode(s);
}

/**
 * 把若干 JPEG 组装成 PDF（每页一张整页图，DCTDecode 直接内嵌 JPEG，无需重编码）。
 * 只做「图片页」这一件事，因此可以完全手写对象表而不引库。
 */
async function buildPdf(jpegs, widthMm, heightMm) {
  const W = ((widthMm * 72) / 25.4).toFixed(2);
  const H = ((heightMm * 72) / 25.4).toFixed(2);
  const n = jpegs.length;
  // 对象编号：1 目录，2 页树，之后每页两个对象（页 + 图），内容流共用一个
  const firstPageObj = 3;
  const contentObj = firstPageObj + n * 2;
  const total = contentObj;

  const kids = [];
  for (let i = 0; i < n; i++) kids.push(`${firstPageObj + i * 2} 0 R`);

  const parts = [];
  const offsets = [];
  let offset = 0;
  const push = (bytes) => {
    parts.push(bytes);
    offset += bytes.length;
  };
  const obj = (num, body) => {
    offsets[num] = offset;
    push(textBytes(`${num} 0 obj\n${body}\nendobj\n`));
  };
  const streamObj = (num, dict, bytes) => {
    offsets[num] = offset;
    push(textBytes(`${num} 0 obj\n${dict}\nstream\n`));
    push(bytes);
    push(textBytes("\nendstream\nendobj\n"));
  };

  push(textBytes("%PDF-1.4\n"));
  obj(1, `<< /Type /Catalog /Pages 2 0 R >>`);
  obj(2, `<< /Type /Pages /Count ${n} /Kids [${kids.join(" ")}] >>`);
  const content = `q ${W} 0 0 ${H} 0 0 cm /Im0 Do Q`;
  for (let i = 0; i < n; i++) {
    const pageObj = firstPageObj + i * 2;
    const imgObj = pageObj + 1;
    obj(
      pageObj,
      `<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ${W} ${H}] ` +
        `/Resources << /XObject << /Im0 ${imgObj} 0 R >> >> /Contents ${contentObj} 0 R >>`,
    );
    const { width, height } = await jpegSize(jpegs[i]);
    streamObj(
      imgObj,
      `<< /Type /XObject /Subtype /Image /Width ${width} /Height ${height} ` +
        `/ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ${jpegs[i].length} >>`,
      jpegs[i],
    );
  }
  obj(contentObj, `<< /Length ${content.length} >>\nstream\n${content}\nendstream`);

  const xrefStart = offset;
  let xref = `xref\n0 ${total + 1}\n0000000000 65535 f \n`;
  for (let i = 1; i <= total; i++)
    xref += String(offsets[i] || 0).padStart(10, "0") + " 00000 n \n";
  push(
    textBytes(
      `${xref}trailer\n<< /Size ${total + 1} /Root 1 0 R >>\nstartxref\n${xrefStart}\n%%EOF\n`,
    ),
  );
  return new Blob(parts, { type: "application/pdf" });
}

/** 从 JPEG 头部读出宽高（SOF0/SOF2 标记），用于 PDF 里的 Image 字典 */
function jpegSize(bytes) {
  let i = 2;
  while (i < bytes.length) {
    if (bytes[i] !== 0xff) {
      i++;
      continue;
    }
    const marker = bytes[i + 1];
    const isSof =
      marker >= 0xc0 &&
      marker <= 0xcf &&
      ![0xc4, 0xc8, 0xcc].includes(marker);
    const len = (bytes[i + 2] << 8) | bytes[i + 3];
    if (isSof)
      return {
        height: (bytes[i + 5] << 8) | bytes[i + 6],
        width: (bytes[i + 7] << 8) | bytes[i + 8],
      };
    // 独立标记（无长度字段）要跳过，否则会把标记字节当长度读飞
    i += [0xd8, 0xd9].includes(marker) || (marker >= 0xd0 && marker <= 0xd7) ? 2 : 2 + len;
  }
  throw new Error("无法解析图片尺寸");
}

/** 工作表名限制 31 字符且不允许 []:*?/\ */
function sheetName(title) {
  return (
    String(title || "报表")
      .replace(/[\\/:*?[\]]/g, "_")
      .slice(0, 31) || "报表"
  );
}

/** 数值列输出真数字，Excel 里才能继续算；其余按文本 */
function cellXml(col, row) {
  const raw = row?.[col.prop];
  if (raw === null || raw === undefined || raw === "") {
    return `<Cell><Data ss:Type="String">${esc(formatCell(col, row))}</Data></Cell>`;
  }
  if (isNumeric(col) && Number.isFinite(Number(raw))) {
    return `<Cell><Data ss:Type="Number">${Number(raw)}</Data></Cell>`;
  }
  return `<Cell><Data ss:Type="String">${esc(formatCell(col, row))}</Data></Cell>`;
}

/**
 * 报表 XLS（Excel 2003 XML，与后端 ReportFilter::excel() 同一格式）。
 * 前端本地直接生成，不必为「导出当前页」再排一次后端异步任务。
 */
function buildReportXls({
  title = "报表",
  columns = [],
  rows = [],
  picked = columns,
}) {
  const cols = picked.length ? picked : columns;
  const head = cols
    .map(
      (c) =>
        `<Cell ss:StyleID="head"><Data ss:Type="String">${esc(c.label)}</Data></Cell>`,
    )
    .join("");
  const body = rows
    .map((row) => `<Row>${cols.map((c) => cellXml(c, row)).join("")}</Row>`)
    .join("");
  return `<?xml version="1.0" encoding="UTF-8"?>\n<?mso-application progid="Excel.Sheet"?>\n<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"><Styles><Style ss:ID="head"><Font ss:Bold="1"/><Interior ss:Color="#F0F0F0" ss:Pattern="Solid"/></Style></Styles><Worksheet ss:Name="${esc(sheetName(title))}"><Table><Row>${head}</Row>${body}</Table></Worksheet></Workbook>`;
}

/** 导出 XLS：与后端导出同名规则，只是数据源换成当前表格 */
export function exportReportXls(options) {
  const name = safeName(options.title || "报表");
  saveBlob(
    new Blob([buildReportXls(options)], {
      type: "application/vnd.ms-excel;charset=utf-8",
    }),
    `${name}.xls`,
  );
}

/** 导出 JPG：当前数据一页一张 */
export async function exportReportJpg({
  title = "报表",
  columns = [],
  rows = [],
  settings = loadPrintSettings(),
}) {
  const size = paperSizeOf(settings);
  const pages = chunkRows(rows, ROWS_PER_PAGE);
  for (let i = 0; i < pages.length; i++) {
    const canvas = await rasterize(
      pageInner({
        title,
        columns,
        rows: pages[i],
        settings,
        pageNo: i + 1,
        pageCount: pages.length,
      }),
      size.w,
      size.h,
    );
    const blob = await canvasToJpeg(canvas);
    saveBlob(
      blob,
      `${safeName(title)}${pages.length > 1 ? `_第${i + 1}页` : ""}.jpg`,
    );
  }
  return pages.length;
}

/** 导出 PDF：所有页合成一个文件 */
export async function exportReportPdf({
  title = "报表",
  columns = [],
  rows = [],
  settings = loadPrintSettings(),
}) {
  const size = paperSizeOf(settings);
  const pages = chunkRows(rows, ROWS_PER_PAGE);
  const jpegs = [];
  for (let i = 0; i < pages.length; i++) {
    const canvas = await rasterize(
      pageInner({
        title,
        columns,
        rows: pages[i],
        settings,
        pageNo: i + 1,
        pageCount: pages.length,
      }),
      size.w,
      size.h,
    );
    jpegs.push(await blobToBytes(await canvasToJpeg(canvas)));
  }
  const blob = await buildPdf(jpegs, size.w, size.h);
  saveBlob(blob, `${safeName(title)}.pdf`);
  return pages.length;
}
