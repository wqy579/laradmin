<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'

const router = useRouter()
const formRef = ref(null)
const loading = ref(false)

const form = ref({
	email: '',
	password: '',
	confirmPassword: '',
})

const rules = {
	email: [{ required: true, message: '请输入邮箱', trigger: 'blur' }],
	password: [
		{ required: true, message: '请输入新密码', trigger: 'blur' },
		{ min: 6, message: '密码不少于 6 位', trigger: 'blur' },
	],
	confirmPassword: [{ required: true, message: '请确认密码', trigger: 'blur' }],
}

function handleReset() {
	formRef.value?.validate((valid) => {
		if (!valid) return
		loading.value = true
		setTimeout(() => {
			loading.value = false
			router.push('/login')
		}, 800)
	})
}
</script>

<template>
	<div class="reset-page">
		<div class="reset-card">
			<h2>重置密码</h2>
			<p class="reset-desc">输入邮箱和新密码来重置您的密码</p>
			<el-form ref="formRef" :model="form" :rules="rules" size="large" @keyup.enter="handleReset">
				<el-form-item prop="email">
					<el-input v-model="form.email" prefix-icon="ElIconMessage" placeholder="邮箱" />
				</el-form-item>
				<el-form-item prop="password">
					<el-input v-model="form.password" prefix-icon="ElIconLock" type="password" placeholder="新密码" show-password />
				</el-form-item>
				<el-form-item prop="confirmPassword">
					<el-input v-model="form.confirmPassword" prefix-icon="ElIconLock" type="password" placeholder="确认新密码" show-password />
				</el-form-item>
				<el-form-item>
					<el-button type="primary" :loading="loading" style="width: 100%" @click="handleReset"> 重置密码 </el-button>
				</el-form-item>
			</el-form>
			<div class="reset-footer">想起密码了？<router-link to="/login">去登录</router-link></div>
		</div>
	</div>
</template>

<style scoped>
.reset-page {
	display: flex;
	align-items: center;
	justify-content: center;
	min-height: 100vh;
	background: var(--layout-bg);
}
.reset-card {
	width: 400px;
	max-width: calc(100vw - 32px);
	padding: 40px;
	background: var(--layout-surface);
	border: 1px solid var(--el-border-color-lighter);
	border-radius: 16px;
	box-shadow: 0 4px 24px rgba(0, 0, 0, 0.06);
}
.reset-card h2 {
	text-align: center;
	margin-bottom: 4px;
	font-size: 22px;
	font-weight: 700;
	color: var(--layout-text);
}
.reset-desc {
	text-align: center;
	margin-bottom: 28px;
	font-size: 14px;
	color: var(--layout-text-muted);
}
.reset-footer {
	text-align: center;
	font-size: 13px;
	color: var(--layout-text-muted);
}
.reset-footer a {
	color: var(--el-color-primary);
	text-decoration: none;
}

/* ===== 暗色模式 ===== */
html.dark .reset-card {
	box-shadow: 0 4px 24px rgba(0, 0, 0, 0.3);
}

/* ===== 响应式 ===== */
@media (max-width: 768px) {
	.reset-card {
		padding: 36px 32px;
	}
	.reset-card :deep(.el-input__wrapper) {
		min-height: 44px;
	}
	.reset-card :deep(.el-button) {
		min-height: 44px;
	}
}
@media (max-width: 480px) {
	.reset-card {
		max-width: calc(100vw - 32px);
		padding: 28px 20px;
		border-radius: 12px;
	}
	.reset-card h2 {
		font-size: 20px;
	}
	.reset-desc {
		margin-bottom: 20px;
	}
	.reset-card :deep(.el-form-item) {
		margin-bottom: 18px;
	}
}
</style>
