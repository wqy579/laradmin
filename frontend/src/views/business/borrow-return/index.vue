<template>
	<div class="group-redirect">正在进入借货单…</div>
</template>

<script setup>
import { onMounted } from 'vue'
import { useRouter } from 'vue-router'

// 借还货管理是父菜单，本身没有业务页面，点进来直接进入第一个子页面（借货单）。
const router = useRouter()

onMounted(() => {
	router.replace('/business/borrow-order')
})
</script>

<style scoped>
.group-redirect {
	padding: 48px;
	color: #909399;
	font-size: 14px;
}
</style>
