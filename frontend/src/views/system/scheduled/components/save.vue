<template>
	<el-dialog v-model="dialogVisible" :title="isEdit ? '编辑调度' : '新增调度'" width="560px" destroy-on-close @closed="onClosed">
		<el-form ref="formRef" :model="formData" :rules="rules" label-width="100px">
			<el-form-item label="任务名称" prop="name">
				<el-input v-model="formData.name" placeholder="请输入任务名称" maxlength="100" />
			</el-form-item>
			<el-form-item label="任务类型" prop="type">
				<el-select v-model="formData.type" placeholder="请选择类型">
					<el-option label="Artisan命令" value="artisan" />
					<el-option label="队列任务" value="job" />
					<el-option label="Shell命令" value="shell" />
				</el-select>
			</el-form-item>
			<el-form-item label="执行命令" prop="command">
				<el-input v-model="formData.command" placeholder="如：migrate 或完整类名/Shell命令" maxlength="255" />
			</el-form-item>
			<el-form-item label="调度方式" prop="scheduleType">
				<el-radio-group v-model="formData.scheduleType">
					<el-radio value="cron">Cron 表达式</el-radio>
					<el-radio value="interval">固定间隔</el-radio>
				</el-radio-group>
			</el-form-item>
			<el-form-item v-if="formData.scheduleType === 'cron'" label="Cron表达式" prop="expression">
				<el-input v-model="formData.expression" placeholder="分 时 日 月 周，如：*/5 * * * *（每5分钟）">
					<template #append>
						<el-popover placement="bottom" :width="320" trigger="click">
							<template #reference>
								<el-button>常用</el-button>
							</template>
							<div class="cron-presets">
								<el-tag v-for="p in cronPresets" :key="p.value" class="cron-tag" @click="formData.expression = p.value">
									{{ p.label }}
								</el-tag>
							</div>
						</el-popover>
					</template>
				</el-input>
			</el-form-item>
			<el-form-item v-if="formData.scheduleType === 'interval'" label="执行间隔" prop="interval">
				<el-input-number v-model="formData.interval" :min="1" :max="86400" :step="10" controls-position="right" style="width: 200px" />
				<span style="margin-left: 8px; color: var(--el-text-color-secondary); font-size: 12px">秒</span>
				<div class="interval-presets">
					<el-tag v-for="p in intervalPresets" :key="p.value" class="cron-tag" @click="formData.interval = p.value">
						{{ p.label }}
					</el-tag>
				</div>
			</el-form-item>
			<el-form-item label="时区">
				<el-input v-model="formData.timezone" placeholder="Asia/Shanghai" />
			</el-form-item>
			<el-form-item label="超时时间">
				<el-input-number v-model="formData.timeout" :min="10" :max="3600" :step="30" controls-position="right" />
				<span style="margin-left: 8px; color: var(--el-text-color-secondary); font-size: 12px">秒</span>
			</el-form-item>
			<el-form-item label="最大重试">
				<el-input-number v-model="formData.max_tries" :min="1" :max="10" controls-position="right" />
			</el-form-item>
			<el-form-item label="防止重叠">
				<el-switch v-model="formData.without_overlapping" />
			</el-form-item>
			<el-form-item label="任务描述">
				<el-input v-model="formData.description" type="textarea" :rows="3" placeholder="可选描述" />
			</el-form-item>
		</el-form>
		<template #footer>
			<el-button @click="dialogVisible = false">取消</el-button>
			<el-button type="primary" :loading="submitting" @click="handleSubmit">确定</el-button>
		</template>
	</el-dialog>
</template>

<script setup>
import { ref, reactive, computed, watch } from 'vue'
import { ElMessage } from 'element-plus'
import systemApi from '@/api/system'

const props = defineProps({
	visible: Boolean,
	record: Object,
})
const emit = defineEmits(['update:visible', 'success'])

const dialogVisible = computed({
	get: () => props.visible,
	set: (v) => emit('update:visible', v),
})
const isEdit = computed(() => !!props.record?.id)
const formRef = ref(null)
const submitting = ref(false)

const defaultForm = () => ({
	name: '',
	command: '',
	type: 'artisan',
	scheduleType: 'cron',
	expression: '* * * * *',
	interval: null,
	timezone: 'Asia/Shanghai',
	timeout: 300,
	without_overlapping: false,
	max_tries: 3,
	description: '',
})
const formData = reactive(defaultForm())

const rules = {
	name: [{ required: true, message: '请输入任务名称', trigger: 'blur' }],
	command: [{ required: true, message: '请输入执行命令', trigger: 'blur' }],
	type: [{ required: true, message: '请选择任务类型', trigger: 'change' }],
}

const cronPresets = [
	{ label: '每分钟', value: '* * * * *' },
	{ label: '每5分钟', value: '*/5 * * * *' },
	{ label: '每10分钟', value: '*/10 * * * *' },
	{ label: '每30分钟', value: '*/30 * * * *' },
	{ label: '每小时', value: '0 * * * *' },
	{ label: '每天零点', value: '0 0 * * *' },
	{ label: '每天8点', value: '0 8 * * *' },
	{ label: '每周一零点', value: '0 0 * * 1' },
	{ label: '每月1号零点', value: '0 0 1 * *' },
]

const intervalPresets = [
	{ label: '10秒', value: 10 },
	{ label: '30秒', value: 30 },
	{ label: '1分钟', value: 60 },
	{ label: '5分钟', value: 300 },
	{ label: '10分钟', value: 600 },
	{ label: '30分钟', value: 1800 },
	{ label: '1小时', value: 3600 },
]

watch(
	() => props.visible,
	(v) => {
		if (v && props.record?.id) {
			const r = props.record
			Object.assign(formData, {
				name: r.name || '',
				command: r.command || '',
				type: r.type || 'artisan',
				scheduleType: r.interval ? 'interval' : 'cron',
				expression: r.expression || '* * * * *',
				interval: r.interval || null,
				timezone: r.timezone || 'Asia/Shanghai',
				timeout: r.timeout || 300,
				without_overlapping: r.without_overlapping || false,
				max_tries: r.max_tries || 3,
				description: r.description || '',
			})
		}
	},
)

const onClosed = () => Object.assign(formData, defaultForm())

const handleSubmit = async () => {
	await formRef.value?.validate()
	submitting.value = true
	try {
		const payload = { ...formData }
		// 根据调度方式清理字段
		if (payload.scheduleType === 'interval') {
			payload.expression = null
		} else {
			payload.interval = null
		}
		delete payload.scheduleType

		if (isEdit.value) {
			await systemApi.scheduled.edit.put(props.record.id, payload)
			ElMessage.success('更新成功')
		} else {
			await systemApi.scheduled.add.post(payload)
			ElMessage.success('创建成功')
		}
		dialogVisible.value = false
		emit('success')
	} catch (e) {
		console.error(e)
	} finally {
		submitting.value = false
	}
}
</script>

<style scoped>
.cron-presets,
.interval-presets {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	margin-top: 4px;
}
.cron-tag {
	cursor: pointer;
}

@media (max-width: 768px) {
	.el-input-number {
		width: 100% !important;
	}
	.interval-presets {
		margin-top: 8px;
	}
}
</style>
