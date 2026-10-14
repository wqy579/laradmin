<template>
	<div class="perf-list">
		<!-- 预警概览 -->
		<div class="overview-cards" v-if="overview">
			<div class="ov-card" :class="{ red: overview.safety_stock > 0 }">
				<div class="ov-num">{{ overview.safety_stock }}</div><div class="ov-label">安全库存预警</div>
			</div>
			<div class="ov-card" :class="{ red: overview.expiry > 0 }">
				<div class="ov-num">{{ overview.expiry }}</div><div class="ov-label">临期/超期</div>
			</div>
			<div class="ov-card" :class="{ red: overview.low_sellable > 0 }">
				<div class="ov-num">{{ overview.low_sellable }}</div><div class="ov-label">可销天数低</div>
			</div>
			<div class="ov-card" :class="{ red: overview.exception > 0 }">
				<div class="ov-num">{{ overview.exception }}</div><div class="ov-label">库存异常</div>
			</div>
			<div class="ov-card blue">
				<div class="ov-num">{{ overview.turnover_days }}</div><div class="ov-label">库存周转(天)</div>
			</div>
		</div>

		<el-tabs v-model="activeTab" @tab-change="refresh">
			<el-tab-pane label="安全库存预警" name="safety-stock">
				<el-table :data="pagedList" border stripe v-loading="loading" height="100%" empty-text="当前筛选条件下暂无数据" @selection-change="onSelectionChange">
					<el-table-column type="selection" width="45" />
					<el-table-column prop="product_code" label="商品编码" width="120" />
					<el-table-column prop="product_name" label="商品名称" min-width="180" />
					<el-table-column prop="brand_name" label="品牌" width="100" />
					<el-table-column prop="category_name" label="分类" width="100" />
					<el-table-column prop="spec" label="规格" width="100" />
					<el-table-column prop="unit" label="单位" width="70" align="center" />
					<el-table-column prop="warehouse_name" label="仓库" width="100" />
					<el-table-column prop="current_stock" label="当前库存" width="100" align="right">
						<template #default="{ row }"><span :class="Number(row.current_stock) < Number(row.safety_stock) ? 'text-red' : ''">{{ row.current_stock }}</span></template>
					</el-table-column>
					<el-table-column prop="safety_stock" label="安全库存" width="100" align="right" />
					<el-table-column prop="suggest_replenish" label="建议补货" width="100" align="right">
						<template #default="{ row }"><b>{{ suggestQty(row) }}</b></template>
					</el-table-column>
					<el-table-column prop="daily_7" label="近7天销量" width="100" align="right" />
					<el-table-column prop="daily_30" label="日均销量" width="90" align="right" />
					<el-table-column prop="sellable_days" label="可销天数" width="100" align="right">
						<template #default="{ row }">{{ row.sellable_days > 99999 ? '充足' : row.sellable_days }}</template>
					</el-table-column>
				</el-table>
			</el-tab-pane>

			<el-tab-pane label="临期/超期预警" name="expiry">
				<el-alert v-if="hint" :title="hint" type="warning" :closable="false" style="margin-bottom: 8px" />
				<el-table :data="pagedList" border stripe v-loading="loading" height="100%" empty-text="当前筛选条件下暂无数据">
					<el-table-column prop="product_code" label="商品编码" width="120" />
					<el-table-column prop="product_name" label="商品名称" min-width="180" />
					<el-table-column prop="spec" label="规格" width="100" />
					<el-table-column prop="batch" label="批次" width="100" />
					<el-table-column label="生产日期" width="110" align="center">
						<template #default="{ row }">{{ row.production_date || row.batch_date || '-' }}</template>
					</el-table-column>
					<el-table-column prop="shelf_life_days" label="保质期(天)" width="100" align="center" />
					<el-table-column prop="expiry_date" label="到期日期" width="120" align="center" />
					<el-table-column prop="remaining_days" label="剩余天数" width="100" align="center">
						<template #default="{ row }">
							<span :style="{ color: expiryDaysColor(row.remaining_days), fontWeight: 'bold' }">{{ row.remaining_days }}</span>
						</template>
					</el-table-column>
					<el-table-column label="库存数量" width="100" align="right">
						<template #default="{ row }">{{ row.quantity ?? row.stock_qty ?? row.current_stock ?? '-' }}</template>
					</el-table-column>
					<el-table-column label="状态" width="90" align="center">
						<template #default="{ row }">
							<el-tag :type="expiryType(row.status)" size="small">{{ expiryLabel(row.status) }}</el-tag>
						</template>
					</el-table-column>
				</el-table>
			</el-tab-pane>

			<el-tab-pane label="库存可销天数" name="sellable-days">
				<el-table :data="pagedList" border stripe v-loading="loading" height="100%" empty-text="当前筛选条件下暂无数据">
					<el-table-column prop="product_code" label="商品编码" width="120" />
					<el-table-column prop="product_name" label="商品名称" min-width="180" />
					<el-table-column prop="spec" label="规格" width="100" />
					<el-table-column prop="current_stock" label="当前库存" width="110" align="right" />
					<el-table-column prop="daily_30" label="日均销量" width="110" align="right" />
					<el-table-column prop="sellable_days" label="可销天数" width="110" align="right">
						<template #default="{ row }">
							<span :style="{ color: sellableColor(row) }">{{ sellableText(row) }}</span>
						</template>
					</el-table-column>
					<el-table-column prop="status" label="标记" width="100" align="center">
						<template #default="{ row }">
							<el-tag :type="sellableSeg(row).type" size="small">{{ sellableSeg(row).label }}</el-tag>
						</template>
					</el-table-column>
					<el-table-column prop="action" label="建议动作" width="100" align="center" />
				</el-table>
			</el-tab-pane>

			<el-tab-pane label="库存异常" name="stock-exception">
				<el-table :data="pagedList" border stripe v-loading="loading" height="100%" empty-text="当前筛选条件下暂无数据">
					<el-table-column prop="product_code" label="商品编码" width="120" />
					<el-table-column prop="product_name" label="商品名称" min-width="180" />
					<el-table-column prop="spec" label="规格" width="100" />
					<el-table-column prop="warehouse_name" label="仓库" width="120" />
					<el-table-column prop="current_stock" label="当前库存" width="120" align="right">
						<template #default="{ row }"><span class="text-red">{{ row.current_stock }}</span></template>
					</el-table-column>
					<el-table-column prop="issue" label="问题" width="120" align="center">
						<template #default="{ row }">
							<span :style="{ color: isLossIssue(row) ? '#E6A23E' : '#F56C6C', fontWeight: 600 }">{{ issueText(row) }}</span>
						</template>
					</el-table-column>
					<el-table-column label="操作" width="120" align="center" fixed="right">
						<template #default>
							<el-button type="primary" link size="small" @click="$router.push('/business/stock-check')">去处理</el-button>
						</template>
					</el-table-column>
				</el-table>
			</el-tab-pane>
		</el-tabs>

		<div class="perf-toolbar">
			<span class="pager-total">共 {{ total }} 条</span>
			<el-pagination small layout="prev, pager, next, jumper" :page-size="30" :total="total" :current-page="page" @current-change="onPage" />
			<el-button type="primary" size="small" :disabled="activeTab !== 'safety-stock' || !selection.length" @click="onPurchase">生成采购申请</el-button>
			<el-button size="small" @click="exportData">导出</el-button>
		</div>
	</div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { useRouter } from 'vue-router'
import perfApi from '@/api/perf'
import { saveBlob } from '@/utils/fileDownload'

const router = useRouter()
const activeTab = ref('safety-stock')
const list = ref([])
const loading = ref(false)
const overview = ref(null)
const hint = ref('')
const page = ref(1)
const total = ref(0)
const selection = ref([])
const PAGE_SIZE = 30

const pagedList = computed(() => {
	const start = (page.value - 1) * PAGE_SIZE
	return list.value.slice(start, start + PAGE_SIZE)
})

const expiryLabel = (s) => ({ normal: '正常', near_expiry: '临期', expired: '过期' }[s] || s)
const expiryType = (s) => ({ normal: 'success', near_expiry: 'warning', expired: 'danger' }[s] || 'info')
// 已过期红、≤30 天橙、其余绿
const expiryDaysColor = (d) => (d <= 0 ? '#F56C6C' : d <= 30 ? '#E6A23E' : '#67C23A')
// 建议补货 = 安全库存×2 − 当前库存，min 0
const suggestQty = (r) => Math.max(0, Number(r.safety_stock || 0) * 2 - Number(r.current_stock || 0))
const sellableSeg = (r) => {
	const days = Number(r.sellable_days)
	const st = r.status
	if (st === 'short' || (!st && days <= 7)) return { label: '缺货风险', type: 'danger' }
	if (st === 'low' || (!st && days <= 15)) return { label: '及时补货', type: 'warning' }
	if (st === 'slow' || (!st && days > 90)) return { label: '滞销', type: 'info' }
	return { label: '正常', type: 'success' }
}
const sellableColor = (r) => {
	const s = sellableSeg(r).label
	return s === '缺货风险' ? '#F56C6C' : s === '及时补货' ? '#E6A23E' : s === '滞销' ? '#909399' : '#67C23A'
}
const sellableText = (r) => (r.sellable_days > 99999 ? '充足' : `${r.sellable_days}天`)
// 盘亏类问题橙色，其余（负库存等）红色
const isLossIssue = (r) => {
	const i = String(r.issue || '')
	return i.includes('loss') || i.includes('shortage') || i.includes('盘亏')
}
const issueText = (r) => ({
	negative_stock: '负库存', stock_loss: '盘亏', loss: '盘亏', shortage: '盘亏',
})[r.issue] || (isLossIssue(r) ? '盘亏' : (r.issue ? String(r.issue) : '负库存'))

const loadOverview = async () => {
	const res = await perfApi.warning.overview.get()
	if (res.code === 200) overview.value = res.data
}

const refresh = async () => {
	loading.value = true
	hint.value = ''
	page.value = 1
	selection.value = []
	try {
		const api = {
			'safety-stock': perfApi.warning.safetyStock,
			expiry: perfApi.warning.expiry,
			'sellable-days': perfApi.warning.sellableDays,
			'stock-exception': perfApi.warning.stockException,
		}[activeTab.value]
		const res = await api.get({})
		if (res.code === 200) {
			list.value = res.data?.list || []
			total.value = res.data?.total ?? list.value.length
			hint.value = res.data?.hint || ''
		}
	} finally { loading.value = false }
}

const onPage = (p) => { page.value = p }
const onSelectionChange = (rows) => { selection.value = rows }

// 勾选多行 → 批量生成采购申请草稿
const onPurchase = () => {
	const items = selection.value.map((r) => ({ product_id: r.product_id, quantity: suggestQty(r) }))
	ElMessageBox.confirm(`将为选中的 ${items.length} 个商品生成采购申请草稿，是否继续？`, '操作确认', {
		confirmButtonText: '确定', cancelButtonText: '取消', type: 'warning',
	}).then(async () => {
		try {
			const res = await perfApi.warning.purchaseDraft.post({ items })
			if (res.code !== 200) return ElMessage.error(res.message || '生成失败')
			const nos = res.data?.apply_nos
			if (nos) ElMessage.success(`已生成采购申请：${Array.isArray(nos) ? nos.join('、') : nos}`)
			if (res.data?.redirect) {
				const q = new URLSearchParams()
				const first = items[0] || {}
				const pid = res.data.product_id || first.product_id
				const qty = res.data.quantity ?? res.data.qty ?? first.quantity
				if (pid) q.set('product_id', pid)
				if (qty) q.set('qty', qty)
				const sep = res.data.redirect.includes('?') ? '&' : '?'
				router.push(q.toString() ? res.data.redirect + sep + q.toString() : res.data.redirect)
			}
		} catch (e) {
			ElMessage.error(e?.response?.data?.message || '生成失败')
		}
	}).catch(() => {})
}

const exportData = () => {
	if (!list.value.length) return ElMessage.warning('当前筛选条件下暂无数据')
	const headers = activeTab.value === 'safety-stock'
		? ['商品编码', '商品名称', '品牌', '分类', '仓库', '当前库存', '安全库存', '建议补货', '日均销量', '可销天数']
		: activeTab.value === 'expiry'
			? ['商品编码', '商品名称', '规格', '保质期', '到期日期', '剩余天数', '状态']
			: activeTab.value === 'sellable-days'
				? ['商品编码', '商品名称', '当前库存', '日均销量', '可销天数', '建议动作']
				: ['商品编码', '商品名称', '仓库', '当前库存', '问题']
	const rows = list.value.map((r) => {
		if (activeTab.value === 'safety-stock') return [r.product_code, r.product_name, r.brand_name, r.category_name, r.warehouse_name, r.current_stock, r.safety_stock, suggestQty(r), r.daily_30, r.sellable_days]
		if (activeTab.value === 'expiry') return [r.product_code, r.product_name, r.spec, r.shelf_life_days, r.expiry_date, r.remaining_days, expiryLabel(r.status)]
		if (activeTab.value === 'sellable-days') return [r.product_code, r.product_name, r.current_stock, r.daily_30, r.sellable_days, r.action]
		return [r.product_code, r.product_name, r.warehouse_name, r.current_stock, issueText(r)]
	})
	const csv = '﻿库存预警\n\n' + [headers.join(','), ...rows.map((r) => r.join(','))].join('\n')
	saveBlob(csv, `stock_warning_${activeTab.value}.csv`)
}

onMounted(() => { loadOverview(); refresh() })
</script>

<style scoped>
.perf-list { height: 100%; display: flex; flex-direction: column; overflow: auto; }
.overview-cards { display: flex; gap: 16px; padding: 12px 20px; }
.ov-card { flex: 1; background: #fff; height: 80px; border: 1px solid #ebeef5; border-radius: 4px; padding: 12px 20px; display: flex; flex-direction: column; justify-content: center; align-items: center; }
.ov-card.red { border-left: 4px solid #f56c6c; }
.ov-card.blue { border-left: 4px solid #409eff; }
.ov-num { font-size: 24px; font-weight: bold; color: #303133; }
.ov-label { font-size: 13px; color: #909399; margin-top: 4px; }
.perf-toolbar { display: flex; align-items: center; justify-content: flex-end; gap: 12px; margin: 12px 20px; }
.pager-total { font-size: 14px; color: #606266; }
.text-red { color: #F56C6C; font-weight: 600; }
</style>
