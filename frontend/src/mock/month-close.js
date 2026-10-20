/**
 * 财务（P0-3 月结查询 / 反月结）· 本地 mock 数据
 *
 * 约束：本机禁止登录 laradmin 管理端做验证（会打断订单录入自动化），
 *       故页面一律使用本文件 mock，不调用真实接口。
 * TODO(api): 后端接口按 REST 预留，就绪后替换：
 *   GET  /api/finance/periods           会计期间关账列表（可按 年/月 筛选）
 *   POST /api/finance/periods/{id}/reverse  反月结（解锁某期间）
 *   GET  /api/finance/periods/export    月结查询导出 Excel
 */

// 会计年 / 月 选项
export const YEARS = [2026, 2025, 2024]
export const MONTHS = Array.from({ length: 12 }, (_, i) => i + 1)

/**
 * 历史关账期间（覆盖近 12 个月：2025-11 ~ 2026-10）。
 * status: closed=已结账 / open=未结账
 * 结账人/结账时间为该期结账快照，仅 closed 期间有值。
 */
export const PERIODS = [
	{ id: 1, year: 2025, month: 11, status: 'closed', status_label: '已结账', voucher_count: 118, total_debit: 8324000.0, total_credit: 8324000.0, closed_by_name: '王会计', closed_at: '2025-12-05 16:32:08' },
	{ id: 2, year: 2025, month: 12, status: 'closed', status_label: '已结账', voucher_count: 152, total_debit: 9468000.0, total_credit: 9468000.0, closed_by_name: '王会计', closed_at: '2026-01-06 17:05:42' },
	{ id: 3, year: 2026, month: 1, status: 'closed', status_label: '已结账', voucher_count: 134, total_debit: 8852000.0, total_credit: 8852000.0, closed_by_name: '王会计', closed_at: '2026-02-04 10:18:36' },
	{ id: 4, year: 2026, month: 2, status: 'closed', status_label: '已结账', voucher_count: 121, total_debit: 7986000.0, total_credit: 7986000.0, closed_by_name: '李会计', closed_at: '2026-03-05 14:47:19' },
	{ id: 5, year: 2026, month: 3, status: 'closed', status_label: '已结账', voucher_count: 146, total_debit: 9134000.0, total_credit: 9134000.0, closed_by_name: '王会计', closed_at: '2026-04-06 11:23:55' },
	{ id: 6, year: 2026, month: 4, status: 'closed', status_label: '已结账', voucher_count: 139, total_debit: 8769000.0, total_credit: 8769000.0, closed_by_name: '李会计', closed_at: '2026-05-07 15:12:03' },
	{ id: 7, year: 2026, month: 5, status: 'closed', status_label: '已结账', voucher_count: 127, total_debit: 8543000.0, total_credit: 8543000.0, closed_by_name: '王会计', closed_at: '2026-06-04 16:29:41' },
	{ id: 8, year: 2026, month: 6, status: 'closed', status_label: '已结账', voucher_count: 143, total_debit: 9072000.0, total_credit: 9072000.0, closed_by_name: '李会计', closed_at: '2026-07-06 09:58:27' },
	{ id: 9, year: 2026, month: 7, status: 'closed', status_label: '已结账', voucher_count: 138, total_debit: 8895000.0, total_credit: 8895000.0, closed_by_name: '王会计', closed_at: '2026-08-05 13:36:14' },
	{ id: 10, year: 2026, month: 8, status: 'closed', status_label: '已结账', voucher_count: 126, total_debit: 8642000.0, total_credit: 8642000.0, closed_by_name: '王会计', closed_at: '2026-09-02 10:24:31' },
	{ id: 11, year: 2026, month: 9, status: 'closed', status_label: '已结账', voucher_count: 143, total_debit: 9183500.5, total_credit: 9183500.5, closed_by_name: '王会计', closed_at: '2026-10-08 09:51:07' },
	{ id: 12, year: 2026, month: 10, status: 'open', status_label: '未结账', voucher_count: 98, total_debit: 6231400.5, total_credit: 6231400.5, closed_by_name: '', closed_at: '' },
]

export function periodKey(year, month) {
	return `${year}-${String(month).padStart(2, '0')}`
}