/**
 * 报表导出 / 打印的公共动作。
 *
 * 后端导出接口直接返回带 BOM 的 CSV 流（responseType: 'blob'），
 * 这里只负责把它落成文件——不要把 Blob 再当 JSON 解一次，否则拿到的是乱码字符串。
 */

/** 把 Blob 或文本落成本地文件 */
export function saveBlob(content, filename) {
	const blob = content instanceof Blob ? content : new Blob([content], { type: 'text/csv;charset=utf-8' })
	const url = URL.createObjectURL(blob)
	const a = document.createElement('a')
	a.href = url
	a.download = filename
	a.style.display = 'none'
	document.body.appendChild(a)
	a.click()
	document.body.removeChild(a)
	// 立刻 revoke 在部分浏览器会赶在下载开始前把链接掐掉，留一帧余量
	setTimeout(() => URL.revokeObjectURL(url), 1000)
}

/**
 * 打印当前表格：把列定义与数据拼成一张独立的表格塞进隐藏 iframe 打印。
 * 直接 window.print() 会把整页（含查询区、侧边栏）一起打出来，
 * 报表场景用户要的只有表格本身。
 */
export function printTable(columns, rows, title = '报表') {
	const head = columns.map((c) => `<th>${escapeHtml(c.label)}</th>`).join('')
	const body = rows
		.map((row) => `<tr>${columns.map((c) => `<td class="${isNumeric(c) ? 'num' : ''}">${escapeHtml(formatCell(c, row))}</td>`).join('')}</tr>`)
		.join('')

	const html = `<!DOCTYPE html><html><head><meta charset="utf-8"><title>${escapeHtml(title)}</title>
<style>
	body { font-family: -apple-system, "Microsoft YaHei", sans-serif; font-size: 12px; color: #303133; }
	h2 { font-size: 16px; font-weight: 700; margin: 0 0 12px; }
	table { border-collapse: collapse; width: 100%; }
	th { background: #f5f7fa; font-size: 13px; font-weight: 700; color: #909399; height: 32px; border: 1px solid #dcdfe6; padding: 0 6px; }
	td { height: 28px; border: 1px solid #e4e7ed; padding: 0 6px; }
	td.num { text-align: right; font-variant-numeric: tabular-nums; }
	@media print { @page { size: A4 landscape; margin: 10mm; } }
</style></head><body>
<h2>${escapeHtml(title)}</h2>
<table><thead><tr>${head}</tr></thead><tbody>${body}</tbody></table>
</body></html>`

	const iframe = document.createElement('iframe')
	iframe.style.position = 'fixed'
	iframe.style.right = '0'
	iframe.style.bottom = '0'
	iframe.style.width = '0'
	iframe.style.height = '0'
	iframe.style.border = '0'
	document.body.appendChild(iframe)
	const doc = iframe.contentWindow.document
	doc.open()
	doc.write(html)
	doc.close()
	// 等 iframe 内资源（字体、样式）就绪再调 print，否则 Chrome 可能打出空白页
	iframe.contentWindow.focus()
	setTimeout(() => {
		iframe.contentWindow.print()
		document.body.removeChild(iframe)
	}, 300)
}

function isNumeric(col) {
	return ['money', 'number', 'int', 'percent', 'rate'].includes(col.type)
}
function formatCell(col, row) {
	const raw = row?.[col.prop]
	if (raw === null || raw === undefined) return ''
	if (col.type === 'money') return '¥' + Number(raw || 0).toFixed(2)
	if (col.type === 'number') return Number(raw || 0).toFixed(2)
	if (col.type === 'int') return String(Math.round(Number(raw || 0)))
	if (col.type === 'percent' || col.type === 'rate') return Number(raw || 0).toFixed(2) + '%'
	if (col.type === 'tag') return col.tag_map?.[raw] ?? raw
	return String(raw)
}
function escapeHtml(s) {
	return String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c])
}
