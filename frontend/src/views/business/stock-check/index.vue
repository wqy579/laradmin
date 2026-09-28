<template>
	<div class="stock-check-page">
		<!-- 主分类面板 -->
		<aside class="panel panel-left" :class="{ 'is-collapsed': isMobile && leftCollapsed }">
			<div class="panel-header">
				<span class="panel-title"><i class="el-icon-folder"></i><span>主分类</span></span>
				<el-button size="small" text @click="leftCollapsed = !leftCollapsed">
					<i :class="leftCollapsed ? 'el-icon-d-arrow-right' : 'el-icon-d-arrow-left'"></i>
				</el-button>
			</div>
			<div class="panel-body" @click.self="isMobile && (leftCollapsed = !leftCollapsed)">
				<div class="main-item">
					<div class="main-row" :class="{ active: !activeMainId }" @click="resetCatFilter">
						<span class="main-icon el-icon-folder"></span>
						<span class="main-name">全部</span>
						<span class="main-count">{{ totalAllProducts }}</span>
					</div>
				</div>
				<div v-for="cat in mainCategories" :key="cat.id" class="main-item">
					<div class="main-row" :class="{ active: activeMainId === cat.id }" @click="selectMain(cat)">
						<span class="main-icon el-icon-folder"></span>
						<span class="main-name">{{ cat.name }}</span>
						<span class="main-count">{{ cat.count || 0 }}</span>
					</div>
				</div>
				<div v-if="!mainCategories.length" class="panel-empty">暂无分类</div>
			</div>
		</aside>

		<!-- 副分类面板 -->
		<aside class="panel panel-mid" :class="{ 'is-collapsed': (isMobile && midCollapsed) || !activeMainId }">
			<div class="panel-header">
				<span class="panel-title"><i class="el-icon-document"></i><span>{{ currentMainCat?.name || '分类' }}</span></span>
				<el-button size="small" text @click="midCollapsed = !midCollapsed">
					<i :class="midCollapsed ? 'el-icon-d-arrow-right' : 'el-icon-d-arrow-left'"></i>
				</el-button>
			</div>
			<div class="panel-body">
				<div class="sub-item all-item" :class="{ active: !activeSubId }" @click="selectSub(activeMainId, null)">
					<span class="sub-dot"></span>
					<span class="sub-name">全部</span>
					<span class="sub-count">{{ currentMainCat?.count || 0 }}</span>
				</div>
				<div v-for="sub in currentSubs" :key="sub.id" class="sub-item"
					:class="{ active: activeSubId === sub.id }" @click="selectSub(activeMainId, sub)">
					<span class="sub-dot"></span>
					<span class="sub-name">{{ sub.name }}</span>
					<span class="sub-count">{{ sub.count || 0 }}</span>
				</div>
				<div v-if="!currentSubs.length" class="panel-empty">无子分类</div>
			</div>
		</aside>

		<!-- 主内容 -->
		<main class="panel panel-right">
			<div class="panel-header toolbar">
				<div class="left-tools">
					<span class="summary-text">
						共 <b>{{ stats.total_rows || 0 }}</b> 条
						<span v-if="stats.total_qty !== undefined">，库存总量 <b>{{ fmt(stats.total_qty) }}</b></span>
						<span v-if="stats.total_amount !== undefined">，库存金额 <b>¥{{ fmtMoney(stats.total_amount) }}</b></span>
					</span>
				</div>
				<div class="right-tools">
					<el-select v-model="searchForm.warehouse_id" placeholder="仓库" clearable size="small" style="width: 130px" @change="search">
						<el-option v-for="w in warehouses" :key="w.id" :label="w.name" :value="w.id" />
					</el-select>
					<el-select v-model="searchForm.stock_filter" placeholder="库存状态" clearable size="small" style="width: 120px" @change="search">
						<el-option label="有货" value="positive" />
						<el-option label="零库存" value="zero" />
						<el-option label="负库存" value="negative" />
					</el-select>
					<el-input v-model="searchForm.keyword" placeholder="搜索商品/条码/编号" clearable size="small" style="width: 180px"
						@keyup.enter="search" @clear="search" />
					<el-button size="small" @click="refresh"><i class="el-icon-refresh"></i>刷新</el-button>
				</div>
			</div>

			<div class="panel-body" style="flex: 1; overflow: hidden; display: flex; flex-direction: column;">
				<div class="selected-info" v-if="activeMainId || activeSubId">
					<el-tag size="small" type="info" closable @close="resetCatFilter">
						<i class="el-icon-folder-opened"></i>
						{{ getActiveFilterLabel() }}
					</el-tag>
					<el-button size="small" text @click="resetCatFilter" style="margin-left: 4px">清除筛选</el-button>
				</div>
				<sTable
					ref="tableRef"
					tableName="stock_total"
					:data="data"
					:columns="columns"
					:loading="loading"
					:total="total"
					:currentPage="currentPage"
					:pageSize="pageSize"
					:pageSizes="pageSizes"
					height="100%"
					stripe
					show-pagination
					@pageChange="currentPage = $event; fetchData()"
					@pageSizeChange="pageSize = $event; currentPage = 1; fetchData()"
				>
					<template #quantity="{ row }">
						<span :class="{
							'diff-up': row.quantity > row.yesterday_qty,
							'diff-down': row.quantity < row.yesterday_qty,
							'diff-zero': Number(row.quantity) === 0
						}">
							{{ formatStock(row.quantity, row.unit_conversion, row.unit_conversion_medium, row.price_unit, row.barcode_medium_unit, row.price_unit_small) }}
							<template v-if="row.quantity !== row.yesterday_qty">
								<i :class="row.quantity > row.yesterday_qty ? 'el-icon-top' : 'el-icon-bottom'" style="margin-left: 2px; font-size: 11px;"></i>
							</template>
						</span>
					</template>
					<template #yesterday_qty="{ row }">
						<span>{{ fmt(row.yesterday_qty) }}</span>
					</template>
					<template #today_in="{ row }">
						<span class="text-in">{{ fmt(row.today_in) }}</span>
					</template>
					<template #today_out="{ row }">
						<span class="text-out">{{ fmt(row.today_out) }}</span>
					</template>
				</sTable>
			</div>
		</main>
	</div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import businessApi from '@/api/business'
import sTable from '@/components/sTable/index.vue'
import { useResponsive } from '@/hooks/useResponsive'

// ---- 面板折叠 ----
const { isMobile } = useResponsive()
const leftCollapsed = ref(false)
const midCollapsed = ref(false)

// ---- 分类 ----
const mainCategories = ref([])
const activeMainId = ref(null)
const activeSubId = ref(null)
const totalAllProducts = ref(0)

const currentMainCat = computed(() =>
	mainCategories.value.find((c) => c.id === activeMainId.value) || null
)
const currentSubs = computed(() => currentMainCat.value?.children || [])

const selectMain = (cat) => {
	activeMainId.value = cat.id
	activeSubId.value = null
	searchForm.main_category_id = cat.id
	searchForm.sub_category_id = null
	search()
	if (isMobile.value) {
		// 与商品档案页一致：每次点击切换展开/收起
		leftCollapsed.value = !leftCollapsed.value
	}
}

const selectSub = (mainId, sub) => {
	activeMainId.value = mainId
	activeSubId.value = sub?.id || null
	searchForm.main_category_id = mainId
	searchForm.sub_category_id = sub?.id || null
	search()
	if (isMobile.value) {
		// 与商品档案页一致：每次点击切换展开/收起
		midCollapsed.value = !midCollapsed.value
	}
}

const resetCatFilter = () => {
	activeMainId.value = null
	activeSubId.value = null
	searchForm.main_category_id = null
	searchForm.sub_category_id = null
	search()
	if (isMobile.value) {
		// 点"全部"同时展开主分类面板，避免无法恢复
		leftCollapsed.value = false
	}
}

const getActiveFilterLabel = () => {
	if (activeSubId) {
		const cat = mainCategories.value.find(c => c.id === activeMainId)
		const sub = cat?.children?.find(s => s.id === activeSubId)
		return sub ? `${cat.name} > ${sub.name}` : ''
	}
	if (activeMainId) {
		const cat = mainCategories.value.find(c => c.id === activeMainId)
		return cat?.name || ''
	}
	return ''
}

// ---- 仓库 ----
const warehouses = ref([])

// ---- 搜索/分页 ----
const searchForm = reactive({
	warehouse_id: '',
	keyword: '',
	stock_filter: '',
	main_category_id: null,
	sub_category_id: null,
})

const data = ref([])
const total = ref(0)
const loading = ref(false)
const stats = ref({})
const tableRef = ref(null)
const currentPage = ref(1)
const pageSize = ref(30)
const pageSizes = [30, 50, 100, 200, 500]

const columns = [
	{ type: 'checkbox', width: 48, fixed: 'left' },
	{ prop: 'name', title: '商品名称', width: 200, showOverflowTooltip: true },
	{ prop: 'spec', title: '规格', width: 90 },
	{ prop: 'barcode', title: '条码', width: 130 },
	{ prop: 'warehouse_name', title: '仓库', width: 120 },
	{ prop: 'yesterday_qty', title: '昨日库存', width: 100, slots: { default: 'yesterday_qty' } },
	{ prop: 'today_in', title: '今日入库', width: 100, slots: { default: 'today_in' } },
	{ prop: 'today_out', title: '今日出库', width: 100, slots: { default: 'today_out' } },
	{ prop: 'quantity', title: '当前库存', width: 110, slots: { default: 'quantity' } },
	{ prop: 'frozen_qty', title: '冻结', width: 90 },
]

function fmt(v) {
	const n = Number(v ?? 0)
	if (Math.abs(n - Math.round(n)) < 0.001) return String(Math.round(n))
	return String(Math.round(n * 100) / 100)
}

// 库存按小单位存，逐级整除成 大(件)/中(盒)/小(袋) 展示，与销售单 formatStock 一致
function formatStock(totalSmall, c, mc, unitLarge, unitMedium, unitSmall) {
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

function fmtMoney(v) {
	const n = Number(v ?? 0)
	return n.toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

async function fetchData() {
	loading.value = true
	try {
		const params = { page: currentPage.value, page_size: pageSize.value }
		Object.keys(searchForm).forEach(k => {
			if (searchForm[k] !== null && searchForm[k] !== undefined && searchForm[k] !== '') {
				params[k] = searchForm[k]
			}
		})
		const res = await businessApi.stockCheck.list.get(params)
		const d = res.data || {}
		data.value = (d.list || []).map(item => ({ ...item }))
		total.value = d.total || 0
		stats.value = d.stats || {}
		if (d.categories) {
			mainCategories.value = d.categories
			totalAllProducts.value = d.categories.reduce((s, c) => s + (c.count || 0), 0)
		}
		if (d.warehouses) {
			warehouses.value = d.warehouses
		}
	} catch (error) {
		ElMessage.error(error?.response?.data?.message || '数据加载失败')
	} finally {
		loading.value = false
	}
}

function search() {
	currentPage.value = 1
	fetchData()
}

function refresh() {
	tableRef.value?.clearCheckboxRow?.()
	fetchData()
}

onMounted(() => {
	fetchData()
})
</script>

<style scoped>
.stock-check-page {
	display: flex;
	height: 100%;
	overflow: hidden;
	gap: 1px;
	background: var(--el-border-color-lighter);
}

.panel {
	display: flex;
	flex-direction: column;
	background: var(--el-bg-color);
	overflow: hidden;
}

.panel-left { width: 220px; flex-shrink: 0; }
.panel-mid { width: 180px; flex-shrink: 0; }

.panel-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 8px 10px 6px;
	border-bottom: 1px solid var(--el-border-color-lighter);
	flex-shrink: 0;
}

.panel-title {
	font-size: 12px;
	font-weight: 600;
	color: var(--el-text-color-primary);
	display: flex;
	align-items: center;
	gap: 4px;
}

.panel-body {
	flex: 1;
	overflow-y: auto;
	padding: 4px 0;
}

.panel-body::-webkit-scrollbar { width: 4px; }
.panel-body::-webkit-scrollbar-thumb { background: var(--el-border-color); border-radius: 2px; }

.panel-right { flex: 1; display: flex; flex-direction: column; min-width: 0; overflow: hidden; }

/* 分类列表 */
.main-row {
	display: flex;
	align-items: center;
	gap: 4px;
	padding: 7px 10px;
	cursor: pointer;
	font-size: 12px;
	color: var(--el-text-color-regular);
	border-bottom: 1px solid var(--el-border-color-lighter);
	transition: background 0.1s;
}
.main-row:hover { background: var(--el-fill-color-light); }
.main-row.active { background: var(--el-color-primary-light-9); color: var(--el-color-primary); font-weight: 500; }
.main-icon { flex-shrink: 0; font-size: 13px; color: var(--el-color-warning); }
.main-name { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.main-count { font-size: 10px; color: var(--el-text-color-placeholder); flex-shrink: 0; }

.sub-item {
	display: flex;
	align-items: center;
	gap: 4px;
	padding: 6px 10px 6px 20px;
	font-size: 11px;
	cursor: pointer;
	color: var(--el-text-color-secondary);
	border-bottom: 1px solid var(--el-border-color-lighter);
	transition: background 0.1s;
}
.sub-item:hover { background: var(--el-fill-color-light); color: var(--el-text-color-regular); }
.sub-item.active { background: var(--el-color-primary-light-9); color: var(--el-color-primary); font-weight: 500; }
.all-item { padding-left: 10px; }
.sub-dot { width: 5px; height: 5px; border-radius: 50%; background: var(--el-text-color-placeholder); flex-shrink: 0; }

.panel-empty {
	padding: 20px;
	text-align: center;
	color: var(--el-text-color-placeholder);
	font-size: 12px;
}

/* 工具栏 */
.toolbar { gap: 8px; flex-wrap: wrap; }
.left-tools, .right-tools { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.summary-text { font-size: 12px; color: var(--el-text-color-regular); }
.summary-text b { color: var(--el-color-primary); }

.selected-info {
	display: flex;
	align-items: center;
	padding: 6px 10px;
	gap: 4px;
	border-bottom: 1px solid var(--el-border-color-lighter);
}

/* 库存差异 */
.diff-up { color: #67c23a; font-weight: 600; }
.diff-down { color: #f56c6c; font-weight: 600; }
.diff-zero { color: var(--el-text-color-placeholder); }
.text-in { color: #67c23a; }
.text-out { color: #f56c6c; }

/* 手机端：分类面板折叠时隐藏文字 */
@media (max-width: 768px) {
	.panel-left { width: 140px; }
	.panel-mid { width: 110px; }
	.panel-left.is-collapsed { width: 48px !important; }
	.panel-mid.is-collapsed { width: 48px !important; }
	.panel-left.is-collapsed .panel-title span,
	.panel-mid.is-collapsed .panel-title span,
	.panel-left.is-collapsed .main-name,
	.panel-left.is-collapsed .main-count,
	.panel-mid.is-collapsed .sub-name,
	.panel-mid.is-collapsed .sub-count { display: none; }
	.panel-left.is-collapsed .main-row,
	.panel-mid.is-collapsed .sub-item { justify-content: center; padding: 10px 4px; }
	.panel-left.is-collapsed .main-icon,
	.panel-mid.is-collapsed .sub-dot { margin: 0; }
	.panel-left.is-collapsed .panel-header,
	.panel-mid.is-collapsed .panel-header { justify-content: center; padding: 8px 4px; }
	.panel-left.is-collapsed .panel-header .el-button,
	.panel-mid.is-collapsed .panel-header .el-button { margin: 0; padding: 4px; }
	.summary-text { display: none; }
}
</style>
