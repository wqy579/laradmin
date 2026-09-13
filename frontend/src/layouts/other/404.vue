<script setup>
import { ref, onMounted, onBeforeUnmount, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useWindowSize } from '@vueuse/core'
import config from '../../config'

const router = useRouter()
const canvas = ref(null)
let animId = null

const { width: winW, height: winH } = useWindowSize()

onMounted(() => {
	const el = canvas.value
	if (!el) return
	const ctx = el.getContext('2d')
	let w = (el.width = winW.value)
	let h = (el.height = winH.value)

	const particles = Array.from({ length: 60 }, () => ({
		x: Math.random() * w,
		y: Math.random() * h,
		vx: (Math.random() - 0.5) * 0.4,
		vy: (Math.random() - 0.5) * 0.4,
		r: Math.random() * 1.5 + 0.5,
	}))

	function draw() {
		ctx.clearRect(0, 0, w, h)
		const color = getComputedStyle(document.documentElement).getPropertyValue('--el-color-primary').trim() || '#409eff'

		particles.forEach((p) => {
			p.x += p.vx
			p.y += p.vy
			if (p.x < 0) p.x = w
			if (p.x > w) p.x = 0
			if (p.y < 0) p.y = h
			if (p.y > h) p.y = 0

			ctx.beginPath()
			ctx.arc(p.x, p.y, p.r, 0, Math.PI * 2)
			ctx.fillStyle = color
			ctx.globalAlpha = 0.6
			ctx.fill()
		})

		ctx.globalAlpha = 0.12
		ctx.strokeStyle = color
		ctx.lineWidth = 0.5
		for (let i = 0; i < particles.length; i++) {
			for (let j = i + 1; j < particles.length; j++) {
				const dx = particles[i].x - particles[j].x
				const dy = particles[i].y - particles[j].y
				if (dx * dx + dy * dy < 18000) {
					ctx.beginPath()
					ctx.moveTo(particles[i].x, particles[i].y)
					ctx.lineTo(particles[j].x, particles[j].y)
					ctx.stroke()
				}
			}
		}
		ctx.globalAlpha = 1
		animId = requestAnimationFrame(draw)
	}
	draw()

	watch([winW, winH], () => {
		w = el.width = winW.value
		h = el.height = winH.value
	})

	onBeforeUnmount(() => {
		cancelAnimationFrame(animId)
	})
})
</script>

<template>
	<div class="not-found">
		<canvas ref="canvas" class="not-found__bg" />
		<div class="not-found__content">
			<div class="not-found__glitch" data-text="404">404</div>
			<p class="not-found__desc">抱歉，您访问的页面不存在</p>
			<button class="not-found__btn" @click="router.push(config.DASHBOARD_URL)">
				<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
					<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" />
					<polyline points="9 22 9 12 15 12 15 22" />
				</svg>
				返回首页
			</button>
		</div>
	</div>
</template>

<style scoped>
.not-found {
	position: relative;
	height: 100vh;
	display: flex;
	align-items: center;
	justify-content: center;
	overflow: hidden;
	background: var(--el-bg-color);
}
.not-found__bg {
	position: absolute;
	inset: 0;
	width: 100%;
	height: 100%;
	pointer-events: none;
}
.not-found__content {
	position: relative;
	z-index: 1;
	text-align: center;
}
.not-found__glitch {
	font-size: clamp(100px, 20vw, 200px);
	font-weight: 800;
	line-height: 1;
	color: var(--el-color-primary);
	position: relative;
	letter-spacing: 0.05em;
	text-shadow:
		0 0 20px color-mix(in srgb, var(--el-color-primary) 40%, transparent),
		0 0 60px color-mix(in srgb, var(--el-color-primary) 20%, transparent);
}
.not-found__glitch::before,
.not-found__glitch::after {
	content: attr(data-text);
	position: absolute;
	top: 0;
	left: 0;
	width: 100%;
	height: 100%;
}
.not-found__glitch::before {
	color: var(--el-color-primary);
	animation: glitch-1 3s infinite linear alternate-reverse;
	clip-path: polygon(0 0, 100% 0, 100% 35%, 0 35%);
}
.not-found__glitch::after {
	color: var(--el-color-primary);
	animation: glitch-2 2s infinite linear alternate-reverse;
	clip-path: polygon(0 65%, 100% 65%, 100% 100%, 0 100%);
}
@keyframes glitch-1 {
	0%,
	90% {
		transform: translate(0);
	}
	92% {
		transform: translate(-3px, 1px);
	}
	94% {
		transform: translate(3px, -1px);
	}
	96% {
		transform: translate(-2px, 2px);
	}
	98% {
		transform: translate(2px, -2px);
	}
	100% {
		transform: translate(0);
	}
}
@keyframes glitch-2 {
	0%,
	88% {
		transform: translate(0);
	}
	90% {
		transform: translate(2px, -1px);
	}
	93% {
		transform: translate(-3px, 2px);
	}
	96% {
		transform: translate(1px, -1px);
	}
	100% {
		transform: translate(0);
	}
}
.not-found__desc {
	font-size: 16px;
	color: var(--el-text-color-secondary);
	margin: 24px 0 40px;
}
.not-found__btn {
	display: inline-flex;
	align-items: center;
	gap: 8px;
	padding: 12px 28px;
	border: 1px solid var(--el-color-primary);
	border-radius: 8px;
	background: transparent;
	color: var(--el-color-primary);
	font-size: 15px;
	font-weight: 500;
	cursor: pointer;
	transition:
		background-color 0.25s,
		color 0.25s,
		box-shadow 0.25s;
	font-family: inherit;
}
.not-found__btn:hover {
	background: var(--el-color-primary);
	color: #fff;
	box-shadow: 0 4px 20px color-mix(in srgb, var(--el-color-primary) 40%, transparent);
}
.not-found__btn:active {
	transform: scale(0.97);
}

@media (prefers-reduced-motion: reduce) {
	.not-found__glitch::before,
	.not-found__glitch::after {
		animation: none;
	}
}
</style>
