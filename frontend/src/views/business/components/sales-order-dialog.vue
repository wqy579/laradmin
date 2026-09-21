<template>
	<el-dialog v-model="visible" :title="record ? '编辑销售订单' : '新增销售订单'" width="1280px" destroy-on-close class="sales-order-dialog">
		<!-- 表头：客户 / 仓库 / 业务员 / 日期 / 备注，顺序与旧系统一致 -->
		<el-form ref="formRef" :model="form" :rules="rules" label-width="70px" size="small">
			<el-row :gutter="16">
				<el-col :span="6">
					<el-form-item label="客户" prop="customer_id">
						<el-select v-model="form.customer_id" placeholder="请选择客户" filterable clearable style="width:100%">
							<el-option v-for="c in customers" :key="c.id" :label="c.name" :value="c.id" />
						</el-select>
					</el-form-item>
				</el-col>
				<el-col :span="6">
					<el-form-item label="仓库" prop="warehouse_id">
						<el-select v-model="form.warehouse_id" placeholder="请选择仓库" filterable clearable style="width:100%" @change="onWarehouseChange">
							<el-option v-for="w in warehouses" :key="w.id" :label="w.name" :value="w.id" />
						</el-select>
					</el-form-item>
				</el-col>
				<el-col :span="6">
					<el-form-item label="业务员">
						<el-select v-model="form.salesman_id" placeholder="请选择业务员" filterable clearable style="width:100%">
							<el-option v-for="s in salesmen" :key="s.id" :label="s.name" :value="s.id" />
						</el-select>
					</el-form-item>
				</el-col>
				<el-col :span="6">
					<el-form-item label="日期" prop="order_date">
						<el-date-picker v-model="form.order_date" type="date" value-format="YYYY-MM-DD" placeholder="请选择日期" style="width:100%" />
					</el-form-item>
				</el-col>
			</el-row>
			<el-form-item label="备注">
				<el-input v-model="form.remark" placeholder="输入备注..." clearable style="width:100%" />
			</el-form-item>
		</el-form>

		<!-- 三栏商品选择：主分类 / 子分类 / 商品输入（仿商品资料页），点商品填入下方表格空行 -->
		<div class="cat-picker">
			<div class="cat-col">
				<div class="cat-col-title">主分类</div>
				<div class="cat-col-body">
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
					<template v-if="picker.mainId && pickerSubs.length">
						<div v-for="s in pickerSubs" :key="s.id" :class="['cat-item', { active: picker.subId === s.id }]" @click="selectPickerSub(s)">
							<span class="cat-name">{{ s.name }}</span>
							<span class="cat-count">{{ s.product_count || 0 }}</span>
						</div>
					</template>
					<el-empty v-else :image-size="28" :description="picker.mainId ? '无子分类' : '请先选主分类'" />
				</div>
			</div>
			<div class="cat-col cat-col-prod">
				<div class="cat-col-title">
					<span>商品输入</span>
					<el-input v-model="picker.prodKeyword" size="small" placeholder="搜索商品..." clearable style="width:150px" @input="filterPickerProducts" />
				</div>
				<div class="cat-col-body">
					<template v-if="picker.subId && pickerProducts.length">
						<div class="cat-prod-toolbar">
							<el-checkbox :model-value="allChecked" :indeterminate="picker.checkedIds.length > 0 && !allChecked" size="small" @change="togglePickerCheckAll">全选</el-checkbox>
							<el-button size="small" type="primary" link :disabled="!picker.checkedIds.length" @click="addCheckedProducts">
								添加选中{{ picker.checkedIds.length ? `(${picker.checkedIds.length})` : '' }}
							</el-button>
						</div>
						<div v-for="p in pickerProducts" :key="p.id" class="cat-prod-item" @click="pickProduct(p)" title="点击加入订单；或勾选后批量添加">
							<el-checkbox :model-value="picker.checkedIds.includes(p.id)" size="small" class="cat-prod-check" @click.stop @change="togglePickerCheck(p)" />
							<span class="cat-prod-name">{{ p.name }}</span>
							<span class="cat-prod-spec">{{ p.spec_display || p.spec || '-' }}</span>
							<span class="cat-prod-price">¥{{ Number(p.price_small || 0).toFixed(2) }}/{{ p.price_unit_small || '个' }}</span>
						</div>
					</template>
					<el-empty v-else :image-size="28" :description="picker.prodLoading ? '加载中…' : (picker.subId ? '无商品' : '请先选子分类')" />
				</div>
			</div>
		</div>

		<!-- 商品表格：10 列，与旧系统新增订单的商品表格逐列对齐 -->
		<div class="items-wrap">
			<table class="items-table">
				<thead>
					<tr>
						<th class="c-idx">#</th>
						<th class="c-product">商品</th>
						<th class="c-spec">规格</th>
						<th class="c-mode">销售模式</th>
						<th class="c-stock">库存</th>
						<th class="c-qty">数量</th>
						<th class="c-price">单价</th>
						<th class="c-amount">金额</th>
						<th class="c-remark">备注</th>
						<th class="c-action">操作</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="(item, idx) in form.items" :key="item.id" class="item-row">
						<td class="c-idx">{{ idx + 1 }}</td>
						<td class="c-product">
							<el-select
								v-model="item.product_id"
								placeholder="选择商品"
								filterable
								remote
								:remote-method="(q) => searchProducts(q, item)"
								:loading="item._loading"
								size="small"
								style="width:100%"
								@change="onProductChange(item)"
							>
								<el-option v-for="p in item._options" :key="p.id" :label="productLabel(p)" :value="p.id">
									<span style="float:left">{{ p.name }}</span>
									<span style="float:right;color:var(--el-text-color-secondary);font-size:12px">
										{{ p.spec_display || p.spec || '-' }}｜¥{{ Number(p.price_small || 0).toFixed(2) }}/{{ smallUnitName(p) }}
									</span>
								</el-option>
							</el-select>
						</td>
						<td class="c-spec">{{ item.spec || '-' }}</td>
						<td class="c-mode">
							<el-select v-model="item.sale_mode" size="small" style="width:100%" @change="onModeChange(item)">
								<el-option v-for="m in saleModes" :key="m" :label="m" :value="m" />
							</el-select>
						</td>
						<td class="c-stock">
							<span :class="{ 'text-danger': item.stockError }">{{ item.stockDisplay || '-' }}</span>
						</td>
						<td class="c-qty">
							<div class="unit-group">
								<template v-if="item.unit_large">
									<el-input v-model="item.qty_large" size="small" class="u-input" placeholder="件" @input="onLargeQtyChange(item)" />
									<span class="u-name">{{ item.unit_large }}</span>
								</template>
								<template v-if="item.unit_medium">
									<el-input v-model="item.qty_medium" size="small" class="u-input" placeholder="盒" @input="onMediumQtyChange(item)" />
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
							<div v-if="item.price_source" class="price-source">{{ item.price_source }}</div>
						</td>
						<td class="c-amount">{{ Number(item.amount || 0).toFixed(2) }}</td>
						<td class="c-remark">
							<el-input v-model="item.remark" size="small" placeholder="备注" />
						</td>
						<td class="c-action">
							<el-button type="primary" link size="small" title="添加" @click="addItem(idx)">＋</el-button>
							<el-button type="primary" link size="small" title="复制" @click="duplicateItem(idx)">⎘</el-button>
							<el-button v-if="form.items.length > 1" type="danger" link size="small" title="删除" @click="removeItem(idx)">🗑</el-button>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<!-- 合计：与旧系统底部一致，大/中/小三档数量 + 总金额 -->
		<div class="summary">
			<div>
				<b>合计：</b>
				大单位 <span>{{ totalLg }}</span> ｜ 中单位 <span>{{ totalMd }}</span> ｜ 小单位 <span>{{ totalSm }}</span>
				<span class="grand">总金额 <span>{{ totalAmount }}</span> 元</span>
			</div>
			<el-button type="primary" :loading="submitting" @click="handleSubmit">
				{{ submitting ? '提交中...' : '提交' }}
			</el-button>
		</div>
	</el-dialog>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import businessApi from '@/api/business'

// 销售模式的六个取值，与旧系统下拉完全一致
const SALE_MODES = ['正常销售', '赠品', '陈列费', '试用', '特价销售', '返利']
// 旧系统 onModeChange 只对这两种模式清零单价并标记 price_source='特殊'
const CLEAR_PRICE_MODES = ['赠品', '陈列费']
// 新增一张空表单时预置的行数，沿用旧系统的 20 行
const EMPTY_ROWS = 20
const DRAFT_KEY = 'sales_order_draft'

const props = defineProps({
	visible: { type: Boolean, default: false },
	record: { type: Object, default: null },
	customers: { type: Array, default: () => [] },
	warehouses: { type: Array, default: () => [] },
	salesmen: { type: Array, default: () => [] },
})
const emit = defineEmits(['update:visible', 'success'])

const formRef = ref(null)
const submitting = ref(false)
const saleModes = SALE_MODES

const form = reactive({
	customer_id: null,
	warehouse_id: null,
	salesman_id: null,
	order_date: null,
	remark: '',
	items: [],
})
const rules = {
	customer_id: [{ required: true, message: '请选择客户', trigger: 'change' }],
	warehouse_id: [{ required: true, message: '请选择仓库', trigger: 'change' }],
	order_date: [{ required: true, message: '请选择日期', trigger: 'change' }],
}
const visible = computed({ get: () => props.visible, set: (v) => emit('update:visible', v) })

// ---------------------------------------------------------------- 行结构

let rowSeq = Date.now()

/**
 * 空行。字段名沿用旧系统（qty_large/price_small/...），mode 映射到 sale_mode
 * 以匹配后端列名；id 只为 v-for key 稳定。
 */
const blankRow = () => ({
	id: ++rowSeq,
	product_id: null,
	product_name: '',
	spec: '',
	sale_mode: '正常销售',
	price_source: '',
	stock: 0,
	stockDisplay: '-',
	stockError: false,
	qty_large: 0,
	qty_medium: 0,
	qty_small: 0,
	price_large: 0,
	price_medium: 0,
	price_small: 0,
	unit_large: '',
	unit_medium: '',
	unit_small: '',
	unit_conversion: 0,
	unit_conversion_medium: 0,
	amount: 0,
	remark: '',
	_options: [],
	_loading: false,
	_searchKeyword: '',
})

/** 把后端/商品列表的商品映射成行内字段（旧系统 applyProduct 的同款取值） */
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
	item.price_small = Number(p.price_small) || 0
	item.price_medium = Number(p.price_medium) || 0
	item.price_large = Number(p.price_large) || 0
	item.stock = stockMap.value[p.id] ?? 0
	item.stockDisplay = formatStock(item.stock, item.unit_conversion, item.unit_conversion_medium, item.unit_large, item.unit_medium, item.unit_small)
}

const productLabel = (p) => `${p.name}${p.code ? ` (${p.code})` : ''}`
const smallUnitName = (p) => p.price_unit_small || '小'

// ---------------------------------------------------------------- 三栏分类选择（主分类/子分类/商品输入，仿商品资料页）

// 本地日期字符串 YYYY-MM-DD：避免 toISOString() 的 UTC 偏差导致凌晨取到昨天
const todayStr = () => {
	const d = new Date()
	const y = d.getFullYear()
	const m = String(d.getMonth() + 1).padStart(2, '0')
	const day = String(d.getDate()).padStart(2, '0')
	return `${y}-${m}-${day}`
}

const categories = ref([])  // 主分类树（带 children / product_count）
const picker = reactive({
	mainId: null, mainName: '',
	subId: null, subName: '',
	prodKeyword: '', prodLoading: false,
	checkedIds: [],   // 三栏第三列勾选待批量加入订单的商品 id
})
const pickerSubs = computed(() => {
	const subs = categories.value.find(m => m.id === picker.mainId)?.children || []
	return subs.slice().sort((a, b) => (b.product_count || 0) - (a.product_count || 0))
})
const pickerProducts = ref([])
let pickerAllProducts = []  // 当前子分类全量商品，供关键字过滤

const loadCategories = async () => {
	const res = await businessApi.product.categories.get().catch(() => null)
	if (res && res.code === 200) categories.value = (res.data || []).slice().sort((a, b) => (b.product_count || 0) - (a.product_count || 0))
}
const resetPicker = () => {
	picker.mainId = null; picker.mainName = ''
	picker.subId = null; picker.subName = ''
	picker.prodKeyword = ''; picker.prodLoading = false
	picker.checkedIds = []
	pickerProducts.value = []; pickerAllProducts = []
}
const selectPickerMain = (m) => {
	picker.mainId = m.id
	picker.mainName = m.name
	picker.subId = null; picker.subName = ''
	picker.prodKeyword = ''
	picker.checkedIds = []
	pickerProducts.value = []; pickerAllProducts = []
}
const selectPickerSub = async (s) => {
	picker.subId = s.id
	picker.subName = s.name
	picker.prodKeyword = ''
	picker.checkedIds = []
	await loadPickerProducts()
}
const loadPickerProducts = async () => {
	if (!picker.subId) { pickerProducts.value = []; pickerAllProducts = []; return }
	picker.prodLoading = true
	const res = await businessApi.product.list.get({ sub_category_id: picker.subId, is_active: 1, per_page: 9999 }).catch(() => null)
	picker.prodLoading = false
	if (res && res.code === 200) {
		pickerAllProducts = res.data?.list || []
		pickerProducts.value = pickerAllProducts
	}
}
const filterPickerProducts = () => {
	const q = (picker.prodKeyword || '').trim().toLowerCase()
	if (!q) { pickerProducts.value = pickerAllProducts; return }
	pickerProducts.value = pickerAllProducts.filter(p =>
		(p.name || '').toLowerCase().includes(q) || (p.spec_display || p.spec || '').toLowerCase().includes(q)
	)
}
/** 点击商品 → 填入第一个空行（无 product_id），没有空行则追加一行 */
const pickProduct = (p) => {
	let row = form.items.find(i => !i.product_id)
	if (!row) { row = blankRow(); form.items.push(row) }
	row._options = [p]
	applyProduct(row, p)
	row.price_source = CLEAR_PRICE_MODES.includes(row.sale_mode) ? '特殊' : ''
	calcAmount(row)
	checkStock(row)
}

// 三栏第三列批量多选：勾选若干商品后一次性铺到表格空行，对齐旧系统"全选添加"
const allChecked = computed(() => pickerProducts.value.length > 0 && picker.checkedIds.length === pickerProducts.value.length)
const togglePickerCheck = (p) => {
	const i = picker.checkedIds.indexOf(p.id)
	if (i >= 0) picker.checkedIds.splice(i, 1)
	else picker.checkedIds.push(p.id)
}
const togglePickerCheckAll = (val) => {
	picker.checkedIds = val ? pickerProducts.value.map(p => p.id) : []
}
const addCheckedProducts = () => {
	picker.checkedIds
		.map(id => pickerProducts.value.find(p => p.id === id))
		.filter(Boolean)
		.forEach(pickProduct)
	picker.checkedIds = []
}

// ---------------------------------------------------------------- 库存

const stockMap = ref({})

/**
 * 与旧系统 formatStock 一致：1 大=c 小、1 中=mc 小，逐级取余。
 * cn=大→小(如 480)、mcn=中→小(如 120)，库存按小单位存，逐级整除展示。
 */
const formatStock = (totalSmall, c, mc, unitLarge, unitMedium, unitSmall) => {
	if (!totalSmall) return '-'
	const num = Number(totalSmall) || 0
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
	return parts.join(' ') || '-'
}

/** 按当前仓库刷新库存（旧系统在仓库/草稿加载后都跑一次） */
const loadStock = async () => {
	if (!form.warehouse_id) {
		stockMap.value = {}
		form.items.forEach(i => { i.stock = 0; i.stockDisplay = '-' })
		return
	}
	const res = await businessApi.stock.list.get({ warehouse_id: form.warehouse_id, per_page: 9999 }).catch(() => null)
	if (!res || res.code !== 200) return
	const map = {}
	;(res.data?.list || []).forEach(row => {
		const pid = row.product_id
		if (!pid) return
		map[pid] = Number(row.available_qty ?? row.quantity ?? 0)
	})
	stockMap.value = map
	form.items.forEach(i => {
		if (!i.product_id) return
		i.stock = map[i.product_id] ?? 0
		i.stockDisplay = formatStock(i.stock, i.unit_conversion, i.unit_conversion_medium, i.unit_large, i.unit_medium, i.unit_small)
	})
}

const onWarehouseChange = () => loadStock()

// ---------------------------------------------------------------- 数量变更

const qtyHandler = (key) => (item) => {
	item[key] = Number(item[key]) || 0
	if (item[key] <= 0) {
		item[key] = 0
		ElMessage.error('数量不能为0或负数')
	}
	calcAmount(item)
	checkStock(item)
}
const onSmallQtyChange = qtyHandler('qty_small')
const onMediumQtyChange = qtyHandler('qty_medium')
const onLargeQtyChange = qtyHandler('qty_large')

// ---------------------------------------------------------------- 金额计算
// 大单位 + 中单位 + 小单位三段相加，与旧系统 calcAmount 一致

const calcAmount = (item) => {
	item.amount = Math.round(
		((Number(item.qty_large) || 0) * (Number(item.price_large) || 0)
			+ (Number(item.qty_medium) || 0) * (Number(item.price_medium) || 0)
			+ (Number(item.qty_small) || 0) * (Number(item.price_small) || 0)) * 100
	) / 100
}

// ---------------------------------------------------------------- 单价换算
// 三组换算用物理口径：c=大→小(1大=c小)、mc=中→小(1中=mc小)，大→中=c/mc。
// 改一档价，另两档据此自动带出。quantity/requiredSmall 走后端膨胀口径 c*mc，与此处不同。

const onSmallPriceChange = (item) => {
	const sm = Number(item.price_small) || 0
	const mc = Number(item.unit_conversion_medium) || 0
	const c = Number(item.unit_conversion) || 0
	if (c > 0 && mc > 0) {
		item.price_medium = Math.round(sm * mc * 100) / 100
		item.price_large = Math.round(sm * c * 100) / 100
	} else if (mc > 0) {
		item.price_medium = Math.round(sm * mc * 100) / 100
		item.price_large = 0
	} else if (c > 0) {
		item.price_large = Math.round(sm * c * 100) / 100
		item.price_medium = 0
	} else {
		item.price_medium = 0
		item.price_large = 0
	}
	calcAmount(item)
}

const onMediumPriceChange = (item) => {
	const md = Number(item.price_medium) || 0
	const mc = Number(item.unit_conversion_medium) || 0
	const c = Number(item.unit_conversion) || 0
	if (c > 0 && mc > 0) {
		item.price_small = Math.round(md / mc * 100) / 100
		item.price_large = Math.round((md / mc) * c * 100) / 100
	} else if (mc > 0) {
		item.price_small = Math.round(md / mc * 100) / 100
		item.price_large = 0
	} else {
		item.price_small = md
		item.price_large = 0
	}
	calcAmount(item)
}

const onLargePriceChange = (item) => {
	const lg = Number(item.price_large) || 0
	const mc = Number(item.unit_conversion_medium) || 0
	const c = Number(item.unit_conversion) || 0
	if (c > 0 && mc > 0) {
		item.price_small = Math.round(lg / c * 100) / 100
		item.price_medium = Math.round((lg / c) * mc * 100) / 100
	} else if (c > 0) {
		item.price_small = Math.round(lg / c * 100) / 100
		item.price_medium = 0
	} else {
		item.price_small = lg
		item.price_medium = 0
	}
	calcAmount(item)
}

// ---------------------------------------------------------------- 销售模式
// 赠品 / 陈列费 → 三档单价清零 + price_source='特殊'；其余模式清空标记

const onModeChange = (item) => {
	if (CLEAR_PRICE_MODES.includes(item.sale_mode)) {
		item.price_small = 0
		item.price_medium = 0
		item.price_large = 0
		item.price_source = '特殊'
	} else {
		item.price_source = ''
	}
	calcAmount(item)
}

// ---------------------------------------------------------------- 商品搜索

const searchProducts = (keyword, item) => {
	item._loading = true
	item._searchKeyword = keyword
	businessApi.product.list.get({ keyword: keyword || '', per_page: 20 })
		.then(res => { item._options = res.code === 200 ? (res.data?.list || []) : [] })
		.catch(() => { item._options = [] })
		.finally(() => { item._loading = false })
}

const onProductChange = (item) => {
	const p = item._options.find(x => x.id === item.product_id)
	applyProduct(item, p)
	item.stockError = false
	item.price_source = CLEAR_PRICE_MODES.includes(item.sale_mode) ? '特殊' : ''
	calcAmount(item)
	checkStock(item)
}

// ---------------------------------------------------------------- 行操作

const addItem = (idx = -1) => {
	form.items.splice(idx + 1, 0, blankRow())
}

/** 复制：连同一行的单位换算关系一起复制，旧系统 duplicateItem 的行为 */
const duplicateItem = (idx) => {
	const src = form.items[idx]
	const copy = blankRow()
	const {
		product_id, product_name, spec, sale_mode, price_source, stock, stockDisplay,
		qty_large, qty_medium, qty_small, price_large, price_medium, price_small,
		unit_large, unit_medium, unit_small, unit_conversion, unit_conversion_medium,
		amount, remark, _options,
	} = src
	Object.assign(copy, {
		product_id, product_name, spec, sale_mode, price_source, stock, stockDisplay,
		qty_large, qty_medium, qty_small, price_large, price_medium, price_small,
		unit_large, unit_medium, unit_small, unit_conversion, unit_conversion_medium,
		amount, remark, _options,
	})
	form.items.splice(idx + 1, 0, copy)
}

const removeItem = (idx) => {
	if (form.items.length <= 1) return
	form.items.splice(idx, 1)
}

// ---------------------------------------------------------------- 库存校验
// 与旧系统 submitOrder 里的库存校验一致：按小单位折算需求量，超出可用库存就标红拦截

const requiredSmall = (item) => {
	const c = Number(item.unit_conversion) || 0
	const mc = Number(item.unit_conversion_medium) || 0
	const ql = Number(item.qty_large) || 0
	const qm = Number(item.qty_medium) || 0
	const qs = Number(item.qty_small) || 0
	return mc > 0 ? ql * c * mc + qm * mc + qs : ql * c + qs
}

const checkStock = (item) => {
	if (!item.product_id || item.stock <= 0) {
		item.stockError = false
		return
	}
	item.stockError = requiredSmall(item) > item.stock
}

// ---------------------------------------------------------------- 合计

const totalLg = computed(() => form.items.reduce((s, i) => s + (Number(i.qty_large) || 0), 0))
const totalMd = computed(() => form.items.reduce((s, i) => s + (Number(i.qty_medium) || 0), 0))
const totalSm = computed(() => form.items.reduce((s, i) => s + (Number(i.qty_small) || 0), 0))
const totalAmount = computed(() => form.items.reduce((s, i) => s + (Number(i.amount) || 0), 0).toFixed(2))

// ---------------------------------------------------------------- 提交

const buildPayload = () => {
	const validItems = form.items.filter(i => i.product_id && (Number(i.qty_small) || 0) + (Number(i.qty_medium) || 0) + (Number(i.qty_large) || 0) > 0)

	return {
		customer_id: form.customer_id,
		warehouse_id: form.warehouse_id,
		salesman_id: form.salesman_id || null,
		order_date: form.order_date,
		remark: form.remark,
		items: validItems.map(i => {
			const c = Number(i.unit_conversion) || 0
			const mc = Number(i.unit_conversion_medium) || 0
			return {
				product_id: i.product_id,
				qty_large: Number(i.qty_large) || 0,
				qty_medium: Number(i.qty_medium) || 0,
				qty_small: Number(i.qty_small) || 0,
				price: Number(i.price_small) || 0,
				price_large: Number(i.price_large) || 0,
				price_medium: Number(i.price_medium) || 0,
				price_small: Number(i.price_small) || 0,
				amount: Number(i.amount) || 0,
				sale_mode: i.sale_mode,
				price_source: i.price_source || '',
				remark: i.remark || '',
			}
		}),
	}
}

const handleSubmit = async () => {
	await formRef.value.validate()

	const stockErrors = form.items
		.filter(i => i.product_id && i.stock > 0 && requiredSmall(i) > i.stock)
		.map(i => `「${i.product_name}」库存不足，可用: ${formatStock(i.stock, i.unit_conversion, i.unit_conversion_medium, i.unit_large, i.unit_medium, i.unit_small)}，需要: ${requiredSmall(i)}`)
	if (stockErrors.length) {
		ElMessage.error(`以下商品库存不足，请调整数量：\n${stockErrors.join('\n')}`)
		return
	}

	const payload = buildPayload()
	if (payload.items.length === 0) {
		ElMessage.error('请至少添加一个有效商品')
		return
	}

	submitting.value = true
	try {
		const res = props.record
			? await businessApi.salesOrder.edit.put(props.record.id, payload)
			: await businessApi.salesOrder.add.post(payload)
		if (res.code === 200) {
			ElMessage.success(res.message || '订单创建成功')
			clearDraft()
			emit('success')
			visible.value = false
		}
	} finally {
		submitting.value = false
	}
}

// ---------------------------------------------------------------- 草稿
// 旧系统 DraftStore：表单变动即写 localStorage，重开继续填，提交成功才清。
// 新系统是对话框 + destroy-on-close，所以改成打开时读、关闭/提交成功时写或清。

const clearDraft = () => {
	try { localStorage.removeItem(DRAFT_KEY) } catch { /* 隐私模式下忽略 */ }
}

const saveDraft = () => {
	if (props.record) return // 编辑不覆盖草稿
	const { _loading, _searchKeyword, _options, ...rest } = form
	// form 上的响应式对象里还带着 items 的临时字段，先剥掉再落盘
	const draft = {
		customer_id: rest.customer_id,
		warehouse_id: rest.warehouse_id,
		salesman_id: rest.salesman_id,
		order_date: rest.order_date,
		remark: rest.remark,
		items: rest.items.map(i => {
			const { _loading: _l, _searchKeyword: _k, _options: _o, ...row } = i
			return row
		}),
	}
	try { localStorage.setItem(DRAFT_KEY, JSON.stringify(draft)) } catch { /* 配额不足时静默 */ }
}

/** 返回 true 表示草稿已恢复，false 表示没有草稿 */
const loadDraft = () => {
	let draft = null
	try { draft = JSON.parse(localStorage.getItem(DRAFT_KEY) || 'null') } catch { draft = null }
	if (!draft || !Array.isArray(draft.items)) return false
	Object.assign(form, {
		customer_id: draft.customer_id || null,
		warehouse_id: draft.warehouse_id || null,
		salesman_id: draft.salesman_id || null,
		order_date: draft.order_date || null,
		remark: draft.remark || '',
	})
	form.items = draft.items.map(i => ({ ...blankRow(), ...i, _loading: false, _searchKeyword: '', _options: [] }))
	return form.items.length > 0
}

// ---------------------------------------------------------------- 表单初始化

const initBlank = () => {
	form.customer_id = null
	form.warehouse_id = null
	form.salesman_id = null
	form.order_date = todayStr()
	form.remark = ''
	form.items = Array.from({ length: EMPTY_ROWS }, () => blankRow())
}

// 业务员下拉：优先用列表页传入的，没有就自己在对话框里拉一次（不依赖父级回调）
const salesmen = ref([])

const loadSalesmen = async () => {
	if (props.salesmen.length) {
		salesmen.value = props.salesmen
		return
	}
	// 仅取在职员工（排除作废 is_active=0）
	const res = await businessApi.employee.list.get({ is_active: 1, per_page: 9999 }).catch(() => null)
	if (res && res.code === 200) salesmen.value = res.data?.list || []
}

watch(
	() => props.visible,
	async (open) => {
		if (!open) return
		await loadSalesmen()
		resetPicker()
		await loadCategories()
		if (props.record) {
			form.customer_id = props.record.customer_id
			form.warehouse_id = props.record.warehouse_id
			form.salesman_id = props.record.salesman_id || null
			form.order_date = props.record.order_date
			form.remark = props.record.remark || ''
			form.items = Array.from({ length: EMPTY_ROWS }, () => blankRow())
			;(props.record.items || []).forEach(it => {
				const row = form.items.pop()
				const p = it.product || {}
				row.product_id = it.product_id
				row.product_name = p.name || ''
				row.spec = p.spec_display || p.spec || '-'
				row.sale_mode = it.sale_mode || '正常销售'
				row.price_source = it.price_source || ''
				row.qty_large = Number(it.qty_large) || 0
				row.qty_medium = Number(it.qty_medium) || 0
				row.qty_small = Number(it.qty_small) || 0
				row.price_large = Number(it.price_large) || 0
				row.price_medium = Number(it.price_medium) || 0
				row.price_small = Number(it.price_small) || 0
				row.unit_large = p.price_unit || ''
				row.unit_medium = p.barcode_medium_unit || ''
				row.unit_small = p.price_unit_small || ''
				row.unit_conversion = Number(p.unit_conversion) || 0
				row.unit_conversion_medium = Number(p.unit_conversion_medium) || 0
				row.amount = Number(it.amount) || 0
				row.remark = it.remark || ''
				row._options = p.id ? [p] : []
				form.items.push(row)
			})
		} else {
			if (!loadDraft()) initBlank()
		}
		await loadStock()
	},
	{ immediate: true }
)

watch(form, saveDraft, { deep: true })

watch(() => props.salesmen, v => { if (v.length) salesmen.value = v }, { immediate: true })
</script>

<style scoped>
.items-wrap { max-height: 46vh; overflow: auto; border: 1px solid var(--el-border-color); border-radius: 4px; margin-bottom: 12px; }
.items-table { width: 100%; border-collapse: collapse; font-size: 12px; min-width: 1040px; }
.items-table th { position: sticky; top: 0; z-index: 2; background: var(--el-color-primary-light-9); color: var(--el-text-color-primary); font-weight: 600; text-align: center; padding: 6px 4px; border-bottom: 1px solid var(--el-border-color); }
.items-table td { padding: 3px 4px; border: 1px solid var(--el-border-color-lighter); vertical-align: middle; }
.item-row:nth-child(even) { background: var(--el-fill-color-lighter); }
.c-idx { width: 26px; text-align: center; }
.c-product { width: 168px; }
.c-spec { width: 70px; text-align: center; color: var(--el-text-color-secondary); }
.c-mode { width: 92px; }
.c-stock { width: 74px; text-align: center; white-space: nowrap; }
.c-qty { width: 178px; }
.c-price { width: 196px; }
.c-amount { width: 80px; text-align: right; font-weight: 600; }
.c-remark { width: 96px; }
.c-action { width: 78px; text-align: center; white-space: nowrap; }
.unit-group { display: flex; align-items: center; gap: 2px; flex-wrap: wrap; }
.u-input, .p-input { width: 46px; }
.u-name { font-size: 10px; color: var(--el-text-color-secondary); }
.price-source { font-size: 10px; color: var(--el-color-warning); margin-top: 2px; }
.text-danger { color: var(--el-color-danger); }
.summary { display: flex; justify-content: space-between; align-items: center; padding: 10px 12px; background: var(--el-fill-color-light); border: 1px solid var(--el-border-color); border-radius: 4px; }
.summary .grand { margin-left: 14px; color: var(--el-color-danger); font-weight: 700; font-size: 16px; }

/* 三栏分类选择：主分类 / 子分类 / 商品输入 */
.cat-picker {
	display: flex;
	gap: 1px;
	background: var(--el-border-color-lighter);
	border: 1px solid var(--el-border-color-lighter);
	border-radius: 4px;
	margin-bottom: 10px;
	height: 168px;
}
.cat-col {
	flex: 1;
	min-width: 0;
	display: flex;
	flex-direction: column;
	background: var(--el-bg-color);
	overflow: hidden;
}
.cat-col-prod { flex: 1.4; }
.cat-col-title {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 6px 10px;
	font-size: 12px;
	font-weight: 600;
	color: var(--el-text-color-primary);
	border-bottom: 1px solid var(--el-border-color-lighter);
	flex-shrink: 0;
}
.cat-col-sub { font-size: 11px; font-weight: 400; color: var(--el-text-color-secondary); margin-left: 4px; }
.cat-col-body { flex: 1; overflow-y: auto; padding: 2px 0; }
.cat-col-body::-webkit-scrollbar { width: 4px; }
.cat-col-body::-webkit-scrollbar-thumb { background: var(--el-border-color); border-radius: 2px; }
.cat-item {
	display: flex;
	align-items: center;
	gap: 4px;
	padding: 5px 10px;
	font-size: 12px;
	cursor: pointer;
	color: var(--el-text-color-regular);
	transition: background 0.1s;
}
.cat-item:hover { background: var(--el-fill-color-light); }
.cat-item.active { background: var(--el-color-primary-light-9); color: var(--el-color-primary); font-weight: 500; }
.cat-name { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.cat-count { font-size: 11px; color: var(--el-text-color-secondary); flex-shrink: 0; }
.cat-prod-toolbar {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	padding: 4px 10px;
	border-bottom: 1px solid var(--el-border-color-lighter);
	background: var(--el-fill-color-lighter);
	position: sticky;
	top: 0;
	z-index: 1;
}
.cat-prod-check { flex-shrink: 0; }
.cat-prod-item {
	display: flex;
	align-items: center;
	gap: 6px;
	padding: 5px 10px;
	font-size: 12px;
	cursor: pointer;
	color: var(--el-text-color-regular);
	transition: background 0.1s;
}
.cat-prod-item:hover { background: var(--el-color-primary-light-9); }
.cat-prod-name { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.cat-prod-spec { font-size: 11px; color: var(--el-text-color-secondary); flex-shrink: 0; }
.cat-prod-price { font-size: 11px; color: var(--el-color-danger); flex-shrink: 0; }
</style>
