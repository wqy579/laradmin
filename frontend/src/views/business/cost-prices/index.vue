<template>
	<div class="cost-page" @click="hideContextMenu" @contextmenu.prevent>
		<!-- 左栏：主分类 + 成本状态筛选 -->
		<aside class="panel-left" :class="{ 'is-collapsed': isMobile && leftCollapsed }">
			<div class="panel-header">
				<span class="panel-title">主分类</span>
			</div>
			<div class="quick-filters">
				<div
					v-for="f in costFilters" :key="f.key"
					:class="['qf-item', { active: activeCostFilter === f.key }]"
					@click="selectCostFilter(f.key)"
				>
					<el-icon><component :is="f.icon" /></el-icon>
					<span>{{ f.label }}</span>
					<span class="qf-count">{{ f.count }}</span>
				</div>
			</div>
			<div class="panel-body" @click.self="isMobile && (leftCollapsed = !leftCollapsed)">
				<div v-if="mainCategories.length" class="main-list">
					<div
						v-for="cat in mainCategories" :key="cat.id"
						:class="['main-item', { active: activeMainId === cat.id }]"
					>
						<div class="main-row" @click.stop="selectMain(cat)">
							<el-icon class="main-icon"><Folder /></el-icon>
							<span class="main-name">{{ cat.name }}</span>
							<span class="main-count">{{ cat.product_count || 0 }}</span>
						</div>
						<div class="main-expand" @click.stop="cat._expanded = !cat._expanded">
							<el-icon :class="{ rotated: cat._expanded }"><ArrowDown /></el-icon>
						</div>
						<div v-if="cat._expanded && cat.children?.length" class="sub-inline-list">
							<div
								v-for="sub in cat.children" :key="sub.id"
								:class="['sub-inline-item', { active: activeSubId === sub.id }]"
								@click.stop="selectSub(cat.id, sub)"
							>
								<span class="sub-dot"></span>
								<span>{{ sub.name }}</span>
								<span class="sub-count">{{ sub.product_count || 0 }}</span>
							</div>
						</div>
					</div>
				</div>
				<el-empty v-else description="暂无分类" :image-size="40" />
			</div>
		</aside>

		<!-- 中栏：副分类 -->
		<aside class="panel-mid" :class="{ 'is-collapsed': isMobile && midCollapsed }">
			<div class="panel-header">
				<span class="panel-title">
					副分类
					<span v-if="activeMainId" class="panel-sub">{{ activeMainName }}</span>
				</span>
			</div>
			<div class="panel-body">
				<div v-if="activeMainId && currentSubs.length" class="sub-list">
					<div
						v-for="sub in currentSubs" :key="sub.id"
						:class="['sub-row', { active: activeSubId === sub.id }]"
						@click.stop="selectSub(activeMainId, sub)"
					>
						<span class="sub-dot"></span>
						<span class="sub-name">{{ sub.name }}</span>
						<span class="sub-count">{{ sub.product_count || 0 }}</span>
					</div>
				</div>
				<div v-else-if="activeMainId" class="sub-empty">
					<el-empty description="暂无副分类" :image-size="36" />
				</div>
				<div v-else class="sub-placeholder">
					<el-icon size="28"><ArrowLeft /></el-icon>
					<p>请先选择主分类</p>
				</div>
			</div>
		</aside>

		<!-- 右栏：成本价格列表 -->
		<main class="panel-right">
			<div class="right-toolbar">
				<div class="left-tools">
					<el-form-item label="搜索" class="search-box">
						<el-input
							v-model="searchForm.keyword"
							placeholder="名称 / 编码 / 条码 / 规格"
							clearable
							@keyup.enter="search"
							@clear="search"
						/>
					</el-form-item>
				</div>
				<div class="right-tools">
					<el-button type="primary" size="small" :disabled="!selectedRows.length" @click="handleBatchSet">
						<el-icon><SetUp /></el-icon>批量设置成本价
					</el-button>
				</div>
			</div>

			<sTable
				ref="tableRef"
				tableName="business_cost_price"
				:data="data"
				:columns="columns"
				:loading="loading"
				:total="total"
				:currentPage="currentPage"
				:pageSize="pageSize"
				:pageSizes="pageSizes"
				rowKey="id"
				height="100%"
				stripe
				@refresh="refresh"
				@pageChange="handlePageChange"
				@pageSizeChange="handlePageSizeChange"
				@selectionChange="onSelectionChange"
			>
				<template #name="{ row }">
					<div class="prod-name">
						<div class="name-main">{{ row.name }}</div>
						<div v-if="row.spec" class="name-sub">{{ row.spec }}</div>
					</div>
				</template>
				<template #price_display="{ row }">
					<div v-if="Number(row.price_large) > 0">
						<span class="price-main">{{ formatMoney(row.price_large) }}</span>
						<span class="price-unit">{{ row.price_unit || '件' }}</span>
					</div>
					<span v-else class="text-muted">-</span>
				</template>
				<template #cost_price="{ row }">
					<div class="cost-cell" :class="{ 'no-cost': !(Number(row.cost_price) > 0) }">
						<el-input-number
							v-model="row.cost_price"
							:min="0"
							:precision="2"
							:step="1"
							:controls="false"
							size="small"
							class="cost-input"
							@change="handleCostChange(row)"
						/>
					</div>
				</template>
			</sTable>
		</main>
	</div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { List, CircleCheck, Remove, ArrowDown, ArrowLeft, Folder, SetUp } from '@element-plus/icons-vue'
import businessApi from '@/api/business'
import sTable from '@/components/sTable/index.vue'
import { useResponsive } from '@/hooks/useResponsive'

const { isMobile } = useResponsive()
const leftCollapsed = ref(false)
const midCollapsed = ref(false)

const hideContextMenu = () => {}

// ---- 主分类 / 副分类 ----
const mainCategories = ref([])
const activeMainId = ref(null)
const activeSubId = ref(null)
const activeCostFilter = ref('')

const currentMainCat = computed(() => mainCategories.value.find((c) => c.id === activeMainId.value) || null)
const activeMainName = computed(() => currentMainCat.value?.name || '')
const currentSubs = computed(() => currentMainCat.value?.children || [])

// ---- 成本状态快捷筛选 ----
const costFilters = ref([
	{ key: '', label: '全部', icon: List, count: null },
	{ key: 'set', label: '已设成本', icon: CircleCheck, count: null },
	{ key: 'unset', label: '未设成本', icon: Remove, count: null },
])

const loadCategories = async () => {
	const res = await businessApi.product.categories.get()
	if (res.code === 200) {
		mainCategories.value = (res.data || []).map((c) => ({ ...c, _expanded: false }))
	}
}

const selectCostFilter = (key) => {
	activeCostFilter.value = key
	activeMainId.value = null
	activeSubId.value = null
	searchForm.keyword = ''
	searchForm.cost = key || ''
	searchForm.main_category_id = null
	searchForm.sub_category_id = null
	search()
}

const selectMain = (cat) => {
	activeMainId.value = cat.id
	activeSubId.value = null
	activeCostFilter.value = ''
	searchForm.main_category_id = cat.id
	searchForm.sub_category_id = null
	searchForm.cost = ''
	searchForm.keyword = ''
	if (isMobile.value) leftCollapsed.value = !leftCollapsed.value
	search()
}

const selectSub = (mainId, sub) => {
	activeMainId.value = mainId
	activeSubId.value = sub.id
	activeCostFilter.value = ''
	searchForm.main_category_id = mainId
	searchForm.sub_category_id = sub.id
	searchForm.cost = ''
	searchForm.keyword = ''
	if (isMobile.value) midCollapsed.value = !midCollapsed.value
	search()
}

// ---- 数据 ----
const searchForm = reactive({ keyword: '', cost: '', main_category_id: null, sub_category_id: null })
const data = ref([])
const total = ref(0)
const loading = ref(false)
const tableRef = ref(null)
const currentPage = ref(1)
const pageSize = ref(30)
const pageSizes = [30, 50, 100, 200, 500]
const selectedRows = ref([])
const savingIds = new Set()

const columns = [
	{ type: 'checkbox', width: 44, fixed: 'left' },
	{ prop: 'id', title: 'ID', width: 60, align: 'center' },
	{ prop: 'name', title: '产品名称', minWidth: 200, showOverflowTooltip: true, slots: { default: 'name' } },
	{ prop: 'barcode_small', title: '条码', width: 120, showOverflowTooltip: true },
	{ prop: 'price_display', title: '标准价', width: 100, align: 'right', slots: { default: 'price_display' } },
	{ prop: 'cost_price', title: '成本价', width: 130, align: 'right', slots: { default: 'cost_price' } },
]

async function fetchData() {
	loading.value = true
	try {
		const params = { page: currentPage.value, page_size: pageSize.value }
		Object.keys(searchForm).forEach((k) => {
			const v = searchForm[k]
			if (v !== null && v !== undefined && v !== '') params[k] = v
		})
		const res = await businessApi.costPrice.list.get(params)
		const d = res.data || {}
		data.value = d.list || []
		total.value = d.total || 0
		if (d.counts) {
			costFilters.value[0].count = d.counts.all ?? costFilters.value[0].count
			costFilters.value[1].count = d.counts.set ?? costFilters.value[1].count
			costFilters.value[2].count = d.counts.unset ?? costFilters.value[2].count
		}
	} catch {
		ElMessage.error('成本价格数据加载失败')
	} finally {
		loading.value = false
	}
}

function search() {
	currentPage.value = 1
	fetchData()
}

function handlePageChange(page) {
	currentPage.value = page
	fetchData()
}

function handlePageSizeChange(size) {
	pageSize.value = size
	fetchData()
}

function onSelectionChange(rows) {
	selectedRows.value = rows
}

function refresh() {
	tableRef.value?.clearCheckboxRow?.()
	fetchData()
}

// 行内修改成本价：自动保存并同步库存
async function handleCostChange(row) {
	if (savingIds.has(row.id)) return
	const price = Number(row.cost_price ?? 0)
	if (isNaN(price) || price < 0) return
	savingIds.add(row.id)
	try {
		const res = await businessApi.costPrice.edit.put(row.id, { cost_price: price })
		if (res.code !== 200) {
			ElMessage.error(res.message || '保存失败')
			fetchData()
		}
	} catch {
		ElMessage.error('保存失败')
	} finally {
		savingIds.delete(row.id)
	}
}

// 批量设置成本价
async function handleBatchSet() {
	if (!selectedRows.value.length) {
		ElMessage.warning('请先勾选产品')
		return
	}
	try {
		const { value } = await ElMessageBox.prompt('请输入批量设置的成本价', '批量设置成本价', {
			confirmButtonText: '确定',
			cancelButtonText: '取消',
			inputPlaceholder: '成本价',
			inputValidator: (v) => (v !== '' && !isNaN(Number(v)) && Number(v) >= 0 ? true : '请输入有效的成本价'),
		})
		if (value === null || value === undefined) return
		const ids = selectedRows.value.map((r) => r.id)
		const res = await businessApi.costPrice.batch.post({ ids, cost_price: Number(value) })
		if (res.code === 200) {
			ElMessage.success(`已为 ${ids.length} 个产品设置成本价`)
			search()
		} else {
			ElMessage.error(res.message || '批量设置失败')
		}
	} catch {
		/* 取消不处理 */
	}
}

const formatMoney = (val) => (val !== null && val !== undefined && Number(val) > 0 ? '¥' + Number(val).toFixed(2) : '-')

onMounted(() => {
	loadCategories()
	fetchData()
})
</script>

<style scoped>
.cost-page {
	display: flex;
	height: 100%;
	overflow: hidden;
	gap: 1px;
	background: var(--el-border-color-lighter);
}

.panel-left,
.panel-mid {
	flex-shrink: 0;
	display: flex;
	flex-direction: column;
	background: var(--el-bg-color);
	overflow: hidden;
}
.panel-left {
	width: 230px;
}
.panel-mid {
	width: 190px;
}

.panel-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 10px 12px 8px;
	border-bottom: 1px solid var(--el-border-color-lighter);
	flex-shrink: 0;
}
.panel-title {
	font-size: 13px;
	font-weight: 600;
	color: var(--el-text-color-primary);
	display: flex;
	align-items: center;
	gap: 4px;
}
.panel-sub {
	font-size: 11px;
	font-weight: 400;
	color: var(--el-text-color-secondary);
	margin-left: 4px;
}
.panel-body {
	flex: 1;
	overflow-y: auto;
	padding: 4px 0;
}
.panel-body::-webkit-scrollbar {
	width: 4px;
}
.panel-body::-webkit-scrollbar-thumb {
	background: var(--el-border-color);
	border-radius: 2px;
}

/* 成本状态快捷筛选 */
.quick-filters {
	display: flex;
	flex-wrap: wrap;
	gap: 3px;
	padding: 8px 10px 6px;
	border-bottom: 1px solid var(--el-border-color-lighter);
	flex-shrink: 0;
}
.qf-item {
	display: inline-flex;
	align-items: center;
	gap: 2px;
	padding: 2px 7px;
	border-radius: 10px;
	font-size: 11px;
	cursor: pointer;
	border: 1px solid var(--el-border-color);
	background: var(--el-fill-color-blank);
	color: var(--el-text-color-regular);
	transition: all 0.15s;
	white-space: nowrap;
}
.qf-item:hover {
	border-color: var(--el-color-primary);
	color: var(--el-color-primary);
}
.qf-item.active {
	background: var(--el-color-primary);
	color: #fff;
	border-color: var(--el-color-primary);
}
.qf-count {
	margin-left: 1px;
	opacity: 0.7;
}

/* 主分类 */
.main-item {
	position: relative;
	border-bottom: 1px solid var(--el-border-color-lighter);
}
.main-row {
	display: flex;
	align-items: center;
	gap: 4px;
	padding: 8px 10px;
	cursor: pointer;
	font-size: 13px;
	color: var(--el-text-color-regular);
	transition: background 0.1s;
}
.main-row:hover {
	background: var(--el-fill-color-light);
}
.main-row.active {
	background: var(--el-color-primary-light-9);
	color: var(--el-color-primary);
	font-weight: 500;
}
.main-icon {
	flex-shrink: 0;
	font-size: 14px;
}
.main-name {
	flex: 1;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
.main-count {
	font-size: 11px;
	color: var(--el-text-color-secondary);
	flex-shrink: 0;
}
.main-expand {
	position: absolute;
	right: 6px;
	top: 50%;
	transform: translateY(-50%);
	cursor: pointer;
	color: var(--el-text-color-secondary);
	font-size: 10px;
	padding: 2px;
	display: flex;
	align-items: center;
}
.main-expand.rotated {
	transform: translateY(-50%) rotate(180deg);
}

.sub-inline-list {
	padding: 0 0 2px 20px;
}
.sub-inline-item {
	display: flex;
	align-items: center;
	gap: 4px;
	padding: 5px 10px;
	font-size: 12px;
	cursor: pointer;
	color: var(--el-text-color-secondary);
	border-radius: 4px;
	margin: 1px 6px;
	transition: background 0.1s;
}
.sub-inline-item:hover {
	background: var(--el-fill-color-light);
	color: var(--el-text-color-regular);
}
.sub-inline-item.active {
	background: var(--el-color-primary-light-9);
	color: var(--el-color-primary);
	font-weight: 500;
}
.sub-dot {
	width: 5px;
	height: 5px;
	border-radius: 50%;
	background: var(--el-text-color-placeholder);
	flex-shrink: 0;
}
.sub-inline-item.active .sub-dot {
	background: var(--el-color-primary);
}
.sub-count {
	margin-left: auto;
	font-size: 10px;
}

/* 副分类 */
.sub-row {
	display: flex;
	align-items: center;
	gap: 6px;
	padding: 7px 12px;
	font-size: 12px;
	cursor: pointer;
	color: var(--el-text-color-regular);
	border-bottom: 1px solid var(--el-border-color-lighter);
	transition: background 0.1s;
}
.sub-row:hover {
	background: var(--el-fill-color-light);
}
.sub-row.active {
	background: var(--el-color-primary-light-9);
	color: var(--el-color-primary);
	font-weight: 500;
}
.sub-name {
	flex: 1;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
.sub-count {
	font-size: 11px;
	color: var(--el-text-color-placeholder);
	flex-shrink: 0;
}
.sub-empty {
	padding: 16px;
}
.sub-placeholder {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	padding: 40px 16px;
	color: var(--el-text-color-placeholder);
	gap: 8px;
}
.sub-placeholder p {
	font-size: 12px;
	margin: 0;
}

/* 右栏 */
.panel-right {
	flex: 1;
	display: flex;
	flex-direction: column;
	min-width: 0;
	overflow: hidden;
	background: var(--el-bg-color);
}
.right-toolbar {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 10px 12px 8px;
	border-bottom: 1px solid var(--el-border-color-lighter);
	flex-shrink: 0;
}
.left-tools {
	display: flex;
	gap: 6px;
	align-items: center;
}
.right-tools {
	display: flex;
	gap: 6px;
	align-items: center;
}
.search-box {
	margin: 0;
}
.search-box :deep(.el-input__wrapper) {
	width: 260px;
}

.cost-cell .cost-input {
	width: 112px;
}
.cost-cell.no-cost :deep(.el-input__wrapper) {
	background: var(--el-color-warning-light-9);
}
.prod-name .name-main {
	font-weight: 500;
	line-height: 1.4;
}
.prod-name .name-sub {
	font-size: 12px;
	color: var(--el-text-color-secondary);
}
.price-main {
	font-weight: 600;
}
.price-unit {
	margin-left: 2px;
	font-size: 12px;
	color: var(--el-text-color-secondary);
}
.text-muted {
	color: var(--el-text-color-placeholder);
}

@media (max-width: 768px) {
	.panel-left.is-collapsed { width: 48px !important; }
	.panel-mid.is-collapsed { width: 48px !important; }
	.panel-left.is-collapsed .panel-title span,
	.panel-left.is-collapsed .qf-item span,
	.panel-left.is-collapsed .qf-count,
	.panel-mid.is-collapsed .panel-title span { display: none; }
	.panel-left.is-collapsed .main-name,
	.panel-left.is-collapsed .main-count { display: none; }
	.panel-left.is-collapsed .main-row { justify-content: center; padding: 10px 4px; }
	.panel-left.is-collapsed .main-icon { margin: 0; }
	.panel-left.is-collapsed .main-expand { display: none; }
	.panel-mid.is-collapsed .sub-name,
	.panel-mid.is-collapsed .sub-count { display: none; }
	.panel-mid.is-collapsed .sub-row { justify-content: center; padding: 10px 4px; }
}
</style>
