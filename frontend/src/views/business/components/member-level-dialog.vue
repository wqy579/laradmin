<template>
	<el-dialog v-model="visible" :title="record ? '编辑等级' : '新增等级'" width="520px" destroy-on-close @close="resetForm">
		<el-form ref="formRef" :model="form" :rules="rules" label-width="100px">
			<el-row :gutter="16">
				<el-col :span="12">
					<el-form-item label="等级编号" prop="level_no">
						<el-input v-model="form.level_no" maxlength="32" placeholder="如 LV3" />
					</el-form-item>
				</el-col>
				<el-col :span="12">
					<el-form-item label="等级名称" prop="name">
						<el-input v-model="form.name" maxlength="50" placeholder="如 钻石会员" />
					</el-form-item>
				</el-col>
			</el-row>
			<el-row :gutter="16">
				<el-col :span="12">
					<el-form-item label="升级门槛" prop="upgrade_amount">
						<el-input-number v-model="form.upgrade_amount" :min="0" :precision="2" :step="1000" style="width:100%" />
					</el-form-item>
				</el-col>
				<el-col :span="12">
					<el-form-item label="折扣率" prop="discount_rate">
						<el-input-number v-model="form.discount_rate" :min="0" :max="100" :precision="1" :step="5" style="width:100%" />
						<span class="hint">折（0-100，如 95 表示 95 折）</span>
					</el-form-item>
				</el-col>
			</el-row>
			<el-row :gutter="16">
				<el-col :span="12">
					<el-form-item label="排序" prop="sort">
						<el-input-number v-model="form.sort" :min="0" :step="1" style="width:100%" />
						<span class="hint">数值越小越靠前</span>
					</el-form-item>
				</el-col>
				<el-col :span="12">
					<el-form-item label="标签颜色" prop="color">
						<div class="color-row">
							<el-color-picker v-model="form.color" />
							<el-input v-model="form.color" maxlength="20" style="flex:1" />
						</div>
					</el-form-item>
				</el-col>
			</el-row>
			<el-form-item label="状态" prop="status">
				<el-switch v-model="form.status" :active-value="1" :inactive-value="0" active-text="启用" inactive-text="停用" />
				<span class="hint" v-if="record?.is_system">系统内置等级不能删除，但可编辑</span>
			</el-form-item>
			<el-form-item label="描述">
				<el-input v-model="form.description" maxlength="1000" type="textarea" :rows="2" placeholder="等级权益说明" />
			</el-form-item>
		</el-form>
		<template #footer>
			<el-button @click="visible = false">取消</el-button>
			<el-button type="primary" :loading="submitting" @click="handleSubmit">确定</el-button>
		</template>
	</el-dialog>
</template>

<script setup>
import { ref, reactive, watch, computed } from 'vue'
import { ElMessage } from 'element-plus'
import businessApi from '@/api/business'

const props = defineProps({
	visible: { type: Boolean, default: false },
	record: { type: Object, default: null },
})
const emit = defineEmits(['update:visible', 'success'])

const visible = computed({
	get: () => props.visible,
	set: (v) => emit('update:visible', v),
})

const formRef = ref()
const submitting = ref(false)

const defaultForm = () => ({
	level_no: '',
	name: '',
	upgrade_amount: 0,
	discount_rate: 100,
	color: '#909399',
	sort: 0,
	status: 1,
	description: '',
})

const form = reactive(defaultForm())

const rules = {
	level_no: [
		{ required: true, message: '请输入等级编号', trigger: 'blur' },
		{ max: 32, message: '编号不超过 32 字符', trigger: 'blur' },
	],
	name: [
		{ required: true, message: '请输入等级名称', trigger: 'blur' },
		{ max: 50, message: '名称不超过 50 字符', trigger: 'blur' },
	],
	discount_rate: [
		{ type: 'number', min: 0, max: 100, message: '折扣率 0-100', trigger: 'blur' },
	],
}

watch(() => props.visible, (v) => {
	if (!v) return
	if (props.record) {
		Object.assign(form, defaultForm(), props.record)
	} else {
		Object.assign(form, defaultForm())
	}
})

const resetForm = () => formRef.value?.resetFields()

const handleSubmit = async () => {
	await formRef.value?.validate()
	submitting.value = true
	try {
		const payload = { ...form }
		if (props.record) {
			const res = await businessApi.memberLevel.edit.put(props.record.id, payload)
			if (res.code === 200 || res.code === 201) {
				ElMessage.success(res.message || '更新成功')
				emit('success')
				visible.value = false
			}
		} else {
			const res = await businessApi.memberLevel.add.post(payload)
			if (res.code === 200 || res.code === 201) {
				ElMessage.success(res.message || '创建成功')
				emit('success')
				visible.value = false
			}
		}
	} finally {
		submitting.value = false
	}
}
</script>

<style scoped>
.hint { color: #999; font-size: 12px; margin-left: 8px; }
.color-row { display: flex; align-items: center; gap: 8px; width: 100%; }
</style>
