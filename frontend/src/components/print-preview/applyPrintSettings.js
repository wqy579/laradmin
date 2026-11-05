/**
 * 把「打印设置 / 纸张设置」作用到单据打印文档上。
 *
 * 单据模板（utils/printTemplates.js）为其它模块共用、本次不在改动范围内，
 * 它产出的 HTML 已内置 @page 与 .page/.company 结构，因此这里在打印与预览
 * 这一层做定向替换，纸张/份数/模板三项设置即时生效，模板文件保持原样。
 */

import { copiesOf, isSimpleTemplate, marginsOf, pageCss, paperSizeOf } from "./printSettings";

/** 替换模板内置的 @page 规则；模板没有该规则时补一条 */
function withPageCss(html, settings) {
  const css = pageCss(settings);
  return /@page\s*\{[^}]*\}/.test(html)
    ? html.replace(/@page\s*\{[^}]*\}/, css)
    : html.replace("</style>", `${css}</style>`);
}

/**
 * 模板把 A4 的排版宽度写死在 html/body 与 .page 上（180mm = 210 - 2×15，
 * 257mm = 297 - 2×20），只换 @page 的话纸张变了正文还是 A4 宽。
 * 这里按「纸张减左右边距 = 正文宽」同步改写，纸型与边距设置才真正生效。
 */
function withPaperBox(html, settings) {
  const { w, h } = paperSizeOf(settings);
  const m = marginsOf(settings);
  const contentW = `${Math.max(10, w - m.l - m.r)}mm`;
  const contentH = `${Math.max(10, h - m.t - m.b)}mm`;
  const widthOnly = (css) => css.replace(/width:\s*[\d.]+mm/, `width: ${contentW};`);
  return html
    .replace(/html,\s*body\s*\{[^}]*\}/, (all) => widthOnly(all))
    .replace(/\.page\s*\{[^}]*\}/, (all) =>
      widthOnly(all).replace(/min-height:\s*[\d.]+mm/, `min-height: ${contentH};`),
    );
}

/** 份数：浏览器没有「打印 N 份」的接口，只能复制正文并强制分页 */
function withCopies(html, settings) {
  const copies = copiesOf(settings);
  if (copies <= 1) return html;
  const body = html.match(/<body[^>]*>([\s\S]*)<\/body>/);
  if (!body) return html;
  // 单据用 .page、报表用 .pg，两者的分页都靠强制 page-break
  const one = body[1].replace(/<div class="(page|pg)">/g, '<div class="$1" style="page-break-after:always">');
  let merged = Array.from({ length: copies }, () => one).join("");
  // 最后一页再强制分页会多打一张白纸
  const last = merged.lastIndexOf('page-break-after:always');
  if (last >= 0) merged = merged.slice(0, last) + merged.slice(last).replace('page-break-after:always', 'page-break-after:auto');
  // 用函数式替换：正文里的 $& 之类会被字符串 replace 当成特殊符号展开
  return html.replace(body[1], () => merged);
}

/** 简洁模板：单据不打印公司名抬头 */
function withoutCompany(html, settings) {
  return isSimpleTemplate(settings)
    ? html.replace(/<div class="company">[\s\S]*?<\/div>/, "")
    : html;
}

export function applyPrintSettings(html, settings) {
  if (!html) return html;
  const papered = withPaperBox(withPageCss(html, settings), settings);
  return withoutCompany(withCopies(papered, settings), settings);
}
