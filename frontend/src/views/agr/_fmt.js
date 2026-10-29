/* 协议域共享数值格式化：所有入口先归一为有限数，非有限值（NaN/Infinity/空串/
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
export const fmtRate = (v, digits = 2) => toNum(v).toFixed(digits)
