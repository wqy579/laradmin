/* 薪酬域共享数值格式化：所有入口先归一为有限数，非有限值（NaN/Infinity/空串/
 * 千分位字符串/带 % 字符串）一律兜底，绝不向模板输出 "NaN"。 */

// 千分位字符串 "1,234" 与百分号字符串 "12.5%" 都要能还原为数值
export const toNum = (v) => {
	if (typeof v === 'number') return Number.isFinite(v) ? v : 0
	const n = Number(String(v ?? '').replace(/[,%\s]/g, ''))
	return Number.isFinite(n) ? n : 0
}

// 空值判定（null / 空串 / NaN 均算空）：模板里需要显示占位符「—」时用
export const isNilNum = (v) => {
	if (typeof v === 'number') return !Number.isFinite(v)
	const s = String(v ?? '').replace(/[,%\s]/g, '')
	return s === '' || !Number.isFinite(Number(s))
}

// 金额：千分位 + 2 位小数
export const fmtMoney = (v) =>
	toNum(v).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

// 比率：固定小数位，非有限值返回同位数的 0（如 '0.0'）
export const fmtRate = (v, digits = 1) => toNum(v).toFixed(digits)

// 负数标红
export const negCls = (v) => (toNum(v) < 0 ? 'text-red' : '')

// 默认期间：上一个自然月（工资按上月核算）
export const lastPeriod = () => {
	const d = new Date()
	d.setMonth(d.getMonth() - 1)
	return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`
}

export const today = () => {
	const d = new Date()
	return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}
