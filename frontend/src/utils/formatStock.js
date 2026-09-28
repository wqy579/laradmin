/**
 * 库存按小单位存，逐级整除成 大/中/小 单位展示，与销售单 formatStock 一致。
 * cn=大→小(如 480)、mcn=中→小(如 120)。
 * 0 或负库存直接显示最小单位数字（负库存少见，不逐级拆）。
 */
export const formatStock = (totalSmall, c, mc, unitLarge, unitMedium, unitSmall) => {
	const num = Math.floor(Number(totalSmall) || 0)
	if (num <= 0) return String(num)
	const cn = Number(c) || 0
	const mcn = Number(mc) || 0
	const ul = unitLarge || '件'
	const um = unitMedium || '盒'
	const us = unitSmall || '袋'
	const parts = []
	if (cn > 0 && mcn > 0) {
		const large = Math.floor(num / cn)
		const remainder = num % cn
		const medium = Math.floor(remainder / mcn)
		const small = remainder % mcn
		if (large > 0) parts.push(`${large}${ul}`)
		if (medium > 0) parts.push(`${medium}${um}`)
		if (small > 0) parts.push(`${small}${us}`)
	} else if (cn > 0) {
		const large = Math.floor(num / cn)
		if (large > 0) parts.push(`${large}${ul}`)
		if (num % cn > 0) parts.push(`${num % cn}${us}`)
	} else if (mcn > 0) {
		const medium = Math.floor(num / mcn)
		if (medium > 0) parts.push(`${medium}${um}`)
		if (num % mcn > 0) parts.push(`${num % mcn}${us}`)
	} else {
		parts.push(`${num}${us}`)
	}
	return parts.join(' ') || String(num)
}
