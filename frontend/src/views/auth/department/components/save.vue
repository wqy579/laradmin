<template>
	<el-dialog v-model="visible" :title="titleMap[mode]" :width="520" destroy-on-close @close="handleClose">
		<el-form ref="formRef" :model="form" :rules="rules" :disabled="mode === 'show'" label-width="80px" label-position="right" status-icon>
			<el-form-item label="上级部门" prop="parent_id">
				<el-tree-select v-model="form.parent_id" :data="departmentTree" :props="{ label: 'name', children: 'children', value: 'id' }" placeholder="顶级部门" clearable check-strictly filterable style="width: 100%" />
			</el-form-item>
			<el-form-item label="部门名称" prop="name">
				<el-input v-model="form.name" placeholder="请输入部门名称" clearable />
			</el-form-item>
			<el-form-item label="部门标识" prop="code">
				<el-input v-model="form.code" placeholder="请输入部门标识" clearable :disabled="mode === 'edit'" />
			</el-form-item>
			<el-form-item label="负责人" prop="leader">
				<el-input v-model="form.leader" placeholder="请输入负责人" clearable />
			</el-form-item>
			<el-form-item label="联系电话" prop="phone">
				<el-input v-model="form.phone" placeholder="请输入联系电话" clearable />
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
import { ref, reactive, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import authApi from '@/api/auth'

const emit = defineEmits(['success', 'closed'])

const mode = ref('add')
const titleMap = { add: '新增部门', edit: '编辑部门', show: '查看部门' }
const visible = ref(false)
const saving = ref(false)
const formRef = ref(null)

const form = reactive({
	id: '',
	parent_id: null,
	name: '',
	code: '',
	leader: '',
	phone: '',
	sort: 0,
	status: 1,
})

const rules = {
	name: [
		{ required: true, message: '请输入部门名称', trigger: 'blur' },
		{ min: 2, max: 50, message: '部门名称长度在 2 到 50 个字符', trigger: 'blur' },
	],
	code: [
		{ required: true, message: '请输入部门标识', trigger: 'blur' },
		{ min: 2, max: 50, message: '部门标识长度在 2 到 50 个字符', trigger: 'blur' },
	],
}

const departmentTree = ref([])

async function loadDepartmentTree() {
	try {
		const res = await authApi.department.tree.get()
		departmentTree.value = res.data || []
	} catch (error) {
		console.error('加载部门树失败:', error)
	}
}

function open(openMode = 'add', data = null, parentRow = null) {
	mode.value = openMode
	visible.value = true
	if (data) {
		Object.assign(form, {
			id: data.id,
			parent_id: data.parent_id || null,
			name: data.name,
			code: data.code,
			leader: data.leader || '',
			phone: data.phone || '',
			sort: data.sort ?? 0,
			status: data.status ?? 1,
		})
	} else if (parentRow) {
		Object.assign(form, {
			parent_id: parentRow.id,
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
		parent_id: null,
		name: '',
		code: '',
		leader: '',
		phone: '',
		sort: 0,
		status: 1,
	})
}

async function handleSubmit() {
	try {
		await formRef.value.validate()
		saving.value = true

		const submitData = {
			parent_id: form.parent_id,
			name: form.name,
			code: form.code,
			leader: form.leader,
			phone: form.phone,
			sort: form.sort,
			status: form.status,
		}

		if (mode.value === 'add') {
			await authApi.department.add.post(submitData)
		} else {
			await authApi.department.edit.put(form.id, submitData)
		}

		ElMessage.success('操作成功')
		emit('success')
		visible.value = false
	} catch (error) {
		if (error !== false) {
			console.error('保存部门失败:', error)
		}
	} finally {
		saving.value = false
	}
}

onMounted(() => {
	loadDepartmentTree()
})

defineExpose({ open, close })
</script>
