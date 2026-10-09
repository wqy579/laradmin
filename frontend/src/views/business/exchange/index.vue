<template>
	<div class="group-redirect">正在进入换货单…</div>
</template>

<script setup>
import { onMounted } from 'vue'
import { useRouter } from 'vue-router'

// 换货管理是父菜单，本身没有业务页面，点进来直接进入第一个子页面（换货单）。
const router = useRouter()

onMounted(() => {
	router.replace('/business/exchange-order')
})
</script>

<style scoped>
.group-redirect {
	padding: 48px;
	color: #909399;
	font-size: 14px;
}
</style>
