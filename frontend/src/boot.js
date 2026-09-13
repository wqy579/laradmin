import router from './router'
import pinia from './stores'
import i18n from './locales'

import ElementPlus from 'element-plus'
import 'element-plus/dist/index.css'

import VxeUI from 'vxe-pc-ui'
import 'vxe-pc-ui/es/style.css'

import VxeTable from 'vxe-table'
import 'vxe-table/es/style.css'

import sTable from './components/sTable/index.vue'
import sSelect from './components/sSelect/index.vue'
import sSelectTree from './components/sSelectTree/index.vue'
import sIconSelect from './components/sIconSelect/index.vue'
import sUpload from './components/sUpload/index.vue'
import sUploadFile from './components/sUpload/file.vue'

import './assets/styles/app.css'

// 同步 VxeTable 主题与系统暗色模式
function syncVxeTheme() {
	VxeUI.setTheme(document.documentElement.classList.contains('dark') ? 'dark' : 'light')
}
syncVxeTheme()

const observer = new MutationObserver(() => syncVxeTheme())
observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] })

// 按需引入 Element Plus 图标：原先 import * 全量引入整个图标库（数百个组件），
// 是构建内存峰值与主包体积的主要来源。这里只引入实际被模板引用的图标。
import {
	Aim, ArrowDown, ArrowLeft, ArrowRight, Back, Bell, Bottom, Box, Check, CircleCheck,
	CircleClose, Clock, Close, CloseBold, Connection, CopyDocument, DataAnalysis, DataBoard,
	Delete, Document, Download, Edit, Expand, Files, Fold, Folder, FolderOpened, FullScreen,
	Grid, Headset, Histogram, Link, List, Location, Lock, Memo, Menu, Message, Minus, Money,
	Monitor, MoreFilled, OfficeBuilding, Picture, PictureFilled, Platform, Plus, Rank, Refresh,
	RefreshRight, Right, Search, Setting, ShoppingCart, Sort, SwitchButton, Top, TrendCharts,
	Upload, User, UserFilled, VideoCamera, View, Wallet, Warning, ZoomIn,
} from '@element-plus/icons-vue'

const ELEMENT_ICONS = {
	Aim, ArrowDown, ArrowLeft, ArrowRight, Back, Bell, Bottom, Box, Check, CircleCheck,
	CircleClose, Clock, Close, CloseBold, Connection, CopyDocument, DataAnalysis, DataBoard,
	Delete, Document, Download, Edit, Expand, Files, Fold, Folder, FolderOpened, FullScreen,
	Grid, Headset, Histogram, Link, List, Location, Lock, Memo, Menu, Message, Minus, Money,
	Monitor, MoreFilled, OfficeBuilding, Picture, PictureFilled, Platform, Plus, Rank, Refresh,
	RefreshRight, Right, Search, Setting, ShoppingCart, Sort, SwitchButton, Top, TrendCharts,
	Upload, User, UserFilled, VideoCamera, View, Wallet, Warning, ZoomIn,
}

// @ant-design/icons-vue 由 sIconSelect 内部按需使用，不再全量注册到全局
export default function boot(app) {
	app.component('sTable', sTable)
	app.component('sSelect', sSelect)
	app.component('sSelectTree', sSelectTree)
	app.component('sIconSelect', sIconSelect)
	app.component('sUpload', sUpload)
	app.component('sUploadFile', sUploadFile)

	// 注册实际用到的 Element Plus 图标（ElIcon 前缀）
	for (const [name, component] of Object.entries(ELEMENT_ICONS)) {
		app.component(`ElIcon${name}`, component)
	}

	app.use(pinia)
	app.use(router)
	app.use(i18n)
	app.use(ElementPlus)
	app.use(VxeUI)
	app.use(VxeTable)
}
