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
				<el-table :data="list" border stripe v-loading="loading" height="100%">
					<el-table-column prop="product_code" label="商品编码" width="120" />
					<el-table-column prop="product_name" label="商品名称" min-width="180" />
					<el-table-column prop="brand_name" label="品牌" width="100" />
					<el-table-column prop="category_name" label="分类" width="100" />
					<el-table-column prop="spec" label="规格" width="100" />
					<el-table-column prop="unit" label="单位" width="70" align="center" />
					<el-table-column prop="warehouse_name" label="仓库" width="100" />
					<el-table-column prop="current_stock" label="当前库存" width="100" align="right">
						<template #default="{ row }"><span class="text-red">{{ row.current_stock }}</span></template>
					</el-table-column>
					<el-table-column prop="safety_stock" label="安全库存" width="100" align="right" />
					<el-table-column prop="suggest_replenish" label="建议补货" width="100" align="right">
						<template #default="{ row }"><b>{{ row.suggest_replenish }}</b></template>
					</el-table-column>
					<el-table-column prop="daily_7" label="近7天销量" width="100" align="right" />
					<el-table-column prop="daily_30" label="日均销量" width="90" align="right" />
					<el-table-column prop="sellable_days" label="可销天数" width="100" align="right">
						<template #default="{ row }">{{ row.sellable_days > 99999 ? '充足' : row.sellable_days }}</template>
					</el-table-column>
					<el-table-column label="操作" width="120" align="center" fixed="right">
						<template #default="{ row }">
							<el-button type="primary" link size="small" @click="genPurchase(row)">生成采购申请</el-button>
						</template>
					</el-table-column>
				</el-table>
			</el-tab-pane>

			<el-tab-pane label="临期/超期预警" name="expiry">
				<el-alert v-if="hint" :title="hint" type="warning" :closable="false" style="margin-bottom: 8px" />
				<el-table :data="list" border stripe v-loading="loading" height="100%">
					<el-table-column prop="product_code" label="商品编码" width="120" />
					<el-table-column prop="product_name" label="商品名称" min-width="180" />
					<el-table-column prop="spec" label="规格" width="100" />
					<el-table-column prop="batch" label="批次" width="100" />
					<el-table-column prop="shelf_life_days" label="保质期(天)" width="100" align="center" />
					<el-table-column prop="expiry_date" label="到期日期" width="120" align="center" />
					<el-table-column prop="remaining_days" label="剩余天数" width="100" align="center">
						<template #default="{ row }">
							<span :style="{ color: row.remaining_days <= 30 ? '#f56c6c' : (row.remaining_days <= 60 ? '#e6a23c' : '#67c23a'), fontWeight: 'bold' }">{{ row.remaining_days }}</span>
						</template>
					</el-table-column>
					<el-table-column label="状态" width="90" align="center">
						<template #default="{ row }">
							<el-tag :type="expiryType(row.status)" size="small">{{ expiryLabel(row.status) }}</el-tag>
						</template>
					</el-table-column>
				</el-table>
			</el-tab-pane>

			<el-tab-pane label="库存可销天数" name="sellable-days">
				<el-table :data="list" border stripe v-loading="loading" height="100%">
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
					<el-table-column prop="status" label="标记" width="80" align="center">
						<template #default="{ row }">
							<el-tag v-if="row.status === 'slow'" type="info" size="small">滞销</el-tag>
							<el-tag v-else-if="row.status === 'short'" type="danger" size="small">缺货</el-tag>
							<el-tag v-else-if="row.status === 'low'" type="warning" size="small">偏低</el-tag>
							<el-tag v-else type="success" size="small">正常</el-tag>
						</template>
					</el-table-column>
					<el-table-column prop="action" label="建议动作" width="100" align="center" />
				</el-table>
			</el-tab-pane>

			<el-tab-pane label="库存异常" name="stock-exception">
				<el-table :data="list" border stripe v-loading="loading" height="100%">
					<el-table-column prop="product_code" label="商品编码" width="120" />
					<el-table-column prop="product_name" label="商品名称" min-width="180" />
					<el-table-column prop="spec" label="规格" width="100" />
					<el-table-column prop="warehouse_name" label="仓库" width="120" />
					<el-table-column prop="current_stock" label="当前库存" width="120" align="right">
						<template #default="{ row }"><span class="text-red">{{ row.current_stock }}</span></template>
					</el-table-column>
					<el-table-column prop="issue" label="问题" width="120" align="center">
						<template #default="{ row }">负库存</template>
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
			<el-button size="small" @click="exportData">导出</el-button>
		</div>
	</div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import { useRouter } from 'vue-router'
import perfApi from '@/api/perf'
import { saveBlob } from '@/utils/fileDownload'

const router = useRouter()
const activeTab = ref('safety-stock')
const list = ref([])
const loading = ref(false)
const overview = ref(null)
const hint = ref('')

const expiryLabel = (s) => ({ normal: '正常', near_expiry: '临期', expired: '过期' }[s] || s)
const expiryType = (s) => ({ normal: 'success', near_expiry: 'warning', expired: 'danger' }[s] || 'info')
const sellableColor = (r) => r.status === 'short' ? '#f56c6c' : r.status === 'low' ? '#e6a23c' : r.status === 'slow' ? '#909399' : '#67c23a'
const sellableText = (r) => r.sellable_days > 99999 ? '充足' : (r.status === 'slow' ? `${r.sellable_days}天(滞销)` : `${r.sellable_days}天`)

const loadOverview = async () => {
	const res = await perfApi.warning.overview.get()
	if (res.code === 200) overview.value = res.data
}

const refresh = async () => {
	loading.value = true
	hint.value = ''
	try {
		const res = await perfApi.warning.list.get({ type: activeTab.value })
		if (res.code === 200) {
			list.value = res.data?.list || []
			hint.value = res.data?.hint || ''
		}
	} finally { loading.value = false }
}

const genPurchase = (row) => {
	router.push(`/business/purchase-application?product_id=${row.product_id}&qty=${row.suggest_replenish}`)
}

const exportData = () => {
	if (!list.value.length) return ElMessage.warning('无数据')
	const headers = activeTab.value === 'safety-stock'
		? ['商品编码', '商品名称', '品牌', '分类', '仓库', '当前库存', '安全库存', '建议补货', '日均销量', '可销天数']
		: activeTab.value === 'expiry'
			? ['商品编码', '商品名称', '规格', '保质期', '到期日期', '剩余天数', '状态']
			: activeTab.value === 'sellable-days'
				? ['商品编码', '商品名称', '当前库存', '日均销量', '可销天数', '建议动作']
				: ['商品编码', '商品名称', '仓库', '当前库存', '问题']
	const rows = list.value.map((r) => {
		if (activeTab.value === 'safety-stock') return [r.product_code, r.product_name, r.brand_name, r.category_name, r.warehouse_name, r.current_stock, r.safety_stock, r.suggest_replenish, r.daily_30, r.sellable_days]
		if (activeTab.value === 'expiry') return [r.product_code, r.product_name, r.spec, r.shelf_life_days, r.expiry_date, r.remaining_days, expiryLabel(r.status)]
		if (activeTab.value === 'sellable-days') return [r.product_code, r.product_name, r.current_stock, r.daily_30, r.sellable_days, r.action]
		return [r.product_code, r.product_name, r.warehouse_name, r.current_stock, '负库存']
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
.perf-toolbar { display: flex; justify-content: flex-end; margin: 12px 20px; }
.text-red { color: #f56c6c; font-weight: 600; }
</style>
