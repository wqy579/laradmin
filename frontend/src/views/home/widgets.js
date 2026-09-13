import { markRaw } from 'vue'
import StatCard from './widgets/stat-card.vue'
import ChartLine from './widgets/chart-line.vue'
import ChartBar from './widgets/chart-bar.vue'
import TodoList from './widgets/todo-list.vue'
import RecentActivity from './widgets/recent-activity.vue'

const WIDGET_REGISTRY = {
	'stat-users': {
		id: 'stat-users',
		title: '用户统计',
		icon: 'ElIconUser',
		defaultW: 3,
		defaultH: 2,
		component: markRaw(StatCard),
		props: {
			type: 'primary',
			label: '总用户数',
			value: '12,846',
			trend: 12.5,
			subValue: '较上月',
		},
	},
	'stat-orders': {
		id: 'stat-orders',
		title: '订单统计',
		icon: 'ElIconShoppingCart',
		defaultW: 3,
		defaultH: 2,
		component: markRaw(StatCard),
		props: {
			type: 'success',
			label: '总订单数',
			value: '3,256',
			trend: 8.3,
			subValue: '较上月',
		},
	},
	'stat-revenue': {
		id: 'stat-revenue',
		title: '收入统计',
		icon: 'ElIconWallet',
		defaultW: 3,
		defaultH: 2,
		component: markRaw(StatCard),
		props: {
			type: 'warning',
			label: '总收入',
			value: '¥128,560',
			trend: -2.4,
			subValue: '较上月',
		},
	},
	'stat-visits': {
		id: 'stat-visits',
		title: '访问统计',
		icon: 'ElIconView',
		defaultW: 3,
		defaultH: 2,
		component: markRaw(StatCard),
		props: {
			type: 'danger',
			label: '今日访问',
			value: '8,432',
			trend: 5.7,
			subValue: '较昨日',
		},
	},
	'chart-line': {
		id: 'chart-line',
		title: '访问趋势',
		icon: 'ElIconTrendCharts',
		defaultW: 6,
		defaultH: 4,
		component: markRaw(ChartLine),
		props: {},
	},
	'chart-bar': {
		id: 'chart-bar',
		title: '销售统计',
		icon: 'ElIconHistogram',
		defaultW: 6,
		defaultH: 4,
		component: markRaw(ChartBar),
		props: {},
	},
	'todo-list': {
		id: 'todo-list',
		title: '待办事项',
		icon: 'ElIconList',
		defaultW: 4,
		defaultH: 4,
		component: markRaw(TodoList),
		props: {},
	},
	'recent-activity': {
		id: 'recent-activity',
		title: '最近动态',
		icon: 'ElIconBell',
		defaultW: 4,
		defaultH: 4,
		component: markRaw(RecentActivity),
		props: {},
	},
}

export function getWidgetList() {
	return Object.values(WIDGET_REGISTRY)
}

export function getWidgetConfig(id) {
	return WIDGET_REGISTRY[id] || null
}

export const DEFAULT_LAYOUT = [
	{ x: 0, y: 0, w: 3, h: 2, i: 'stat-users', widgetId: 'stat-users' },
	{ x: 3, y: 0, w: 3, h: 2, i: 'stat-orders', widgetId: 'stat-orders' },
	{ x: 6, y: 0, w: 3, h: 2, i: 'stat-revenue', widgetId: 'stat-revenue' },
	{ x: 9, y: 0, w: 3, h: 2, i: 'stat-visits', widgetId: 'stat-visits' },
	{ x: 0, y: 2, w: 6, h: 4, i: 'chart-line', widgetId: 'chart-line' },
	{ x: 6, y: 2, w: 6, h: 4, i: 'chart-bar', widgetId: 'chart-bar' },
]
