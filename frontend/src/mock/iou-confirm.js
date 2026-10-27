/* ============================================================
 * D-24 欠条确认/驳回 + 签字图 · 本地 mock 数据
 *
 * 说明：
 *   - 纯前端 mock：签字图上传用 URL.createObjectURL 本地预览，
 *     不发起任何上传请求，保存时仅存文件名与预览地址。
 *   - 列表 mock 中签字图为内联 SVG data URI（离线可预览）。
 *
 * TODO(api)：后端接口按 REST 约定预留：
 *   GET  /api/finance/ious                  欠条列表（筛选：欠款人/状态/日期范围 + 分页）
 *   POST /api/finance/ious                  新增欠条
 *   GET  /api/finance/ious/{id}             欠条详情
 *   POST /api/finance/ious/{id}/confirm     确认欠条
 *   POST /api/finance/ious/{id}/reject      驳回欠条（body 携带驳回原因）
 *   POST /api/finance/ious/{id}/signature   上传签字图（multipart）
 * ============================================================ */

import { PARTNERS, PARTNER_LABEL, fmtMoney, today } from './borrow-repay'

export { PARTNERS, PARTNER_LABEL, fmtMoney, today }

/* 状态字典 */
export const IOU_STATUS = {
	pending: { label: '待确认', tag: 'warning' },
	confirmed: { label: '已确认', tag: 'success' },
	rejected: { label: '已驳回', tag: 'danger' },
}

export const IOU_STATUS_LABEL = Object.fromEntries(Object.entries(IOU_STATUS).map(([k, v]) => [k, v.label]))

function pad(n, w = 2) {
	return String(n).padStart(w, '0')
}

/* 相对今天偏移 n 天（负数 = 过去） */
function offsetDays(n) {
	const d = new Date()
	d.setDate(d.getDate() + n)
	return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}

/* 欠条编号：IOU + 年月日 + 3 位流水 */
function buildIouNo(dateStr, seq) {
	const ymd = dateStr.replace(/-/g, '')
	return `IOU${ymd}${pad(seq, 3)}`
}

/* 新增欠条时生成编号（mock 内流水自增） */
export function genIouNo(dateStr) {
	const seq = (genIouNo.__seq = ((genIouNo.__seq || 900) + 1) % 1000)
	return buildIouNo(dateStr, seq)
}

/* mock 签字图：内联 SVG data URI（模拟手写签名），离线可预览 */
function mockSignature(name) {
	const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="240" height="120"><rect width="240" height="120" fill="#fff"/><path d="M20 80 Q 40 30 60 70 T 100 70 T 140 60 Q 160 40 180 70 T 220 55" stroke="#303133" stroke-width="2" fill="none"/><text x="20" y="110" font-size="12" fill="#909399">${name} 签名（mock）</text></svg>`
	return `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`
}

/**
 * 生成欠条列表（含 待确认/已确认/已驳回 三种状态）。
 */
export function mockIous() {
	const rows = [
		{
			id: 'iou-1',
			partner: 'p-1',
			amount: 15000,
			date: offsetDays(-12),
			due_date: offsetDays(18),
			status: 'pending',
			signature_name: '张建国',
			abstract: '旺季备货货款欠款',
			creator: '陈静',
		},
		{
			id: 'iou-2',
			partner: 'p-2',
			amount: 8000,
			date: offsetDays(-9),
			due_date: offsetDays(21),
			status: 'pending',
			signature_name: '李秀兰',
			abstract: '新店开业垫款欠条',
			creator: '李娜',
		},
		{
			id: 'iou-3',
			partner: 'p-3',
			amount: 26000,
			date: offsetDays(-20),
			due_date: offsetDays(-2),
			status: 'confirmed',
			signature_name: '王德发',
			abstract: '节令商品货款确认',
			creator: '王强',
		},
		{
			id: 'iou-4',
			partner: 'p-4',
			amount: 4500,
			date: offsetDays(-15),
			due_date: offsetDays(15),
			status: 'confirmed',
			signature_name: '赵小军',
			abstract: '设备租赁欠款',
			creator: '刘敏',
		},
		{
			id: 'iou-5',
			partner: 'p-5',
			amount: 3200,
			date: offsetDays(-6),
			due_date: offsetDays(24),
			status: 'rejected',
			signature_name: '周淑芬',
			reject_reason: '金额与对账单不符，请核实后重新立据',
			abstract: '药品货款短期欠款',
			creator: '陈静',
		},
		{
			id: 'iou-6',
			partner: 'emp-1',
			amount: 5000,
			date: today(),
			due_date: offsetDays(30),
			status: 'pending',
			signature_name: '张伟',
			abstract: '员工出差备用金欠条',
			creator: '张伟',
		},
	]

	rows.forEach((r, i) => {
		r.no = buildIouNo(r.date, i + 1)
		r.signature_file = `${r.no}_signature.png`
		r.signature_url = mockSignature(r.signature_name)
		r.confirmed_at = r.status === 'pending' ? '' : r.date
	})
	return rows
}
