<template>
	<span class="ns">{{ display }}</span>
</template>

<script setup>
import { ref, watch, onUnmounted } from 'vue';
const props = defineProps({
	value: { type: Number, default: 0 },
	decimals: { type: Number, default: 2 },
	duration: { type: Number, default: 800 },
});
const display = ref('0');
let raf = null;
const fmt = (n) => Number(n).toLocaleString('zh-CN', { minimumFractionDigits: props.decimals, maximumFractionDigits: props.decimals });
const animate = (to) => {
	cancelAnimationFrame(raf);
	const from = parseFloat(String(display.value).replace(/,/g, '')) || 0;
	const start = performance.now();
	const tick = (now) => {
		const t = Math.min(1, (now - start) / props.duration);
		const eased = 1 - Math.pow(1 - t, 3);
		display.value = fmt(from + (to - from) * eased);
		if (t < 1) raf = requestAnimationFrame(tick);
	};
	raf = requestAnimationFrame(tick);
};
watch(() => props.value, (v) => animate(Number(v) || 0), { immediate: true });
onUnmounted(() => cancelAnimationFrame(raf));
</script>
<style scoped>
.ns { font-variant-numeric: tabular-nums; }
</style>
