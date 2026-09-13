import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { resolve } from 'path'

// 线上构建可用内存约 1.4G（2G 内存 + 2G swap 的共享服务器，构建时 laravels 已停止）。
// 此配置刻意规避两类高内存操作：
//   1. rollupOptions.output.manualChunks —— 需要把整个依赖图驻留内存做模块分配，
//      在内存紧张时直接 OOM（此前线上两次构建卡在 transforming 后拖死整机）。
//      改用 router 层动态 import() 天然分包，效果等价且构建期零额外内存开销。
//   2. 进程启动后才设置 NODE_OPTIONS —— 那时 Vite 已按默认堆上限运行，
//      限制不生效，反而误导排查。堆上限已移入 package.json 的 build 脚本。
//
// 堆上限实测（Node 24 / Vite 5.4.21）：
//   800MB  失败（V8 native crash）
//   900MB  通过（~25s）  ← build 脚本采用此值
//   1024MB 通过（~25s）
// 低于 800MB 会触发 V8 地址空间保留崩溃，因此不能简单按物理内存等比下调。
export default defineConfig({
	base: '/admin/',
	plugins: [vue()],
	resolve: {
		alias: {
			'@': resolve(__dirname, 'src'),
		},
	},
	server: {
		allowedHosts: ['.monkeycode-ai.online'],
		proxy: {
			'/admin': {
				target: 'https://laravel.qjwykj.com',
				changeOrigin: true,
				secure: false,
			},
		},
	},
	build: {
		// 线上内存紧张：sourcemap 会显著增加构建期内存与产物体积
		sourcemap: false,
		// esbuild 压缩是单进程、低内存实现，避免 terser 的高内存占用
		minify: 'esbuild',
		// 按路由拆分 CSS，减少单文件体积与中间态内存
		cssCodeSplit: true,
		// 分包由动态 import() 完成，不再依赖 manualChunks
		chunkSizeWarningLimit: 1024,
	},
})
