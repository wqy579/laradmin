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
          <el-button @click="settingsVisible = true">打印设置</el-button>
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
          :srcdoc="docHtml"
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
      </div>
    </template>
  </el-dialog>

  <el-dialog
    v-model="settingsVisible"
    title="打印设置"
    width="420px"
    append-to-body
  >
    <el-form label-width="90px" size="default">
      <el-form-item label="公司名">
        <el-input
          v-model="settings.companyName"
          placeholder="留空使用系统默认"
        />
      </el-form-item>
      <el-form-item label="纸张">
        <el-input value="A4 纵向（210×297mm）" disabled />
      </el-form-item>
      <el-form-item label="边距">
        <el-input value="上下 20mm / 左右 15mm" disabled />
      </el-form-item>
    </el-form>
    <template #footer>
      <el-button @click="settingsVisible = false">关闭</el-button>
      <el-button type="primary" @click="saveSettings">保存</el-button>
    </template>
  </el-dialog>
</template>

<script setup>
import { ref, computed, watch } from "vue";
import { printDocHtml } from "@/utils/printTemplates";

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

// ===== 打印设置持久化 =====
const SETTINGS_KEY = "print_settings";
function loadSettings() {
  try {
    const raw = localStorage.getItem(SETTINGS_KEY);
    return { companyName: "", ...(raw ? JSON.parse(raw) : {}) };
  } catch {
    return { companyName: "" };
  }
}
const settings = ref(loadSettings());
function saveSettings() {
  localStorage.setItem(SETTINGS_KEY, JSON.stringify(settings.value));
  settingsVisible.value = false;
  // 公司名改了就立刻刷新预览，不用重新打开弹窗
  if (props.rebuild) props.rebuild(settings.value.companyName);
}

// ===== 缩放 =====
// 纸张原始尺寸 794px × 1123px（A4 210×297mm @96dpi）
const PAGE_W = 794;
const PAGE_H = 1123;
const ratio = computed(() => zoom.value / 100);
// iframe 本体始终是纸张原始尺寸，缩放只作用于 transform
const frameStyle = computed(() => ({
  width: `${PAGE_W}px`,
  height: `${PAGE_H}px`,
  transform: `scale(${ratio.value})`,
  transformOrigin: "top left",
}));
// 外层占位按缩放后尺寸设定，滚动条才跟得上
const scaleBox = computed(() => ({
  width: `${Math.round(PAGE_W * ratio.value)}px`,
  height: `${Math.round(PAGE_H * ratio.value)}px`,
}));

function handlePrint() {
  printDocHtml(props.docHtml);
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
.pv-actions .el-button:nth-child(1) {
  width: 90px;
}
.pv-actions .el-button:nth-child(2) {
  width: 80px;
}
.pv-actions .el-button:nth-child(3) {
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
</style>
