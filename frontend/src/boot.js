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
import './assets/styles/report.css'

// 同步 VxeTable 主题与系统暗色模式
function syncVxeTheme() {
	VxeUI.setTheme(document.documentElement.classList.contains('dark') ? 'dark' : 'light')
}
syncVxeTheme()

const observer = new MutationObserver(() => syncVxeTheme())
observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] })

// 图标全量注册，不做"只注册模板里用到的"白名单。三个理由：
// ① 菜单图标存在 auth_permission.meta.icon 里，运行时才拿到名字。白名单靠静态扫模板
//    得出，永远列不全；漏一个，菜单渲染成空 <el-icon>，且不报任何错。首页图标
//    （ElIconHomeFilled）就是这么丢的。
// ② sIconSelect 给出全量名称列表（Element 293 + Ant 790），用户选中的名字原样写进
//    数据库。注册集合必须 ⊇ 可选项集合，否则"选了就看不见"。
// ③ 体积上白名单其实没省：sIconSelect 为了取名称列表本来就已 import * 全量引入整库
//    （见 sIconSelect/index.vue 对 ./element-icons、./ant-icons 的引用），
//    注册多少不影响打进包里多少。
import { allIcons as elementIcons } from './components/sIconSelect/element-icons'
import { allIcons as antIcons } from './components/sIconSelect/ant-icons'

// ElIcon* 对应 Element Plus，AIcon* 对应 Ant Design，前缀约定与 sIconSelect 拼
// fullName 的规则一致，数据库里存的字符串可直接用于 <component :is="...">。
export default function boot(app) {
	app.component('sTable', sTable)
	app.component('sSelect', sSelect)
	app.component('sSelectTree', sSelectTree)
	app.component('sIconSelect', sIconSelect)
	app.component('sUpload', sUpload)
	app.component('sUploadFile', sUploadFile)

	for (const [name, component] of Object.entries(elementIcons)) {
		app.component(`ElIcon${name}`, component)
	}
	for (const [name, component] of Object.entries(antIcons)) {
		app.component(`AIcon${name}`, component)
	}

	app.use(pinia)
	app.use(router)
	app.use(i18n)
	app.use(ElementPlus)
	app.use(VxeUI)
	app.use(VxeTable)
}
