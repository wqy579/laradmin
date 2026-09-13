<script setup>
import { ref, computed, reactive, onMounted } from 'vue'
import { useResizeObserver } from '@vueuse/core'

const props = defineProps({
	title: {
		type: String,
		default: '访问趋势',
	},
	data: {
		type: Array,
		default: () => [
			{ month: '1月', value: 3020 },
			{ month: '2月', value: 4510 },
			{ month: '3月', value: 2840 },
			{ month: '4月', value: 5520 },
			{ month: '5月', value: 4280 },
			{ month: '6月', value: 6530 },
			{ month: '7月', value: 5020 },
			{ month: '8月', value: 7210 },
			{ month: '9月', value: 6100 },
			{ month: '10月', value: 8050 },
			{ month: '11月', value: 6820 },
			{ month: '12月', value: 9020 },
		],
	},
})

const containerRef = ref(null)
const containerWidth = ref(0)
const containerHeight = ref(0)
const hoveredIndex = ref(-1)

// 坐标轴留白随容器宽度自适应，窄屏压缩留白让图表区域最大化
const padding = reactive({ top: 24, right: 28, bottom: 48, left: 56 })

function updateMetrics() {
	if (!containerRef.value) return
	const w = containerRef.value.clientWidth
	containerWidth.value = w
	containerHeight.value = containerRef.value.clientHeight
	if (w < 300) {
		padding.top = 16
		padding.right = 12
		padding.bottom = 28
		padding.left = 36
	} else if (w < 480) {
		padding.top = 20
		padding.right = 18
		padding.bottom = 36
		padding.left = 44
	} else {
		padding.top = 24
		padding.right = 28
		padding.bottom = 48
		padding.left = 56
	}
}

useResizeObserver(containerRef, updateMetrics)

const maxY = computed(() => Math.max(...props.data.map((d) => d.value)) * 1.2)
const minY = computed(() => Math.min(...props.data.map((d) => d.value)) * 0.8)

const chartWidth = computed(() => Math.max(containerWidth.value - padding.left - padding.right, 100))
const chartHeight = computed(() => Math.max(containerHeight.value - padding.top - padding.bottom, 100))

const points = computed(() => {
	return props.data.map((d, i) => {
		const x = padding.left + (i / (props.data.length - 1)) * chartWidth.value
		const y = padding.top + chartHeight.value - ((d.value - minY.value) / (maxY.value - minY.value)) * chartHeight.value
		return { x, y, value: d.value, label: d.month }
	})
})

const pathD = computed(() => {
	if (points.value.length === 0) return ''
	let d = `M ${points.value[0].x} ${points.value[0].y}`
	for (let i = 1; i < points.value.length; i++) {
		const prev = points.value[i - 1]
		const curr = points.value[i]
		const cpx = (prev.x + curr.x) / 2
		d += ` C ${cpx} ${prev.y}, ${cpx} ${curr.y}, ${curr.x} ${curr.y}`
	}
	return d
})

const areaD = computed(() => {
	if (points.value.length === 0) return ''
	const first = points.value[0]
	const last = points.value[points.value.length - 1]
	const bottomY = padding.top + chartHeight.value
	return `${pathD.value} L ${last.x} ${bottomY} L ${first.x} ${bottomY} Z`
})

const yTicks = computed(() => {
	const ticks = []
	const step = (maxY.value - minY.value) / 4
	for (let i = 0; i <= 4; i++) {
		ticks.push(minY.value + step * i)
	}
	return ticks
})

onMounted(updateMetrics)
</script>

<template>
	<div class="chart-widget">
		<div class="chart-widget__header">
			<el-icon class="chart-widget__icon" :size="16">
				<ElIconTrendCharts />
			</el-icon>
			<span class="chart-widget__title">{{ title }}</span>
		</div>
		<div ref="containerRef" class="chart-widget__body">
			<svg :width="containerWidth" :height="containerHeight" class="chart-line__svg">
				<g class="chart-line__grid">
					<line
						v-for="(tick, i) in yTicks"
						:key="i"
						:x1="padding.left"
						:y1="padding.top + chartHeight - ((tick - minY) / (maxY - minY)) * chartHeight"
						:x2="padding.left + chartWidth"
						:y2="padding.top + chartHeight - ((tick - minY) / (maxY - minY)) * chartHeight"
						stroke="var(--el-border-color-lighter)"
						stroke-width="1"
					/>
				</g>

				<g class="chart-line__y-axis">
					<text v-for="(tick, i) in yTicks" :key="i" :x="padding.left - 10" :y="padding.top + chartHeight - ((tick - minY) / (maxY - minY)) * chartHeight + 4" text-anchor="end" fill="var(--el-text-color-secondary)" font-size="11">{{ Math.round(tick / 1000) }}k</text>
				</g>

				<g class="chart-line__x-axis">
					<text v-for="(point, i) in points" :key="i" :x="point.x" :y="padding.top + chartHeight + 16" text-anchor="middle" fill="var(--el-text-color-secondary)" font-size="11">
						{{ point.label }}
					</text>
				</g>

				<path :d="areaD" fill="url(#areaGradient)" class="chart-line__area" />

				<path :d="pathD" fill="none" stroke="var(--el-color-primary)" stroke-width="3" stroke-linecap="round" class="chart-line__line" />

				<g class="chart-line__dots">
					<circle
						v-for="(point, i) in points"
						:key="i"
						:cx="point.x"
						:cy="point.y"
						:r="hoveredIndex === i ? 8 : 5"
						fill="white"
						:stroke="hoveredIndex === i ? 'var(--el-color-primary)' : 'var(--el-color-primary)'"
						stroke-width="hoveredIndex === i ? 3 : 2"
						class="chart-line__dot"
						@mouseenter="hoveredIndex = i"
						@mouseleave="hoveredIndex = -1"
					/>
				</g>

				<defs>
					<linearGradient id="areaGradient" x1="0%" y1="0%" x2="0%" y2="100%">
						<stop offset="0%" style="stop-color: var(--el-color-primary); stop-opacity: 0.3" />
						<stop offset="100%" style="stop-color: var(--el-color-primary); stop-opacity: 0" />
					</linearGradient>
				</defs>
			</svg>

			<div v-if="hoveredIndex !== -1" class="chart-line__tooltip" :style="{ left: `${points[hoveredIndex].x}px`, top: `${points[hoveredIndex].y - 10}px` }">
				<div class="chart-line__tooltip-value">{{ points[hoveredIndex].value.toLocaleString() }}</div>
				<div class="chart-line__tooltip-label">{{ points[hoveredIndex].label }}</div>
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

.chart-line__svg {
	width: 100%;
	height: 100%;
}

.chart-line__line {
	transition: stroke-width 0.2s ease;
}

.chart-line__dot {
	transition:
		r 0.2s ease,
		stroke-width 0.2s ease;
	cursor: pointer;
}

.chart-line__tooltip {
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

.chart-line__tooltip-value {
	font-size: 14px;
	font-weight: 600;
	color: var(--el-text-color-primary);
	margin-bottom: 2px;
}

.chart-line__tooltip-label {
	font-size: 12px;
	color: var(--el-text-color-secondary);
}
</style>
