<template>
	<div class="page">
		<div class="header">
			<span class="title">积分兑换</span>
			<div>
				<el-button @click="dialog.product = true">新增兑换商品</el-button>
				<el-button type="primary" @click="dialog.order = true">代客发起兑换</el-button>
			</div>
		</div>

		<!-- 统计 -->
		<el-row :gutter="12" class="stats">
			<el-col :span="4"><el-card shadow="never" class="stat"><div class="num">{{ orderStats.pending_count }}</div><div class="lbl">待审核</div></el-card></el-col>
			<el-col :span="4"><el-card shadow="never" class="stat"><div class="num">{{ orderStats.approved_count }}</div><div class="lbl">待发货</div></el-card></el-col>
			<el-col :span="4"><el-card shadow="never" class="stat"><div class="num">{{ orderStats.shipped_count }}</div><div class="lbl">已发货</div></el-card></el-col>
			<el-col :span="4"><el-card shadow="never" class="stat"><div class="num">{{ orderStats.completed_count }}</div><div class="lbl">已完成</div></el-card></el-col>
			<el-col :span="8"><el-card shadow="never" class="stat"><div class="num">{{ fmt(orderStats.total_points_used) }}</div><div class="lbl">已消耗积分</div></el-card></el-col>
		</el-row>

		<!-- 兑换商品 -->
		<el-card shadow="never" class="card">
			<div class="card-head">
				<span class="card-title">兑换商品</span>
				<el-switch v-model="onlyOn" @change="loadProducts">
					<template #inactive-text>全部</template>
					<template #active-text>仅上架</template>
				</el-switch>
			</div>
			<el-table :data="products" border stripe v-loading="productLoading" size="small">
				<el-table-column prop="product_no" label="商品编号" width="120" />
				<el-table-column prop="name" label="商品名称" width="150" show-overflow-tooltip />
				<el-table-column prop="spec" label="规格" width="100" show-overflow-tooltip />
				<el-table-column prop="points" label="所需积分" width="90" align="right">
					<template #default="{ row }"><span class="pts">{{ row.points }}</span></template>
				</el-table-column>
				<el-table-column prop="stock" label="库存" width="80" align="center" />
				<el-table-column prop="exchanged" label="已兑换" width="80" align="center" />
				<el-table-column prop="limit_per_person" label="每人限购" width="90" align="center">
					<template #default="{ row }">{{ row.limit_per_person > 0 ? row.limit_per_person : '不限' }}</template>
				</el-table-column>
				<el-table-column label="状态" width="80" align="center">
					<template #default="{ row }">
						<el-tag :type="row.status === 1 ? 'success' : 'info'" size="small">
							{{ row.status === 1 ? '上架' : '下架' }}
						</el-tag>
					</template>
				</el-table-column>
				<el-table-column label="操作" width="200" align="center" fixed="right">
					<template #default="{ row }">
						<el-button type="primary" link size="small" @click="editProduct(row)">编辑</el-button>
						<el-button v-if="row.status === 1" type="warning" link size="small" @click="turnOff(row)">下架</el-button>
						<el-button v-else type="success" link size="small" @click="turnOn(row)">上架</el-button>
						<el-popconfirm title="确定删除该商品？" @confirm="delProduct(row)">
							<template #reference><el-button type="danger" link size="small">删除</el-button></template>
						</el-popconfirm>
					</template>
				</el-table-column>
			</el-table>
		</el-card>

		<!-- 兑换订单 -->
		<el-card shadow="never" class="card">
			<div class="card-head">
				<span class="card-title">兑换订单</span>
				<el-select v-model="searchForm.status" clearable size="small" placeholder="状态" style="width:140px" @change="loadOrders">
					<el-option label="待审核" :value="0" />
					<el-option label="已审核待发货" :value="1" />
					<el-option label="已发货" :value="2" />
					<el-option label="已完成" :value="3" />
					<el-option label="已驳回" :value="4" />
					<el-option label="已取消" :value="5" />
				</el-select>
				<el-input v-model="searchForm.keyword" clearable size="small" placeholder="单号/会员/商品"
					style="width:200px" @clear="loadOrders" @keyup.enter="loadOrders" />
			</div>
			<el-table :data="orders" border stripe v-loading="orderLoading" size="small"
				@selection-change="onOrderSelect">
				<el-table-column type="selection" width="42" />
				<el-table-column prop="order_no" label="兑换单号" width="170" />
				<el-table-column label="会员" width="140">
					<template #default="{ row }">{{ row.member_name }}<span class="muted"> {{ row.member_no }}</span></template>
				</el-table-column>
				<el-table-column label="商品" min-width="160" show-overflow-tooltip>
					<template #default="{ row }">{{ row.product_name }}<span class="muted"> {{ row.product_spec || '' }}</span></template>
				</el-table-column>
				<el-table-column label="数量" width="70" align="center">
					<template #default="{ row }">×{{ row.quantity }}</template>
				</el-table-column>
				<el-table-column label="总积分" width="90" align="right">
					<template #default="{ row }"><span class="pts">{{ row.total_points }}</span></template>
				</el-table-column>
				<el-table-column label="收货信息" min-width="180" show-overflow-tooltip>
					<template #default="{ row }">{{ row.contact_phone }}{{ row.shipping_address ? ' · ' + row.shipping_address : '' }}</template>
				</el-table-column>
				<el-table-column label="状态" width="110" align="center">
					<template #default="{ row }">
						<el-tag :type="orderStatusType(row.status)" size="small">{{ orderStatusName(row.status) }}</el-tag>
					</template>
				</el-table-column>
				<el-table-column label="操作" width="260" align="center" fixed="right">
					<template #default="{ row }">
						<el-button type="primary" link size="small" @click="viewOrder(row)">详情</el-button>
						<el-button v-if="row.status === 0" type="success" link size="small" @click="approve(row)">审核</el-button>
						<el-button v-if="row.status === 0" type="danger" link size="small" @click="reject(row)">驳回</el-button>
						<el-button v-if="row.status === 1" type="primary" link size="small" @click="ship(row)">发货</el-button>
						<el-button v-if="row.status === 2" type="success" link size="small" @click="complete(row)">完成</el-button>
						<el-button v-if="!isFinal(row.status)" type="warning" link size="small" @click="cancel(row)">取消</el-button>
					</template>
				</el-table-column>
			</el-table>
			<el-pagination class="pager" background layout="total, prev, pager, next, sizes"
				:total="orderTotal" v-model:current-page="orderPage" v-model:page-size="orderPageSize"
				:page-sizes="[10, 20, 50]" @current-change="loadOrders" @size-change="onOrderPageSize" />
		</el-card>
	</div>

	<ProductDialog v-if="dialog.product" v-model:visible="dialog.product" :record="currentProduct" @success="loadProducts" />
	<OrderDialog v-if="dialog.order" v-model:visible="dialog.order" @success="onOrderChanged" />
	<OrderDetail v-if="dialog.orderDetail" v-model:visible="dialog.orderDetail" :order="currentOrder" />
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import businessApi from '@/api/business'
import ProductDialog from '../components/point-product-dialog.vue'
import OrderDialog from '../components/point-order-dialog.vue'
import OrderDetail from '../components/point-order-detail.vue'

const fmt = (n) => Number(n || 0).toLocaleString()

// ---------- 商品 ----------
const products = ref([])
const productLoading = ref(false)
const onlyOn = ref(false)

const loadProducts = async () => {
	productLoading.value = true
	try {
		const api = onlyOn.value ? businessApi.pointProduct.onShelf : businessApi.pointProduct.list
		const res = await api.get({})
		products.value = res.data?.list || res.data || []
	} finally { productLoading.value = false }
}
const dialog = reactive({ product: false, order: false, orderDetail: false })
const currentProduct = ref(null)
const editProduct = (row) => { currentProduct.value = row; dialog.product = true }
const turnOn = async (row) => {
	const res = await businessApi.pointProduct.turnOn.post(row.id)
	if (res.code === 200) { ElMessage.success('已上架'); loadProducts() }
}
const turnOff = async (row) => {
	const res = await businessApi.pointProduct.turnOff.post(row.id)
	if (res.code === 200) { ElMessage.success('已下架'); loadProducts() }
}
const delProduct = async (row) => {
	const res = await businessApi.pointProduct.delete.delete(row.id)
	if (res.code === 200) { ElMessage.success('已删除'); loadProducts() }
}

// ---------- 订单 ----------
const orders = ref([])
const orderLoading = ref(false)
const orderTotal = ref(0)
const orderPage = ref(1)
const orderPageSize = ref(20)
const orderStats = reactive({ pending_count: 0, approved_count: 0, shipped_count: 0, completed_count: 0, total_points_used: 0 })
const searchForm = ref({ keyword: '', status: null })
const currentOrder = ref(null)
const selectedOrders = ref([])

const loadOrders = async () => {
	orderLoading.value = true
	try {
		const res = await businessApi.pointOrder.list.get({
			...searchForm.value,
			page: orderPage.value,
			page_size: orderPageSize.value,
		})
		orders.value = res.data?.list || []
		orderTotal.value = res.data?.pagination?.total ?? res.data?.total ?? 0
	} finally { orderLoading.value = false }
}
const loadOrderStats = async () => {
	const res = await businessApi.pointOrder.statistics.get()
	Object.assign(orderStats, res.data || {})
}
const onOrderPageSize = (size) => { orderPageSize.value = size; orderPage.value = 1; loadOrders() }
const onOrderSelect = (rows) => { selectedOrders.value = rows }
const onOrderChanged = () => { loadOrders(); loadOrderStats() }
const viewOrder = (row) => { currentOrder.value = row; dialog.orderDetail = true }

const orderStatuses = { 0: '待审核', 1: '已审核待发货', 2: '已发货', 3: '已完成', 4: '已驳回', 5: '已取消' }
const orderStatusName = (s) => orderStatuses[s] || '-'
const orderStatusType = (s) => ({ 0: 'warning', 1: 'primary', 2: '', 3: 'success', 4: 'danger', 5: 'info' }[s] || 'info')
const isFinal = (s) => [3, 4, 5].includes(s)

const approve = (row) => {
	ElMessageBox.confirm(`确定审核通过「${row.order_no}」？审核后将扣减会员 ${row.total_points} 积分与商品库存`, '审核确认', { type: 'warning' })
		.then(async () => {
			const res = await businessApi.pointOrder.approve.post(row.id)
			if (res.code === 200) { ElMessage.success(res.message || '审核通过，已扣减积分与库存'); onOrderChanged() }
		}).catch(() => {})
}
const reject = (row) => {
	ElMessageBox.prompt('请输入驳回原因', '驳回', { inputPattern: /\S+/, inputErrorMessage: '请填写原因' })
		.then(async ({ value }) => {
			const res = await businessApi.pointOrder.reject.post(row.id, { reason: value })
			if (res.code === 200) { ElMessage.success(res.message || '已驳回'); onOrderChanged() }
		}).catch(() => {})
}
const ship = (row) => {
	ElMessageBox.prompt('请输入发货单号', '发货', { inputPattern: /\S+/, inputErrorMessage: '请填写物流单号' })
		.then(async ({ value }) => {
			const res = await businessApi.pointOrder.ship.post(row.id, { ship_no: value })
			if (res.code === 200) { ElMessage.success(res.message || '已发货'); onOrderChanged() }
		}).catch(() => {})
}
const complete = async (row) => {
	const res = await businessApi.pointOrder.complete.post(row.id)
	if (res.code === 200) { ElMessage.success(res.message || '已完成'); onOrderChanged() }
}
const cancel = (row) => {
	ElMessageBox.prompt('请输入取消原因', '取消', { inputPattern: /\S+/, inputErrorMessage: '请填写原因' })
		.then(async ({ value }) => {
			const res = await businessApi.pointOrder.cancel.post(row.id, { reason: value })
			if (res.code === 200) { ElMessage.success(res.message || '已取消，积分与库存已退回'); onOrderChanged() }
		}).catch(() => {})
}

onMounted(() => { loadProducts(); loadOrders(); loadOrderStats() })
</script>

<style scoped>
.page { background: #f0f2f5; min-height: 100vh; padding: 0; }
.header { height: 60px; background: #fff; border-bottom: 1px solid #e8e8e8; padding: 0 24px; display: flex; align-items: center; justify-content: space-between; }
.title { font-size: 18px; font-weight: 700; color: #333; }
.stats { margin: 16px 24px 0; }
.stat { text-align: center; }
.stat .num { font-size: 20px; font-weight: 700; color: #333; }
.stat .lbl { font-size: 12px; color: #999; margin-top: 2px; }
.card { margin: 12px 24px 16px; border-radius: 4px; }
.card-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
.card-title { font-size: 15px; font-weight: 700; color: #333; }
.pts { color: var(--el-color-primary); font-weight: 700; }
.muted { color: #c0c4cc; font-size: 12px; }
.pager { margin-top: 12px; justify-content: flex-end; }
</style>
