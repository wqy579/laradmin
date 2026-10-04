<template>
	<div class="promo-page">
		<div class="page-header">
			<span class="page-title">促销管理</span>
			<div class="header-actions">
				<el-button type="primary" @click="openForm()"><i class="el-icon-plus"></i>新增促销单</el-button>
				<el-button style="color:#fa8c16;border-color:#fa8c16" @click="reportVisible=true">促销效果报表</el-button>
			</div>
		</div>

		<div class="filter-bar">
			<el-input v-model="searchForm.promotion_no" placeholder="输入促销单号" clearable style="width:160px" @keyup.enter="search" />
			<el-select v-model="searchForm.type" placeholder="促销类型" clearable style="width:140px">
				<el-option label="限时折扣" value="discount" />
				<el-option label="满减促销" value="full_reduction" />
				<el-option label="买赠促销" value="buy_gift" />
				<el-option label="特价促销" value="special_price" />
			</el-select>
			<el-date-picker v-model="dateRange" type="daterange" range-separator="至" start-placeholder="开始" end-placeholder="结束" value-format="YYYY-MM-DD" style="width:260px" />
			<el-button type="primary" @click="search">查询</el-button>
			<el-button @click="resetSearch">重置</el-button>
		</div>

		<div class="table-card">
			<sTable
				ref="tableRef" tableName="promotion" :data="data" :columns="columns" :loading="loading"
				:total="total" :currentPage="currentPage" :pageSize="pageSize" :pageSizes="[10,20,50,100]"
				height="100%" stripe show-pagination emptyText="暂无促销单，点击右上角新增促销单开始创建促销活动"
				@pageChange="(p)=>{currentPage=p;fetchData()}" @pageSizeChange="(s)=>{pageSize=s;currentPage=1;fetchData()}"
			>
				<template #promotion_no="{ row }">
					<el-link type="primary" @click="openForm(row)">{{ row.promotion_no }}</el-link>
				</template>
				<template #type="{ row }">
					<el-tag :type="typeMeta[row.type]?.tag" size="small" effect="plain">{{ typeMeta[row.type]?.label }}</el-tag>
				</template>
				<template #time="{ row }">
					<div class="time-cell">{{ row.start_time }}</div>
					<div class="time-sub">{{ row.end_time }}</div>
				</template>
				<template #customer_scope="{ row }">{{ scopeMeta[row.customer_scope] }}</template>
				<template #discount_sum="{ row }"><span class="down">¥{{ fmt(row.discount_sum) }}</span></template>
				<template #status="{ row }">
					<el-tag :type="statusMeta[row.status]?.tag" size="small" effect="light">{{ statusMeta[row.status]?.label }}</el-tag>
				</template>
				<template #op="{ row }">
					<el-button v-if="['upcoming','active'].includes(row.status)" link type="primary" @click="openForm(row)">编辑</el-button>
					<el-button v-if="['upcoming','active'].includes(row.status)" link type="danger" @click="disable(row)">停用</el-button>
					<el-button link type="primary" @click="openForm(row)">查看</el-button>
					<el-button v-if="['ended','disabled'].includes(row.status)" link @click="copyRow(row)">复制</el-button>
				</template>
			</sTable>
		</div>

		<promotion-form v-if="formVisible" :visible="formVisible" :row="editingRow" @close="formVisible=false" @saved="onSaved" />
		<promotion-report v-if="reportVisible" v-model="reportVisible" />
	</div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import businessApi from '@/api/business'
import sTable from '@/components/sTable/index.vue'
import PromotionForm from './promotion-form.vue'
import PromotionReport from './promotion-report.vue'

const data = ref([]), total = ref(0), loading = ref(false)
const currentPage = ref(1), pageSize = ref(20)
const searchForm = reactive({ promotion_no: '', type: '' })
const dateRange = ref([])
const formVisible = ref(false), editingRow = ref(null)
const reportVisible = ref(false)

const typeMeta = {
	discount: { label: '限时折扣', tag: 'primary' },
	full_reduction: { label: '满减促销', tag: 'warning' },
	buy_gift: { label: '买赠促销', tag: 'success' },
	special_price: { label: '特价促销', tag: 'danger' },
}
const statusMeta = {
	draft: { label: '草稿', tag: 'info' },
	upcoming: { label: '未开始', tag: 'info' },
	active: { label: '进行中', tag: 'success' },
	ended: { label: '已结束', tag: 'info' },
	disabled: { label: '已停用', tag: 'danger' },
}
const scopeMeta = { all: '全部客户', level: '指定等级', specified: '指定客户' }

const columns = [
	{ prop: 'promotion_no', title: '促销单号', width: 160, slots: { default: 'promotion_no' } },
	{ prop: 'type', title: '促销类型', width: 110, slots: { default: 'type' } },
	{ prop: 'name', title: '促销名称', width: 180 },
	{ prop: 'time', title: '促销时间', width: 200, slots: { default: 'time' } },
	{ prop: 'customer_scope', title: '适用范围', width: 110, slots: { default: 'customer_scope' } },
	{ prop: 'item_count', title: '商品数', width: 80 },
	{ prop: 'discount_sum', title: '已优惠', width: 120, slots: { default: 'discount_sum' } },
	{ prop: 'used_orders', title: '使用订单', width: 90 },
	{ prop: 'status', title: '状态', width: 100, slots: { default: 'status' } },
	{ prop: 'op', title: '操作', width: 180, slots: { default: 'op' } },
]

function fmt(v) { return Number(v ?? 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }

async function fetchData() {
	loading.value = true
	try {
		const params = { page: currentPage.value, page_size: pageSize.value }
		if (searchForm.promotion_no) params.promotion_no = searchForm.promotion_no
		if (searchForm.type) params.type = searchForm.type
		if (dateRange.value?.length === 2) { params.start_date = dateRange.value[0]; params.end_date = dateRange.value[1] }
		const res = await businessApi.promotion.list.get(params)
		const d = res.data || {}
		data.value = d.list || []; total.value = d.total || 0
	} catch (e) { ElMessage.error(e?.response?.data?.message || '加载失败') }
	finally { loading.value = false }
}
function search() { currentPage.value = 1; fetchData() }
function resetSearch() { searchForm.promotion_no = ''; searchForm.type = ''; dateRange.value = []; search() }
function openForm(row) { editingRow.value = row || null; formVisible.value = true }
function onSaved() { formVisible.value = false; fetchData() }

async function disable(row) {
	try { await ElMessageBox.confirm('确认停用该促销？停用后订单将不再享受该促销优惠', '提示', { type: 'warning' }) }
	catch { return }
	try { await businessApi.promotion.disable.post(row.id); ElMessage.success('已停用'); fetchData() }
	catch (e) { ElMessage.error(e?.response?.data?.message || '操作失败') }
}
function copyRow(row) { editingRow.value = { ...row, id: undefined }; formVisible.value = true }

onMounted(fetchData)
</script>

<style scoped>
.promo-page { display: flex; flex-direction: column; height: 100%; background: #f0f2f5; padding: 16px; box-sizing: border-box; }
.page-header { height: 56px; background: #fff; border: 1px solid #e8e8e8; border-radius: 4px; display: flex; align-items: center; justify-content: space-between; padding: 0 16px; }
.page-title { font-size: 18px; font-weight: bold; color: #333; }
.filter-bar { margin-top: 12px; background: #fff; border-radius: 4px; padding: 12px 16px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
.table-card { margin-top: 12px; flex: 1; background: #fff; border-radius: 4px; padding: 8px; overflow: hidden; display: flex; flex-direction: column; }
.time-cell { color: #333; } .time-sub { color: #999; font-size: 12px; }
.down { color: #f5222d; font-weight: 500; }
</style>
