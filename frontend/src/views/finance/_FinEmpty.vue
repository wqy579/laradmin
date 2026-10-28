<template>
	<div class="fin-empty">
		<svg width="96" height="80" viewBox="0 0 96 80" fill="none" xmlns="http://www.w3.org/2000/svg">
			<rect x="18" y="12" width="60" height="44" rx="2" fill="#fff" stroke="#dcdfe6" stroke-width="1.5" />
			<rect x="14" y="20" width="8" height="36" rx="1" fill="#dcdfe6" />
			<path d="M30 22h28" stroke="#e4e7ed" stroke-width="1.5" stroke-linecap="round" />
			<path d="M30 29h20" stroke="#e4e7ed" stroke-width="1.5" stroke-linecap="round" />
			<path d="M30 36h24" stroke="#e4e7ed" stroke-width="1.5" stroke-linecap="round" />
			<path d="M34 51l6-6 7 4 7-7 6 5" stroke="#c0c4cc" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" fill="none" />
			<rect x="18" y="60" width="60" height="5" rx="2.5" fill="#f5f7fa" />
			<path d="M38 66h20" stroke="#dcdfe6" stroke-width="1.5" stroke-linecap="round" />
		</svg>
		<p class="fin-empty__text">{{ text }}</p>
	</div>
</template>

<script setup>
defineProps({
	text: { type: String, default: '暂无数据' },
})
</script>

<style>
.fin-empty {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 10px;
	padding: 32px 0;
}
.fin-empty__text {
	margin: 0;
	font-size: 14px;
	color: #c0c4cc;
}
</style>
