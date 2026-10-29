<template>
	<div class="f5-manage-page f5-page">
		<!-- 商品上架管理 -->
		<div class="mg-card">
			<div class="mg-head">
				<div class="mg-title">
					<span>商品上架管理</span>
					<span class="mg-sub">价格单一数据源：始终读后台 products 实时价，同步即刷新读</span>
				</div>
				<div class="mg-actions">
					<el-button @click="syncPrice" :loading="syncing">
						<el-icon><Refresh /></el-icon>价格同步
					</el-button>
					<el-button type="primary" @click="openAdd">
						<el-icon><Plus /></el-icon>上架商品
					</el-button>
				</div>
			</div>

			<div class="mg-query">
				<el-form inline :model="shelfQuery" @submit.prevent>
					<el-form-item label="关键字">
						<el-input v-model="shelfQuery.keyword" placeholder="商品名称/编码" clearable @keyup.enter="loadShelf" @clear="loadShelf" />
					</el-form-item>
					<el-form-item label="状态">
						<el-select v-model="shelfQuery.is_on_shelf" placeholder="全部" clearable style="width: 120px" @change="loadShelf">
							<el-option label="已上架" :value="1" />
							<el-option label="已下架" :value="0" />
						</el-select>
					</el-form-item>
					<el-form-item>
						<el-button type="primary" @click="loadShelf">查询</el-button>
					</el-form-item>
				</el-form>
			</div>

			<el-table :data="shelfList" v-loading="shelfLoading" border>
				<el-table-column label="商品" min-width="220">
					<template #default="{ row }">
						<div class="mg-prod">
							<div class="mg-prod-name">{{ row.name }}</div>
							<div class="mg-prod-code">{{ row.code }} · {{ row.spec_display || row.spec || '' }}</div>
						</div>
					</template>
				</el-table-column>
				<el-table-column label="三档单价" min-width="180" align="right">
					<template #default="{ row }">
						<div class="mg-prices">
							<span>{{ fmtPrice(row.price_large) }}/{{ row.unit_large }}</span>
							<span>{{ fmtPrice(row.price_medium) }}/{{ row.unit_medium }}</span>
							<span>{{ fmtPrice(row.price_small) }}/{{ row.unit_small }}</span>
						</div>
					</template>
				</el-table-column>
				<el-table-column label="宣传语" min-width="160">
					<template #default="{ row }">{{ row.slogan || '—' }}</template>
				</el-table-column>
				<el-table-column label="排序" width="80" align="center">
					<template #default="{ row }">{{ row.sort }}</template>
				</el-table-column>
				<el-table-column label="状态" width="90" align="center">
					<template #default="{ row }">
						<el-tag :type="row.is_on_shelf ? 'success' : 'info'" size="small">{{ row.is_on_shelf ? '已上架' : '已下架' }}</el-tag>
					</template>
				</el-table-column>
				<el-table-column label="上架时间" width="160">
					<template #default="{ row }">{{ row.created_at || '—' }}</template>
				</el-table-column>
				<el-table-column label="操作" width="200" fixed="right">
					<template #default="{ row }">
						<el-button link type="primary" size="small" @click="openEdit(row)">编辑</el-button>
						<el-button link :type="row.is_on_shelf ? 'warning' : 'success'" size="small" @click="toggleShelf(row)">
							{{ row.is_on_shelf ? '下架' : '上架' }}
						</el-button>
						<el-button link type="danger" size="small" @click="removeShelf(row)">移除</el-button>
					</template>
				</el-table-column>
			</el-table>

			<div class="mg-pager" v-if="shelfTotal > shelfQuery.page_size">
				<el-pagination background layout="prev, pager, next, total" :total="shelfTotal" :page-size="shelfQuery.page_size" :current-page="shelfQuery.page" @current-change="(p) => { shelfQuery.page = p; loadShelf() }" />
			</div>
		</div>

		<!-- 订单处理 -->
		<div class="mg-card mg-card-order">
			<div class="mg-head">
				<div class="mg-title">
					<span>商城订单</span>
					<span class="mg-sub">接单/拒单仅更新商城侧，申报单在 Order 域继续流转</span>
				</div>
			</div>

			<div class="mg-query">
				<el-form inline :model="orderQuery" @submit.prevent>
					<el-form-item label="关键字">
						<el-input v-model="orderQuery.keyword" placeholder="商城单号/客户/申报单号" clearable @keyup.enter="loadOrders" @clear="loadOrders" />
					</el-form-item>
					<el-form-item label="状态">
						<el-select v-model="orderQuery.status" placeholder="全部" clearable style="width: 130px" @change="loadOrders">
							<el-option v-for="(label, v) in statusMap" :key="v" :label="label" :value="v" />
						</el-select>
					</el-form-item>
					<el-form-item>
						<el-button type="primary" @click="loadOrders">查询</el-button>
					</el-form-item>
				</el-form>
			</div>

			<el-table :data="orderList" v-loading="orderLoading" border>
				<el-table-column label="商城订单号" min-width="160">
					<template #default="{ row }">
						<el-link type="primary" @click="openDetail(row)">{{ row.order_no }}</el-link>
					</template>
				</el-table-column>
				<el-table-column label="分销商" min-width="120" prop="customer_name" />
				<el-table-column label="商品数" width="90" align="center">
					<template #default="{ row }">{{ row.items.length }}</template>
				</el-table-column>
				<el-table-column label="总数量" width="100" align="right">
					<template #default="{ row }">{{ row.total_qty }}</template>
				</el-table-column>
				<el-table-column label="总金额" width="120" align="right">
					<template #default="{ row }"><span class="mg-amount">{{ fmtPrice(row.total_amount) }}</span></template>
				</el-table-column>
				<el-table-column label="状态" width="90" align="center">
					<template #default="{ row }">
						<el-tag :type="row.status_tag_type" size="small">{{ row.status_label }}</el-tag>
					</template>
				</el-table-column>
				<el-table-column label="申报单号" min-width="160">
					<template #default="{ row }">
						<span v-if="row.sales_order_no">{{ row.sales_order_no }}</span>
						<span v-else class="mg-muted">—</span>
					</template>
				</el-table-column>
				<el-table-column label="下单时间" width="160">
					<template #default="{ row }">{{ row.created_at || '—' }}</template>
				</el-table-column>
				<el-table-column label="操作" width="160" fixed="right">
					<template #default="{ row }">
						<el-button link type="primary" size="small" @click="openDetail(row)">详情</el-button>
						<el-button v-if="row.status === 'pending'" link type="success" size="small" @click="doAccept(row)">接单</el-button>
						<el-button v-if="row.status === 'pending'" link type="danger" size="small" @click="doReject(row)">拒绝</el-button>
					</template>
				</el-table-column>
			</el-table>

			<div class="mg-pager" v-if="orderTotal > orderQuery.page_size">
				<el-pagination background layout="prev, pager, next, total" :total="orderTotal" :page-size="orderQuery.page_size" :current-page="orderQuery.page" @current-change="(p) => { orderQuery.page = p; loadOrders() }" />
			</div>
		</div>

		<!-- 上架/编辑弹窗 -->
		<el-dialog v-model="shelfDlg" class="f5-dialog" :title="shelfForm.id ? '编辑上架信息' : '上架商品'" width="560px">
			<el-form :model="shelfForm" label-width="100px">
				<el-form-item v-if="!shelfForm.id" label="选择商品" required>
					<el-select v-model="shelfForm.product_id" filterable remote :remote-method="searchOptions" :loading="optionsLoading" placeholder="搜索商品名称/编码" style="width: 100%">
						<el-option v-for="o in options" :key="o.product_id" :label="`${o.name}（${o.code}）`" :value="o.product_id" />
					</el-select>
				</el-form-item>
				<el-form-item v-else label="商品">
					<span>{{ shelfForm.name }}（{{ shelfForm.code }}）</span>
				</el-form-item>
				<el-form-item label="排序">
					<el-input-number v-model="shelfForm.sort" :min="0" />
				</el-form-item>
				<el-form-item label="宣传语">
					<el-input v-model="shelfForm.slogan" placeholder="前台卡片上的促销文案" maxlength="200" />
				</el-form-item>
				<el-form-item label="备注">
					<el-input v-model="shelfForm.remark" type="textarea" :rows="2" />
				</el-form-item>
			</el-form>
			<template #footer>
				<el-button @click="shelfDlg = false">取消</el-button>
				<el-button type="primary" :loading="shelfSubmitting" @click="submitShelf">保存</el-button>
			</template>
		</el-dialog>

		<!-- 订单详情弹窗 -->
		<el-dialog v-model="detailDlg" class="f5-dialog" title="订单详情" width="760px">
			<template v-if="detail">
				<div class="mg-detail-head">
					<div class="mg-detail-no">{{ detail.order_no }}</div>
					<el-tag :type="detail.status_tag_type" size="small">{{ detail.status_label }}</el-tag>
				</div>
				<el-descriptions :column="2" border size="small" class="mg-desc">
					<el-descriptions-item label="分销商">{{ detail.customer_name }}</el-descriptions-item>
					<el-descriptions-item label="下单时间">{{ detail.created_at || '—' }}</el-descriptions-item>
					<el-descriptions-item label="申报单号">{{ detail.sales_order_no || '—' }}</el-descriptions-item>
					<el-descriptions-item label="收货人">{{ detail.receiver_name || '—' }}</el-descriptions-item>
					<el-descriptions-item label="联系电话">{{ detail.receiver_phone || '—' }}</el-descriptions-item>
					<el-descriptions-item label="收货地址">{{ detail.receiver_address || '—' }}</el-descriptions-item>
					<el-descriptions-item label="接单时间">{{ detail.accepted_at || '—' }}</el-descriptions-item>
					<el-descriptions-item label="备注">{{ detail.accept_remark || '—' }}</el-descriptions-item>
				</el-descriptions>
				<el-table :data="detail.items" border size="small" class="mg-detail-table">
					<el-table-column label="商品" min-width="180">
						<template #default="{ row }">
							<div>{{ row.product_name }}</div>
							<div class="mg-muted">{{ row.product_code }} · {{ row.spec || '' }}</div>
						</template>
					</el-table-column>
					<el-table-column label="三档数量" min-width="150" align="right">
						<template #default="{ row }">
							<div>{{ row.qty_large }} {{ row.unit_large }} / {{ row.qty_medium }} {{ row.unit_medium }} / {{ row.qty_small }} {{ row.unit_small }}</div>
						</template>
					</el-table-column>
					<el-table-column label="数量" width="80" align="right" prop="quantity" />
					<el-table-column label="单价" width="100" align="right">
						<template #default="{ row }">{{ fmtPrice(row.price_small) }}/{{ row.unit_small }}</template>
					</el-table-column>
					<el-table-column label="金额" width="110" align="right">
						<template #default="{ row }"><span class="mg-amount">{{ fmtPrice(row.amount) }}</span></template>
					</el-table-column>
				</el-table>
				<div class="mg-detail-total">
					<span>合计</span>
					<span class="mg-detail-total-amt">{{ fmtPrice(detail.total_amount) }}</span>
				</div>
			</template>
		</el-dialog>
	</div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus, Refresh } from '@element-plus/icons-vue'
import mallApi from '@/api/mall'

const fmtPrice = (v) => Number(v || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

// —— 商品上架 ——
const shelfQuery = reactive({ keyword: '', is_on_shelf: null, page: 1, page_size: 10 })
const shelfList = ref([])
const shelfTotal = ref(0)
const shelfLoading = ref(false)
const syncing = ref(false)

const shelfDlg = ref(false)
const shelfSubmitting = ref(false)
const shelfForm = reactive({ id: null, product_id: null, name: '', code: '', sort: 0, slogan: '', remark: '' })
const options = ref([])
const optionsLoading = ref(false)

// —— 订单 ——
const statusMap = { pending: '待接单', accepted: '已接单', rejected: '已拒绝', cancelled: '已取消' }
const orderQuery = reactive({ keyword: '', status: null, page: 1, page_size: 10 })
const orderList = ref([])
const orderTotal = ref(0)
const orderLoading = ref(false)

const detailDlg = ref(false)
const detail = ref(null)

async function loadShelf() {
	shelfLoading.value = true
	try {
		const res = await mallApi.product.list({ keyword: shelfQuery.keyword, is_on_shelf: shelfQuery.is_on_shelf ?? '', page: shelfQuery.page, page_size: shelfQuery.page_size })
		if (res.code === 200) {
			shelfList.value = res.data.list || []
			shelfTotal.value = res.data.total || 0
		}
	} catch {
		ElMessage.error('加载上架商品失败')
	} finally {
		shelfLoading.value = false
	}
}

async function syncPrice() {
	syncing.value = true
	try {
		const res = await mallApi.product.syncPrice()
		if (res.code === 200) {
			ElMessage.success(`已同步 ${res.data.synced_count} 个商品价格`)
			loadShelf()
		}
	} catch {
		ElMessage.error('价格同步失败')
	} finally {
		syncing.value = false
	}
}

function openAdd() {
	Object.assign(shelfForm, { id: null, product_id: null, name: '', code: '', sort: 0, slogan: '', remark: '' })
	options.value = []
	shelfDlg.value = true
	searchOptions('')
}
function openEdit(row) {
	Object.assign(shelfForm, {
		id: row.id,
		product_id: row.product_id,
		name: row.name,
		code: row.code,
		sort: row.sort,
		slogan: row.slogan || '',
		remark: row.remark || '',
	})
	shelfDlg.value = true
}

async function searchOptions(kw) {
	optionsLoading.value = true
	try {
		const res = await mallApi.product.options({ keyword: kw, page: 1, page_size: 20 })
		if (res.code === 200) options.value = res.data.list || []
	} catch {
		options.value = []
	} finally {
		optionsLoading.value = false
	}
}

async function submitShelf() {
	if (!shelfForm.id && !shelfForm.product_id) return ElMessage.warning('请选择商品')
	shelfSubmitting.value = true
	try {
		const payload = { sort: shelfForm.sort, slogan: shelfForm.slogan, remark: shelfForm.remark }
		if (shelfForm.id) {
			const res = await mallApi.product.update(shelfForm.id, payload)
			if (res.code === 200) ElMessage.success('保存成功')
		} else {
			const res = await mallApi.product.add({ ...payload, product_id: shelfForm.product_id })
			if (res.code === 200 || res.code === 201) ElMessage.success('上架成功')
		}
		shelfDlg.value = false
		loadShelf()
	} catch (e) {
		ElMessage.error(e?.response?.data?.msg || e?.message || '保存失败')
	} finally {
		shelfSubmitting.value = false
	}
}

async function toggleShelf(row) {
	try {
		const res = await mallApi.product.toggle(row.id, { is_on_shelf: row.is_on_shelf ? 0 : 1 })
		if (res.code === 200) {
			ElMessage.success(res.msg || '已切换')
			loadShelf()
		}
	} catch (e) {
		ElMessage.error(e?.response?.data?.msg || '操作失败')
	}
}

async function removeShelf(row) {
	try {
		await ElMessageBox.confirm(`确认移除「${row.name}」的上架记录？`, '提示', { type: 'warning' })
	} catch { return }
	try {
		const res = await mallApi.product.remove(row.id)
		if (res.code === 200) {
			ElMessage.success('已移除')
			loadShelf()
		}
	} catch (e) {
		ElMessage.error(e?.response?.data?.msg || '移除失败')
	}
}

// —— 订单 ——
async function loadOrders() {
	orderLoading.value = true
	try {
		const res = await mallApi.order.list({ keyword: orderQuery.keyword, status: orderQuery.status ?? '', page: orderQuery.page, page_size: orderQuery.page_size })
		if (res.code === 200) {
			orderList.value = res.data.list || []
			orderTotal.value = res.data.total || 0
		}
	} catch {
		ElMessage.error('加载订单失败')
	} finally {
		orderLoading.value = false
	}
}

async function openDetail(row) {
	try {
		const res = await mallApi.order.detail(row.id)
		if (res.code === 200) {
			detail.value = res.data
			detailDlg.value = true
		}
	} catch (e) {
		ElMessage.error(e?.response?.data?.msg || '加载详情失败')
	}
}

async function doAccept(row) {
	let remark = ''
	try {
		const { value } = await ElMessageBox.prompt('确认接单，可填写备注', '接单', { inputPlaceholder: '选填', confirmButtonText: '确认接单' })
		remark = value || ''
	} catch { return }
	try {
		const res = await mallApi.order.accept(row.id, { remark })
		if (res.code === 200) {
			ElMessage.success('已接单')
			loadOrders()
		}
	} catch (e) {
		ElMessage.error(e?.response?.data?.msg || '接单失败')
	}
}

async function doReject(row) {
	try {
		await ElMessageBox.confirm('确认拒绝该商城订单？', '拒绝', { type: 'warning', confirmButtonText: '确认拒绝' })
	} catch { return }
	try {
		const res = await mallApi.order.reject(row.id, { remark: '后台拒绝' })
		if (res.code === 200) {
			ElMessage.success('已拒绝')
			loadOrders()
		}
	} catch (e) {
		ElMessage.error(e?.response?.data?.msg || '拒绝失败')
	}
}

onMounted(() => {
	loadShelf()
	loadOrders()
})
</script>

<style>
@import '../../_shared.css';
</style>

<style scoped>
.f5-manage-page { padding: 16px 20px; display: flex; flex-direction: column; gap: 16px; }
.mg-card { background: #fff; border: 1px solid #ebeef5; border-radius: 4px; }
.mg-head { display: flex; justify-content: space-between; align-items: center; padding: 14px 20px; border-bottom: 1px solid #ebeef5; }
.mg-title { font-size: 15px; color: #303133; font-weight: 500; }
.mg-sub { font-size: 12px; color: #909399; font-weight: 400; margin-left: 10px; }
.mg-actions { display: flex; gap: 8px; }
.mg-query { padding: 16px 20px; border-bottom: 1px solid #ebeef5; }
.mg-prod-name { font-size: 13px; color: #303133; }
.mg-prod-code { font-size: 12px; color: #909399; }
.mg-prices { display: flex; flex-direction: column; gap: 2px; font-size: 12px; color: #606266; font-variant-numeric: tabular-nums; }
.mg-amount { font-variant-numeric: tabular-nums; font-weight: 600; }
.mg-muted { color: #909399; font-size: 12px; }
.mg-pager { display: flex; justify-content: flex-end; align-items: center; height: 52px; }
.mg-card-order { margin-top: 0; }
.mg-detail-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
.mg-detail-no { font-size: 16px; font-weight: 600; color: #303133; }
.mg-desc { margin-bottom: 12px; }
.mg-detail-table { margin-bottom: 12px; }
.mg-detail-total { display: flex; justify-content: flex-end; align-items: baseline; gap: 12px; font-size: 14px; color: #303133; padding-top: 8px; }
.mg-detail-total-amt { font-size: 20px; font-weight: 700; color: #f56c6c; font-variant-numeric: tabular-nums; }
</style>
