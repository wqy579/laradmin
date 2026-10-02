<template>
	<div class="biz-list">
		<div class="status-tabs">
			<div v-for="t in statusTabs" :key="t.value" :class="['status-tab', { active: activeTab === t.value }]" @click="switchTab(t.value)">{{ t.label }}</div>
		</div>
		<div class="toolbar">
			<div class="left-panel">
				<el-input v-model="keyword" placeholder="搜索订单号/客户" clearable size="small" style="width:180px" @keyup.enter="fetchData" @clear="fetchData" />
				<el-button size="small" @click="fetchData">查询</el-button>
			</div>
		</div>
		<div v-if="selectedRows.length" class="select-bar">
			<span>已选 {{ selectedRows.length }} 条</span>
			<el-button size="small" @click="batchPrint">批量打印</el-button>
			<el-button size="small" link @click="clearSelection">取消选择</el-button>
		</div>
		<sTable ref="tableRef" tableName="dispatch_list" :data="data" :columns="columns" :loading="loading" height="100%" stripe
			@selectionChange="selectedRows = $event">
			<template #status="{ row }">
				<el-tag :type="statusType(row.status)" size="small">{{ statusLabel(row.status) }}</el-tag>
			</template>
		</sTable>
	</div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import businessApi from '@/api/business'
import sTable from '@/components/sTable/index.vue'

const data = ref([])
const loading = ref(false)
const keyword = ref('')
const activeTab = ref('all')
const selectedRows = ref([])
const tableRef = ref(null)

const statusTabs = [
	{ value: 'all', label: '全部' },
	{ value: '待配送', label: '待配送' },
	{ value: '配送中', label: '配送中' },
	{ value: '已收款', label: '已完成' },
]

const statusType = (s) => ({ '待配送': 'info', '配送中': 'primary', '已收款': 'success', '待收款': 'danger' }[s] || 'info')
const statusLabel = (s) => ({ '待配送': '待配送', '配送中': '配送中', '已收款': '已完成', '待收款': '待收款' }[s] || s)

const columns = [
	{ type: 'checkbox', width: 48, fixed: 'left' },
	{ prop: 'order_no', title: '订单编号', width: 120, slots: { default: 'order_no' } },
	{ prop: 'customer_name', title: '客户', width: 150 },
	{ prop: 'warehouse_name', title: '仓库', width: 100 },
	{ prop: 'salesman_name', title: '业务员', width: 90 },
	{ prop: 'total_amount', title: '金额', width: 100, align: 'right' },
	{ prop: 'status', title: '状态', width: 90, align: 'center', slots: { default: 'status' } },
	{ prop: 'order_date', title: '日期', width: 100 },
	{ prop: 'delivery_person_name', title: '配送员', width: 90 },
]

const switchTab = (v) => { activeTab.value = v; fetchData() }
const clearSelection = () => { selectedRows.value = []; tableRef.value?.clearCheckboxRow?.() }

async function fetchData() {
	loading.value = true
	try {
		const params = { page_size: 9999 }
		if (activeTab.value !== 'all') params.status = activeTab.value
		if (keyword.value) params.keyword = keyword.value
		const res = await businessApi.salesOrder.list.get(params)
		if (res.code === 200) {
			let list = res.data?.list || []
			// 只显示配送相关状态的订单
			const dispatchStatuses = ['待配送', '配送中', '已收款', '待收款']
			data.value = list.filter(r => dispatchStatuses.includes(r.status))
		}
	} catch { ElMessage.error('加载失败') }
	finally { loading.value = false }
}
const batchPrint = async () => {
	for (const row of selectedRows.value) {
		try { await businessApi.salesOrder.print.post(row.id) } catch {}
	}
	ElMessage.success(`已打印 ${selectedRows.value.length} 单`)
	clearSelection(); fetchData()
}
onMounted(() => fetchData())
</script>

<style scoped>
.biz-list { height: 100%; display: flex; flex-direction: column; }
.status-tabs { display: flex; border-bottom: 1px solid var(--el-border-color); padding: 0 8px; }
.status-tab { padding: 6px 16px; cursor: pointer; font-size: 13px; color: var(--el-text-color-regular); border-bottom: 2px solid transparent; }
.status-tab.active { color: var(--el-color-primary); font-weight: 600; border-bottom-color: var(--el-color-primary); }
.toolbar { display: flex; justify-content: flex-end; padding: 8px; }
.left-panel { display: flex; gap: 8px; }
.select-bar { display: flex; align-items: center; gap: 8px; padding: 6px 12px; background: var(--el-color-primary-light-9); font-size: 12px; }
</style>
