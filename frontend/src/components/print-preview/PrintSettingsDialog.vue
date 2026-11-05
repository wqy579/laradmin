<template>
	<el-dialog v-model="visible" :title="DIALOG_TITLE[mode] || '打印设置'" width="420px" append-to-body>
		<el-form label-width="90px" size="default">
			<template v-if="mode !== 'print'">
				<el-form-item label="纸张">
					<el-radio-group v-model="form.paper">
						<el-radio v-for="p in PAPERS" :key="p.value" :value="p.value">{{ p.label }}</el-radio>
					</el-radio-group>
				</el-form-item>
				<el-form-item v-if="form.paper === 'CUSTOM'" label="宽 × 高">
					<el-input-number v-model="form.customW" :min="40" :max="1200" size="small" controls-position="right" style="width: 110px" />
					<span class="ps-unit">mm ×</span>
					<el-input-number v-model="form.customH" :min="40" :max="1200" size="small" controls-position="right" style="width: 110px" />
					<span class="ps-unit">mm</span>
				</el-form-item>
				<!-- all 模式下方向由下方打印区块提供，避免同一个字段出现两次 -->
				<el-form-item v-if="mode === 'paper'" label="方向">
					<el-radio-group v-model="form.orientation">
						<el-radio v-for="o in ORIENTATIONS" :key="o.value" :value="o.value">{{ o.label }}</el-radio>
					</el-radio-group>
				</el-form-item>
				<el-form-item label="边距">
					<el-select v-model="form.margin" style="width: 100%">
						<el-option v-for="m in MARGINS" :key="m.value" :label="m.label" :value="m.value" />
					</el-select>
				</el-form-item>
				<el-form-item label="当前">
					<span class="ps-preview">{{ paperLabel(form) }}</span>
				</el-form-item>
			</template>

			<template v-if="mode !== 'paper'">
				<el-divider v-if="mode === 'all'" />
				<el-form-item label="打印模板">
					<el-select v-model="form.template" style="width: 100%">
						<el-option v-for="t in TEMPLATES" :key="t.value" :label="t.label" :value="t.value" />
					</el-select>
				</el-form-item>
				<el-form-item label="份数">
					<el-select v-model="form.copies" style="width: 100%">
						<el-option v-for="c in COPIES_OPTIONS" :key="c" :label="`${c} 份`" :value="c" />
					</el-select>
				</el-form-item>
				<el-form-item label="方向">
					<el-radio-group v-model="form.orientation">
						<el-radio v-for="o in ORIENTATIONS" :key="o.value" :value="o.value">{{ o.label }}</el-radio>
					</el-radio-group>
				</el-form-item>
				<el-form-item label="公司名">
					<el-input v-model="form.companyName" placeholder="留空使用系统默认" />
				</el-form-item>
				<el-form-item label="纸张">
					<span class="ps-preview">{{ paperLabel(form) }}</span>
					<el-button link type="primary" size="small" style="margin-left: 8px" @click="$emit('switch-paper')">纸张设置</el-button>
				</el-form-item>
			</template>
		</el-form>
		<template #footer>
			<el-button @click="visible = false">关闭</el-button>
			<el-button type="primary" @click="onSave">保存</el-button>
		</template>
	</el-dialog>
</template>

<script setup>
import { ref, watch } from 'vue'
import { COPIES_OPTIONS, MARGINS, ORIENTATIONS, PAPERS, TEMPLATES, loadPrintSettings, paperLabel, savePrintSettings } from './printSettings'

const props = defineProps({
	modelValue: { type: Boolean, default: false },
	/** print=打印设置（模板/份数/方向），paper=纸张设置（纸张/尺寸/边距），all=报表设置（两块都要） */
	mode: { type: String, default: 'print' },
})
const emit = defineEmits(['update:modelValue', 'saved', 'switch-paper'])

const DIALOG_TITLE = { print: '打印设置', paper: '纸张设置', all: '报表设置' }

const visible = ref(false)
const form = ref(loadPrintSettings())

watch(
	() => props.modelValue,
	(v) => {
		visible.value = v
		// 每次打开都回读存储：另一个弹窗可能刚改过同一份设置
		if (v) form.value = loadPrintSettings()
	},
)
watch(visible, (v) => emit('update:modelValue', v))

function onSave() {
	savePrintSettings(form.value)
	visible.value = false
	emit('saved', { ...form.value })
}
</script>

<style scoped>
.ps-unit {
	margin: 0 6px;
	font-size: 12px;
	color: #909399;
}
.ps-preview {
	font-size: 13px;
	color: #606266;
}
</style>
