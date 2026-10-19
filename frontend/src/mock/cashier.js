/* ============================================================
 * 出纳资金单族（P0-8）· 本地 mock 状态仓库（模块级单例）
 *
 * 存取款/借款还款/应收应付调整/资金增减/欠条 共用一个模板，
 * 所有资金单保存即写 cash_flow，并实时刷新资金账户余额。
 * 取款/借出/支出 若超过账户余额则报「账户余额不足」并阻止。
 *
 * TODO(api)：接入后端后整体替换为 API 调用（见 views/cashier/shared.js 顶部路径约定）。
 * ============================================================ */

import { reactive } from 'vue'
import { mockAccounts, mockFlows, docNo } from '../views/cashier/shared'

const state = reactive({
	accounts: mockAccounts(),
	flows: mockFlows(),
	deposits: [], // 存取款单 CQ
	loans: [], // 借款/还款单 JK/HK
	adjusts: [], // 应收应付调整单 TZ
	fundInOut: [], // 资金增减单 ZJ
	ious: [], // 欠条 QT
})

/* 各单据自增序号（按天，简化用全局递增，日期当天可复现） */
const seq = { deposit: 0, loan: 0, repay: 0, adjust: 0, fund: 0, iou: 0 }

/* 每个单据类型从当日 0001 起编号 */
function nextSeq(key) {
	seq[key] += 1
	return seq[key]
}

/* ---------- 账户 ---------- */

export function listAccounts() {
	return state.accounts
}

export function accountBalance(accountId) {
	const a = state.accounts.find((x) => x.id === accountId)
	return a ? Number(a.balance) || 0 : 0
}

function applyBalance(accountId, delta) {
	const a = state.accounts.find((x) => x.id === accountId)
	if (a) a.balance = Math.round((Number(a.balance) + delta) * 100) / 100
}

/* 往资金流水写一条记录（所有资金单保存共用） */
function writeFlow({ no, date, docType, account, direction, amount, counterpart, handler, remark, status }) {
	const flow = {
		id: Date.now() + Math.floor(Math.random() * 1000),
		no,
		date,
		docType,
		account,
		direction, // in 收入 / out 支出
		amount: Number(amount),
		counterpart,
		handler,
		remark,
		status: status || 'audited',
	}
	state.flows.unshift(flow)
	return flow
}

/* ---------- 存取款单 D-17 ---------- */

export function listDeposits() {
	return state.deposits
}

/**
 * 保存存取款单
 * @param {object} form 单据头字段 + depositType(存款/取款/互转) + fee(手续费)
 */
export function addDeposit(form) {
	const accountId = form.account
	const balance = accountBalance(accountId)
	const amount = Number(form.amount) || 0
	const fee = Number(form.fee) || 0

	// 取款：本金 + 手续费合计不能超过余额
	if (form.depositType === 'withdraw' && amount + fee > balance) {
		return { ok: false, error: '账户余额不足' }
	}
	// 互转：源账户扣款，目标账户入账；手续费从源账户出
	if (form.depositType === 'transfer') {
		if (amount + fee > balance) {
			return { ok: false, error: '账户余额不足' }
		}
		applyBalance(accountId, -(amount + fee))
		applyBalance(form.to_account, amount)
		writeFlow({ no: form.no, date: form.date, docType: 'deposit', account: accountId, direction: 'out', amount: amount + fee, counterpart: form.counterpart, handler: form.handler, remark: `${form.remark}（互转至${form.to_account_name}）`, status: 'audited' })
	} else {
		const delta = form.depositType === 'deposit' ? amount - fee : -(amount + fee)
		applyBalance(accountId, delta)
		writeFlow({ no: form.no, date: form.date, docType: 'deposit', account: accountId, direction: form.depositType === 'deposit' ? 'in' : 'out', amount, counterpart: form.counterpart, handler: form.handler, remark: form.remark, status: 'audited' })
	}

	const doc = { ...form, account_name: accountLabel(accountId), status: 'audited', create_at: new Date().toISOString() }
	state.deposits.unshift(doc)
	return { ok: true, doc }
}

/* ---------- 借款/还款单 D-19 ---------- */

export function listLoans() {
	return state.loans
}

/**
 * 保存借款单（JK）
 * 借出 = 支出；预计归还日 + 是否生息 + 利率
 */
export function addLoan(form) {
	const accountId = form.account
	const amount = Number(form.amount) || 0
	const balance = accountBalance(accountId)
	if (amount > balance) {
		return { ok: false, error: '账户余额不足' }
	}
	applyBalance(accountId, -amount)
	writeFlow({ no: form.no, date: form.date, docType: 'loan', account: accountId, direction: 'out', amount, counterpart: form.counterpart, handler: form.handler, remark: form.remark, status: 'audited' })
	const doc = { ...form, kind: 'loan', account_name: accountLabel(accountId), status: 'audited', create_at: new Date().toISOString() }
	state.loans.unshift(doc)
	return { ok: true, doc }
}

/**
 * 保存还款单（HK）
 * 关联借款单 + 利息金额；归还 = 收入
 */
export function addRepay(form) {
	const accountId = form.account
	const amount = Number(form.amount) || 0
	const interest = Number(form.interest) || 0
	applyBalance(accountId, amount)
	// 归还本金入账，利息单列（可视为费用，简化并入流水金额显示）
	writeFlow({ no: form.no, date: form.date, docType: 'repay', account: accountId, direction: 'in', amount: amount + interest, counterpart: form.counterpart, handler: form.handler, remark: `${form.remark}${interest ? '（含利息' + interest + '）' : ''}`, status: 'audited' })
	// 关联的借款单标记为已还（若为归还全部剩余）
	if (form.loanId) {
		const loan = state.loans.find((l) => l.no === form.loanId)
		if (loan) loan.repaid = true
	}
	const doc = { ...form, kind: 'repay', account_name: accountLabel(accountId), status: 'audited', create_at: new Date().toISOString() }
	state.loans.unshift(doc)
	return { ok: true, doc }
}

/* ---------- 应收应付调整单 D-18 ---------- */

export function listAdjusts() {
	return state.adjusts
}

/**
 * 保存应收应付调整单（TZ）
 * 调整类型：ar-inc 应收增 / ar-dec 应收减 / ap-inc 应付增 / ap-dec 应付减
 * 同步客户/供应商余额 + 经营历程留痕
 */
export function addAdjust(form) {
	const accountId = form.account
	const amount = Number(form.amount) || 0
	const balance = accountBalance(accountId)
	// 应收减 / 应付减 视作支出，需校验余额
	if ((form.adjustType === 'ar-dec' || form.adjustType === 'ap-dec') && amount > balance) {
		return { ok: false, error: '账户余额不足' }
	}
	const direction = form.adjustType === 'ar-dec' || form.adjustType === 'ap-dec' ? 'out' : 'in'
	const delta = direction === 'in' ? amount : -amount
	applyBalance(accountId, delta)
	writeFlow({ no: form.no, date: form.date, docType: 'adjust', account: accountId, direction, amount, counterpart: form.counterpart, handler: form.handler, remark: `${form.adjustTypeLabel}：${form.remark}`, status: 'audited' })
	const doc = { ...form, account_name: accountLabel(accountId), status: 'audited', create_at: new Date().toISOString() }
	state.adjusts.unshift(doc)
	return { ok: true, doc }
}

/* ---------- 资金增减单 D-22/23 ---------- */

export function listFundInOut() {
	return state.fundInOut
}

/**
 * 保存资金增减单（ZJ）
 * fundType: in 资金增加(收入) / out 资金减少(支出)
 */
export function addFundInOut(form) {
	const accountId = form.account
	const amount = Number(form.amount) || 0
	const balance = accountBalance(accountId)
	if (form.fundType === 'out' && amount > balance) {
		return { ok: false, error: '账户余额不足' }
	}
	const delta = form.fundType === 'in' ? amount : -amount
	applyBalance(accountId, delta)
	writeFlow({ no: form.no, date: form.date, docType: form.fundType === 'in' ? 'fund-in' : 'fund-out', account: accountId, direction: form.fundType === 'in' ? 'in' : 'out', amount, counterpart: form.counterpart, handler: form.handler, remark: form.remark, status: 'audited' })
	const doc = { ...form, account_name: accountLabel(accountId), status: 'audited', create_at: new Date().toISOString() }
	state.fundInOut.unshift(doc)
	return { ok: true, doc }
}

/* ---------- 欠条确认 D-24 ---------- */

export function listIous() {
	return state.ious
}

/**
 * 欠条确认/驳回（QT）
 * 确认 → 确认应收 + 客户余额；驳回 → 不产生流水
 */
export function settleIou(form, action) {
	if (action === 'confirm') {
		writeFlow({ no: form.no, date: form.date, docType: 'iou', account: form.account, direction: 'in', amount: Number(form.amount) || 0, counterpart: form.counterpart, handler: form.handler, remark: form.remark, status: 'audited' })
		const doc = { ...form, account_name: accountLabel(form.account), status: 'audited', confirm_time: new Date().toISOString() }
		state.ious.unshift(doc)
		return { ok: true, doc }
	}
	// 驳回
	const doc = { ...form, status: 'rejected', reject_time: new Date().toISOString() }
	state.ious.unshift(doc)
	return { ok: true, doc }
}

/* ---------- 内部工具 ---------- */

function accountLabel(id) {
	const a = state.accounts.find((x) => x.id === id)
	return a ? a.name : String(id)
}

/* 生成指定前缀、当日日期的新单号 */
export function genDocNo(prefix, date) {
	return docNo(prefix, date || undefined, nextSeq(depPrefixKey(prefix)))
}

function depPrefixKey(prefix) {
	return { CQ: 'deposit', JK: 'loan', HK: 'repay', TZ: 'adjust', ZJ: 'fund', QT: 'iou' }[prefix] || 'deposit'
}