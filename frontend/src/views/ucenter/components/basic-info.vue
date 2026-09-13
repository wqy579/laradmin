<template>
	<div class="basic-info">
		<h3 class="section-title">个人资料</h3>
		<el-form :model="formData" :rules="rules" ref="formRef" label-width="100px" class="info-form">
			<el-form-item label="用户名" prop="username">
				<el-input v-model="formData.username" placeholder="请输入用户名" />
			</el-form-item>
			<el-form-item label="真实姓名" prop="real_name">
				<el-input v-model="formData.real_name" placeholder="请输入真实姓名" />
			</el-form-item>
			<el-form-item label="手机号" prop="phone">
				<el-input v-model="formData.phone" placeholder="请输入手机号" />
			</el-form-item>
			<el-form-item label="邮箱" prop="email">
				<el-input v-model="formData.email" placeholder="请输入邮箱" />
			</el-form-item>
			<el-form-item>
				<el-button type="primary" @click="handleSubmit" :loading="loading">保存修改</el-button>
				<el-button @click="handleReset">重置</el-button>
			</el-form-item>
		</el-form>
	</div>
</template>

<script setup>
import { ref, reactive, watch } from 'vue'
import { ElMessage } from 'element-plus'
import api from '../../../api/auth'
import { useUserStore } from '../../../stores/modules/user'

const props = defineProps({
	userInfo: {
		type: Object,
		default: () => ({}),
	},
})

const emit = defineEmits(['update'])

const userStore = useUserStore()
const formRef = ref(null)
const loading = ref(false)

const formData = reactive({
	username: '',
	real_name: '',
	phone: '',
	email: '',
})

watch(
	() => props.userInfo,
	(newVal) => {
		if (newVal) {
			formData.username = newVal.username || ''
			formData.real_name = newVal.real_name || ''
			formData.phone = newVal.phone || ''
			formData.email = newVal.email || ''
		}
	},
	{ immediate: true, deep: true },
)

const rules = {
	username: [
		{ required: true, message: '请输入用户名', trigger: 'blur' },
		{ min: 3, max: 20, message: '用户名长度在 3 到 20 个字符', trigger: 'blur' },
	],
	real_name: [
		{ required: true, message: '请输入真实姓名', trigger: 'blur' },
		{ min: 2, max: 20, message: '姓名长度在 2 到 20 个字符', trigger: 'blur' },
	],
	phone: [{ pattern: /^1[3-9]\d{9}$/, message: '请输入正确的手机号', trigger: 'blur' }],
	email: [{ type: 'email', message: '请输入正确的邮箱地址', trigger: 'blur' }],
}

const handleSubmit = async () => {
	try {
		await formRef.value.validate()
		loading.value = true

		const res = await api.me.put({
			username: formData.username,
			real_name: formData.real_name,
			phone: formData.phone,
			email: formData.email,
		})

		if (res && res.data) {
			userStore.setUserInfo(res.data)
			emit('update', res.data)
			ElMessage.success('保存成功')
		} else {
			ElMessage.success('保存成功')
		}
	} catch (error) {
		ElMessage.error(error.message || '保存失败，请重试')
	} finally {
		loading.value = false
	}
}

const handleReset = () => {
	if (props.userInfo) {
		formData.username = props.userInfo.username || ''
		formData.real_name = props.userInfo.real_name || ''
		formData.phone = props.userInfo.phone || ''
		formData.email = props.userInfo.email || ''
	}
	formRef.value?.clearValidate()
	ElMessage.info('已重置')
}
</script>

<style scoped>
.section-title {
	font-size: 16px;
	font-weight: 600;
	color: #303133;
	margin: 0 0 24px;
	padding-bottom: 12px;
	border-bottom: 1px solid #f0f0f0;
}

.info-form {
	max-width: 560px;
}
</style>
