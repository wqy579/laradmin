<template>
	<el-dialog v-model="visible" :title="titleMap[mode]" :width="520" destroy-on-close @close="handleClose">
		<el-form ref="formRef" :model="form" :rules="rules" :disabled="mode === 'show'" label-width="80px" label-position="right" status-icon>
			<el-form-item label="角色名称" prop="name">
				<el-input v-model="form.name" placeholder="请输入角色名称" clearable />
			</el-form-item>
			<el-form-item label="角色标识" prop="code">
				<el-input v-model="form.code" placeholder="请输入角色标识" clearable :disabled="mode === 'edit'" />
			</el-form-item>
			<el-form-item label="描述" prop="description">
				<el-input v-model="form.description" type="textarea" :rows="3" placeholder="请输入角色描述" />
			</el-form-item>
			<el-form-item label="排序" prop="sort">
				<el-input-number v-model="form.sort" :min="0" :max="9999" controls-position="right" />
			</el-form-item>
			<el-form-item label="状态" prop="status">
				<el-radio-group v-model="form.status">
					<el-radio :value="1">正常</el-radio>
					<el-radio :value="0">禁用</el-radio>
				</el-radio-group>
			</el-form-item>
		</el-form>
		<template #footer v-if="mode !== 'show'">
			<el-button @click="handleClose">取消</el-button>
			<el-button type="primary" :loading="saving" @click="handleSubmit">保存</el-button>
		</template>
	</el-dialog>
</template>

<script setup>
import { ref, reactive } from 'vue'
import { ElMessage } from 'element-plus'
import authApi from '@/api/auth'

const emit = defineEmits(['success', 'closed'])

const mode = ref('add')
const titleMap = { add: '新增角色', edit: '编辑角色', show: '查看角色' }
const visible = ref(false)
const saving = ref(false)
const formRef = ref(null)

const form = reactive({
	id: '',
	name: '',
	code: '',
	description: '',
	sort: 0,
	status: 1,
})

const rules = {
	name: [
		{ required: true, message: '请输入角色名称', trigger: 'blur' },
		{ min: 2, max: 50, message: '角色名称长度在 2 到 50 个字符', trigger: 'blur' },
	],
	code: [
		{ required: true, message: '请输入角色标识', trigger: 'blur' },
		{ min: 2, max: 50, message: '角色标识长度在 2 到 50 个字符', trigger: 'blur' },
	],
}

function open(openMode = 'add', data = null) {
	mode.value = openMode
	visible.value = true
	if (data) {
		Object.assign(form, {
			id: data.id,
			name: data.name,
			code: data.code,
			description: data.description || '',
			sort: data.sort ?? 0,
			status: data.status ?? 1,
		})
	}
	return { open }
}

function close() {
	visible.value = false
}

function handleClose() {
	emit('closed')
	visible.value = false
	Object.assign(form, {
		id: '',
		name: '',
		code: '',
		description: '',
		sort: 0,
		status: 1,
	})
}

async function handleSubmit() {
	try {
		await formRef.value.validate()
		saving.value = true

		const submitData = {
			name: form.name,
			code: form.code,
			description: form.description,
			sort: form.sort,
			status: form.status,
		}

		if (mode.value === 'add') {
			await authApi.role.add.post(submitData)
		} else {
			await authApi.role.edit.put(form.id, submitData)
		}

		ElMessage.success('操作成功')
		emit('success')
		visible.value = false
	} catch (error) {
		if (error !== false) {
			console.error('保存角色失败:', error)
		}
	} finally {
		saving.value = false
	}
}

defineExpose({ open, close })
</script>
