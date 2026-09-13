<template>
	<el-dialog v-model="visible" :title="record ? '编辑客户' : '新增客户'" width="600px" destroy-on-close @close="formRef?.resetFields()">
		<el-form ref="formRef" :model="form" :rules="rules" label-width="90px">
			<el-form-item label="客户名称" prop="name">
				<el-input v-model="form.name" placeholder="请输入客户名称" maxlength="200" />
			</el-form-item>
			<el-form-item label="客户编码" prop="code">
				<el-input v-model="form.code" placeholder="请输入客户编码" maxlength="50" />
			</el-form-item>
			<el-form-item label="所属线路" prop="route_id">
				<el-select v-model="form.route_id" placeholder="请选择线路" clearable style="width:100%">
					<el-option v-for="r in routes" :key="r.id" :label="r.name" :value="r.id" />
				</el-select>
			</el-form-item>
			<el-form-item label="联系人" prop="contact">
				<el-input v-model="form.contact" placeholder="请输入联系人" maxlength="100" />
			</el-form-item>
			<el-form-item label="联系电话" prop="phone">
				<el-input v-model="form.phone" placeholder="请输入联系电话" maxlength="50" />
			</el-form-item>
			<el-form-item label="地址" prop="address">
				<el-input v-model="form.address" placeholder="请输入地址" maxlength="500" />
			</el-form-item>
			<el-form-item label="门头照" prop="image">
				<el-upload
					class="customer-avatar-uploader"
					action="/admin/system/upload"
					:show-file-list="false"
					:headers="uploadHeaders"
					:on-success="handleUploadSuccess"
					:on-error="handleUploadError"
					:before-upload="beforeUpload"
				>
					<img v-if="form.image" :src="form.image" class="customer-avatar" />
					<el-icon v-else class="customer-avatar-uploader-icon"><Plus /></el-icon>
				</el-upload>
				<div class="upload-tip">支持 jpg/png 格式，大小不超过 2MB</div>
			</el-form-item>
			<el-form-item label="备注" prop="remark">
				<el-input v-model="form.remark" type="textarea" :rows="2" placeholder="请输入备注" />
			</el-form-item>
			<el-form-item label="状态" prop="is_active">
				<el-radio-group v-model="form.is_active">
					<el-radio :value="1">启用</el-radio>
					<el-radio :value="0">禁用</el-radio>
				</el-radio-group>
			</el-form-item>
		</el-form>
		<template #footer>
			<el-button @click="visible = false">取消</el-button>
			<el-button type="primary" :loading="submitting" @click="handleSubmit">确定</el-button>
		</template>
	</el-dialog>
</template>

<script setup>
import { ref, watch, computed, onMounted, getCurrentInstance } from 'vue'
import { ElMessage } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import businessApi from '@/api/business'
import { useUserStore } from '@/stores/modules/user'

const props = defineProps({ visible: Boolean, record: Object })
const emit = defineEmits(['update:visible', 'success'])

const formRef = ref(null)
const submitting = ref(false)
const routes = ref([])
const form = ref({ name: '', code: '', route_id: null, contact: '', phone: '', address: '', image: '', remark: '', is_active: 1 })
const rules = {
	name: [{ required: true, message: '请输入客户名称', trigger: 'blur' }],
	route_id: [{ required: true, message: '请选择所属线路', trigger: 'change' }],
}

const visible = computed({ get: () => props.visible, set: (v) => emit('update:visible', v) })

const userStore = useUserStore()
const uploadHeaders = computed(() => ({
	Authorization: `Bearer ${userStore.token || ''}`,
}))

const loadRoutes = async () => {
	const res = await businessApi.route.list.get({ page_size: 9999 })
	if (res.code === 200) routes.value = res.data?.list || []
}

watch(() => props.record, (val) => {
	form.value = val ? { ...val, route_id: val.route_id || null } : { name: '', code: '', route_id: null, contact: '', phone: '', address: '', image: '', remark: '', is_active: 1 }
}, { immediate: true })

const handleUploadSuccess = (response) => {
	if (response.code === 200) {
		form.value.image = response.data?.url || response.data?.path || ''
	} else {
		ElMessage.error(response.message || '上传失败')
	}
}

const handleUploadError = () => {
	ElMessage.error('上传失败')
}

const beforeUpload = (file) => {
	const isImage = file.type.startsWith('image/')
	const isLt2M = file.size / 1024 / 1024 < 2
	if (!isImage) {
		ElMessage.error('只能上传图片文件！')
	}
	if (!isLt2M) {
		ElMessage.error('图片大小不能超过 2MB！')
	}
	return isImage && isLt2M
}

onMounted(() => { loadRoutes() })

const handleSubmit = async () => {
	await formRef.value.validate()
	submitting.value = true
	try {
		const payload = { ...form.value }
		const res = props.record
			? await businessApi.customer.edit.put(props.record.id, payload)
			: await businessApi.customer.add.post(payload)
		if (res.code === 200) { ElMessage.success(res.message || '操作成功'); emit('success'); visible.value = false }
	} finally { submitting.value = false }
}
</script>

<style scoped>
.customer-avatar-uploader .el-upload {
	border: 1px dashed var(--el-border-color);
	border-radius: 6px;
	cursor: pointer;
	position: relative;
	overflow: hidden;
	transition: var(--el-transition-fast);
}
.customer-avatar-uploader .el-upload:hover {
	border-color: var(--el-color-primary);
}
.customer-avatar-uploader-icon {
	font-size: 28px;
	color: #8c939d;
	width: 80px;
	height: 80px;
	display: flex;
	align-items: center;
	justify-content: center;
}
.customer-avatar {
	width: 80px;
	height: 80px;
	display: block;
	object-fit: cover;
}
.upload-tip {
	font-size: 12px;
	color: #909399;
	margin-top: 4px;
}
</style>
