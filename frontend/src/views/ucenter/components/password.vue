<template>
	<el-form :model="formData" :rules="rules" ref="formRef" label-width="120px">
		<el-form-item label="原密码" prop="old_password">
			<el-input type="password" v-model="formData.old_password" placeholder="请输入原密码" />
		</el-form-item>
		<el-form-item label="新密码" prop="password">
			<el-input type="password" v-model="formData.password" placeholder="请输入新密码" />
		</el-form-item>
		<el-form-item label="确认密码" prop="password_confirmation">
			<el-input type="password" v-model="formData.password_confirmation" placeholder="请再次输入新密码" />
		</el-form-item>
		<el-form-item>
			<el-button type="primary" @click="handleSubmit" :loading="loading">修改密码</el-button>
			<el-button @click="handleReset">重置</el-button>
		</el-form-item>
	</el-form>
</template>

<script setup>
import { ref, reactive } from 'vue'
import { ElMessage } from 'element-plus'
import { useRouter } from 'vue-router'
import { useUserStore } from '../../../stores/modules/user'
import api from '../../../api/auth'

const emit = defineEmits(['success'])
const router = useRouter()
const userStore = useUserStore()
const formRef = ref(null)
const loading = ref(false)

const formData = reactive({
	old_password: '',
	password: '',
	password_confirmation: '',
})

const rules = {
	old_password: [{ required: true, message: '请输入原密码', trigger: 'blur' }],
	password: [
		{ required: true, message: '请输入新密码', trigger: 'blur' },
		{ min: 6, max: 20, message: '密码长度在 6 到 20 个字符', trigger: 'blur' },
	],
	password_confirmation: [
		{ required: true, message: '请再次输入新密码', trigger: 'blur' },
		{
			validator: (rule, value) => {
				if (!value || !formData.password) {
					return Promise.resolve()
				}
				if (value !== formData.password) {
					return Promise.reject(new Error('两次输入的密码不一致'))
				}
				return Promise.resolve()
			},
			trigger: 'blur',
		},
	],
}

const handleSubmit = async () => {
	try {
		await formRef.value.validate()
		loading.value = true

		const res = await api.changePassword.post({
			old_password: formData.old_password,
			password: formData.password,
			password_confirmation: formData.password_confirmation,
		})

		if (!res || res.code !== 200) {
			throw new Error(res.message || '密码修改失败，请重试')
		}

		ElMessage.success('密码修改成功，请重新登录')

		userStore.logout()

		setTimeout(() => {
			router.push('/login')
		}, 1500)

		emit('success')
		handleReset()
	} catch (error) {
		ElMessage.error(error.message || '密码修改失败，请重试')
	} finally {
		loading.value = false
	}
}

const handleReset = () => {
	formData.old_password = ''
	formData.password = ''
	formData.password_confirmation = ''
	formRef.value?.resetFields()
}
</script>

<style scoped></style>
