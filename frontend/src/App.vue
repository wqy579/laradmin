<template>
	<el-config-provider :locale="elementLocale">
		<RouterView />
	</el-config-provider>
</template>

<script setup>
import { computed, onMounted, onUnmounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import zhCn from 'element-plus/es/locale/lang/zh-cn.mjs'
import en from 'element-plus/es/locale/lang/en.mjs'
import { useUserStore } from './stores/modules/user'
import { useWebSocket } from './hooks/useWebSocket'

const { locale } = useI18n()
const userStore = useUserStore()
const { init: initWebSocket, close: closeWebSocket } = useWebSocket()

const localeMap = { 'zh-CN': zhCn, en }
const elementLocale = computed(() => localeMap[locale.value] || zhCn)

watch(
	() => [userStore.token, userStore.userInfo],
	() => {
		if (userStore.token && userStore.userInfo?.id) {
			initWebSocket()
		} else if (!userStore.token) {
			closeWebSocket()
		}
	},
	{ deep: true },
)

onMounted(() => {
	if (userStore.token && userStore.userInfo?.id) {
		initWebSocket()
	}
})

onUnmounted(() => {
	closeWebSocket()
})
</script>
