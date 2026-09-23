<template>
	<div class="product-page" @click="hideContextMenu" @contextmenu.prevent>
		<!-- 左栏：主分类 -->
		<aside class="panel-left" :class="{ 'is-collapsed': isMobile && leftCollapsed }">
			<div class="panel-header">
				<span class="panel-title">主分类</span>
				<el-button type="success" size="small" @click="handleAddMain">
					<el-icon><Plus /></el-icon>添加分类
				</el-button>
			</div>
			<div class="quick-filters">
				<div
					v-for="f in statusFilters" :key="f.key"
					:class="['qf-item', { active: activeStatusFilter === f.key }]"
					@click="selectStatusFilter(f.key)"
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
						class="main-item"
						@contextmenu.prevent.stop="showMainContext($event, cat)"
					>
						<div class="main-row" :class="{ active: activeMainId === cat.id }" @click.stop="selectMain(cat)">
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
								@contextmenu.prevent.stop="showSubContext($event, cat, sub)"
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
				<el-button
					v-if="activeMainId"
					type="success" size="small" @click="handleAddSub"
				>
					<el-icon><Plus /></el-icon>添加
				</el-button>
			</div>
			<div class="panel-body">
				<div v-if="activeMainId && currentSubs.length" class="sub-list">
					<div
						v-for="sub in currentSubs" :key="sub.id"
						:class="['sub-row', { active: activeSubId === sub.id }]"
						@click.stop="selectSub(activeMainId, sub)"
						@contextmenu.prevent.stop="showSubContext($event, currentMainCat, sub)"
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

		<!-- 右栏：商品列表 -->
		<main class="panel-right">
			<div class="right-toolbar">
				<div class="left-tools">
						<el-dropdown :disabled="!selectedRows.length">
							<el-button :disabled="!selectedRows.length" size="small">
								批量操作
							</el-button>
							<template #dropdown>
								<el-dropdown-menu>
									<el-dropdown-item @click="handleBatchStatus(true)">启用</el-dropdown-item>
									<el-dropdown-item @click="handleBatchStatus(false)">禁用</el-dropdown-item>
									<el-dropdown-item divided style="color:var(--el-color-danger)" @click="handleBatchDelete">批量删除</el-dropdown-item>
								</el-dropdown-menu>
							</template>
						</el-dropdown>
						<el-select
							v-model="searchForm.main_category_id"
							clearable
							size="small"
							placeholder="主分类"
							style="width:130px"
							@change="handleMainFromSelect"
						>
							<el-option v-for="c in mainCategories" :key="c.id" :label="`${c.name}（${c.product_count || 0}）`" :value="c.id" />
						</el-select>
						<el-select
							v-model="searchForm.sub_category_id"
							clearable
							size="small"
							placeholder="副分类"
							style="width:130px"
							:disabled="!searchForm.main_category_id"
							@change="handleSubFromSelect"
						>
							<el-option v-for="s in currentSubs" :key="s.id" :label="`${s.name}（${s.product_count || 0}）`" :value="s.id" />
						</el-select>
						<el-form-item label="搜索" class="search-box">
						<el-input v-model="searchForm.query" placeholder="ID/条码/名称/规格" clearable @clear="search" @keyup.enter="search" />
					</el-form-item>
				</div>
				<div class="right-tools">
					<el-button
						type="primary" size="small"
						:disabled="!canAddProduct"
						:title="canAddProduct ? '' : '请先选择主分类和副分类'"
						@click="handleAdd"
					>
						<el-icon><Plus /></el-icon>新增产品
					</el-button>
				</div>
			</div>

			<sTable
				ref="tableRef"
				tableName="business_product"
				:search-form="searchForm"
				:data="tableData"
				:columns="columns"
				:loading="loading"
				:total="total"
				:currentPage="paginationProps.currentPage"
				:pageSize="paginationProps.pageSize"
				:pageSizes="paginationProps.pageSizes"
				rowKey="id"
				height="100%"
				stripe
				@contextmenu.prevent.stop="handleRowContext"
				@refresh="refresh"
				@pageChange="handlePageChange"
				@pageSizeChange="handlePageSizeChange"
				@selectionChange="onSelectionChange"
			>
				<template #image="{ row }">
					<div v-if="row.image" class="prod-img">
						<el-image :src="'/storage/' + row.image" fit="cover" :preview-src-list="['/storage/' + row.image]" style="width:40px;height:40px;border-radius:4px" />
					</div>
					<span v-else class="text-muted">-</span>
				</template>
				<template #name="{ row }">
					<span class="prod-name">{{ row.name }}</span>
				</template>
				<template #barcode_small="{ row }">
					<span v-if="row.barcode_small">{{ row.barcode_small }}</span>
					<span v-else class="text-muted">-</span>
				</template>
			<template #prices="{ row }">
				<div v-if="row.price_large" class="price-block">
					<div class="price-main">{{ formatMoney(row.price_large) }}/{{ row.price_unit || '件' }}</div>
					<div v-if="row.price_small" class="price-sub">{{ formatMoney(row.price_small) }}/{{ row.price_unit_small || '个' }}</div>
				</div>
				<span v-else class="text-muted">-</span>
			</template>
			<template #shelf_life_days="{ row }">
					<span>{{ row.shelf_life_days || '-' }}</span>
				</template>
				<template #is_online="{ row }">
					<el-switch
						:model-value="!!row.is_online"
						size="small"
						@change="handleToggleOnline(row)"
					/>
				</template>
				<template #is_active="{ row }">
					<el-tag :type="row.is_active ? 'success' : 'info'" size="small">{{ row.is_active ? '正常' : '作废' }}</el-tag>
				</template>
			<template #ops="{ row }">
				<div class="ops-icons">
					<el-button link size="small" @click="handleCopy(row)">
						<el-icon><CopyDocument /></el-icon>
					</el-button>
					<el-button type="primary" link size="small" @click="handleEdit(row)">
						<el-icon><Edit /></el-icon>
					</el-button>
					<el-popconfirm title="确定删除该产品吗？" @confirm="handleDelete(row)">
						<template #reference>
							<el-button type="danger" link size="small">
								<el-icon><Delete /></el-icon>
							</el-button>
						</template>
					</el-popconfirm>
				</div>
			</template>
			</sTable>
		</main>

		<!-- 右键菜单：产品行 -->
		<teleport to="body">
			<div v-if="ctxMenu.visible && ctxMenu.type === 'product'"
				class="ctx-menu"
				:style="{ left: ctxMenu.x + 'px', top: ctxMenu.y + 'px' }"
				@click.stop
			>
				<div @click="handleEdit(ctxMenu.data)">编辑产品</div>
				<div @click="handleToggleOnline(ctxMenu.data)">
					{{ ctxMenu.data.is_online ? '下架' : '上架' }}
				</div>
				<div class="ctx-sep"></div>
				<div class="ctx-del" @click="handleDelete(ctxMenu.data)">删除</div>
			</div>
			<!-- 右键菜单：主分类 -->
			<div v-else-if="ctxMenu.visible && ctxMenu.type === 'main'"
				class="ctx-menu"
				:style="{ left: ctxMenu.x + 'px', top: ctxMenu.y + 'px' }"
				@click.stop
			>
				<div @click="handleAddSubTarget(ctxMenu.data)">增加下级分类</div>
				<div @click="handleRenameMain(ctxMenu.data)">修改分类名称</div>
				<div class="ctx-sep"></div>
				<div class="ctx-del" @click="handleDeleteMain(ctxMenu.data)">删除分类</div>
			</div>
			<!-- 右键菜单：副分类 -->
			<div v-else-if="ctxMenu.visible && ctxMenu.type === 'sub'"
				class="ctx-menu"
				:style="{ left: ctxMenu.x + 'px', top: ctxMenu.y + 'px' }"
				@click.stop
			>
				<div @click="handleRenameSub(ctxMenu.data)">修改分类名称</div>
				<div class="ctx-sep"></div>
				<div class="ctx-del" @click="handleDeleteSub(ctxMenu.data)">删除分类</div>
			</div>
		</teleport>

		<!-- 产品对话框 -->
		<ProductDialog
			v-if="dialog.product"
			v-model:visible="dialog.product"
			:record="currentProduct"
			:categories="flatCategories"
			:prefill-main="activeMainId"
			:prefill-sub="activeSubId"
			@success="handleProductSuccess"
		/>
		<!-- 分类对话框 -->
		<CategoryDialog
			v-if="dialog.category"
			v-model:visible="dialog.category"
			:record="currentCategory"
			:parent-mains="mainCategories"
			@success="handleCategorySuccess"
		/>
	</div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, nextTick } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
	Folder, FolderOpened, ArrowDown, ArrowLeft, Plus, Filter,
	CircleCheck, Remove, CircleClose, List,
	CopyDocument, Edit, Delete
} from '@element-plus/icons-vue'
import { useTable } from '@/hooks/useTable'
import businessApi from '@/api/business'
import sTable from '@/components/sTable/index.vue'
import ProductDialog from '../components/product-dialog.vue'
import CategoryDialog from '../components/category-dialog.vue'
import { useResponsive } from '@/hooks/useResponsive'

const { isMobile } = useResponsive()
// 手机端折叠状态
const leftCollapsed = ref(false)
const midCollapsed = ref(false)

// ---- 主分类 ----
const mainCategories = ref([])
const activeMainId = ref(null)
const activeSubId = ref(null)
const activeStatusFilter = ref('')

const currentMainCat = computed(() =>
	mainCategories.value.find(c => c.id === activeMainId.value) || null
)
const activeMainName = computed(() => currentMainCat.value?.name || '')
const currentSubs = computed(() => currentMainCat.value?.children || [])

const canAddProduct = computed(() => !!activeMainId.value && !!activeSubId.value)

// ---- 状态快捷筛选 ----
const statusFilters = ref([
	{ key: '', label: '全部', icon: List, count: null },
	{ key: 'online', label: '已上架', icon: CircleCheck, count: null },
	{ key: 'offline', label: '未上架', icon: Remove, count: null },
	{ key: 'inactive', label: '作废', icon: CircleClose, count: null },
])

const loadStatusCounts = async () => {
	try {
		const [allRes, onlineRes, offlineRes, inactiveRes] = await Promise.all([
			businessApi.product.list.get({ page_size: 1 }),
			businessApi.product.list.get({ page_size: 1, is_online: 1 }),
			businessApi.product.list.get({ page_size: 1, is_online: 0 }),
			businessApi.product.list.get({ page_size: 1, is_active: 0 }),
		])
		if (statusFilters.value[0]) statusFilters.value[0].count = allRes.data?.total ?? 0
		if (statusFilters.value[1]) statusFilters.value[1].count = onlineRes.data?.total ?? 0
		if (statusFilters.value[2]) statusFilters.value[2].count = offlineRes.data?.total ?? 0
		if (statusFilters.value[3]) statusFilters.value[3].count = inactiveRes.data?.total ?? 0
	} catch {}
}

const selectStatusFilter = (key) => {
	activeStatusFilter.value = key
	if (key === '') {
		activeMainId.value = null
		activeSubId.value = null
	}
	searchForm.id = null
	searchForm.keyword = ''
	searchForm.spec = ''
	searchForm.barcode_small = ''
	searchForm.is_online = key === 'online' ? 1 : key === 'offline' ? 0 : null
	searchForm.is_active = key === 'inactive' ? 0 : null
	searchForm.main_category_id = null
	searchForm.sub_category_id = null
	search()
}

// ---- 分类选择（左/中栏点选）----
const selectMain = (cat) => {
	activeMainId.value = cat.id
	activeSubId.value = null
	activeStatusFilter.value = ''
	searchForm.main_category_id = cat.id
	searchForm.sub_category_id = null
	searchForm.id = null
	searchForm.keyword = ''
	searchForm.spec = ''
	searchForm.barcode_small = ''
	searchForm.is_online = null
	searchForm.is_active = null
	if (isMobile.value) leftCollapsed.value = !leftCollapsed.value
	search()
}

const selectSub = (mainId, sub) => {
	activeMainId.value = mainId
	activeSubId.value = sub.id
	activeStatusFilter.value = ''
	searchForm.main_category_id = mainId
	searchForm.sub_category_id = sub.id
	searchForm.id = null
	searchForm.keyword = ''
	searchForm.spec = ''
	searchForm.barcode_small = ''
	searchForm.is_online = null
	searchForm.is_active = null
	if (isMobile.value) midCollapsed.value = !midCollapsed.value
	search()
}

// ---- 分类选择（搜索栏下拉）----
const handleMainFromSelect = (mainId) => {
	searchForm.sub_category_id = null
	if (mainId) {
		// 下拉选中同步高亮左/中栏，保持三栏视觉一致
		const cat = mainCategories.value.find(c => c.id === mainId)
		if (cat) {
			activeMainId.value = mainId
			activeSubId.value = null
			activeStatusFilter.value = ''
			if (!cat._expanded) cat._expanded = true
		}
	} else {
		activeMainId.value = null
		activeSubId.value = null
	}
	search()
}

const handleSubFromSelect = (subId) => {
	if (subId) {
		activeMainId.value = searchForm.main_category_id
		activeSubId.value = subId
		activeStatusFilter.value = ''
	} else {
		activeSubId.value = null
	}
	search()
}

// ---- 加载分类（计数随产品增删改实时刷新）----
const loadCategories = async () => {
	const prevMain = activeMainId.value
	const prevSub = activeSubId.value
	const res = await businessApi.product.categories.get()
	if (res.code !== 200) return
	const prevExpanded = mainCategories.value.filter(c => c._expanded).map(c => c.id)
	mainCategories.value = (res.data || []).map(c => ({ ...c, _expanded: prevExpanded.includes(c.id) }))
	await nextTick()
	validateSelection(prevMain, prevSub)
}

// ---- 商品列表 ----
const searchFormRef = ref(null)

const { tableRef, data: tableData, total, loading, selectedRows, paginationProps, refresh, search, resetSearch, handlePageChange, handlePageSizeChange, onSelectionChange, searchForm } = useTable({
	apiObj: { get: (params) => businessApi.product.list.get(params) },
})

const showSearch = ref(false)

// ---- 列定义（与旧系统一致） ----
const columns = [
	{ type: 'checkbox', width: 48, fixed: 'left' },
	{ prop: 'image', title: '图片', width: 56, slots: { default: 'image' } },
	{ prop: 'name', title: '名称', width: 180, showOverflowTooltip: true, slots: { default: 'name' } },
	{ prop: 'spec_display', title: '规格', width: 100, showOverflowTooltip: true },
	{ prop: 'conversion_display', title: '整零转换', width: 160, showOverflowTooltip: true },
	{ prop: 'barcode_small', title: '条码', width: 110, slots: { default: 'barcode_small' } },
	{ prop: 'prices', title: '标准价', width: 90, slots: { default: 'prices' } },
	{ prop: 'shelf_life_days', title: '保质', width: 48 },
	{ prop: 'is_online', title: '上架', width: 56, slots: { default: 'is_online' } },
	{ prop: 'is_active', title: '状态', width: 56, slots: { default: 'is_active' } },
	{ prop: 'ops', title: '操作', width: 100, fixed: 'right', slots: { default: 'ops' } },
]

// ---- 产品对话框 ----
const dialog = reactive({ product: false, category: false })
const currentProduct = ref(null)

const flatCategories = computed(() => {
	const result = []
	mainCategories.value.forEach(m => {
		result.push({ id: m.id, name: m.name, is_main: true })
		m.children?.forEach(s => result.push({ id: s.id, name: s.name, is_main: false, parent_id: m.id }))
	})
	return result
})

const handleAdd = () => {
	if (!activeMainId.value) {
		ElMessage.warning('请先选择一个主分类')
		return
	}
	if (!activeSubId.value) {
		ElMessage.warning('请先选择一个副分类')
		return
	}
	currentProduct.value = null
	dialog.product = true
}

const handleEdit = (row) => {
	ctxMenu.visible = false
	currentProduct.value = row
	dialog.product = true
}

const handleCopy = (row) => {
	const copy = { ...row, id: null, code: null, external_id: null }
	delete copy.id
	delete copy.code
	delete copy.external_id
	delete copy.created_at
	delete copy.updated_at
	currentProduct.value = copy
	dialog.product = true
}

const handleDelete = async (row) => {
	await ElMessageBox.confirm('确定删除该产品吗？', '提示', { type: 'warning' })
	const res = await businessApi.product.delete.delete(row.id)
	if (res.code === 200) { ElMessage.success('删除成功'); refreshProduct() }
}

const handleToggleOnline = async (row) => {
	const newOnline = row.is_online ? 0 : 1
	const res = await businessApi.product.edit.put(row.id, { ...row, is_online: newOnline })
	if (res.code === 200) { row.is_online = newOnline; refreshProduct() }
}

const handleProductSuccess = () => { refreshProduct(); loadStatusCounts() }

// ---- 批量操作 ----
const handleBatchStatus = async (status) => {
	const res = await businessApi.product.batchUpdateStatus.post({
		ids: selectedRows.value.map(r => r.id),
		is_active: status
	})
	if (res.code === 200) {
		ElMessage.success('操作成功')
		selectedRows.value = []
		tableRef.value?.clearCheckboxRow?.()
		refreshProduct()
	}
}

const handleBatchDelete = async () => {
	await ElMessageBox.confirm('确定删除选中的产品吗？', '提示', { type: 'warning' })
	const res = await businessApi.product.batchDelete.post({
		ids: selectedRows.value.map(r => r.id)
	})
	if (res.code === 200) {
		ElMessage.success('删除成功')
		selectedRows.value = []
		tableRef.value?.clearCheckboxRow?.()
		refreshProduct()
	}
}

// 产品列表 + 分类计数一并刷新（计数接口实时统计，随产品增删改保持同步）
const refreshProduct = () => { refresh(); loadCategories() }

// ---- 分类对话框 ----
const currentCategory = ref(null)

const handleAddMain = () => {
	ctxMenu.visible = false
	currentCategory.value = { name: '', is_main: true, parent_id: null }
	dialog.category = true
}

const handleAddSub = () => {
	ctxMenu.visible = false
	if (!currentMainCat.value) return
	currentCategory.value = { name: '', is_main: false, parent_id: currentMainCat.value.id, main_cat_name: currentMainCat.value.name }
	dialog.category = true
}

const handleAddSubTarget = (mainCat) => {
	ctxMenu.visible = false
	currentCategory.value = { name: '', is_main: false, parent_id: mainCat.id, main_cat_name: mainCat.name }
	dialog.category = true
}

const handleRenameMain = async (cat) => {
	ctxMenu.visible = false
	const { value: newName } = await ElMessageBox.prompt('请输入新的分类名称', '修改分类名称', {
		confirmButtonText: '确定', cancelButtonText: '取消', inputValue: cat.name,
		inputValidator: v => v ? true : '分类名称不能为空'
	})
	if (!newName) return
	const res = await businessApi.product.category.edit.put(cat.id, { name: newName })
	if (res.code === 200) { ElMessage.success('修改成功'); handleCategorySuccess() }
}

const handleRenameSub = async (sub) => {
	ctxMenu.visible = false
	const { value: newName } = await ElMessageBox.prompt('请输入新的分类名称', '修改分类名称', {
		confirmButtonText: '确定', cancelButtonText: '取消', inputValue: sub.name,
		inputValidator: v => v ? true : '分类名称不能为空'
	})
	if (!newName) return
	const res = await businessApi.product.category.edit.put(sub.id, { name: newName })
	if (res.code === 200) { ElMessage.success('修改成功'); handleCategorySuccess() }
}

const handleDeleteMain = async (cat) => {
	ctxMenu.visible = false
	await ElMessageBox.confirm(`确定删除主分类"${cat.name}"吗？其下所有商品分类标记将被清空。`, '提示', { type: 'warning' })
	const res = await businessApi.product.category.delete.delete(cat.id)
	if (res.code === 200) { ElMessage.success('删除成功'); handleCategorySuccess() }
}

const handleDeleteSub = async (sub) => {
	ctxMenu.visible = false
	await ElMessageBox.confirm(`确定删除副分类"${sub.name}"吗？其下商品将归入主分类/未分类。`, '提示', { type: 'warning' })
	const res = await businessApi.product.category.delete.delete(sub.id)
	if (res.code === 200) { ElMessage.success('删除成功'); handleCategorySuccess() }
}

const handleCategorySuccess = () => {
	refreshProduct()
	loadStatusCounts()
}

// 分类增删改后，校验当前选中的分类是否仍存在
const validateSelection = (prevMainId, prevSubId) => {
	const stillExists = (id) => mainCategories.value.some(c =>
		c.id === id || (c.children || []).some(s => s.id === id)
	)
	if (prevMainId && !stillExists(prevMainId)) {
		activeMainId.value = null
		activeSubId.value = null
		searchForm.main_category_id = null
		searchForm.sub_category_id = null
		refresh()
		return true
	}
	if (prevSubId && !stillExists(prevSubId)) {
		activeSubId.value = null
		searchForm.sub_category_id = null
		refresh()
		return true
	}
	return false
}

// ---- 右键菜单 ----
const ctxMenu = reactive({ visible: false, x: 0, y: 0, type: '', data: null })

const showMainContext = (event, cat) => {
	ctxMenu.visible = false
	nextTick(() => {
		ctxMenu.x = event.clientX
		ctxMenu.y = event.clientY
		ctxMenu.type = 'main'
		ctxMenu.data = cat
		ctxMenu.visible = true
	})
}

const showSubContext = (event, mainCat, sub) => {
	ctxMenu.visible = false
	nextTick(() => {
		ctxMenu.x = event.clientX
		ctxMenu.y = event.clientY
		ctxMenu.type = 'sub'
		ctxMenu.data = { ...sub, _mainCat: mainCat }
		ctxMenu.visible = true
	})
}

const hideContextMenu = () => { ctxMenu.visible = false }

// 表格行右键：通过 sTable 的 contextmenu 事件触发
const handleRowContext = (event, { row }) => {
	ctxMenu.visible = false
	nextTick(() => {
		ctxMenu.x = event.clientX
		ctxMenu.y = event.clientY
		ctxMenu.type = 'product'
		ctxMenu.data = row
		ctxMenu.visible = true
	})
}

const formatMoney = (val) => val ? '¥' + Number(val).toFixed(2) : '-'
const formatUnitConversion = (row) => {
	if (!row.unit_conversion) return '-'
	const large = parseInt(row.unit_conversion)
	if (large <= 0) return row.unit_conversion

	const ucm = parseFloat(row.unit_conversion_medium)
	const unitLarge = row.price_unit || '件'
	const unitMedium = row.barcode_medium_unit || '盒'
	const unitSmall = row.price_unit_small || '个'

	const parts = []

	if (ucm > 0 && ucm < large) {
		// 有中单位：1件=120瓶=480个
		// TEMP: force rebuild
	// FIX_VER: 20260905-3-build-test
	const largeToMedium = ucm > 0 && large > 0 ? Math.round(large / ucm) : 0;
		parts.push(`1${unitLarge}=${largeToMedium}${unitMedium}=${large}${unitSmall}`)
	} else {
		// 无中单位：1件=480个
		parts.push(`1${unitLarge}=${large}${unitSmall}`)
	}

	return parts.join(' ')
}

onMounted(() => {
	loadCategories()
	loadStatusCounts()
	refresh()
})
</script>

<style scoped>
.product-page {
	display: flex;
	height: 100%;
	overflow: hidden;
	gap: 1px;
	background: var(--el-border-color-lighter);
}

/* ---- 面板通用 ---- */
.panel-left, .panel-mid {
	flex-shrink: 0;
	display: flex;
	flex-direction: column;
	background: var(--el-bg-color);
	overflow: hidden;
}
.panel-left { width: 240px; }
.panel-mid { width: 200px; }

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
.panel-body::-webkit-scrollbar { width: 4px; }
.panel-body::-webkit-scrollbar-thumb { background: var(--el-border-color); border-radius: 2px; }

/* ---- 快捷状态筛选 ---- */
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
.qf-item:hover { border-color: var(--el-color-primary); color: var(--el-color-primary); }
.qf-item.active { background: var(--el-color-primary); color: #fff; border-color: var(--el-color-primary); }
.qf-count { margin-left: 1px; opacity: 0.7; }

/* ---- 主分类列表 ---- */
.main-list { }
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
.main-row:hover { background: var(--el-fill-color-light); }
.main-row.active { background: var(--el-color-primary-light-9); color: var(--el-color-primary); font-weight: 500; }
.main-icon { flex-shrink: 0; font-size: 14px; }
.main-name { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.main-count { font-size: 11px; color: var(--el-text-color-secondary); flex-shrink: 0; }
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
.main-expand.rotated { transform: translateY(-50%) rotate(180deg); }

/* 内嵌副分类 */
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
.sub-inline-item:hover { background: var(--el-fill-color-light); color: var(--el-text-color-regular); }
.sub-inline-item.active { background: var(--el-color-primary-light-9); color: var(--el-color-primary); font-weight: 500; }
.sub-dot { width: 5px; height: 5px; border-radius: 50%; background: var(--el-text-color-placeholder); flex-shrink: 0; }
.sub-inline-item.active .sub-dot { background: var(--el-color-primary); }
.sub-count { margin-left: auto; font-size: 10px; }

/* ---- 中栏副分类列表 ---- */
.sub-list { }
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
.sub-row:hover { background: var(--el-fill-color-light); }
.sub-row.active { background: var(--el-color-primary-light-9); color: var(--el-color-primary); font-weight: 500; }
.sub-dot { width: 6px; height: 6px; border-radius: 50%; background: var(--el-text-color-placeholder); flex-shrink: 0; }
.sub-row.active .sub-dot { background: var(--el-color-primary); }
.sub-name { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.sub-count { font-size: 11px; color: var(--el-text-color-placeholder); flex-shrink: 0; }
.sub-empty { padding: 16px; }
.sub-placeholder {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	padding: 40px 16px;
	color: var(--el-text-color-placeholder);
	gap: 8px;
}
.sub-placeholder p { font-size: 12px; margin: 0; }

/* ---- 右栏 ---- */
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
.left-tools { display: flex; gap: 6px; align-items: center; }
.right-tools { display: flex; gap: 6px; align-items: center; }
.search-box { margin: 0; }
.search-box :deep(.el-form-item) { margin: 0; }
.search-box :deep(.el-form-item__label) { display: none; }
.search-form {
	display: flex;
	align-items: center;
	gap: 4px;
	padding: 8px 12px;
	border-bottom: 1px solid var(--el-border-color-lighter);
	flex-shrink: 0;
	background: var(--el-fill-color-blank);
	flex-wrap: wrap;
}
.search-form :deep(.el-form-item) { margin-bottom: 0; margin-right: 4px; }
.text-muted { color: var(--el-text-color-placeholder); }

/* ---- 产品表格样式 ---- */
.prod-img { width: 40px; height: 40px; }
.prod-name { font-weight: 500; }
.price-block { line-height: 1.4; }
.price-main { font-weight: 600; font-size: 13px; color: #333; }
.price-sub { font-size: 11px; color: #999; margin-top: 1px; }

/* ---- 右键菜单 ---- */
.ctx-menu {
	position: fixed;
	z-index: 99999;
	background: #fff;
	border: 1px solid #d9d9d9;
	border-radius: 4px;
	box-shadow: 0 2px 8px rgba(0,0,0,.15);
	min-width: 140px;
	padding: 4px 0;
}
.ctx-menu > div {
  padding: 8px 16px;
  cursor: pointer;
  font-size: 13px;
  color: #333;
  transition: background .15s;
}

/* ---- 操作图标 ---- */
.ops-icons {
	display: flex;
	align-items: center;
	gap: 4px;
	justify-content: center;
}
.ops-icons .el-button {
	padding: 4px;
	margin: 0;
}
@media (max-width: 768px) {
  .panel-left.is-collapsed { width: 48px !important; }
  .panel-mid.is-collapsed { width: 48px !important; }

  /* 隐藏标题文字和按钮 */
  .panel-left.is-collapsed .panel-title span,
  .panel-left.is-collapsed .qf-item span,
  .panel-left.is-collapsed .qf-count,
  .panel-mid.is-collapsed .panel-title span {
    display: none;
  }

  /* 主分类列表 */
  .panel-left.is-collapsed .main-name,
  .panel-left.is-collapsed .main-count {
    display: none;
  }
  .panel-left.is-collapsed .main-row {
    justify-content: center;
    padding: 10px 4px;
  }
  .panel-left.is-collapsed .main-icon {
    margin: 0;
  }
  .panel-left.is-collapsed .main-expand {
    display: none;
  }

  /* 副分类列表 */
  .panel-mid.is-collapsed .sub-name,
  .panel-mid.is-collapsed .sub-count {
    display: none;
  }
  .panel-mid.is-collapsed .sub-row {
    justify-content: center;
    padding: 10px 4px;
  }
}
.ctx-menu > div:hover { background: #e8f0fe; color: #1a73e8; }
.ctx-menu .ctx-del { color: #f56c6c; }
.ctx-menu .ctx-del:hover { background: #fef0f0; }
.ctx-sep { height: 1px; background: #e8e8e8; margin: 4px 0; padding: 0; cursor: default; }
</style>
