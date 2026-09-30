<template>
	<div class="si-dialog">
	<el-dialog v-model="visible" :title="record ? '编辑入库单' : '新增入库单'" width="98%" top="2vh" destroy-on-close class="stock-in-dialog">
		<el-form ref="formRef" :model="form" :rules="rules" label-width="70px" size="small">
			<el-row :gutter="16">
				<el-col :span="8">
					<el-form-item label="供应商" prop="supplier_id">
						<el-select v-model="form.supplier_id" placeholder="请选择供应商" filterable clearable style="width:100%">
							<el-option v-for="s in suppliers" :key="s.id" :label="s.name" :value="s.id" />
						</el-select>
					</el-form-item>
				</el-col>
				<el-col :span="8">
					<el-form-item label="仓库" prop="warehouse_id">
						<el-select v-model="form.warehouse_id" placeholder="请选择仓库" clearable style="width:100%">
							<el-option v-for="w in warehouses" :key="w.id" :label="w.name" :value="w.id" />
						</el-select>
					</el-form-item>
				</el-col>
				<el-col :span="8">
					<el-form-item label="日期" prop="order_date">
						<el-date-picker v-model="form.order_date" type="date" value-format="YYYY-MM-DD" placeholder="请选择日期" style="width:100%" />
					</el-form-item>
				</el-col>
			</el-row>
			<el-form-item label="备注">
				<el-input v-model="form.remark" placeholder="输入备注..." clearable style="width:100%" />
			</el-form-item>
		</el-form>

		<!-- 三栏：主分类 / 子分类 / 商品表格，行内远程搜索选择（对齐销售单/采购订单） -->
		<div class="cat-picker">
			<div class="cat-col">
				<div class="cat-col-title">主分类</div>
				<div class="cat-col-body">
					<div :class="['cat-item', { active: !picker.mainId }]" @click="selectPickerAllMain">
						<span class="cat-name">全部</span>
						<span class="cat-count">{{ totalProductCount }}</span>
					</div>
					<div v-for="m in categories" :key="m.id" :class="['cat-item', { active: picker.mainId === m.id }]" @click="selectPickerMain(m)">
						<span class="cat-name">{{ m.name }}</span>
						<span class="cat-count">{{ m.product_count || 0 }}</span>
					</div>
					<el-empty v-if="!categories.length" :image-size="28" description="无分类" />
				</div>
			</div>
			<div class="cat-col">
				<div class="cat-col-title">
					子分类
					<span v-if="picker.mainName" class="cat-col-sub">{{ picker.mainName }}</span>
				</div>
				<div class="cat-col-body">
					<template v-if="picker.mainId">
						<div :class="['cat-item', { active: !picker.subId }]" @click="selectPickerAllSub">
							<span class="cat-name">全部</span>
							<span class="cat-count">{{ currentMainProductCount }}</span>
						</div>
						<div v-for="s in pickerSubs" :key="s.id" :class="['cat-item', { active: picker.subId === s.id }]" @click="selectPickerSub(s)">
							<span class="cat-name">{{ s.name }}</span>
							<span class="cat-count">{{ s.product_count || 0 }}</span>
						</div>
						<el-empty v-if="!pickerSubs.length" :image-size="28" description="无子分类" />
					</template>
					<el-empty v-else :image-size="28" description="请先选主分类" />
				</div>
			</div>
			<!-- 第三栏：商品表格 -->
			<div class="cat-col cat-col-main">
				<div class="items-wrap">
					<div class="items-table-scroll">
					<table class="items-table">
						<thead>
							<tr>
								<th class="c-idx">#</th>
								<th class="c-product">商品</th>
								<th class="c-spec">规格</th>
								<th class="c-qty">数量</th>
								<th class="c-price">成本价</th>
								<th class="c-amount">金额</th>
								<th class="c-remark">备注</th>
								<th class="c-action">操作</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="(item, idx) in form.items" :key="item.id" class="item-row">
								<td class="c-idx">{{ idx + 1 }}</td>
								<td class="c-product">
									<div class="prod-cell">
										<input class="prod-search"
											:value="item.product_id ? item.product_name : item._searchKeyword"
											:placeholder="item.product_id ? '' : '搜索商品名/编码/规格'"
											:readonly="!!item.product_id"
											@input="onProdInput(item, $event)"
											@focus="onProdFocus(item)"
											@blur="onProdBlur(item)" />
										<span v-if="item.product_id" class="prod-clear" title="清除重选" @mousedown.prevent="clearProductFromRow(item, true)">✕</span>
										<div v-if="item.showSuggestions && item._options.length" class="prod-suggestions" @mousedown.prevent @mouseenter="onProdSuggEnter(item)" @mouseleave="onProdSuggLeave(item)">
											<div class="prod-sugg-bar">
												<span>{{ item._searchKeyword ? '搜索结果' : '全部商品（按库存排序）' }}</span>
												<span class="prod-sugg-all" title="全选并添加" @click="selectAllProducts(item)">全选添加</span>
											</div>
											<div v-for="(p, sidx) in item._options" :key="p.id" class="prod-sugg-row" @click="toggleProductCheck(item, sidx, p)">
												<input type="checkbox" class="prod-sugg-check" @click.stop="toggleProductCheck(item, sidx, p)" />
												<div class="prod-sugg-info">
													<div class="prod-sugg-name">{{ p.name }}</div>
													<div class="prod-sugg-meta">
														{{ p.spec_display || p.spec || '-' }} ｜ ¥{{ Number(p.price_small || 0).toFixed(2) }}/{{ smallUnitName(p) }}
													</div>
												</div>
											</div>
										</div>
										<div v-else-if="item.showSuggestions && !item._loading" class="prod-suggestions prod-sugg-empty">暂无商品</div>
									</div>
								</td>
								<td class="c-spec">{{ item.spec || '-' }}</td>
								<td class="c-qty">
									<div class="unit-group">
										<template v-if="item.unit_large">
											<el-input v-model="item.qty_large" size="small" class="u-input" :placeholder="item.unit_large" @input="onLargeQtyChange(item)" />
											<span class="u-name">{{ item.unit_large }}</span>
										</template>
										<template v-if="item.unit_medium">
											<el-input v-model="item.qty_medium" size="small" class="u-input" :placeholder="item.unit_medium" @input="onMediumQtyChange(item)" />
											<span class="u-name">{{ item.unit_medium }}</span>
										</template>
										<el-input v-model="item.qty_small" size="small" class="u-input" placeholder="袋" @input="onSmallQtyChange(item)" />
										<span v-if="item.unit_small" class="u-name">{{ item.unit_small }}</span>
									</div>
								</td>
								<td class="c-price">
									<div class="unit-group">
										<template v-if="item.unit_large">
											<el-input v-model="item.price_large" size="small" class="p-input" placeholder="件价" @input="onLargePriceChange(item)" />
											<span class="u-name">元/{{ item.unit_large }}</span>
										</template>
										<template v-if="item.unit_medium">
											<el-input v-model="item.price_medium" size="small" class="p-input" placeholder="盒价" @input="onMediumPriceChange(item)" />
											<span class="u-name">元/{{ item.unit_medium }}</span>
										</template>
										<el-input v-model="item.price_small" size="small" class="p-input" placeholder="袋价" @input="onSmallPriceChange(item)" />
										<span v-if="item.unit_small" class="u-name">元/{{ item.unit_small }}</span>
									</div>
								</td>
								<td class="c-amount">{{ Number(item.amount || 0).toFixed(2) }}</td>
								<td class="c-remark"><el-input v-model="item.remark" size="small" placeholder="备注" /></td>
								<td class="c-action">
									<el-button type="primary" link size="small" title="添加" @click="addItem(idx)">＋</el-button>
									<el-button type="primary" link size="small" title="复制" @click="duplicateItem(idx)">⎘</el-button>
									<el-button v-if="form.items.length > 1" type="danger" link size="small" title="删除" @click="removeItem(idx)">🗑</el-button>
								</td>
							</tr>
						</tbody>
					</table>
					</div>
				</div>
			</div>
		</div>

		<!-- 合计 -->
		<div class="summary">
			<div>
				<b>合计：</b>
				大单位 <span>{{ totalLg }}</span> ｜ 中单位 <span>{{ totalMd }}</span> ｜ 小单位 <span>{{ totalSm }}</span>
				<span class="grand">总金额 <span>{{ totalAmount }}</span> 元</span>
			</div>
			<el-button v-if="!record" type="primary" :loading="submitting" @click="handleSubmit">
				{{ submitting ? '提交中...' : '提交' }}
			</el-button>
		</div>
	</el-dialog>
	</div>
</template>

<script setup>
import { ref, reactive, computed, watch } from 'vue'
import { ElMessage } from 'element-plus'
import businessApi from '@/api/business'

const props = defineProps({ visible: Boolean, record: Object, suppliers: { type: Array, default: () => [] }, warehouses: { type: Array, default: () => [] }, products: { type: Array, default: () => [] } })
const emit = defineEmits(['update:visible', 'success'])
const formRef = ref(null)
const submitting = ref(false)
const visible = computed({ get: () => props.visible, set: (v) => emit('update:visible', v) })

const form = reactive({
	supplier_id: null, warehouse_id: null, order_date: null, remark: '', items: [],
})
const rules = {
	warehouse_id: [{ required: true, message: '请选择仓库', trigger: 'change' }],
	order_date: [{ required: true, message: '请选择日期', trigger: 'change' }],
}

// ---------------------------------------------------------------- 行结构
let rowSeq = Date.now()
const EMPTY_ROWS = 5
const blankRow = () => ({
	id: ++rowSeq,
	product_id: null, product_name: '', spec: '',
	qty_large: 0, qty_medium: 0, qty_small: 0,
	price_large: 0, price_medium: 0, price_small: 0,
	unit_large: '', unit_medium: '', unit_small: '',
	unit_conversion: 0, unit_conversion_medium: 0,
	amount: 0, remark: '',
	_options: [], _loading: false, _searchKeyword: '',
	showSuggestions: false, checkedProducts: [],
	_blurTimer: null, _suggHover: false, _prevOptions: [],
})

const applyProduct = (item, p) => {
	if (!p) return
	item.product_id = p.id
	item.product_name = p.name
	item.spec = p.spec_display || p.spec || '-'
	item.unit_large = p.price_unit || ''
	item.unit_medium = p.barcode_medium_unit || ''
	item.unit_small = p.price_unit_small || ''
	item.unit_conversion = Number(p.unit_conversion) || 0
	item.unit_conversion_medium = Number(p.unit_conversion_medium) || 0
	// 入库默认带成本价（cost_price_large/small），缺失回落到销售价
	const csm = Number(p.cost_price_small ?? p.price_small ?? 0) || 0
	const clg = Number(p.cost_price_large ?? p.price_large ?? 0) || 0
	item.price_small = csm
	item.price_large = clg
	item.price_medium = Number(p.cost_price_large ?? p.price_medium ?? 0) || 0
	const mc = item.unit_conversion_medium
	const c = item.unit_conversion
	if (!item.price_medium && csm > 0 && mc > 0) item.price_medium = Math.round(csm * mc * 100) / 100
	if (!item.price_large && csm > 0 && c > 0) item.price_large = Math.round(csm * c * 100) / 100
}
const smallUnitName = (p) => p.price_unit_small || '小'

// ---------------------------------------------------------------- 三栏分类
const categories = ref([])
const picker = reactive({ mainId: null, mainName: '', subId: null, subName: '' })
const pickerSubs = computed(() => categories.value.find(m => m.id === picker.mainId)?.children || [])
const loadCategories = async () => {
	const res = await businessApi.product.categories.get().catch(() => null)
	if (res && res.code === 200) categories.value = (res.data || []).slice().sort((a, b) => (b.product_count || 0) - (a.product_count || 0))
}
const resetPicker = () => { picker.mainId = null; picker.mainName = ''; picker.subId = null; picker.subName = '' }
const selectPickerMain = (m) => { picker.mainId = m.id; picker.mainName = m.name; picker.subId = null; picker.subName = ''; refreshOpenSuggestions() }
const selectPickerSub = (s) => { picker.subId = s.id; picker.subName = s.name; refreshOpenSuggestions() }
const selectPickerAllMain = () => { resetPicker(); refreshOpenSuggestions() }
const selectPickerAllSub = () => { picker.subId = null; picker.subName = ''; refreshOpenSuggestions() }
const totalProductCount = computed(() => categories.value.reduce((s, m) => s + (m.product_count || 0), 0))
const currentMainProductCount = computed(() => categories.value.find(m => m.id === picker.mainId)?.product_count || 0)

// ---------------------------------------------------------------- 商品行内搜索
let prodSearchTimer = null
const searchProducts = (keyword, item) => {
	item._loading = true
	item._searchKeyword = keyword
	businessApi.product.list.get({
		keyword: keyword || '', per_page: 20, is_active: 1,
		main_category_id: picker.mainId || undefined, sub_category_id: picker.subId || undefined,
	}).then(res => {
		const list = res.code === 200 ? (res.data?.list || []) : []
		item._options = list
		item.checkedProducts = list.map(() => false)
		item._prevOptions = list.slice()
	}).catch(() => { item._options = [] }).finally(() => { item._loading = false })
}
const onProdInput = (item, e) => { item._searchKeyword = e.target.value; clearTimeout(prodSearchTimer); prodSearchTimer = setTimeout(() => searchProducts(item._searchKeyword, item), 300) }
const onProdFocus = (item) => {
	if (item._blurTimer) { clearTimeout(item._blurTimer); item._blurTimer = null }
	if (!form.warehouse_id) { ElMessage.error('请先选择仓库'); item.showSuggestions = false; return }
	if (item.product_id) { item.showSuggestions = true; return }
	item.showSuggestions = true
	searchProducts(item._searchKeyword || '', item)
}
const onProdBlur = (item) => { if (item._suggHover) return; if (item._blurTimer) clearTimeout(item._blurTimer); item._blurTimer = setTimeout(() => { item.showSuggestions = false }, 200) }
const onProdSuggEnter = (item) => { item._suggHover = true; if (item._blurTimer) { clearTimeout(item._blurTimer); item._blurTimer = null } }
const onProdSuggLeave = (item) => { item._suggHover = false; onProdBlur(item) }
const refreshOpenSuggestions = () => { form.items.forEach(i => { if (i.showSuggestions) searchProducts(i._searchKeyword || '', i) }) }

const fillRowWithProduct = (row, p) => { applyProduct(row, p); row._searchKeyword = ''; calcAmount(row) }
const toggleProductCheck = (item, sidx, p) => {
	const opts = item._options
	if (!opts[sidx]) return
	const items = form.items
	const sIdx = items.indexOf(item)
	if (sIdx < 0) return
	let target = item
	if (item.product_id) {
		let targetIdx = -1
		for (let k = sIdx + 1; k < items.length; k++) { if (!items[k].product_id) { targetIdx = k; break } }
		target = targetIdx >= 0 ? items[targetIdx] : blankRow()
		if (targetIdx < 0) items.push(target)
	}
	fillRowWithProduct(target, p)
	item.showSuggestions = true
	item._searchKeyword = ''
}
const selectAllProducts = (item) => {
	const products = item._options
	if (!products || !products.length) return
	if (!form.warehouse_id) { ElMessage.error('请先选择仓库'); return }
	const sIdx = form.items.indexOf(item)
	if (sIdx < 0) return
	const inOrder = new Set(form.items.map(i => i.product_id).filter(Boolean))
	let added = 0
	let insertAt = sIdx + 1
	for (const p of products) {
		if (inOrder.has(p.id)) continue
		const row = blankRow()
		fillRowWithProduct(row, p)
		form.items.splice(insertAt, 0, row)
		insertAt++
		added++
		inOrder.add(p.id)
	}
	item._searchKeyword = ''
	item.showSuggestions = false
	item._options = []
	item.checkedProducts = []
	item._prevOptions = []
	if (added) ElMessage.success(`已添加 ${added} 个商品`)
	else ElMessage.warning('所选商品已全部在单中')
}
const clearProductFromRow = (row, reopen = false) => {
	row.product_id = null
	row.product_name = ''
	row.spec = ''
	row.qty_large = 0; row.qty_medium = 0; row.qty_small = 0
	row.price_large = 0; row.price_medium = 0; row.price_small = 0
	row.amount = 0
	row._options = []
	row.checkedProducts = []
	row._prevOptions = []
	row._searchKeyword = ''
	if (reopen) { row.showSuggestions = true; searchProducts('', row) }
}

// ---------------------------------------------------------------- 行操作
const addItem = (idx = -1) => { form.items.splice(idx + 1, 0, blankRow()) }
const duplicateItem = (idx) => {
	const src = form.items[idx]
	const copy = blankRow()
	const { product_id, product_name, spec, qty_large, qty_medium, qty_small, price_large, price_medium, price_small, unit_large, unit_medium, unit_small, unit_conversion, unit_conversion_medium, amount, remark } = src
	Object.assign(copy, { product_id, product_name, spec, qty_large, qty_medium, qty_small, price_large, price_medium, price_small, unit_large, unit_medium, unit_small, unit_conversion, unit_conversion_medium, amount, remark })
	form.items.splice(idx + 1, 0, copy)
}
const removeItem = (idx) => { if (form.items.length <= 1) return; form.items.splice(idx, 1) }

// ---------------------------------------------------------------- 数量 / 成本价 / 金额
const qtyHandler = (key) => (item) => {
	item[key] = Number(item[key]) || 0
	if (item[key] <= 0) { item[key] = 0; ElMessage.error('数量不能为0或负数') }
	calcAmount(item)
}
const onSmallQtyChange = qtyHandler('qty_small')
const onMediumQtyChange = qtyHandler('qty_medium')
const onLargeQtyChange = qtyHandler('qty_large')
const calcAmount = (item) => {
	item.amount = Math.round(
		((Number(item.qty_large) || 0) * (Number(item.price_large) || 0)
			+ (Number(item.qty_medium) || 0) * (Number(item.price_medium) || 0)
			+ (Number(item.qty_small) || 0) * (Number(item.price_small) || 0)) * 100
	) / 100
}
const onSmallPriceChange = (item) => {
	const sm = Number(item.price_small) || 0
	const mc = Number(item.unit_conversion_medium) || 0
	const c = Number(item.unit_conversion) || 0
	if (c > 0 && mc > 0) { item.price_medium = Math.round(sm * mc * 100) / 100; item.price_large = Math.round(sm * c * 100) / 100 }
	else if (mc > 0) { item.price_medium = Math.round(sm * mc * 100) / 100; item.price_large = 0 }
	else if (c > 0) { item.price_large = Math.round(sm * c * 100) / 100; item.price_medium = 0 }
	else { item.price_medium = 0; item.price_large = 0 }
	calcAmount(item)
}
const onMediumPriceChange = (item) => {
	const md = Number(item.price_medium) || 0
	const mc = Number(item.unit_conversion_medium) || 0
	const c = Number(item.unit_conversion) || 0
	if (c > 0 && mc > 0) { item.price_small = Math.round(md / mc * 100) / 100; item.price_large = Math.round((md / mc) * c * 100) / 100 }
	else if (mc > 0) { item.price_small = Math.round(md / mc * 100) / 100; item.price_large = 0 }
	else { item.price_small = md; item.price_large = 0 }
	calcAmount(item)
}
const onLargePriceChange = (item) => {
	const lg = Number(item.price_large) || 0
	const mc = Number(item.unit_conversion_medium) || 0
	const c = Number(item.unit_conversion) || 0
	if (c > 0 && mc > 0) { item.price_small = Math.round(lg / c * 100) / 100; item.price_medium = Math.round((lg / c) * mc * 100) / 100 }
	else if (c > 0) { item.price_small = Math.round(lg / c * 100) / 100; item.price_medium = 0 }
	else { item.price_small = lg; item.price_medium = 0 }
	calcAmount(item)
}

// ---------------------------------------------------------------- 合计
const totalLg = computed(() => form.items.reduce((s, i) => s + (Number(i.qty_large) || 0), 0))
const totalMd = computed(() => form.items.reduce((s, i) => s + (Number(i.qty_medium) || 0), 0))
const totalSm = computed(() => form.items.reduce((s, i) => s + (Number(i.qty_small) || 0), 0))
const totalAmount = computed(() => form.items.reduce((s, i) => s + (Number(i.amount) || 0), 0).toFixed(2))

// ---------------------------------------------------------------- 初始化
const todayStr = () => {
	const d = new Date()
	return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}
const initBlank = () => {
	form.supplier_id = null; form.warehouse_id = null; form.order_date = todayStr(); form.remark = ''
	form.items = Array.from({ length: EMPTY_ROWS }, () => blankRow())
}

watch(() => props.visible, async (open) => {
	if (!open) return
	resetPicker()
	await loadCategories()
	if (props.record) {
		// 详情回显（Stock 行，只读）：列表 row 是 Stock with product/warehouse
		form.supplier_id = null
		form.warehouse_id = props.record.warehouse_id
		form.order_date = null
		form.remark = ''
		form.items = Array.from({ length: EMPTY_ROWS }, () => blankRow())
		const row = form.items[0]
		const p = props.record.product || {}
		row.product_id = props.record.product_id
		row.product_name = p.name || ''
		row.spec = p.spec_display || p.spec || '-'
		row.qty_small = Number(props.record.quantity) || 0
		row.price_small = Number(props.record.cost_price) || 0
		row.unit_large = p.price_unit || ''
		row.unit_medium = p.barcode_medium_unit || ''
		row.unit_small = p.price_unit_small || ''
		row.unit_conversion = Number(p.unit_conversion) || 0
		row.unit_conversion_medium = Number(p.unit_conversion_medium) || 0
		row.amount = Number(props.record.total_amount) || 0
		calcAmount(row)
	} else {
		initBlank()
	}
}, { immediate: true })

const handleSubmit = async () => {
	await formRef.value.validate()
	// 提交前前端校验：数量未填写（入库无销售模式、无库存校验，价格允许 0）
	const errs = []
	for (const i of form.items) {
		if (!i.product_id) continue
		const ql = Number(i.qty_large) || 0, qm = Number(i.qty_medium) || 0, qs = Number(i.qty_small) || 0
		if (ql + qm + qs <= 0) errs.push(`「${i.product_name}」数量未填写`)
	}
	if (errs.length) { ElMessage.error(`以下问题需处理：\n${errs.join('\n')}`); return }
	const validItems = form.items.filter(i => i.product_id && (Number(i.qty_small) || 0) + (Number(i.qty_medium) || 0) + (Number(i.qty_large) || 0) > 0)
	if (!validItems.length) { ElMessage.warning('请至少添加一个商品并填写数量'); return }
	submitting.value = true
	try {
		const payload = {
			supplier_id: form.supplier_id,
			warehouse_id: form.warehouse_id,
			order_date: form.order_date,
			remark: form.remark,
			items: validItems.map(i => ({
				product_id: i.product_id,
				qty_large: Number(i.qty_large) || 0,
				qty_medium: Number(i.qty_medium) || 0,
				qty_small: Number(i.qty_small) || 0,
				price_large: Number(i.price_large) || 0,
				price_medium: Number(i.price_medium) || 0,
				price_small: Number(i.price_small) || 0,
				amount: Number(i.amount) || 0,
				remark: i.remark || '',
			})),
		}
		const res = await businessApi.stockIn.add.post(payload)
		if (res.code === 200) { ElMessage.success(res.message || '入库成功'); emit('success'); visible.value = false }
		else ElMessage.error(res.message || '提交失败')
	} catch (e) {
		// axios 拦截器已弹 toast
	} finally { submitting.value = false }
}
</script>

<style scoped>
.si-dialog :deep(.stock-in-dialog) { max-height: 96vh; overflow: auto; margin-bottom: 2vh; }
.si-dialog :deep(.el-dialog__body) { display: flex; flex-direction: column; }

.cat-picker { height: 680px; min-height: 0; display: flex; gap: 1px; background: var(--el-border-color-lighter); border: 1px solid var(--el-border-color-lighter); border-radius: 4px; margin-bottom: 10px; }
.cat-col { flex: 0 0 112px; min-width: 0; min-height: 0; height: 100%; display: flex; flex-direction: column; background: var(--el-bg-color); overflow: hidden; }
.cat-col-main { flex: 1 1 0; }
.cat-col-title { display: flex; align-items: center; justify-content: space-between; padding: 6px 10px; font-size: 12px; font-weight: 600; color: var(--el-text-color-primary); border-bottom: 1px solid var(--el-border-color-lighter); flex-shrink: 0; }
.cat-col-sub { font-size: 11px; font-weight: 400; color: var(--el-text-color-secondary); margin-left: 4px; }
.cat-col-body { flex: 1; overflow-y: auto; padding: 2px 0; }
.cat-col-body::-webkit-scrollbar { width: 4px; }
.cat-col-body::-webkit-scrollbar-thumb { background: var(--el-border-color); border-radius: 2px; }
.cat-item { display: flex; align-items: center; gap: 4px; padding: 5px 10px; font-size: 12px; cursor: pointer; color: var(--el-text-color-regular); transition: background 0.1s; }
.cat-item:hover { background: var(--el-fill-color-light); }
.cat-item.active { background: var(--el-color-primary-light-9); color: var(--el-color-primary); font-weight: 500; }
.cat-name { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.cat-count { font-size: 11px; color: var(--el-text-color-secondary); flex-shrink: 0; }

.items-wrap { flex: 1 1 0; min-width: 0; min-height: 0; overflow: hidden; display: flex; flex-direction: column; border: none; border-radius: 0; margin: 0; background: var(--el-bg-color); }
.items-table-scroll { flex: 1 1 0; min-height: 0; overflow: auto; }
.items-table { width: 100%; border-collapse: collapse; font-size: 12px; min-width: 760px; }
.items-table th { position: sticky; top: 0; z-index: 2; background: var(--el-color-primary-light-9); color: var(--el-text-color-primary); font-weight: 600; text-align: center; padding: 6px 4px; border-bottom: 1px solid var(--el-border-color); }
.items-table td { padding: 3px 4px; border: 1px solid var(--el-border-color-lighter); vertical-align: middle; }
.item-row:nth-child(even) { background: var(--el-fill-color-lighter); }
.c-idx { width: 26px; text-align: center; }
.c-product { width: 168px; }
.c-spec { width: 70px; text-align: center; color: var(--el-text-color-secondary); }
.c-qty { width: 178px; }
.c-price { width: 196px; }
.c-amount { width: 80px; text-align: right; font-weight: 600; }
.c-remark { width: 96px; }
.c-action { width: 78px; text-align: center; white-space: nowrap; }
.unit-group { display: flex; align-items: center; gap: 2px; flex-wrap: wrap; }
.u-input, .p-input { width: 46px; }
.u-name { font-size: 10px; color: var(--el-text-color-secondary); }

.prod-cell { position: relative; width: 100%; }
.prod-search { width: 100%; box-sizing: border-box; border: 1px solid var(--el-border-color); border-radius: 3px; padding: 2px 6px; font-size: 12px; line-height: 20px; background: var(--el-bg-color); }
.prod-search[readonly] { background: var(--el-fill-color-lighter); color: var(--el-text-color-primary); font-weight: 500; cursor: default; }
.prod-search:focus { outline: none; border-color: var(--el-color-primary); }
.prod-clear { position: absolute; right: 4px; top: 50%; transform: translateY(-50%); cursor: pointer; color: var(--el-text-color-secondary); font-size: 12px; line-height: 1; padding: 2px; }
.prod-clear:hover { color: var(--el-color-danger); }
.prod-suggestions { position: absolute; top: 100%; left: 0; width: 100%; min-width: 220px; max-height: 260px; overflow-y: auto; z-index: 1000; margin-top: 2px; background: var(--el-bg-color); border: 1px solid var(--el-border-color); border-radius: 4px; box-shadow: 0 2px 8px rgba(0,0,0,0.12); }
.prod-suggestions::-webkit-scrollbar { width: 6px; }
.prod-suggestions::-webkit-scrollbar-thumb { background: var(--el-border-color); border-radius: 3px; }
.prod-sugg-bar { display: flex; justify-content: space-between; align-items: center; padding: 4px 10px; font-size: 11px; color: var(--el-text-color-secondary); background: var(--el-fill-color-lighter); border-bottom: 1px solid var(--el-border-color-lighter); }
.prod-sugg-all { cursor: pointer; color: var(--el-color-primary); font-weight: 600; }
.prod-sugg-all:hover { text-decoration: underline; }
.prod-sugg-row { display: flex; align-items: center; gap: 6px; padding: 5px 10px; cursor: pointer; border-bottom: 1px solid var(--el-border-color-lighter); font-size: 12px; }
.prod-sugg-row:hover { background: var(--el-fill-color-light); }
.prod-sugg-check { flex: 0 0 auto; margin: 0; cursor: pointer; }
.prod-sugg-info { flex: 1 1 auto; min-width: 0; }
.prod-sugg-name { font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.prod-sugg-meta { font-size: 11px; color: var(--el-text-color-secondary); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.prod-sugg-empty { padding: 10px; text-align: center; color: var(--el-text-color-secondary); font-size: 12px; }

.summary { display: flex; justify-content: space-between; align-items: center; padding: 10px 12px; background: var(--el-fill-color-light); border: 1px solid var(--el-border-color); border-radius: 4px; }
.summary .grand { margin-left: 14px; color: var(--el-color-danger); font-weight: 700; font-size: 16px; }
</style>
