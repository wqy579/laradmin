<template>
	<div class="f5-shop-page f5-page">
		<!-- 顶部栏：搜索 + 购物车入口 -->
		<div class="sp-top">
			<div class="sp-title">
				<h2>分销商城</h2>
				<span class="sp-sub">下单即生成 F1 订货申报单</span>
			</div>
			<div class="sp-actions">
				<el-input v-model="keyword" placeholder="搜索商品名称/编码" clearable style="width: 240px" @keyup.enter="loadCatalog" @clear="loadCatalog">
					<template #prefix><el-icon><Search /></el-icon></template>
				</el-input>
				<el-button type="primary" @click="loadCatalog">搜索</el-button>
				<el-badge :value="cartCount" :hidden="!cartCount" class="sp-cart-badge">
					<el-button @click="openCart">
						<el-icon><ShoppingCart /></el-icon>&nbsp;购物车
					</el-button>
				</el-badge>
			</div>
		</div>

		<!-- 商品目录 -->
		<div class="sp-catalog" v-loading="loading">
			<div class="sp-grid" v-if="list.length">
				<div v-for="p in list" :key="p.product_id" class="sp-card">
					<div class="sp-card-img">
						<img v-if="p.image" :src="p.image" :alt="p.name" />
						<div v-else class="sp-card-img-ph">{{ p.name.slice(0, 1) }}</div>
					</div>
					<div class="sp-card-body">
						<div class="sp-card-name" :title="p.name">{{ p.name }}</div>
						<div class="sp-card-spec">{{ p.spec_display || p.spec || '' }}</div>
						<div class="sp-card-slogan">{{ p.slogan || '' }}</div>
						<div class="sp-card-price">
							<span class="sp-price">{{ fmtPrice(p.price_large) }}</span>
							<span class="sp-price-unit">/{{ p.unit_large }}</span>
						</div>
						<div class="sp-card-buy">
							<span class="sp-stock">三档规格可选</span>
							<el-button type="primary" size="small" @click="addToCart(p)">
								<el-icon><Plus /></el-icon>加入购物车
							</el-button>
						</div>
					</div>
				</div>
			</div>
			<FinEmpty v-else-if="!loading" text="暂无上架商品" />
		</div>

		<!-- 分页 -->
		<div class="sp-pager" v-if="total > page_size">
			<el-pagination background layout="prev, pager, next, total" :total="total" :page-size="page_size" :current-page="page" @current-change="onPage" />
		</div>

		<!-- 购物车抽屉 -->
		<el-drawer v-model="cartOpen" title="购物车" size="560px">
			<div v-if="cartItems.length" class="sp-cart">
				<div v-for="c in cartItems" :key="c.product_id" class="sp-cart-row">
					<div class="sp-cart-info">
						<div class="sp-cart-name">{{ c.name }}</div>
						<div class="sp-cart-spec">{{ c.spec_display || '' }}</div>
					</div>
					<div class="sp-cart-qty">
						<el-input-number v-model="c.qty_large" :min="0" :max="c.stock" size="small" controls-position="right" />
						<span class="sp-qty-unit">× {{ c.unit_large }} @ {{ fmtPrice(c.price_large) }}</span>
					</div>
					<div class="sp-cart-amt">{{ fmtPrice(lineAmount(c)) }}</div>
					<el-button link type="danger" @click="removeCart(c)"><el-icon><Delete /></el-icon></el-button>
				</div>
				<div class="sp-cart-total">
					<span>合计</span>
					<span class="sp-cart-total-amt">{{ fmtPrice(cartTotal) }}</span>
				</div>
				<el-button type="primary" class="sp-checkout-btn" @click="checkoutOpen = true">去下单</el-button>
			</div>
			<FinEmpty v-else text="购物车是空的" />
		</el-drawer>

		<!-- 下单对话框 -->
		<el-dialog v-model="checkoutOpen" class="f5-dialog" title="确认下单" width="640px">
			<el-form :model="checkout" label-width="100px">
				<el-form-item label="分销商" required>
					<el-select v-model="checkout.customer_id" filterable placeholder="选择客户" style="width: 100%">
						<el-option v-for="c in customers" :key="c.id" :label="`${c.name}${c.code ? '（' + c.code + '）' : ''}`" :value="c.id" />
					</el-select>
				</el-form-item>
				<el-form-item label="提货仓库" required>
					<el-select v-model="checkout.warehouse_id" placeholder="选择仓库" style="width: 100%">
						<el-option v-for="w in warehouses" :key="w.id" :label="w.name" :value="w.id" />
					</el-select>
				</el-form-item>
				<el-form-item label="收货人">
					<el-input v-model="checkout.receiver_name" placeholder="默认取客户名" />
				</el-form-item>
				<el-form-item label="联系电话">
					<el-input v-model="checkout.receiver_phone" placeholder="默认取客户电话" />
				</el-form-item>
				<el-form-item label="收货地址">
					<el-input v-model="checkout.receiver_address" placeholder="默认取客户地址" />
				</el-form-item>
				<el-form-item label="订单明细">
					<div class="sp-ck-summary">
						<div v-for="c in cartItems" :key="c.product_id" class="sp-ck-line">
							<span class="sp-ck-name">{{ c.name }}</span>
							<span class="sp-ck-qty">{{ c.qty_large }} {{ c.unit_large }} × {{ fmtPrice(c.price_large) }}</span>
							<span class="sp-ck-amt">{{ fmtPrice(lineAmount(c)) }}</span>
						</div>
						<div class="sp-ck-grand">
							<span>应付金额</span>
							<span class="sp-ck-grand-amt">{{ fmtPrice(cartTotal) }}</span>
						</div>
					</div>
				</el-form-item>
			</el-form>
			<template #footer>
				<el-button @click="checkoutOpen = false">取消</el-button>
				<el-button type="primary" :loading="submitting" @click="submitOrder">确认下单（生成申报单）</el-button>
			</template>
		</el-dialog>
	</div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Search, ShoppingCart, Plus, Delete } from '@element-plus/icons-vue'
import FinEmpty from '../../../finance/_FinEmpty.vue'
import mallApi from '@/api/mall'

const keyword = ref('')
const list = ref([])
const total = ref(0)
const page = ref(1)
const page_size = 12
const loading = ref(false)

const cartOpen = ref(false)
const checkoutOpen = ref(false)
const submitting = ref(false)

// 购物车：{product_id, name, spec_display, unit_large, price_large, stock, qty_large}
const cart = reactive({ items: [] })
const cartItems = computed(() => cart.items)
const cartCount = computed(() => cart.items.reduce((n, c) => n + c.qty_large, 0))
const cartTotal = computed(() => cart.items.reduce((n, c) => n + lineAmount(c), 0))

const customers = ref([])
const warehouses = ref([])
const checkout = reactive({ customer_id: null, warehouse_id: null, receiver_name: '', receiver_phone: '', receiver_address: '' })

const fmtPrice = (v) => Number(v || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const lineAmount = (c) => Number(c.qty_large || 0) * Number(c.price_large || 0)

async function loadCatalog() {
	loading.value = true
	try {
		const res = await mallApi.product.catalog({ keyword: keyword.value, page: page.value, page_size })
		if (res.code === 200) {
			list.value = res.data.list || []
			total.value = res.data.total || 0
		}
	} catch {
		ElMessage.error('加载目录失败')
	} finally {
		loading.value = false
	}
}
function onPage(p) {
	page.value = p
	loadCatalog()
}

function addToCart(p) {
	const exist = cart.items.find((c) => c.product_id === p.product_id)
	if (exist) { exist.qty_large += 1; return }
	cart.items.push({
		product_id: p.product_id,
		name: p.name,
		spec_display: p.spec_display || '',
		unit_large: p.unit_large,
		price_large: p.price_large,
		qty_large: 1,
	})
	ElMessage.success('已加入购物车')
}
function removeCart(c) {
	cart.items = cart.items.filter((x) => x.product_id !== c.product_id)
}
function openCart() {
	cartOpen.value = true
}

async function loadCustomers() {
	try {
		const res = await mallApi.product.shopCustomers()
		if (res.code === 200) customers.value = res.data.customers || []
	} catch { /* 静默 */ }
}
async function loadWarehouses() {
	try {
		const res = await mallApi.order.warehouses()
		if (res.code === 200) warehouses.value = res.data.warehouses || []
	} catch { /* 静默 */ }
}

// 客户端幂等键：同一购物车内容重复提交（双击/重试）复用同一键，
// 后端只建一条订单与申报单（F5-HARDENING 第 1 项防重复提交）。
let submitIdempotencyKey = null

async function submitOrder() {
	if (!checkout.customer_id) return ElMessage.warning('请选择分销商')
	if (!checkout.warehouse_id) return ElMessage.warning('请选择提货仓库')
	const items = cart.items.filter((c) => c.qty_large > 0).map((c) => ({
		product_id: c.product_id,
		qty_large: c.qty_large,
		qty_medium: 0,
		qty_small: 0,
	}))
	if (!items.length) return ElMessage.warning('购物车为空')

	// 首次点击生成键；成功后清空键，下次下单换新键。
	if (!submitIdempotencyKey) {
		submitIdempotencyKey = `shop-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`
	}

	submitting.value = true
	try {
		const res = await mallApi.order.create({
			...checkout,
			items,
			idempotency_key: submitIdempotencyKey,
		})
		if (res.code === 200 || res.code === 201) {
			ElMessage.success(`下单成功，申报单号：${res.data?.sales_order_no || ''}`)
			cart.items = []
			checkoutOpen.value = false
			cartOpen.value = false
			submitIdempotencyKey = null
			// 刷新目录库存
			loadCatalog()
		} else {
			ElMessage.error(res.msg || '下单失败')
		}
	} catch (e) {
		const msg = e?.response?.data?.msg || e?.message || '下单失败'
		ElMessage.error(msg)
	} finally {
		submitting.value = false
	}
}

onMounted(() => {
	loadCatalog()
	loadCustomers()
	loadWarehouses()
})
</script>

<style>
@import '../../_shared.css';
</style>

<style scoped>
.f5-shop-page { padding: 16px 20px; }
.sp-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
.sp-title h2 { margin: 0; font-size: 16px; color: #303133; }
.sp-sub { font-size: 12px; color: #909399; margin-left: 8px; }
.sp-actions { display: flex; align-items: center; gap: 10px; }
.sp-cart-badge :deep(.el-badge__content) { background: #f56c6c; }
.sp-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 16px; }
.sp-card { background: #fff; border: 1px solid #ebeef5; border-radius: 4px; overflow: hidden; transition: box-shadow 0.2s; }
.sp-card:hover { box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08); }
.sp-card-img { height: 160px; background: #f5f7fa; display: flex; align-items: center; justify-content: center; position: relative; }
.sp-card-img img { max-width: 100%; max-height: 100%; object-fit: cover; }
.sp-card-img-ph { font-size: 24px; color: #c0c4cc; font-weight: 600; }
.sp-card-body { padding: 12px; }
.sp-card-name { font-size: 14px; color: #303133; font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.sp-card-spec { font-size: 12px; color: #909399; margin-top: 2px; }
.sp-card-slogan { font-size: 12px; color: #e6a23c; margin-top: 4px; min-height: 16px; }
.sp-card-price { margin-top: 8px; display: flex; align-items: baseline; }
.sp-price { font-size: 16px; color: #f56c6c; font-weight: 600; font-variant-numeric: tabular-nums; }
.sp-price-unit { font-size: 12px; color: #909399; }
.sp-card-buy { margin-top: 10px; display: flex; justify-content: space-between; align-items: center; }
.sp-stock { font-size: 12px; color: #909399; }
.sp-pager { display: flex; justify-content: flex-end; align-items: center; height: 52px; }
.sp-cart-row { display: flex; align-items: center; gap: 10px; padding: 10px 0; border-bottom: 1px dashed #ebeef5; }
.sp-cart-info { flex: 1; min-width: 0; }
.sp-cart-name { font-size: 13px; color: #303133; }
.sp-cart-spec { font-size: 12px; color: #909399; }
.sp-cart-qty { display: flex; flex-direction: column; align-items: flex-end; gap: 2px; }
.sp-qty-unit { font-size: 12px; color: #909399; font-variant-numeric: tabular-nums; }
.sp-cart-amt { font-size: 13px; color: #f56c6c; font-weight: 600; font-variant-numeric: tabular-nums; min-width: 80px; text-align: right; }
.sp-cart-total { display: flex; justify-content: space-between; padding: 14px 0; font-size: 14px; color: #303133; }
.sp-cart-total-amt { font-size: 16px; color: #f56c6c; font-weight: 700; font-variant-numeric: tabular-nums; }
.sp-checkout-btn { width: 100%; }
.sp-ck-summary { width: 100%; }
.sp-ck-line { display: flex; justify-content: space-between; font-size: 13px; color: #606266; padding: 4px 0; }
.sp-ck-name { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.sp-ck-qty { margin: 0 12px; color: #909399; font-variant-numeric: tabular-nums; }
.sp-ck-amt { font-variant-numeric: tabular-nums; }
.sp-ck-grand { display: flex; justify-content: space-between; padding: 10px 0 0; border-top: 1px solid #ebeef5; margin-top: 8px; font-size: 14px; color: #303133; }
.sp-ck-grand-amt { color: #f56c6c; font-weight: 700; font-variant-numeric: tabular-nums; }
</style>
