<script setup>
import { ref, reactive, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ElMessage } from 'element-plus'
import { Sunny, Moon } from '@element-plus/icons-vue'
import { useStorage } from '@vueuse/core'
import { useAppStore } from '../../stores/app'
import { useUserStore } from '../../stores/modules/user'
import authApi from '../../api/auth'
import config from '../../config'

const router = useRouter()
const route = useRoute()
const { locale } = useI18n()
const appStore = useAppStore()
const userStore = useUserStore()

const formRef = ref(null)
const loading = ref(false)
const showPassword = ref(false)

const form = reactive({
	username: '',
	password: '',
	remember: false,
})

const rememberedUser = useStorage('remembered_user', '')
if (rememberedUser.value) {
	form.username = rememberedUser.value
	form.remember = true
}

watch(
	() => form.remember,
	(val) => {
		if (val) {
			rememberedUser.value = form.username
		} else {
			rememberedUser.value = ''
		}
	},
)

const rules = {
	username: [{ required: true, message: '请输入用户名', trigger: 'blur' }],
	password: [
		{ required: true, message: '请输入密码', trigger: 'blur' },
		{ min: 6, message: '密码不少于 6 位', trigger: 'blur' },
	],
}

function switchLocale(lang) {
	locale.value = lang
	appStore.setLocale(lang)
}

function handleLogin() {
	formRef.value?.validate((valid) => {
		if (!valid) return
		loading.value = true
		authApi.login
			.post({
				username: form.username,
				password: form.password,
			})
			.then((res) => {
				const data = res.data
userStore.setToken(data.token)
                localStorage.setItem('laradmin_token', data.token)
                userStore.setUserInfo(data.user)
                userStore.setMenu(data.menu || [])
                userStore.setPermissions(data.permissions || [])
				if (form.remember) {
					rememberedUser.value = form.username
				} else {
					rememberedUser.value = ''
				}
				const redirect = route.query.redirect || config.DASHBOARD_URL
				router.push(redirect)
			})
			.catch((err) => {
				ElMessage.error(err.message || '登录失败')
			})
			.finally(() => {
				loading.value = false
			})
	})
}
</script>

<template>
	<div class="login-page">
		<!-- 背景网格 -->
		<div class="login-bg">
			<div class="login-bg__grid" />
			<div class="login-bg__glow login-bg__glow--1" />
			<div class="login-bg__glow login-bg__glow--2" />
		</div>

		<!-- 顶部工具栏 -->
		<div class="login-toolbar">
			<el-dropdown trigger="click" @command="switchLocale">
				<button type="button" class="login-toolbar__btn">
					<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
						<circle cx="12" cy="12" r="10" />
						<line x1="2" y1="12" x2="22" y2="12" />
						<path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10A15.3 15.3 0 0 1 12 2z" />
					</svg>
					<span>{{ locale === 'zh-CN' ? '简体中文' : 'English' }}</span>
				</button>
				<template #dropdown>
					<el-dropdown-menu>
						<el-dropdown-item command="zh-CN" :disabled="locale === 'zh-CN'">简体中文</el-dropdown-item>
						<el-dropdown-item command="en" :disabled="locale === 'en'">English</el-dropdown-item>
					</el-dropdown-menu>
				</template>
			</el-dropdown>
			<el-tooltip :content="appStore.isDark ? '亮色模式' : '暗色模式'" placement="bottom">
				<button type="button" class="login-toolbar__btn" @click="appStore.toggleDark()">
					<el-icon><Sunny v-if="appStore.isDark" /><Moon v-else /></el-icon>
				</button>
			</el-tooltip>
		</div>

		<!-- 登录卡片 -->
		<div class="login-card">
			<div class="login-card__inner">
				<!-- Logo & 标题 -->
				<div class="login-header">
					<div class="login-header__logo">
						<svg viewBox="0 0 44 44" width="44" height="44">
							<rect fill="var(--el-color-primary)" rx="12" width="44" height="44" />
							<text x="22" y="30" fill="#fff" font-size="24" font-weight="700" text-anchor="middle" font-family="system-ui">T</text>
						</svg>
					</div>
					<h1 class="login-header__title">Tensent Admin</h1>
					<p class="login-header__desc">登录您的账户以继续使用</p>
				</div>

				<!-- 表单 -->
				<el-form ref="formRef" :model="form" :rules="rules" size="large" class="login-form" @keyup.enter="handleLogin">
					<el-form-item prop="username">
						<el-input v-model="form.username" prefix-icon="ElIconUser" placeholder="用户名" clearable />
					</el-form-item>

					<el-form-item prop="password">
						<el-input v-model="form.password" prefix-icon="ElIconLock" :type="showPassword ? 'text' : 'password'" placeholder="密码">
							<template #suffix>
								<button type="button" class="login-pwd-toggle" :aria-label="showPassword ? '隐藏密码' : '显示密码'" @click="showPassword = !showPassword">
									<!-- eye open -->
									<svg v-if="!showPassword" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
										<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
										<circle cx="12" cy="12" r="3" />
									</svg>
									<!-- eye off -->
									<svg v-else width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
										<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94" />
										<path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19" />
										<line x1="1" y1="1" x2="23" y2="23" />
									</svg>
								</button>
							</template>
						</el-input>
					</el-form-item>

					<div class="login-form__options">
						<el-checkbox v-model="form.remember">记住密码</el-checkbox>
					</div>

					<el-form-item>
						<button type="button" class="login-submit" :class="{ 'is-loading': loading }" :disabled="loading" @click="handleLogin">
							<svg v-if="loading" class="login-submit__spinner" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><path d="M12 2a10 10 0 0 1 10 10" /></svg>
							<span>{{ loading ? '登录中...' : '登 录' }}</span>
						</button>
					</el-form-item>
				</el-form>
			</div>

			<!-- 底部版权 -->
			<div class="login-footer">
				<span>Tensent Admin &copy; 2026</span>
			</div>
		</div>
	</div>
</template>

<style scoped>
/* ===== 页面 ===== */
.login-page {
	position: relative;
	display: flex;
	align-items: center;
	justify-content: center;
	min-height: 100vh;
	overflow: hidden;
	background: var(--layout-bg);
}

/* ===== 背景 ===== */
.login-bg {
	position: absolute;
	inset: 0;
	overflow: hidden;
	pointer-events: none;
}
.login-bg__grid {
	position: absolute;
	inset: -20px;
	background-image: linear-gradient(var(--el-border-color-lighter) 1px, transparent 1px), linear-gradient(90deg, var(--el-border-color-lighter) 1px, transparent 1px);
	background-size: 60px 60px;
	mask-image: radial-gradient(ellipse 70% 60% at 50% 50%, black 30%, transparent 100%);
	-webkit-mask-image: radial-gradient(ellipse 70% 60% at 50% 50%, black 30%, transparent 100%);
	opacity: 0.6;
}
.login-bg__glow {
	position: absolute;
	border-radius: 50%;
	filter: blur(100px);
	opacity: 0.25;
}
.login-bg__glow--1 {
	width: 500px;
	height: 500px;
	top: -10%;
	right: -5%;
	background: var(--el-color-primary);
	animation: glowDrift 12s ease-in-out infinite alternate;
}
.login-bg__glow--2 {
	width: 400px;
	height: 400px;
	bottom: -8%;
	left: -4%;
	background: var(--el-color-primary-light-5);
	animation: glowDrift 10s ease-in-out infinite alternate-reverse;
}

/* ===== 工具栏 ===== */
.login-toolbar {
	position: absolute;
	top: 20px;
	right: 24px;
	display: flex;
	align-items: center;
	gap: 8px;
	z-index: 10;
}
.login-toolbar__btn {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	height: 34px;
	padding: 0 10px;
	border: 1px solid var(--el-border-color-light);
	border-radius: 8px;
	background: var(--layout-surface);
	color: var(--layout-text-secondary);
	font-size: 13px;
	cursor: pointer;
	transition:
		border-color 0.2s,
		color 0.2s,
		box-shadow 0.2s;
	font-family: inherit;
}
.login-toolbar__btn:hover {
	border-color: var(--el-color-primary-light-5);
	color: var(--el-color-primary);
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
}

/* ===== 登录卡片 ===== */
.login-card {
	position: relative;
	z-index: 5;
	width: 420px;
	max-width: calc(100vw - 32px);
	background: var(--layout-surface);
	border: 1px solid var(--el-border-color-lighter);
	border-radius: 20px;
	box-shadow:
		0 4px 24px rgba(0, 0, 0, 0.06),
		0 1px 2px rgba(0, 0, 0, 0.04);
	animation: cardEnter 0.5s cubic-bezier(0.16, 1, 0.3, 1) both;
}
.login-card__inner {
	padding: 48px 40px 24px;
}

/* ===== 头部 ===== */
.login-header {
	text-align: center;
	margin-bottom: 36px;
}
.login-header__logo {
	display: flex;
	justify-content: center;
	margin-bottom: 16px;
}
.login-header__title {
	font-size: 24px;
	font-weight: 700;
	color: var(--layout-text);
	letter-spacing: -0.3px;
	margin-bottom: 6px;
}
.login-header__desc {
	font-size: 14px;
	color: var(--layout-text-muted);
}

/* ===== 表单 ===== */
.login-form :deep(.el-input__wrapper) {
	border-radius: 10px;
	padding: 4px 14px;
	box-shadow: 0 0 0 1px var(--el-border-color) inset;
	transition: box-shadow 0.25s;
}
.login-form :deep(.el-input__wrapper:hover) {
	box-shadow: 0 0 0 1px var(--el-border-color-hover) inset;
}
.login-form :deep(.el-input__wrapper.is-focus) {
	box-shadow:
		0 0 0 1px var(--el-color-primary) inset,
		0 0 0 3px var(--el-color-primary-light-9);
}
.login-form :deep(.el-form-item) {
	margin-bottom: 22px;
}
.login-form__options {
	display: flex;
	align-items: center;
	margin-bottom: 24px;
}

/* 密码显隐 */
.login-pwd-toggle {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 28px;
	height: 28px;
	border: none;
	border-radius: 6px;
	background: transparent;
	cursor: pointer;
	color: var(--layout-text-muted);
	padding: 0;
	transition:
		color 0.2s,
		background-color 0.2s;
}
.login-pwd-toggle:hover {
	color: var(--el-color-primary);
	background: var(--el-fill-color-light);
}

/* ===== 提交按钮 ===== */
.login-submit {
	width: 100%;
	height: 46px;
	font-size: 15px;
	font-weight: 600;
	letter-spacing: 2px;
	border: none;
	border-radius: 10px;
	cursor: pointer;
	color: #fff;
	background: var(--el-color-primary);
	box-shadow: 0 4px 12px color-mix(in srgb, var(--el-color-primary) 40%, transparent);
	transition:
		transform 0.2s,
		box-shadow 0.2s,
		opacity 0.2s;
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 8px;
	font-family: inherit;
}
.login-submit:hover {
	transform: translateY(-1px);
	box-shadow: 0 6px 20px color-mix(in srgb, var(--el-color-primary) 50%, transparent);
}
.login-submit:active {
	transform: translateY(0);
	box-shadow: 0 2px 8px color-mix(in srgb, var(--el-color-primary) 35%, transparent);
}
.login-submit.is-loading {
	opacity: 0.8;
	pointer-events: none;
}
.login-submit__spinner {
	animation: spin 0.7s linear infinite;
}

/* ===== 底部 ===== */
.login-footer {
	padding: 16px 40px 24px;
	text-align: center;
	font-size: 12px;
	color: var(--layout-text-muted);
	border-top: 1px solid var(--el-border-color-extra-light);
}

/* ===== 动画 ===== */
@keyframes cardEnter {
	from {
		opacity: 0;
		transform: translateY(20px) scale(0.98);
	}
	to {
		opacity: 1;
		transform: translateY(0) scale(1);
	}
}
@keyframes glowDrift {
	from {
		transform: translate(0, 0);
	}
	to {
		transform: translate(30px, -20px);
	}
}
@keyframes spin {
	to {
		transform: rotate(360deg);
	}
}

/* ===== 暗色模式 ===== */
html.dark .login-card {
	box-shadow:
		0 4px 24px rgba(0, 0, 0, 0.3),
		0 1px 2px rgba(0, 0, 0, 0.2);
}
html.dark .login-bg__grid {
	opacity: 0.3;
}
html.dark .login-bg__glow {
	opacity: 0.15;
}
html.dark .login-footer {
	border-top-color: var(--el-border-color);
}
@media (prefers-reduced-motion: reduce) {
	.login-card,
	.login-bg__glow,
	.login-submit__spinner {
		animation: none !important;
	}
}

/* ===== 响应式 ===== */
@media (max-width: 768px) {
	.login-card__inner {
		padding: 40px 32px 22px;
	}
	.login-footer {
		padding: 16px 32px 22px;
	}
	.login-form :deep(.el-input__wrapper) {
		min-height: 44px;
	}
	.login-submit {
		min-height: 44px;
	}
}
@media (max-width: 480px) {
	.login-card {
		border-radius: 16px;
		max-width: calc(100vw - 32px);
	}
	.login-card__inner {
		padding: 32px 24px 20px;
	}
	.login-header__title {
		font-size: 20px;
	}
	.login-header__logo svg {
		width: 36px;
		height: 36px;
	}
	.login-form :deep(.el-input__wrapper) {
		min-height: 44px;
		padding: 4px 12px;
	}
	.login-submit {
		min-height: 44px;
	}
	.login-footer {
		padding: 14px 24px 20px;
	}
	.login-toolbar {
		top: 12px;
		right: 12px;
	}
}
</style>
