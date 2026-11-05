/**
 * 打印设置 / 纸张设置的公共存储与 @page 样式生成。
 *
 * 单据打印（print-preview 弹窗）与报表打印（ReportToolbar）共用同一份设置，
 * 存 localStorage，改一次全站生效。
 */

export const SETTINGS_KEY = "print_settings";

/** 纸张预设：连凯对标里报表与备货单常用的三档 */
export const PAPERS = [
  { value: "A4", label: "A4（210×297mm）", w: 210, h: 297 },
  {
    value: "DOT",
    label: "针式连续纸（241×140mm）",
    w: 241,
    h: 140,
    hint: "针式打印机连续走纸，适合多联单据",
  },
  { value: "CUSTOM", label: "自定义尺寸", w: 0, h: 0 },
];

export const ORIENTATIONS = [
  { value: "portrait", label: "纵向" },
  { value: "landscape", label: "横向" },
];

export const COPIES_OPTIONS = [1, 2, 3];

export const MARGINS = [
  { value: "20/15", label: "上下 20mm / 左右 15mm", t: 20, r: 15, b: 20, l: 15 },
  { value: "10/10", label: "四边 10mm", t: 10, r: 10, b: 10, l: 10 },
  { value: "5/5", label: "四边 5mm（省纸）", t: 5, r: 5, b: 5, l: 5 },
];

export const TEMPLATES = [
  { value: "standard", label: "标准（含公司名与表头）" },
  { value: "simple", label: "简洁（仅表格数据）" },
];

const DEFAULTS = {
  /** 公司名：留空用各模板默认值 */
  companyName: "",
  /** 打印模板 */
  template: "standard",
  /** 份数 */
  copies: 1,
  /** 纸张 */
  paper: "A4",
  /** 纵向 / 横向 */
  orientation: "portrait",
  /** 自定义纸张宽高（mm），仅 paper=CUSTOM 生效 */
  customW: 210,
  customH: 297,
  /** 边距预设 */
  margin: "20/15",
};

export function loadPrintSettings() {
  try {
    const raw = localStorage.getItem(SETTINGS_KEY);
    return { ...DEFAULTS, ...(raw ? JSON.parse(raw) : {}) };
  } catch {
    return { ...DEFAULTS };
  }
}

export function savePrintSettings(settings) {
  localStorage.setItem(SETTINGS_KEY, JSON.stringify(settings));
}

/** 当前设置对应的纸张物理尺寸（mm，已按方向换算） */
export function paperSizeOf(settings = loadPrintSettings()) {
  const preset = PAPERS.find((p) => p.value === settings.paper) || PAPERS[0];
  let w = settings.paper === "CUSTOM" ? Number(settings.customW) : preset.w;
  let h = settings.paper === "CUSTOM" ? Number(settings.customH) : preset.h;
  w = Number.isFinite(w) && w > 0 ? w : 210;
  h = Number.isFinite(h) && h > 0 ? h : 297;
  if (settings.orientation === "landscape") [w, h] = [h, w];
  return { w, h };
}

export function marginsOf(settings) {
  const m = MARGINS.find((x) => x.value === settings.margin);
  return m || MARGINS[0];
}

/** 打印份数：非法值一律回退 1 */
export function copiesOf(settings = loadPrintSettings()) {
  const n = Math.round(Number(settings.copies));
  return n >= 1 && n <= COPIES_OPTIONS[COPIES_OPTIONS.length - 1] ? n : 1;
}

/** 简洁模板：只出表格，不渲染公司名与单据抬头 */
export function isSimpleTemplate(settings = loadPrintSettings()) {
  return settings.template === "simple";
}

/**
 * 生成打印文档用的 @page 规则。
 * 横向时用 size {高} {宽}（先宽后高的逆序）表达旋转，浏览器兼容性最稳。
 */
export function pageCss(settings = loadPrintSettings()) {
  const { w, h } = paperSizeOf(settings);
  const m = marginsOf(settings);
  return `@page { size: ${w}mm ${h}mm; margin: ${m.t}mm ${m.r}mm ${m.b}mm ${m.l}mm; }`;
}

/** 供设置弹窗展示的纸张可读名，如「A4 横向」 */
export function paperLabel(settings = loadPrintSettings()) {
  const preset = PAPERS.find((p) => p.value === settings.paper) || PAPERS[0];
  const size = paperSizeOf(settings);
  const dim = `${size.w}×${size.h}mm`;
  const name =
    settings.paper === "CUSTOM" ? "自定义" : preset.label.split("（")[0];
  const orient =
    ORIENTATIONS.find((o) => o.value === settings.orientation)?.label || "纵向";
  return `${name} ${orient}（${dim}）`;
}
