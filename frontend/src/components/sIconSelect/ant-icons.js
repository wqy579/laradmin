import * as Icons from '@ant-design/icons-vue'

// @ant-design/icons-vue 除图标组件外还导出这几个工具，不能注册成组件
const NON_ICON_EXPORTS = ['default', 'createFromIconfontCN', 'getTwoToneColor', 'setTwoToneColor']

// 全部图标组件：boot.js 用它做 AIcon 前缀的全量全局注册。
// 理由同 element-icons.js：菜单图标是运行时才从数据库拿到的名字。
export const allIcons = Object.fromEntries(
	Object.entries(Icons).filter(([name]) => !NON_ICON_EXPORTS.includes(name)),
)

// 全部图标名称：sIconSelect 选择器用它渲染可选项
export const allIconNames = Object.keys(allIcons)
