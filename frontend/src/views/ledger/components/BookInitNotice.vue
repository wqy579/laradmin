<script setup>
/**
 * QA-10 总账「账套初始化中」提示条。
 *
 * 总账 4 页（科目 / 期初 / 凭证 / 月结）顶部的提示：后端已是真实接口，
 * 原「演示数据」黄条（demo-watermark）对 ledger 域已不成立，改为本组件——
 * 仅在账套尚未开账（期初余额未确认，即 ledger_opening_balances 无数据）时显示，
 * 开账后自动隐藏。
 *
 * 视觉沿用演示条规格（warning 色 #E6A23C、浅底 #FDF6EC、32px 高、12px 字号、
 * Warning 图标），但不带斜向水印层（真实数据不需要防伪水印）。
 *
 * 请求失败时整条不渲染：宁可漏提示，也不要因接口抖动误报「数据不可用」。
 */
import { onMounted, ref } from 'vue'
import ledgerApi from '@/api/ledger'

const opened = ref(true)

onMounted(async () => {
	try {
		const res = await ledgerApi.period.bootStatus.get()
		opened.value = res.data?.opened === true
	} catch {
		opened.value = true
	}
})
</script>

<template>
	<div v-if="!opened" class="book-init-bar">
		<el-icon :size="14"><ElIconWarning /></el-icon>
		<span>账套初始化中：期初余额尚未确认开账，数据请勿作为决策依据</span>
	</div>
</template>

<style scoped>
.book-init-bar {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 8px;
	height: 32px;
	font-size: 12px;
	color: #e6a23c;
	background: #fdf6ec;
	border-bottom: 1px solid #e6a23c;
}
</style>
