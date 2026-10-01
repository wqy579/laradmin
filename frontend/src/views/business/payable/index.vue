<template>
	<div class="biz-list">
		<div class="toolbar">
			<div class="left-panel">
				<el-input v-model="keyword" placeholder="搜索供应商" clearable size="small" style="width:200px" @keyup.enter="fetchData" @clear="fetchData" />
				<el-button size="small" @click="fetchData">查询</el-button>
			</div>
		</div>
		<sTable ref="tableRef" tableName="finance_payable" :data="data" :columns="columns" :loading="loading" height="100%" stripe>
		</sTable>
	</div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import businessApi from '@/api/business'
import sTable from '@/components/sTable/index.vue'

const data = ref([])
const loading = ref(false)
const keyword = ref('')
const columns = [
	{ prop: 'supplier_name', title: '供应商', width: 200 },
	{ prop: 'paid_total', title: '已付款', width: 120, align: 'right', slots: { default: 'paid_total' } },
]

async function fetchData() {
	loading.value = true
	try {
		const res = await businessApi.pay.payable.get({})
		if (res.code === 200) {
			let list = res.data?.list || []
			if (keyword.value) list = list.filter(r => r.supplier_name?.includes(keyword.value))
			data.value = list
		}
	} catch { ElMessage.error('加载失败') }
	finally { loading.value = false }
}
onMounted(() => fetchData())
</script>

<style scoped>
.biz-list { height: 100%; display: flex; flex-direction: column; }
.toolbar { display: flex; justify-content: flex-end; padding: 8px; }
.left-panel { display: flex; gap: 8px; }
</style>
