<template>
	<el-dialog v-model="dialogVisible" :title="isEdit ? '编辑分组' : '添加分组'" width="480px" :close-on-click-modal="false" @closed="handleCancel">
		<el-form ref="formRef" :model="formData" :rules="formRules" label-width="80px">
			<el-form-item label="分组名称" prop="name">
				<el-input v-model="formData.name" placeholder="请输入分组名称" />
			</el-form-item>

			<el-form-item label="上级分组" prop="parent_id">
				<el-tree-select v-model="formData.parent_id" :data="filteredTreeData" :props="{ label: 'name', children: 'children', value: 'id' }" placeholder="顶级分组" clearable check-strictly filterable style="width: 100%" />
			</el-form-item>

			<el-form-item label="排序" prop="sort">
				<el-input-number v-model="formData.sort" :min="0" :max="9999" style="width: 100%" controls-position="right" />
				<div class="form-tip">数字越小排序越靠前</div>
			</el-form-item>

			<el-form-item label="状态" prop="status">
				<el-switch v-model="formData.status" active-text="启用" inactive-text="禁用" :active-value="1" :inactive-value="0" />
			</el-form-item>
		</el-form>

		<template #footer>
			<el-button @click="handleCancel">取消</el-button>
			<el-button type="primary" :loading="submitLoading" @click="handleOk">确定</el-button>
		</template>
	</el-dialog>
</template>

<script setup>
import { ref, reactive, computed, watch } from 'vue'
import { ElMessage } from 'element-plus'
import systemApi from '@/api/system'

const props = defineProps({
	visible: {
		type: Boolean,
		default: false,
	},
	record: {
		type: Object,
		default: null,
	},
	groupTree: {
		type: Array,
		default: () => [],
	},
})

const emit = defineEmits(['update:visible', 'success'])

const formRef = ref(null)
const submitLoading = ref(false)

const dialogVisible = computed({
	get: () => props.visible,
	set: (value) => emit('update:visible', value),
})

const isEdit = computed(() => !!props.record)

const formData = reactive({
	name: '',
	parent_id: null,
	sort: 0,
	status: 1,
	item_type: 'group',
})

const formRules = {
	name: [{ required: true, message: '请输入分组名称', trigger: 'blur' }],
}

// 编辑时排除自身及子节点
const filteredTreeData = computed(() => {
	if (!isEdit.value || !props.record) return props.groupTree
	const excludeId = props.record.id
	const filterNode = (nodes) => {
		return nodes
			.filter((node) => node.id !== excludeId)
			.map((node) => ({
				...node,
				children: node.children ? filterNode(node.children) : undefined,
			}))
	}
	return filterNode(props.groupTree)
})

watch(
	() => props.visible,
	(val) => {
		if (val) {
			if (isEdit.value && props.record) {
				formData.name = props.record.name || ''
				formData.parent_id = props.record.parent_id || null
				formData.sort = props.record.sort ?? 0
				formData.status = props.record.status ?? 1
			} else {
				formData.name = ''
				formData.parent_id = null
				formData.sort = 0
				formData.status = 1
			}
		}
	},
	{ immediate: true },
)

const handleOk = async () => {
	try {
		await formRef.value.validate()
		submitLoading.value = true

		const submitData = {
			name: formData.name,
			parent_id: formData.parent_id || null,
			sort: formData.sort,
			status: formData.status,
			item_type: 'group',
		}

		let res
		if (isEdit.value) {
			res = await systemApi.config.edit.put(props.record.id, submitData)
		} else {
			res = await systemApi.config.add.post(submitData)
		}

		if (res.code === 200) {
			ElMessage.success(isEdit.value ? '编辑成功' : '新增成功')
			emit('success')
		} else {
			ElMessage.error(res.message || (isEdit.value ? '编辑失败' : '新增失败'))
		}
	} catch (error) {
		if (error !== false) {
			console.error(isEdit.value ? '编辑分组失败:' : '新增分组失败:', error)
			ElMessage.error(isEdit.value ? '编辑失败' : '新增失败')
		}
	} finally {
		submitLoading.value = false
	}
}

const handleCancel = () => {
	emit('update:visible', false)
}
</script>

<style scoped>
.form-tip {
	font-size: 12px;
	color: var(--el-text-color-placeholder);
	margin-top: 4px;
}
</style>
