import { defineStore } from 'pinia'
import { ref, watch } from 'vue'
import { DEFAULT_LAYOUT, getWidgetConfig } from '../views/home/widgets'

const THEME_COLORS = [
	{ name: 'default', color: '#409eff' },
	{ name: 'green', color: '#67c23a' },
	{ name: 'orange', color: '#e6a23c' },
	{ name: 'red', color: '#f56c6c' },
	{ name: 'purple', color: '#6366f1' },
]

export const useAppStore = defineStore(
	'app',
	() => {
		const locale = ref('zh-CN')
		const layout = ref('default')
		const isDark = ref(false)
		const themeColor = ref('#409eff')
		const sidebarCollapsed = ref(false)
		const subMenuCollapsed = ref(false)
		const activeMenu = ref('/home')
		const tagsList = ref([])
		const showSettings = ref(false)
		const showTagsBar = ref(true)
		const dashboardLayout = ref(JSON.parse(JSON.stringify(DEFAULT_LAYOUT)))
		const dashboardEditMode = ref(false)

		const widgetLoadingStates = ref({})
		const widgetErrorStates = ref({})
		const widgetData = ref({})

		const todoList = ref([
			{ id: 1, text: '完成首页设计稿审核', done: false },
			{ id: 2, text: '更新用户权限模块', done: false },
			{ id: 3, text: '修复订单列表分页问题', done: true },
			{ id: 4, text: '部署测试环境', done: false },
			{ id: 5, text: '编写 API 接口文档', done: true },
		])

		const layoutList = [
			{ value: 'default', label: '双栏布局' },
			{ value: 'basic', label: '经典布局' },
			{ value: 'top', label: '顶部菜单' },
		]

		const themeColors = THEME_COLORS

		function setLocale(val) {
			locale.value = val
		}
		function setLayout(val) {
			layout.value = val
		}

		function toggleDark() {
			isDark.value = !isDark.value
		}

		function setThemeColor(color) {
			themeColor.value = color
		}

		function toggleSidebar() {
			sidebarCollapsed.value = !sidebarCollapsed.value
		}
		function toggleSubMenu() {
			subMenuCollapsed.value = !subMenuCollapsed.value
		}

		function addTag(tag) {
			if (!tagsList.value.find((t) => t.path === tag.path)) {
				tagsList.value.push(tag)
			}
			activeMenu.value = tag.path
		}

		function removeTag(path) {
			const idx = tagsList.value.findIndex((t) => t.path === path)
			if (idx > -1) {
				tagsList.value.splice(idx, 1)
				if (activeMenu.value === path) {
					const next = tagsList.value[Math.min(idx, tagsList.value.length - 1)]
					activeMenu.value = next?.path || '/home'
				}
			}
		}

		function removeOtherTags(path) {
			tagsList.value = tagsList.value.filter((t) => t.path === path || !t.closable)
		}

		function removeLeftTags(path) {
			const idx = tagsList.value.findIndex((t) => t.path === path)
			if (idx > -1) {
				const right = tagsList.value.slice(idx)
				const fixed = tagsList.value.slice(0, idx).filter((t) => !t.closable)
				tagsList.value = [...fixed, ...right]
			}
		}

		function removeRightTags(path) {
			const idx = tagsList.value.findIndex((t) => t.path === path)
			if (idx > -1) {
				const left = tagsList.value.slice(0, idx + 1)
				const fixed = tagsList.value.slice(idx + 1).filter((t) => !t.closable)
				tagsList.value = [...left, ...fixed]
			}
		}

		function clearTags() {
			tagsList.value = tagsList.value.filter((t) => !t.closable)
		}

		function reorderTags(fromIndex, toIndex) {
			const affixTags = tagsList.value.filter((t) => !t.closable)
			const normalTags = tagsList.value.filter((t) => t.closable !== false)
			const [moved] = normalTags.splice(fromIndex, 1)
			normalTags.splice(toIndex, 0, moved)
			tagsList.value = [...affixTags, ...normalTags]
		}

		function addDashboardWidget(widgetId) {
			const config = getWidgetConfig(widgetId)
			if (!config) return
			const layout = dashboardLayout.value
			const maxY = layout.reduce((max, item) => Math.max(max, item.y + item.h), 0)
			layout.push({
				x: 0,
				y: maxY,
				w: config.defaultW,
				h: config.defaultH,
				i: `${widgetId}-${Date.now()}`,
				widgetId,
			})
		}

		function removeDashboardWidget(itemId) {
			const idx = dashboardLayout.value.findIndex((item) => item.i === itemId)
			if (idx > -1) dashboardLayout.value.splice(idx, 1)
			delete widgetLoadingStates.value[itemId]
			delete widgetErrorStates.value[itemId]
			delete widgetData.value[itemId]
		}

		function resetDashboardLayout() {
			dashboardLayout.value = JSON.parse(JSON.stringify(DEFAULT_LAYOUT))
			widgetLoadingStates.value = {}
			widgetErrorStates.value = {}
			widgetData.value = {}
		}

		function setWidgetLoading(itemId, loading) {
			widgetLoadingStates.value[itemId] = loading
		}

		function setWidgetError(itemId, error) {
			widgetErrorStates.value[itemId] = error
		}

		function setWidgetData(itemId, data) {
			widgetData.value[itemId] = data
		}

		function addTodo(text) {
			todoList.value.unshift({ id: Date.now(), text, done: false })
		}

		function toggleTodo(id) {
			const todo = todoList.value.find((t) => t.id === id)
			if (todo) todo.done = !todo.done
		}

		function removeTodo(id) {
			const idx = todoList.value.findIndex((t) => t.id === id)
			if (idx > -1) todoList.value.splice(idx, 1)
		}

		function clearCompletedTodos() {
			todoList.value = todoList.value.filter((t) => !t.done)
		}

		function applyThemeColor(color, dark) {
			const el = document.documentElement
			el.style.setProperty('--el-color-primary', color)
			const r = parseInt(color.slice(1, 3), 16)
			const g = parseInt(color.slice(3, 5), 16)
			const b = parseInt(color.slice(5, 7), 16)
			const mr = dark ? 29 : 255
			const mg = dark ? 30 : 255
			const mb = dark ? 31 : 255
			for (let i = 1; i <= 9; i++) {
				const mix = 1 - i / 10
				el.style.setProperty(`--el-color-primary-light-${10 - i}`, `rgb(${Math.round(r + (mr - r) * mix)}, ${Math.round(g + (mg - g) * mix)}, ${Math.round(b + (mb - b) * mix)})`)
			}
		}

		watch(
			isDark,
			(val) => {
				document.documentElement.classList.toggle('dark', val)
				applyThemeColor(themeColor.value, val)
			},
			{ immediate: true },
		)

		watch(themeColor, (color) => {
			applyThemeColor(color, isDark.value)
		})

		return {
			locale,
			layout,
			isDark,
			themeColor,
			sidebarCollapsed,
			subMenuCollapsed,
			activeMenu,
			tagsList,
			showSettings,
			showTagsBar,
			dashboardLayout,
			dashboardEditMode,
			widgetLoadingStates,
			widgetErrorStates,
			widgetData,
			todoList,
			layoutList,
			themeColors,
			setLocale,
			setLayout,
			toggleDark,
			setThemeColor,
			toggleSidebar,
			toggleSubMenu,
			addTag,
			removeTag,
			removeOtherTags,
			removeLeftTags,
			removeRightTags,
			clearTags,
			reorderTags,
			addDashboardWidget,
			removeDashboardWidget,
			resetDashboardLayout,
			setWidgetLoading,
			setWidgetError,
			setWidgetData,
			addTodo,
			toggleTodo,
			removeTodo,
			clearCompletedTodos,
		}
	},
	{
		persist: {
			pick: ['locale', 'layout', 'isDark', 'themeColor', 'showTagsBar', 'dashboardLayout', 'tagsList', 'todoList', 'widgetData'],
		},
	},
)
