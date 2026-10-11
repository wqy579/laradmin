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
		// dev 下前端 base('/admin/') 与后端 API 前缀(/admin) 整段重合：
		// 若把 /admin 整段代理出去，会一并吞掉 Vite 自身的 /admin/src、
		// /admin/@vite、/admin/@fs、/admin/node_modules 与 public 静态文件，
		// 导致 dev server 形同虚设——浏览器只能拿到代理目标的构建产物，
		// 本地源码改动完全无法在 5173 生效（此前 6 项修复"不生效"即此因）。
		// 故 target 指向本地 Octane(8000)，并用 bypass 把 dev 自身资源交还 Vite。
		proxy: {
			'/admin': {
				target: 'http://localhost:8000',
				changeOrigin: true,
				secure: false,
				bypass(req) {
					// bypass 返回 string → 不代理，按该 url 继续走 Vite 中间件链；
					// 返回 false → 直接 404（Vite http-proxy 的语义，不是"交给 Vite"）。
					// 故需交还 Vite 的请求统一返回 req.url（字符串）。
					// SPA 入口（hash 路由下浏览器只请求 /admin/ 与静态文件）
					if (req.url === '/admin/' || req.url === '/admin' || req.url === '/admin/index.html' || req.url === '/') return req.url
					// Vite dev 自身路径：@id（plugin-vue export-helper 等）、@vite(HMR)、@fs、@react-refresh、src、node_modules
					if (/^\/admin\/(@vite|@fs|@id|@react-refresh|src|node_modules)\b/.test(req.url)) return req.url
					// 带文件后缀的静态资源（含 public/config.js）
					if (/\.(js|css|scss|vue|ts|json|svg|png|jpe?g|ico|woff2?|ttf|map)(\?|$)/.test(req.url)) return req.url
				},
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
