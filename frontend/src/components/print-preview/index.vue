<template>
  <el-dialog
    v-model="visibleProxy"
    :width="900"
    top="5vh"
    :close-on-click-modal="false"
    destroy-on-close
    @close="handleClose"
  >
    <template #header>
      <div class="pv-toolbar">
        <div class="pv-title">{{ title }}</div>
        <div class="pv-actions">
          <el-button @click="openSettings('print')">打印设置</el-button>
          <el-button @click="openSettings('paper')">纸张设置</el-button>
          <el-button type="primary" @click="handlePrint">打印</el-button>
          <el-button @click="visibleProxy = false">关闭</el-button>
        </div>
      </div>
    </template>

    <div class="pv-body" v-loading="loading">
      <!-- 缩放走 transform：transform 不改变布局占位，故用外层 .pv-scale
		     按缩放比例设定宽高，保证滚动条尺寸跟随缩放同步变化 -->
      <div
        v-show="!loading"
        class="pv-scale"
        :style="{ width: scaleBox.width, height: scaleBox.height }"
      >
        <iframe
          ref="frame"
          class="pv-frame"
          :srcdoc="displayHtml"
          :style="frameStyle"
        />
      </div>
    </div>

    <template #footer>
      <div class="pv-zoom">
        <span class="pv-zoom-label">缩放</span>
        <el-button-group>
          <el-button
            v-for="z in ZOOMS"
            :key="z"
            size="small"
            :type="zoom === z ? 'primary' : ''"
            @click="zoom = z"
            >{{ z }}%</el-button
          >
        </el-button-group>
        <span class="pv-paper">{{ paperLabel(settings) }}</span>
      </div>
    </template>
  </el-dialog>

  <PrintSettingsDialog
    v-model="settingsVisible"
    :mode="settingsMode"
    @saved="onSettingsSaved"
  />
</template>

<script setup>
import { ref, computed, watch } from "vue";
import { printDocHtml } from "@/utils/printTemplates";
import { applyPrintSettings } from "./applyPrintSettings";
import { loadPrintSettings, marginsOf, paperLabel, paperSizeOf } from "./printSettings";
import PrintSettingsDialog from "./PrintSettingsDialog.vue";

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  /** 预览标题，展示在工具栏左侧 */
  title: { type: String, default: "打印预览" },
  /** 完整的打印文档 HTML（见 utils/printTemplates.js） */
  docHtml: { type: String, default: "" },
  loading: { type: Boolean, default: false },
  /** 可选：传入后「打印设置」里的公司名变更会实时重渲染预览 */
  rebuild: { type: Function, default: null },
});
const emit = defineEmits(["update:modelValue"]);

const visibleProxy = computed({
  get: () => props.modelValue,
  set: (v) => emit("update:modelValue", v),
});

const ZOOMS = [50, 75, 100, 125];
const zoom = ref(75);
const settingsVisible = ref(false);
const settingsMode = ref("print");

// ===== 打印设置 / 纸张设置（localStorage 持久化，全站共用一份）=====
const settings = ref(loadPrintSettings());

function openSettings(mode) {
  settingsMode.value = mode;
  settings.value = loadPrintSettings();
  settingsVisible.value = true;
}

function onSettingsSaved(saved) {
  settings.value = saved;
  settingsVisible.value = false;
  // 公司名改了就立刻刷新预览，不用重新打开弹窗
  if (props.rebuild) props.rebuild(saved.companyName, saved);
}

/** 纸张/份数/模板设置作用到文档后的实际 HTML */
const displayHtml = computed(() => {
  const html = applyPrintSettings(props.docHtml, settings.value);
  // 屏幕不解析 @page，把打印边距改成正文内边距，预览才和打出来一致
  const m = marginsOf(settings.value);
  const { w } = paperSizeOf(settings.value);
  const screen = `<style>html,body{margin:0 auto;padding:${m.t}mm ${m.r}mm ${m.b}mm ${m.l}mm;width:${w}mm;box-sizing:border-box}</style>`;
  return html.replace("</head>", screen + "</head>");
});

// ===== 缩放 =====
// 纸张尺寸随设置变化，预览按 mm 排版后换算成 96dpi 像素做缩放占位
const MM_TO_PX = 96 / 25.4;
const pagePx = computed(() => {
  const { w, h } = paperSizeOf(settings.value);
  return { w: Math.round(w * MM_TO_PX), h: Math.round(h * MM_TO_PX) };
});
const ratio = computed(() => zoom.value / 100);
// iframe 本体始终是纸张原始尺寸，缩放只作用于 transform
const frameStyle = computed(() => ({
  width: `${pagePx.value.w}px`,
  height: `${pagePx.value.h}px`,
  transform: `scale(${ratio.value})`,
  transformOrigin: "top left",
}));
// 外层占位按缩放后尺寸设定，滚动条才跟得上
const scaleBox = computed(() => ({
  width: `${Math.round(pagePx.value.w * ratio.value)}px`,
  height: `${Math.round(pagePx.value.h * ratio.value)}px`,
}));

function handlePrint() {
  printDocHtml(displayHtml.value);
}
function handleClose() {
  settingsVisible.value = false;
}
watch(
  () => props.docHtml,
  () => {
    zoom.value = 75;
  },
);
</script>

<style scoped>
.pv-toolbar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  height: 50px;
  padding: 9px 16px;
  background: #f5f7fa;
  border-bottom: 1px solid #ebeef5;
}
.pv-title {
  font-size: 16px;
  font-weight: bold;
  color: #303133;
}
.pv-actions {
  display: flex;
  gap: 10px;
}
.pv-actions .el-button {
  height: 32px;
}
.pv-actions .el-button:nth-child(1),
.pv-actions .el-button:nth-child(2) {
  width: 90px;
}
.pv-actions .el-button:nth-child(3) {
  width: 80px;
}
.pv-actions .el-button:nth-child(4) {
  width: 60px;
}
.pv-body {
  background: #e8e8e8;
  padding: 20px;
  overflow: auto;
  max-height: 600px;
  display: flex;
  justify-content: center;
}
.pv-scale {
  flex-shrink: 0;
  position: relative;
}
.pv-frame {
  display: block;
  border: none;
  background: #ffffff;
  box-shadow: 0 2px 12px rgba(0, 0, 0, 0.15);
}
.pv-zoom {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 12px;
}
.pv-zoom-label {
  font-size: 13px;
  color: #606266;
}
.pv-paper {
  font-size: 12px;
  color: #909399;
}
</style>
