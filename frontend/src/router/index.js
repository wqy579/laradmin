import { createRouter, createWebHashHistory } from 'vue-router'
import NProgress from 'nprogress'
import 'nprogress/nprogress.css'
import config from '../config'
import { useUserStore } from '../stores/modules/user'
import systemRoutes from './systemRoutes'
import { useDictionaryStore } from '../stores/modules/dictionary'
import authApi from '../api/auth'

// 配置 NProgress
NProgress.configure({
	showSpinner: false,
	trickleSpeed: 200,
	minimum: 0.3,
})

/**
 * 404 路由
 */
const notFoundRoute = {
	path: '/:pathMatch(.*)*',
	name: 'NotFound',
	component: () => import('../layouts/other/404.vue'),
	meta: {
		title: '404',
		hidden: true,
	},
}

// 创建路由实例
const router = createRouter({
	history: createWebHashHistory(),
	routes: systemRoutes,
})

/**
 * 组件导入映射
 */
const modules = import.meta.glob('../views/**/*.vue')

/**
 * 动态加载组件
 * @param {string} componentPath - 组件路径
 * @returns {Promise} 组件
 */
function loadComponent(componentPath) {
	if (componentPath.startsWith('pages/')) {
		const path = componentPath.replace('pages/', '../views/')
		if (modules[`${path}.vue`]) return modules[`${path}.vue`]
	}

	const directPath = `../views/${componentPath}.vue`
	if (modules[directPath]) return modules[directPath]

	const indexPath = `../views/${componentPath}/index.vue`
	if (modules[indexPath]) return modules[indexPath]

	return null
}

/**
 * 发现同级目录下的兄弟路由
 * @param {string} componentPath - 组件路径
 * @param {string} routePath - 路由路径
 * @returns {Array} 兄弟路由数组
 */
function discoverSiblingRoutes(componentPath, routePath) {
	const componentDir = componentPath.replace(/\/index$/, '')
	const dirPrefix = `../views/${componentDir}/`
	const siblingRoutes = []

	Object.keys(modules).forEach((modulePath) => {
		if (modulePath.startsWith(dirPrefix) && !modulePath.endsWith('/index.vue') && !modulePath.includes('/components/')) {
			const fileName = modulePath.replace(dirPrefix, '').replace('.vue', '')
			siblingRoutes.push({
				path: `${routePath}/${fileName}`,
				name: `${routePath}/${fileName}`.replace(/\//g, '-'),
				component: modules[modulePath],
				meta: { title: fileName, hidden: true },
			})
		}
	})

	return siblingRoutes
}

/**
 * 将后端菜单转换为路由格式
 * @param {Array} menus - 后端返回的菜单数据
 * @returns {Array} 路由数组
 */
function transformMenusToRoutes(menus) {
	if (!menus || !Array.isArray(menus)) {
		return []
	}

	const routes = []

	menus
		.filter((menu) => menu && menu.path)
		.forEach((menu) => {
			const routePath = menu.path.split('?')[0]
			const route = {
				path: routePath,
				name: menu.name || menu.path.replace(/\//g, '-'),
				meta: {
					title: menu.meta?.title || menu.title,
					icon: menu.meta?.icon || menu.icon,
					hidden: menu.hidden || menu.meta?.hidden,
					keepAlive: menu.meta?.keepAlive || false,
					affix: menu.meta?.affix || 0,
					role: menu.meta?.role || [],
				},
			}

			if (menu.component) {
				const comp = loadComponent(menu.component)
				// ⚠️ 组件缺失时不能 return：父菜单少写一个落地页，会让整棵子树（下面所有
				// 子菜单路由）一起不注册，表现为「整个模块没开发」。这里只放弃 component，
				// 路由本身照常注册，子菜单仍可访问。
				if (comp) route.component = comp
			}

			if (menu.children && menu.children.length > 0) {
				route.children = transformMenusToRoutes(menu.children)
			}

			if (menu.redirect) {
				route.redirect = menu.redirect
			}

			routes.push(route)

			if (menu.component) {
				const siblingRoutes = discoverSiblingRoutes(menu.component, routePath)
				siblingRoutes.forEach((sr) => routes.push(sr))
			}
		})

	return routes
}

/**
 * URL 别名：菜单里不显示，但让更直觉/旧的路径也能打开对应页面。
 *
 * 背景：验收时直接用 /business/borrow、/dashboard-big 这类路径访问，而菜单注册的
 * 规范路径是 /business/borrow-order、/dashboard，路径对不上就被判成 404「未开发」。
 * 这里补一层重定向，两种写法都能进。
 */
// 只列「菜单里没有、但可能被直接访问」的路径。父菜单路径（/business/borrow-return、
// /business/exchange）本身已是落地页并重定向到首个子页面，再注册同名别名会重复，故不列。
const ROUTE_ALIASES = {
	'/business/borrow': '/business/borrow-order',
	'/business/return': '/business/return-order',
	'/dashboard-big': '/dashboard',
}

function addAliasRoutes() {
	Object.entries(ROUTE_ALIASES).forEach(([from, to]) => {
		router.addRoute('Layout', {
			path: from,
			redirect: to,
			meta: { title: to, hidden: true },
		})
	})
}

/**
 * 路由守卫
 */
let isDynamicRouteLoaded = false

router.beforeEach(async (to, from, next) => {
	// 开始进度条
	NProgress.start()

	// 设置页面标题
	document.title = to.meta.title ? `${to.meta.title} - ${config.APP_NAME}` : config.APP_NAME

	const userStore = useUserStore()
	const isLoggedIn = userStore.isLoggedIn()
	const whiteList = config.whiteList || []

	// 1. 如果在白名单中，直接放行
	if (whiteList.includes(to.path)) {
		next()
		return
	}

	// 2. 如果未登录，跳转到登录页
	if (!isLoggedIn) {
		next({
			path: '/login',
			query: { redirect: to.fullPath },
		})
		return
	}

	// 3. 已登录情况
	// 如果访问登录页，重定向到首页
	if (to.path === '/login') {
		next({ path: config.DASHBOARD_URL })
		return
	}

	// 4. 动态路由加载
	if (!isDynamicRouteLoaded) {
		try {
			// 获取后端返回的用户菜单
			const mergedMenus = userStore.getMenu()

			if (mergedMenus && mergedMenus.length > 0) {
				// 将合并后的菜单转换为路由
				const dynamicRoutes = transformMenusToRoutes(mergedMenus)

				// 添加动态路由到 Layout 的子路由
				dynamicRoutes.forEach((route) => {
					router.addRoute('Layout', route)
				})
				addAliasRoutes()

				// 添加 404 路由（必须在最后添加）
				router.addRoute(notFoundRoute)

				isDynamicRouteLoaded = true

				// 加载字典数据
				const dictionaryStore = useDictionaryStore()
				await dictionaryStore.loadAllDictionaries()

				// 重新导航，确保新添加的路由被正确匹配
				next({ ...to, replace: true })
			} else {
				// 没有菜单数据：刷新后 token 已恢复但菜单未持久化，先尝试重新拉取
				try {
					const menuRes = await authApi.permission.menu.get()
					const freshMenu = menuRes?.data || []
					if (freshMenu.length > 0) {
						userStore.setMenu(freshMenu)

						const dynamicRoutes = transformMenusToRoutes(freshMenu)
						dynamicRoutes.forEach((route) => {
							router.addRoute('Layout', route)
						})
						addAliasRoutes()
						router.addRoute(notFoundRoute)

						isDynamicRouteLoaded = true

						const dictionaryStore = useDictionaryStore()
						await dictionaryStore.loadAllDictionaries()

						next({ ...to, replace: true })
					} else {
						userStore.logout()
						next({ path: '/login', query: { redirect: to.fullPath } })
					}
				} catch (error) {
					console.error('重新获取菜单失败:', error)
					userStore.logout()
					next({ path: '/login', query: { redirect: to.fullPath } })
				}
			}
		} catch (error) {
			console.error('动态路由加载失败:', error)

			// 加载失败，清除用户信息并跳转到登录页
			userStore.logout()
			next({
				path: '/login',
				query: { redirect: to.fullPath },
			})
		}
	} else {
		// 动态路由已加载，直接放行
		next()
	}
})

router.afterEach(() => {
	// 结束进度条
	NProgress.done()
})

/**
 * 重置路由（用于登出时）
 */
export function resetRouter() {
	isDynamicRouteLoaded = false

	const newRouter = createRouter({
		history: createWebHashHistory(),
		routes: systemRoutes,
	})

	router.matcher = newRouter.matcher
}

export default router

