<template>
	<div class="sTable" :style="{ height: _height }" ref="sTableMain" tabindex="0" @keydown="handleKeydown">
		<div class="sTable-table" :style="{ flex: 1, minHeight: 0 }">
			<vxe-grid
				v-bind="$attrs"
				ref="xGrid"
				:key="toggleIndex"
				:columns="renderColumns"
				:data="data"
				:height="height === 'auto' ? null : '100%'"
				:size="config.size"
				:border="config.border"
				:stripe="config.stripe"
				:loading="loading && !skeleton"
				:sort-config="sortConfig"
				:filter-config="filterConfig"
				:floating-filter-config="floatingFilterConfig"
				:row-config="rowConfig"
				:empty-text="emptyText"
				@sort-change="$emit('sortChange', $event)"
				@filter-change="$emit('filterChange', $event)"
				@checkbox-change="handleCheckboxChange"
				@checkbox-all="handleCheckboxChange"
			>
				<template #empty>
					<el-empty :description="emptyText" :image-size="100" />
				</template>
				<template v-for="(_, name) in gridSlots" :key="name" #[name]="slotProps">
					<slot :name="name" v-bind="slotProps ?? {}" />
				</template>
				<!-- 浮动筛选：按列 filter 配置自动渲染 -->
				<template v-for="fc in filterColumns" :key="fc.slotName" #[fc.slotName]>
					<!-- 文本输入 -->
					<el-input v-if="fc.type === 'input'" v-model="searchForm[fc.field]" :placeholder="fc.placeholder" clearable size="small" class="ff-input" @keyup.enter="$emit('search')" @clear="$emit('search')" />
					<!-- 下拉选择（使用 sSelect，支持静态选项/字典/接口） -->
					<s-select
						v-else-if="fc.type === 'select'"
						v-model="searchForm[fc.field]"
						:placeholder="fc.placeholder"
						:options="fc.options"
						:dict="fc.dict"
						:api-obj="fc.apiObj"
						:params="fc.params"
						:api-params="fc.apiParams"
						:filterable="fc.filterable"
						:search-key="fc.searchKey"
						clearable
						size="small"
						class="ff-input"
						@change="$emit('search')"
						@clear="$emit('search')"
					/>
					<!-- 数值输入 -->
					<el-input-number v-else-if="fc.type === 'number'" v-model="searchForm[fc.field]" :placeholder="fc.placeholder" :min="fc.min" :max="fc.max" :step="fc.step" :precision="fc.precision" controls-position="right" size="small" class="ff-input" @change="$emit('search')" />
					<!-- 数值范围 -->
					<div v-else-if="fc.type === 'numberrange'" class="ff-range">
						<el-input-number v-model="searchForm[fc.startField]" :placeholder="'最小'" :min="fc.min" :max="fc.max" :step="fc.step" :precision="fc.precision" controls-position="right" size="small" class="ff-range-input" @change="$emit('search')" />
						<span class="ff-range-sep">~</span>
						<el-input-number v-model="searchForm[fc.endField]" :placeholder="'最大'" :min="fc.min" :max="fc.max" :step="fc.step" :precision="fc.precision" controls-position="right" size="small" class="ff-range-input" @change="$emit('search')" />
					</div>
					<!-- 日期/日期时间（单值） -->
					<el-date-picker v-else-if="fc.isSingleDate" v-model="searchForm[fc.field]" :type="fc.dateType" :placeholder="fc.placeholder" :value-format="fc.valueFormat" :format="fc.format" clearable size="small" class="ff-input" @change="$emit('search')" />
					<!-- 日期/日期时间（范围） -->
					<el-date-picker
						v-else-if="fc.isRangeDate"
						:model-value="getRangeValue(fc)"
						:type="fc.dateType"
						range-separator="至"
						start-placeholder="开始"
						end-placeholder="结束"
						:value-format="fc.valueFormat"
						:format="fc.format"
						clearable
						size="small"
						class="ff-input"
						@change="onRangeChange(fc, $event)"
					/>
				</template>
			</vxe-grid>
			<!-- 首屏骨架屏：API 慢/超时时第一屏先渲染「表头 + 占位行」，杜绝长时间白屏。
			     仅在 skeleton 开启且 loading 时显示；用绝对定位盖住表格，loading 结束即消失。 -->
			<div v-if="skeleton && loading" class="skeleton-mask">
				<div class="skeleton-head">
					<div
						v-for="(col, i) in skeletonColumns"
						:key="'h' + i"
						class="skeleton-cell"
						:style="{ width: col.width }"
					>
						<span class="skeleton-bar" style="width: 45%"></span>
					</div>
				</div>
				<div
					v-for="row in skeletonRows"
					:key="'r' + row"
					class="skeleton-row"
				>
					<div
						v-for="(col, i) in skeletonColumns"
						:key="'c' + row + i"
						class="skeleton-cell"
						:style="{ width: col.width, textAlign: col.align || 'left' }"
					>
						<span
							class="skeleton-bar"
							:style="{
								width: skeletonBarWidth(col),
							}"
						></span>
					</div>
				</div>
			</div>
		</div>
		<div class="sTable-page" v-if="!hidePagination || !hideDo">
			<div class="sTable-pagination">
				<el-pagination v-if="!hidePagination" background size="small" :layout="mobileLayout" :total="total" :page-size="pageSize" :page-sizes="pageSizes" :current-page="currentPage" @current-change="$emit('pageChange', $event)" @update:page-size="$emit('pageSizeChange', $event)" />
			</div>
			<div class="sTable-do" v-if="!hideDo">
				<el-button v-if="!hideRefresh" @click="$emit('refresh')" circle title="刷新">
					<el-icon><ElIconRefresh /></el-icon>
				</el-button>
				<!-- 列设置 -->
				<el-popover v-if="columnSettingVisible" placement="top" :width="380" trigger="click" :hide-after="0" @show="columnPanelOpen = true" @hide="columnPanelOpen = false">
					<template #reference>
						<el-button circle title="列设置" style="margin-left: 12px">
							<el-icon><ElIconGrid /></el-icon>
						</el-button>
					</template>
					<div class="column-panel" v-if="columnPanelOpen">
						<div class="column-panel-header">
							<span class="column-panel-title">列设置</span>
							<el-button size="small" link @click="resetColumnConfig">重置</el-button>
						</div>
						<div class="column-panel-list">
							<div v-for="(col, idx) in columnConfig" :key="col._key" class="column-panel-item" :class="{ 'is-fixed': col.fixed }" draggable="true" @dragstart="onDragStart(idx, $event)" @dragover.prevent="onDragOver(idx)" @dragend="onDragEnd">
								<el-icon class="column-panel-drag"><ElIconRank /></el-icon>
								<el-checkbox v-model="col.visible" @change="applyColumnConfig" />
								<span class="column-panel-label">{{ col.title }}</span>
								<el-button-group class="column-panel-fixed">
									<el-button size="small" :class="{ 'is-active': col.fixed === 'left' }" @click="toggleFixed(col, 'left')" title="固定到左侧">
										<el-icon><ElIconArrowLeft /></el-icon>
									</el-button>
									<el-button size="small" :class="{ 'is-active': !col.fixed }" @click="toggleFixed(col, null)" title="不固定">
										<el-icon><ElIconMinus /></el-icon>
									</el-button>
									<el-button size="small" :class="{ 'is-active': col.fixed === 'right' }" @click="toggleFixed(col, 'right')" title="固定到右侧">
										<el-icon><ElIconArrowRight /></el-icon>
									</el-button>
								</el-button-group>
							</div>
						</div>
					</div>
				</el-popover>
				<!-- 表格设置（尺寸/边框/斑马纹） -->
				<el-popover v-if="!hideSetting" placement="top" :title="t('table.tableSettings')" :width="400" trigger="click" :hide-after="0">
					<template #reference>
						<el-button circle title="表格设置" style="margin-left: 12px">
							<el-icon><ElIconSetting /></el-icon>
						</el-button>
					</template>
					<el-form label-width="80px" label-position="left">
						<el-form-item :label="t('table.tableSize')">
							<el-radio-group v-model="config.size" size="small" @change="handleConfigChange">
								<el-radio-button value="medium">{{ t('table.sizeLarge') }}</el-radio-button>
								<el-radio-button value="default">{{ t('table.sizeDefault') }}</el-radio-button>
								<el-radio-button value="small">{{ t('table.sizeSmall') }}</el-radio-button>
							</el-radio-group>
						</el-form-item>
						<el-form-item :label="t('table.style')">
							<el-checkbox v-model="config.border" :label="t('table.borderVertical')" />
							<el-checkbox v-model="config.stripe" :label="t('table.stripe')" />
						</el-form-item>
					</el-form>
				</el-popover>
			</div>
		</div>
	</div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onActivated, onDeactivated, nextTick, useSlots } from 'vue'
import { useEventListener } from '@vueuse/core'
import { useI18n } from 'vue-i18n'
import { useResponsive } from '../../hooks/useResponsive'

const { isMobile } = useResponsive()
const { t } = useI18n()
const slots = useSlots()

const gridSlots = computed(() => {
	const result = {}
	for (const name in slots) {
		result[name] = slots[name]
	}
	return result
})

const props = defineProps({
	tableName: { type: String, default: '' },
	data: { type: Array, default: () => [] },
	columns: { type: Array, default: () => [] },
	searchForm: { type: Object, default: () => ({}) },
	height: { type: [String, Number], default: '100%' },
	size: { type: String, default: 'default' },
	border: { type: Boolean, default: false },
	stripe: { type: Boolean, default: false },
	loading: { type: Boolean, default: false },
	skeleton: { type: Boolean, default: false },
	emptyText: { type: String, default: '暂无数据' },
	total: { type: Number, default: 0 },
	currentPage: { type: Number, default: 1 },
	pageSize: { type: Number, default: 10 },
	pageSizes: { type: Array, default: () => [10, 20, 50, 100] },
	rowKey: { type: String, default: 'id' },
	remoteSort: { type: Boolean, default: false },
	remoteFilter: { type: Boolean, default: false },
	hidePagination: { type: Boolean, default: false },
	hideDo: { type: Boolean, default: false },
	hideRefresh: { type: Boolean, default: false },
	hideSetting: { type: Boolean, default: false },
	paginationLayout: { type: String, default: 'total, prev, pager, next, jumper, sizes' },
})

const emit = defineEmits(['refresh', 'pageChange', 'pageSizeChange', 'sortChange', 'filterChange', 'selectionChange', 'search'])

const mobileLayout = computed(() => (isMobile.value ? 'total, prev, next' : props.paginationLayout))

const xGrid = ref(null)
const sTableMain = ref(null)
const toggleIndex = ref(0)
const isActivated = ref(true)

const config = ref({
	size: props.size,
	border: props.border,
	stripe: props.stripe,
})

// ==================== 一、横向滚动增强 ====================

const SCROLL_STEP = 120

function handleKeydown(e) {
	if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
		e.preventDefault()
		scrollHorizontal(e.key === 'ArrowLeft' ? -SCROLL_STEP : SCROLL_STEP)
	}
}

function handleWheel(e) {
	if (e.altKey) {
		e.preventDefault()
		scrollHorizontal(e.deltaY > 0 ? SCROLL_STEP : -SCROLL_STEP)
	}
}

async function scrollHorizontal(offset) {
	const grid = xGrid.value
	if (!grid) return
	try {
		const pos = await grid.getScroll()
		grid.scrollTo((pos.scrollLeft || 0) + offset, pos.scrollTop || 0)
	} catch {
		const el = grid.$el?.querySelector('.vxe-table--body-inner-wrapper') || grid.$el?.querySelector('.vxe-table--body-wrapper')
		if (el) el.scrollLeft += offset
	}
}

// ==================== 二、列配置功能 ====================

const columnPanelOpen = ref(false)
const columnConfig = ref([])
const dragIndex = ref(null)

const columnSettingVisible = computed(() => {
	return props.columns.some((col) => !col.type)
})

const STORAGE_KEY = computed(() => {
	return props.tableName ? `sTable_cols_${props.tableName}` : ''
})

function initColumnConfig() {
	const saved = loadColumnConfigFromStorage()
	if (saved && saved.length > 0) {
		columnConfig.value = mergeColumnConfig(props.columns, saved)
	} else {
		columnConfig.value = buildColumnConfig(props.columns)
	}
}

function buildColumnConfig(columns) {
	return columns.map((col, idx) => ({
		_key: col.type ? `__type_${col.type}_${idx}` : col.prop || col.field || `__col_${idx}`,
		prop: col.prop || col.field || '',
		title: col.label || col.title || '',
		visible: col.hidden !== true,
		fixed: col.fixed || null,
		originalIndex: idx,
	}))
}

function mergeColumnConfig(columns, saved) {
	const built = buildColumnConfig(columns)
	const result = []
	const usedKeys = new Set()
	for (const s of saved) {
		const match = built.find((b) => b._key === s._key)
		if (match) {
			result.push({ ...match, visible: s.visible, fixed: s.fixed ?? match.fixed })
			usedKeys.add(s._key)
		}
	}
	for (const b of built) {
		if (!usedKeys.has(b._key)) {
			result.push(b)
		}
	}
	return result
}

function loadColumnConfigFromStorage() {
	if (!STORAGE_KEY.value) return null
	try {
		const raw = localStorage.getItem(STORAGE_KEY.value)
		return raw ? JSON.parse(raw) : null
	} catch {
		return null
	}
}

function saveColumnConfigToStorage() {
	if (!STORAGE_KEY.value) return
	try {
		localStorage.setItem(STORAGE_KEY.value, JSON.stringify(columnConfig.value.map((c) => ({ _key: c._key, visible: c.visible, fixed: c.fixed }))))
	} catch {
		/* ignore */
	}
}

function applyColumnConfig() {
	toggleIndex.value++
	saveColumnConfigToStorage()
}

function resetColumnConfig() {
	columnConfig.value = buildColumnConfig(props.columns)
	localStorage.removeItem(STORAGE_KEY.value)
	toggleIndex.value++
}

function toggleFixed(col, value) {
	col.fixed = col.fixed === value ? null : value
	applyColumnConfig()
}

function onDragStart(idx, e) {
	dragIndex.value = idx
	e.dataTransfer.effectAllowed = 'move'
}

function onDragOver(idx) {
	if (dragIndex.value === null || dragIndex.value === idx) return
	const list = [...columnConfig.value]
	const [item] = list.splice(dragIndex.value, 1)
	list.splice(idx, 0, item)
	columnConfig.value = list
	dragIndex.value = idx
}

function onDragEnd() {
	dragIndex.value = null
	applyColumnConfig()
}

const configMap = computed(() => {
	const map = new Map()
	for (const cfg of columnConfig.value) {
		map.set(cfg._key, cfg)
	}
	return map
})

// 归一化列的 filter 配置：true/"input" => { type:'input' }，对象原样返回
function normalizeFilter(filter) {
	if (!filter) return null
	if (filter === true) return { type: 'input' }
	if (typeof filter === 'string') return { type: filter }
	return filter
}

// 日期类型集合（对应 el-date-picker 的 type）
const SINGLE_DATE_TYPES = new Set(['date', 'datetime', 'month', 'year', 'week', 'dates'])
const RANGE_DATE_TYPES = new Set(['daterange', 'datetimerange', 'monthrange', 'quartersrange'])
const RANGE_VALUE_TYPES = new Set(['daterange', 'datetimerange', 'monthrange', 'numberrange'])

// 默认日期格式
function pickValueFormat(type, custom) {
	if (custom) return custom
	if (type === 'datetime' || type === 'datetimerange') return 'YYYY-MM-DD HH:mm:ss'
	if (type === 'month' || type === 'monthrange') return 'YYYY-MM'
	if (type === 'year') return 'YYYY'
	if (type === 'quarter' || type === 'quartersrange') return 'YYYY-MM'
	return 'YYYY-MM-DD'
}

// 所有声明了 filter 的列，用于自动渲染浮动筛选输入框
const filterColumns = computed(() => {
	const result = []
	for (const col of props.columns) {
		const f = normalizeFilter(col.filter)
		const field = col.prop || col.field
		if (!f || !field) continue
		const actualField = f.field || field
		const type = f.type || 'input'
		const isRangeDate = RANGE_DATE_TYPES.has(type)
		const isSingleDate = SINGLE_DATE_TYPES.has(type)
		const isRangeValue = RANGE_VALUE_TYPES.has(type)
		result.push({
			field: actualField,
			slotName: `__ff_${field}`,
			type,
			placeholder: f.placeholder || col.label || col.title || '',
			// select 通用
			options: f.options || [],
			dict: f.dict || '',
			apiObj: f.apiObj || null,
			params: f.params || {},
			apiParams: f.apiParams || {},
			filterable: !!f.filterable,
			searchKey: f.searchKey || 'keyword',
			// number
			min: f.min,
			max: f.max,
			step: f.step ?? 1,
			precision: f.precision,
			// date
			dateType: type,
			isSingleDate,
			isRangeDate,
			valueFormat: pickValueFormat(type, f.valueFormat),
			format: f.format || '',
			// range
			startField: f.startField || (isRangeValue ? `${actualField}_start` : ''),
			endField: f.endField || (isRangeValue ? `${actualField}_end` : ''),
		})
	}
	return result
})

const hasFilter = computed(() => filterColumns.value.length > 0)

// 获取范围筛选的当前值（数组形式，供 el-date-picker 显示）
function getRangeValue(fc) {
	const s = props.searchForm?.[fc.startField]
	const e = props.searchForm?.[fc.endField]
	return s || e ? [s ?? null, e ?? null] : null
}

// 处理范围筛选变更：拆分到两个字段
function onRangeChange(fc, val) {
	const [start, end] = Array.isArray(val) ? val : [null, null]
	if (props.searchForm) {
		props.searchForm[fc.startField] = start ?? null
		props.searchForm[fc.endField] = end ?? null
	}
	emit('search')
}

const renderColumns = computed(() => {
	const cfgMap = configMap.value

	return props.columns
		.map((col, idx) => {
			const key = col.type ? `__type_${col.type}_${idx}` : col.prop || col.field || `__col_${idx}`
			return { col, key }
		})
		.filter(({ key }) => {
			const cfg = cfgMap.get(key)
			return cfg ? cfg.visible : true
		})
		.sort((a, b) => {
			const ca = cfgMap.get(a.key)
			const cb = cfgMap.get(b.key)
			const oa = ca ? columnConfig.value.indexOf(ca) : 999
			const ob = cb ? columnConfig.value.indexOf(cb) : 999
			return oa - ob
		})
		.map(({ col, key }) => {
			const cfg = cfgMap.get(key)
			const { prop, label, width, minWidth, fixed, sortable, filters, filter, align, headerAlign, showOverflowTooltip, slots: colSlots, ...rest } = col
			const result = {
				field: prop,
				title: label,
				width,
				minWidth,
				fixed: cfg?.fixed ?? fixed,
				sortable,
				align,
				headerAlign,
				showOverflow: showOverflowTooltip ?? true,
			}
			if (col.type) result.type = col.type
			// 合并 slots：保留使用方自定义插槽，并自动注入浮动筛选插槽
			const mergedSlots = colSlots ? { ...colSlots } : {}
			if (normalizeFilter(filter) && prop) {
				result.floatingFilters = true
				mergedSlots.floatingFilter = `__ff_${prop}`
			}
			if (Object.keys(mergedSlots).length) result.slots = mergedSlots
			if (filters) result.filters = filters
			return { ...result, ...rest }
		})
})

// ==================== 骨架屏（首屏加载占位） ====================
// 取可见列（过滤隐藏列）作为骨架列：宽度/对齐与真实表头一致，
// 让骨架形状贴近真实表格，避免首屏闪烁感。
const skeletonColumns = computed(() => {
	return renderColumns.value
		.filter((col) => !col.type) // 跳过 checkbox 等类型列
		.map((col) => ({
			width: (col.width || 100) + 'px',
			align: col.align || 'left',
		}))
})

// 占位行数：跟随分页 pageSize，最多 12 行，最少 5 行（首屏视觉饱满）
const skeletonRows = computed(() => {
	const n = Number(props.pageSize) || 10
	return Math.min(12, Math.max(5, n))
})

function skeletonBarWidth(col) {
	// 固定列按列宽一半的条，避免撑破；对齐列给较窄条，模拟文字
	return col.align === 'right' ? '50%' : col.align === 'center' ? '38%' : '58%'
}

// ==================== 三、表格配置 ====================

const _height = computed(() => {
	return Number(props.height) ? Number(props.height) + 'px' : props.height
})

const sortConfig = computed(() => ({
	remote: props.remoteSort,
	showIcon: true,
	allowClear: true,
}))

const filterConfig = computed(() => ({
	remote: props.remoteFilter,
	destroyOnClose: true,
	multiple: true,
}))

const floatingFilterConfig = computed(() => (hasFilter.value ? { enabled: true } : null))

const rowConfig = computed(() => ({
	keyField: props.rowKey,
	isHover: true,
}))

function handleCheckboxChange() {
	emit('selectionChange', xGrid.value?.getCheckboxRecords?.() || [])
}

function handleConfigChange() {
	nextTick(() => {
		if (xGrid.value) xGrid.value.refreshColumn?.()
	})
}

// ==================== 四、方法转发 ====================

function clearCheckboxRow() {
	xGrid.value?.clearCheckboxRow?.()
}
function getCheckboxRecords() {
	return xGrid.value?.getCheckboxRecords?.() || []
}
function clearSort() {
	xGrid.value?.clearSort?.()
}
function clearFilter() {
	xGrid.value?.clearFilter?.()
}
function setCurrentRow(row) {
	xGrid.value?.setCurrentRow?.(row)
}
function getCurrentRow() {
	return xGrid.value?.getCurrentRow?.()
}
function toggleCheckboxRow(row, checked) {
	xGrid.value?.toggleCheckboxRow?.(row, checked)
}
function scrollTo(left, top) {
	xGrid.value?.scrollTo?.(left, top)
}
function getGridInstance() {
	return xGrid.value
}

// Row helpers (direct data mutation, parent owns the data)
function unshiftRow(row) {
	props.data.unshift(row)
}
function pushRow(row) {
	props.data.push(row)
}
function updateKey(row, key = props.rowKey) {
	props.data.filter((item) => item[key] === row[key]).forEach((item) => Object.assign(item, row))
}
function updateIndex(row, index) {
	Object.assign(props.data[index], row)
}
function removeIndex(index) {
	props.data.splice(index, 1)
}
function removeKey(key, keyField = props.rowKey) {
	const idx = props.data.findIndex((item) => item[keyField] === key)
	if (idx > -1) props.data.splice(idx, 1)
}
function removeKeys(keys = [], keyField = props.rowKey) {
	keys.forEach((key) => {
		const idx = props.data.findIndex((item) => item[keyField] === key)
		if (idx > -1) props.data.splice(idx, 1)
	})
}

// Watchers
watch(
	() => props.columns,
	() => {
		initColumnConfig()
	},
	{ deep: true },
)

useEventListener(sTableMain, 'wheel', handleWheel, { passive: false })

// Lifecycle
onMounted(() => {
	initColumnConfig()
})

onActivated(() => {
	if (!isActivated.value) {
		nextTick(() => {
			if (xGrid.value) xGrid.value.refreshColumn?.()
		})
	}
})

onDeactivated(() => {
	isActivated.value = false
})

defineExpose({
	clearCheckboxRow,
	getCheckboxRecords,
	clearSort,
	clearFilter,
	setCurrentRow,
	getCurrentRow,
	toggleCheckboxRow,
	scrollTo,
	getGridInstance,
	unshiftRow,
	pushRow,
	updateKey,
	updateIndex,
	removeIndex,
	removeKey,
	removeKeys,
})
</script>

<style scoped>
.sTable {
	display: flex;
	flex-direction: column;
	outline: none;
}

.sTable-table {
	flex: 1;
	min-height: 0;
	position: relative;
}

.sTable-page {
	height: 50px;
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 0 15px;
}

.sTable-do {
	white-space: nowrap;
}

/* 工具按钮强制保持正圆（覆盖 responsive.css 中 .el-button 的 min-height: 44px） */
.sTable-do :deep(.el-button.is-circle) {
	width: 32px;
	height: 32px;
	min-width: 32px;
	min-height: 32px;
	padding: 0;
	flex-shrink: 0;
	box-sizing: border-box;
}

.sTable-do :deep(.el-button.is-circle .el-icon) {
	font-size: inherit;
}

/* 骨架屏：绝对定位盖住表格区，行高对齐 vxe 默认 41px，避免真实行加载时跳动 */
.skeleton-mask {
	position: absolute;
	inset: 0;
	background: #fff;
	overflow: hidden;
	pointer-events: none;
	z-index: 5;
}

.skeleton-head {
	display: flex;
	height: 41px;
	border-bottom: 1px solid #ebeef5;
	background: #f5f7fa;
}

.skeleton-row {
	display: flex;
	height: 41px;
	border-bottom: 1px solid #f2f6fc;
}

.skeleton-cell {
	display: flex;
	align-items: center;
	padding: 0 12px;
	flex-shrink: 0;
	box-sizing: border-box;
	overflow: hidden;
}

.skeleton-bar {
	display: inline-block;
	height: 14px;
	border-radius: 4px;
	background: linear-gradient(90deg, #f0f2f5 25%, #e6e8eb 37%, #f0f2f5 63%);
	background-size: 400% 100%;
	animation: skeleton-shimmer 1.4s ease infinite;
}

@keyframes skeleton-shimmer {
	0% {
		background-position: 100% 50%;
	}
	100% {
		background-position: 0 50%;
	}
}

/* 浮动筛选输入框 */
.ff-input {
	width: 100%;
}
:deep(.vxe-header--row) .vxe-cell--wrapper {
	padding: 2px 0;
}

/* 浮动筛选：数值范围 */
.ff-range {
	display: flex;
	align-items: center;
	gap: 4px;
	width: 100%;
}
.ff-range-input {
	flex: 1;
	min-width: 0;
}
.ff-range-sep {
	color: var(--el-text-color-placeholder);
	flex-shrink: 0;
}

/* 列配置面板 */
.column-panel {
	max-height: 400px;
	display: flex;
	flex-direction: column;
}

.column-panel-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 10px;
	padding-bottom: 8px;
	border-bottom: 1px solid var(--el-border-color-lighter);
}

.column-panel-title {
	font-size: 14px;
	font-weight: 600;
	color: var(--el-text-color-primary);
}

.column-panel-list {
	flex: 1;
	overflow-y: auto;
}

.column-panel-item {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 4px 0;
	cursor: grab;
	user-select: none;
}

.column-panel-item:active {
	cursor: grabbing;
}

.column-panel-item.is-fixed {
	background: var(--el-color-primary-light-9);
	border-radius: 4px;
	padding: 4px;
}

.column-panel-drag {
	color: var(--el-text-color-placeholder);
	cursor: grab;
	flex-shrink: 0;
}

.column-panel-drag:hover {
	color: var(--el-text-color-secondary);
}

.column-panel-label {
	font-size: 13px;
	color: var(--el-text-color-regular);
	flex: 1;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.column-panel-fixed {
	flex-shrink: 0;
}

.column-panel-fixed .el-button {
	padding: 4px 6px;
	font-size: 12px;
}

.column-panel-fixed .el-button.is-active {
	color: var(--el-color-primary);
	border-color: var(--el-color-primary);
}

@media (max-width: 768px) {
	.sTable-page {
		flex-wrap: wrap;
		height: auto;
		min-height: 50px;
		padding: 8px 12px;
		gap: 8px;
	}
	.sTable-pagination {
		flex: 1;
		min-width: 0;
	}
	.sTable-do {
		flex-shrink: 0;
	}
	/* 移动端工具按钮：使用 small 尺寸（24x24），覆盖全局 .el-button { min-height: 44px } */
	.sTable-do :deep(.el-button.is-circle) {
		width: var(--el-component-size-small, 24px);
		height: var(--el-component-size-small, 24px);
		min-width: var(--el-component-size-small, 24px);
		min-height: var(--el-component-size-small, 24px);
	}
	.sTable-do :deep(.el-button.is-circle .el-icon svg) {
		width: 12px;
		height: 12px;
	}
}
</style>
