import * as Icons from '@ant-design/icons-vue'

// 导出所有 Ant Design 图标名称（排除非图标导出）
export const allIconNames = Object.keys(Icons).filter((k) => k !== 'default' && k !== 'createFromIconfontCN' && k !== 'getTwoToneColor' && k !== 'setTwoToneColor')
