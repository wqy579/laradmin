import * as Icons from '@element-plus/icons-vue'

// 全部图标组件：boot.js 用它做 ElIcon 前缀的全量全局注册。
// 菜单图标存在 auth_permission.meta.icon 里，运行时才拿到具体名字，
// 注册集合必须是全量——只覆盖模板里静态引用到的那部分会漏项。
// @element-plus/icons-vue 的全部 293 个导出都是图标组件，无 install 等杂质，可直接用。
export const allIcons = Icons

// 全部图标名称：sIconSelect 选择器用它渲染可选项
export const allIconNames = Object.keys(Icons)
