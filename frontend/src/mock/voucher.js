/* ============================================================
 * P0-1 财务凭证 · 本地 mock 数据（凭证头 + 分录）
 *
 * 约束：本机禁止登录 laradmin 管理端做验证（会打断订单录入自动化），
 *       故页面一律使用本文件 mock，不调用真实接口。
 *
 * TODO(api)：后端接口按 REST 约定预留（设计文档「数据表建议」），
 *   生成 凭证头+分录 两条表（gl_voucher / gl_voucher_item）：
 *   GET  /api/finance/vouchers                凭证列表（筛选：凭证字/日期/科目/部门/经手人/状态/含红冲 + 分页）
 *   POST /api/finance/vouchers                新增凭证（存草稿 或 保存并审核）
 *   PUT  /api/finance/vouchers/{id}           修改凭证（仅草稿）
 *   DELETE /api/finance/vouchers/{id}         删除凭证（仅草稿）
 *   POST /api/finance/vouchers/{id}/audit     审核凭证
 *   POST /api/finance/vouchers/{id}/reverse   红冲凭证
 *   GET  /api/finance/subjects/tree           科目树（含品牌辅助核算标记）
 *   GET  /api/finance/vouchers/export         凭证导出 Excel
 * ============================================================ */

/* ---------- 基础字典 ---------- */

/* 凭证字：记 / 收 / 付 / 转 */
export const VOUCHER_TYPES = [
	{ value: '记', label: '记账凭证' },
	{ value: '收', label: '收款凭证' },
	{ value: '付', label: '付款凭证' },
	{ value: '转', label: '转账凭证' },
]

/* 部门 */
export const DEPARTMENTS = [
	{ value: 'dept-1', label: '财务部' },
	{ value: 'dept-2', label: '销售部' },
	{ value: 'dept-3', label: '采购部' },
	{ value: 'dept-4', label: '行政部' },
	{ value: 'dept-5', label: '仓储部' },
]

/* 经手人（员工） */
export const EMPLOYEES = [
	{ value: 'emp-1', label: '张伟' },
	{ value: 'emp-2', label: '李娜' },
	{ value: 'emp-3', label: '王强' },
	{ value: 'emp-4', label: '刘敏' },
	{ value: 'emp-5', label: '赵磊' },
	{ value: 'emp-6', label: '陈静' },
	{ value: 'emp-7', label: '周慧' },
	{ value: 'emp-8', label: '吴桐' },
]

/* 品牌（辅助核算） */
export const BRANDS = [
	{ value: 'b-1', label: '华北' },
	{ value: 'b-2', label: '华东' },
	{ value: 'b-3', label: '华南' },
	{ value: 'b-4', label: '华中' },
	{ value: 'b-5', label: '西南' },
	{ value: 'b-6', label: '西北' },
]

/* 客户供应商 */
export const PARTNERS = [
	{ value: 'p-1', label: '华东连锁超市' },
	{ value: 'p-2', label: '好又多卖场' },
	{ value: 'p-3', label: '天天平价' },
	{ value: 'p-4', label: '金鼎便利店' },
	{ value: 'p-5', label: '百姓大药房' },
	{ value: 'p-6', label: '丰乐食品' },
	{ value: 'p-7', label: '宏图商行' },
	{ value: 'p-8', label: '顺发批发部' },
]

/* 凭证状态 */
export const VOUCHER_STATUS = {
	draft: { label: '草稿', tag: 'info' },
	audited: { label: '已审核', tag: 'success' },
	reversed: { label: '已红冲', tag: 'danger' },
}

export const EMP_LABEL = Object.fromEntries(EMPLOYEES.map((e) => [e.value, e.label]))
export const DEPT_LABEL = Object.fromEntries(DEPARTMENTS.map((d) => [d.value, d.label]))
export const BRAND_LABEL = Object.fromEntries(BRANDS.map((b) => [b.value, b.label]))
export const PARTNER_LABEL = Object.fromEntries(PARTNERS.map((p) => [p.value, p.label]))

/* ---------- 科目树（含辅助核算标记） ---------- */

/* 科目节点：
 *   value   科目编码
 *   label   科目名称
 *   hasBrand 是否挂品牌辅助核算（true 时该行分录必须选品牌）
 */
export const SUBJECT_TREE = [
	{
		value: '1',
		label: '资产',
		children: [
			{ value: '1001', label: '库存现金' },
			{ value: '1002', label: '银行存款' },
			{
				value: '1122',
				label: '应收账款',
				children: [
					{ value: '112201', label: '应收账款-客户' },
				],
			},
			{
				value: '1405',
				label: '库存商品',
				children: [
					{ value: '140501', label: '库存商品-品牌', hasBrand: true },
					{ value: '140502', label: '库存商品-一般', hasBrand: true },
				],
			},
			{ value: '1601', label: '固定资产' },
		],
	},
	{
		value: '2',
		label: '负债',
		children: [
			{ value: '2202', label: '应付账款' },
			{ value: '2211', label: '应付职工薪酬' },
			{ value: '2221', label: '应交税费' },
		],
	},
	{
		value: '3',
		label: '所有者权益',
		children: [
			{ value: '4001', label: '实收资本' },
			{ value: '4103', label: '本年利润' },
			{ value: '4104', label: '利润分配' },
		],
	},
	{
		value: '6',
		label: '损益',
		children: [
			{ value: '6001', label: '主营业务收入' },
			{ value: '6401', label: '主营业务成本' },
			{ value: '6601', label: '销售费用' },
			{ value: '6602', label: '管理费用' },
			{ value: '6603', label: '财务费用' },
		],
	},
]

/* 扁平科目列表：value -> 科目节点（供判断辅助核算） */
export const SUBJECT_MAP = (() => {
	const map = {}
	const walk = (nodes) => {
		nodes.forEach((n) => {
			map[n.value] = n
			if (n.children) walk(n.children)
		})
	}
	walk(SUBJECT_TREE)
	return map
})()

/* 科目节点是否挂品牌辅助核算 */
export function subjectHasBrand(code) {
	const n = SUBJECT_MAP[code]
	return !!(n && n.hasBrand)
}

/* ---------- 格式化 ---------- */

export function fmtMoney(v) {
	const n = Number(v || 0)
	const neg = n < 0
	const s = Math.abs(n).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',')
	return (neg ? '-' : '') + s
}

function pad(n, w = 2) {
	return String(n).padStart(w, '0')
}

export function today() {
	const d = new Date()
	return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}

export function daysAgo(n) {
	const d = new Date()
	d.setDate(d.getDate() - n)
	return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}

/* ---------- mock 凭证数据 ---------- */

let _seed = 20261017
function rnd() {
	_seed = (_seed * 1664525 + 1013904223) >>> 0
	return _seed / 4294967296
}
function pick(arr) {
	return arr[Math.floor(rnd() * arr.length)]
}
function randAmount(min, max) {
	return Math.round((min + rnd() * (max - min)) * 100) / 100
}

/* 凭证字序号计数器（V + 年月 + 4 位流水，同一年月内递增） */
function buildVoucherNo(type, year, month, seq) {
	return `V${year}${pad(month, 2)}${pad(seq, 4)}`
}

/**
 * 生成 9 张凭证（含草稿 / 已审核 / 已红冲 三种状态），
 * 红冲单金额取负、摘要带「(红冲)原凭证号」，原凭证状态标为 reversed。
 */
export function mockVouchers() {
	const YEAR = 2026
	const MONTH = 10
	let seq = 1
	const rows = []

	/* 借/贷一对分录，金额相等 */
	const entry = (debit, credit, subject, opts = {}) => {
		const e = {
			subject,
			subject_name: SUBJECT_MAP[subject]?.label || subject,
			brand: opts.brand || '',
			partner: opts.partner || '',
			abstract: opts.abstract || '',
			debit: debit || 0,
			credit: credit || 0,
		}
		if (subjectHasBrand(subject) && !e.brand) e.brand = pick(BRANDS).value
		return e
	}

	const pushVoucher = (type, date, opts = {}) => {
		const status = opts.status || 'audited'
		const items = opts.items
		rows.push({
			id: `v${YEAR}${pad(seq, 2)}_${seq}`,
			no: buildVoucherNo(type, YEAR, MONTH, seq++),
			voucher_type: type,
			date,
			department: opts.department || 'dept-1',
			employee: opts.employee || 'emp-1',
			attachment_count: opts.attachment_count ?? 0,
			auto_audit: !!opts.auto_audit,
			remark: opts.remark || '',
			status,
			reversed_no: opts.reversed_no || '',
			items,
		})
	}

	/* 1 销售收款（已审核） */
	pushVoucher('记', daysAgo(1), {
		status: 'audited',
		department: 'dept-2',
		employee: 'emp-2',
		attachment_count: 3,
		remark: '销售回款',
		items: [
			entry(0, 5860, '1002', { abstract: '收到货款', partner: 'p-1' }),
			entry(5860, 0, '6001', { abstract: '主营业务收入', partner: 'p-1' }),
		],
	})

	/* 2 采购付款（已审核） */
	pushVoucher('付', daysAgo(2), {
		status: 'audited',
		department: 'dept-3',
		employee: 'emp-3',
		attachment_count: 5,
		remark: '采购商品付款',
		items: [
			entry(4800, 0, '140501', { abstract: '采购入库', brand: 'b-1' }),
			entry(0, 4800, '1002', { abstract: '银行付款' }),
		],
	})

	/* 3 费用报销（草稿） */
	pushVoucher('付', daysAgo(4), {
		status: 'draft',
		department: 'dept-4',
		employee: 'emp-4',
		attachment_count: 2,
		remark: '差旅费报销（待审核）',
		items: [
			entry(1250, 0, '6602', { abstract: '差旅费' }),
			entry(0, 1250, '1001', { abstract: '现金支付' }),
		],
	})

	/* 4 结转成本（已审核） */
	pushVoucher('转', daysAgo(5), {
		status: 'audited',
		department: 'dept-5',
		employee: 'emp-1',
		attachment_count: 0,
		remark: '月末结转销售成本',
		items: [
			entry(3900, 0, '6401', { abstract: '结转销售成本' }),
			entry(0, 3900, '140502', { abstract: '库存商品出库', brand: 'b-2' }),
		],
	})

	/* 5 收到投资（已审核） */
	pushVoucher('收', daysAgo(7), {
		status: 'audited',
		department: 'dept-1',
		employee: 'emp-1',
		attachment_count: 1,
		remark: '股东注资',
		items: [
			entry(200000, 0, '1002', { abstract: '收到投资款' }),
			entry(0, 200000, '4001', { abstract: '实收资本' }),
		],
	})

	/* 6 计提工资（已审核） */
	pushVoucher('转', daysAgo(9), {
		status: 'audited',
		department: 'dept-4',
		employee: 'emp-6',
		attachment_count: 0,
		remark: '计提本月工资',
		items: [
			entry(8600, 0, '6602', { abstract: '管理人员工资' }),
			entry(0, 8600, '2211', { abstract: '应付职工薪酬' }),
		],
	})

	/* 7 缴纳增值税（已审核） */
	pushVoucher('付', daysAgo(11), {
		status: 'audited',
		department: 'dept-1',
		employee: 'emp-2',
		attachment_count: 4,
		remark: '缴纳上月增值税',
		items: [
			entry(2200, 0, '2221', { abstract: '应交增值税' }),
			entry(0, 2200, '1002', { abstract: '银行缴税' }),
		],
	})

	/* 8 销售收款（已审核，后被红冲 → 原凭证标 reversed） */
	pushVoucher('记', daysAgo(13), {
		status: 'reversed',
		department: 'dept-2',
		employee: 'emp-5',
		attachment_count: 2,
		remark: '销售回款（已红冲）',
		items: [
			entry(0, 3280, '1002', { abstract: '收到货款', partner: 'p-3' }),
			entry(3280, 0, '6001', { abstract: '主营业务收入', partner: 'p-3' }),
		],
	})

	/* 9 第 8 张的红冲单（金额取反，摘要带「(红冲)原凭证号」） */
	pushVoucher('记', daysAgo(13), {
		status: 'audited',
		department: 'dept-2',
		employee: 'emp-5',
		attachment_count: 0,
		remark: '',
		reversed_no: rows[rows.length - 1].no,
		items: [
			entry(3280, 0, '1002', { abstract: `(红冲)${rows[rows.length - 1].no}`, partner: 'p-3' }),
			entry(0, 3280, '6001', { abstract: `(红冲)${rows[rows.length - 1].no}`, partner: 'p-3' }),
		],
	})

	/* 10 新草稿（可编辑演示） */
	pushVoucher('记', daysAgo(0), {
		status: 'draft',
		department: 'dept-2',
		employee: 'emp-1',
		attachment_count: 0,
		remark: '',
		items: [
			entry(1500, 0, '140501', { abstract: '采购入库', brand: 'b-3' }),
			entry(0, 1500, '2202', { abstract: '应付账款', partner: 'p-8' }),
		],
	})

	return rows
}

/* 科目树 mock（可直接用于 TreeSelect，带 searchable） */
export function mockSubjectTree() {
	return JSON.parse(JSON.stringify(SUBJECT_TREE))
}