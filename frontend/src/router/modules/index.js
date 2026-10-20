/**
 * 路由模块自动聚合（基建：避免多人同时改 systemRoutes.js 冲突）
 * 约定：每个开发者在本目录新建自己的模块文件，默认导出路由数组，例如：
 *
 *   // finance.js
 *   export default [
 *     { path: '/finance/voucher', component: () => import('@/views/finance/Voucher.vue'),
 *       meta: { title: '财务凭证', perm: 'fin:voucher:view' } },
 *   ]
 *
 * 本文件用 import.meta.glob 自动收集，无需手工登记。
 */
const modules = import.meta.glob('./*.js', { eager: true })

const routes = []
for (const path in modules) {
  const mod = modules[path]
  if (mod && mod.default && Array.isArray(mod.default)) {
    routes.push(...mod.default)
  }
}

export default routes
