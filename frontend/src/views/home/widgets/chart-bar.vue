<script setup>
import { ref, computed, reactive, onMounted } from 'vue'
import { useResizeObserver } from '@vueuse/core'

const props = defineProps({
	title: {
		type: String,
		default: '销售统计',
	},
	data: {
		type: Array,
		default: () => [
			{ category: '电子产品', value: 4200 },
			{ category: '服装', value: 3100 },
			{ category: '食品', value: 2800 },
			{ category: '家居', value: 2200 },
			{ category: '美妆', value: 1900 },
			{ category: '数码', value: 1600 },
		],
	},
})

const containerRef = ref(null)
const containerWidth = ref(0)
const containerHeight = ref(0)
const hoveredIndex = ref(-1)

// 坐标轴留白随容器宽度自适应，窄屏压缩留白让图表区域最大化
const padding = reactive({ top: 24, right: 28, bottom: 56, left: 56 })

function updateMetrics() {
	if (!containerRef.value) return
	const w = containerRef.value.clientWidth
	containerWidth.value = w
	containerHeight.value = containerRef.value.clientHeight
	if (w < 300) {
		padding.top = 16
		padding.right = 12
		padding.bottom = 36
		padding.left = 36
	} else if (w < 480) {
		padding.top = 20
		padding.right = 18
		padding.bottom = 44
		padding.left = 44
	} else {
		padding.top = 24
		padding.right = 28
		padding.bottom = 56
		padding.left = 56
	}
}

useResizeObserver(containerRef, updateMetrics)

const maxY = computed(() => Math.max(...props.data.map((d) => d.value)) * 1.2)

const chartWidth = computed(() => Math.max(containerWidth.value - padding.left - padding.right, 100))
const chartHeight = computed(() => Math.max(containerHeight.value - padding.top - padding.bottom, 100))

const barWidth = computed(() => Math.max((chartWidth.value / props.data.length) * 0.6, 20))
const barGap = computed(() => chartWidth.value / props.data.length)

const bars = computed(() => {
	return props.data.map((d, i) => {
		const x = padding.left + i * barGap.value + (barGap.value - barWidth.value) / 2
		const height = (d.value / maxY.value) * chartHeight.value
		const y = padding.top + chartHeight.value - height
		return { x, y, width: barWidth.value, height, value: d.value, label: d.category }
	})
})

const yTicks = computed(() => {
	const ticks = []
	const step = maxY.value / 4
	for (let i = 0; i <= 4; i++) {
		ticks.push(step * i)
	}
	return ticks
})

onMounted(updateMetrics)
</script>

<template>
	<div class="chart-widget">
		<div class="chart-widget__header">
			<el-icon class="chart-widget__icon" :size="16">
				<ElIconHistogram />
			</el-icon>
			<span class="chart-widget__title">{{ title }}</span>
		</div>
		<div ref="containerRef" class="chart-widget__body">
			<svg :width="containerWidth" :height="containerHeight" class="chart-bar__svg">
				<g class="chart-bar__grid">
					<line v-for="(tick, i) in yTicks" :key="i" :x1="padding.left" :y1="padding.top + chartHeight - (tick / maxY) * chartHeight" :x2="padding.left + chartWidth" :y2="padding.top + chartHeight - (tick / maxY) * chartHeight" stroke="var(--el-border-color-lighter)" stroke-width="1" />
				</g>

				<g class="chart-bar__y-axis">
					<text v-for="(tick, i) in yTicks" :key="i" :x="padding.left - 10" :y="padding.top + chartHeight - (tick / maxY) * chartHeight + 4" text-anchor="end" fill="var(--el-text-color-secondary)" font-size="11">{{ Math.round(tick / 1000) }}k</text>
				</g>

				<g class="chart-bar__x-axis">
					<text v-for="(bar, i) in bars" :key="i" :x="bar.x + bar.width / 2" :y="padding.top + chartHeight + 16" text-anchor="middle" fill="var(--el-text-color-secondary)" font-size="11">
						{{ bar.label }}
					</text>
				</g>

				<g class="chart-bar__bars">
					<rect
						v-for="(bar, i) in bars"
						:key="i"
						:x="bar.x"
						:y="bar.y"
						:width="bar.width"
						:height="bar.height"
						:rx="6"
						:fill="hoveredIndex === i ? 'var(--el-color-primary)' : 'var(--el-color-primary-light-3)'"
						class="chart-bar__bar"
						@mouseenter="hoveredIndex = i"
						@mouseleave="hoveredIndex = -1"
					/>
				</g>
			</svg>

			<div v-if="hoveredIndex !== -1" class="chart-bar__tooltip" :style="{ left: `${bars[hoveredIndex].x + bars[hoveredIndex].width / 2}px`, top: `${bars[hoveredIndex].y - 10}px` }">
				<div class="chart-bar__tooltip-value">{{ bars[hoveredIndex].value.toLocaleString() }}</div>
				<div class="chart-bar__tooltip-label">{{ bars[hoveredIndex].label }}</div>
			</div>
		</div>
	</div>
</template>

<style scoped>
.chart-widget {
	height: 100%;
	display: flex;
	flex-direction: column;
}

.chart-widget__header {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 12px 16px;
	border-bottom: 1px solid var(--el-border-color-lighter);
	background: linear-gradient(to bottom, var(--el-bg-color-page), var(--el-bg-color));
}

.chart-widget__icon {
	color: var(--el-color-primary);
}

.chart-widget__title {
	font-size: 14px;
	font-weight: 600;
	color: var(--el-text-color-primary);
}

.chart-widget__body {
	flex: 1;
	position: relative;
	min-height: 0;
}

.chart-bar__svg {
	width: 100%;
	height: 100%;
}

.chart-bar__bar {
	transition: fill 0.2s ease;
	cursor: pointer;
}

.chart-bar__tooltip {
	position: absolute;
	transform: translate(-50%, -100%);
	background: var(--el-bg-color);
	border: 1px solid var(--el-border-color);
	border-radius: 8px;
	padding: 8px 12px;
	box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
	pointer-events: none;
	z-index: 10;
}

.chart-bar__tooltip-value {
	font-size: 14px;
	font-weight: 600;
	color: var(--el-text-color-primary);
	margin-bottom: 2px;
}

.chart-bar__tooltip-label {
	font-size: 12px;
	color: var(--el-text-color-secondary);
}
</style>
