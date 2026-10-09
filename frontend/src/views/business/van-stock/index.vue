<template>
	<div class="page">
		<div class="header"><span class="title">车上库存管理</span></div>
		<el-card shadow="never" class="card">
			<div class="filter">
				<el-select v-model="query.vehicle_warehouse_id" placeholder="选择车辆" filterable clearable style="width:200px" @change="load">
					<el-option v-for="w in vehicleWarehouses" :key="w.id" :label="w.name" :value="w.id" />
				</el-select>
				<el-input v-model="query.keyword" placeholder="搜索商品名/编码" style="width:200px" clearable @keyup.enter="load" />
				<el-button type="primary" @click="load">查询</el-button>
				<el-button @click="loadHistory">变动记录</el-button>
				<el-button type="warning" @click="loadWarning">库存预警</el-button>
			</div>
			<el-table :data="list" border stripe v-loading="loading">
				<el-table-column label="车辆仓" width="140"><template #default="{ row }">{{ row.warehouse_name }}</template></el-table-column>
				<el-table-column prop="product_code" label="编码" width="100" />
				<el-table-column prop="product_name" label="商品" min-width="160" />
				<el-table-column prop="spec" label="规格" width="100" />
				<el-table-column prop="stock_qty" label="库存" width="80" align="center" />
				<el-table-column prop="available_qty" label="可用" width="80" align="center" />
				<el-table-column prop="cost_price" label="成本价" width="90" align="right" />
				<el-table-column label="库存金额" width="110" align="right"><template #default="{ row }"><span class="amt">¥{{ row.stock_amount }}</span></template></el-table-column>
			</el-table>
			<el-pagination class="pager" layout="total, sizes, prev, pager, next, jumper" :total="total" :page-sizes="[10, 20, 50]" :page-size="query.page_size" :current-page="query.page" @current-change="(p) => { query.page = p; load(); }" @size-change="(s) => { query.page_size = s; load(); }" />
		</el-card>

		<el-dialog v-model="historyVisible" title="车上库存变动记录" width="900px">
			<el-table :data="historyList" border size="small" max-height="500">
				<el-table-column prop="created_at" label="时间" width="160" />
				<el-table-column prop="product_name" label="商品" min-width="140" />
				<el-table-column prop="warehouse_name" label="车辆仓" width="120" />
				<el-table-column prop="change_type" label="类型" width="90" align="center" />
				<el-table-column label="变动" width="70" align="center"><template #default="{ row }"><span :style="{ color: row.change_qty > 0 ? '#67c23a' : '#f56c6c' }">{{ row.change_qty > 0 ? '+' : '' }}{{ row.change_qty }}</span></template></el-table-column>
				<el-table-column prop="before_qty" label="前" width="60" align="center" />
				<el-table-column prop="after_qty" label="后" width="60" align="center" />
				<el-table-column prop="related_type" label="来源" width="120" />
				<el-table-column prop="remark" label="备注" min-width="120" show-overflow-tooltip />
			</el-table>
			<el-pagination layout="total, prev, pager, next" :total="historyTotal" :page-size="20" :current-page="historyPage" @current-change="(p) => { historyPage = p; loadHistory(); }" style="margin-top:12px;justify-content:flex-end" />
		</el-dialog>

		<el-dialog v-model="warningVisible" title="库存预警" width="800px">
			<el-table :data="warningList" border size="small">
				<el-table-column prop="warehouse_name" label="车辆仓" width="140" />
				<el-table-column prop="product_name" label="商品" min-width="140" />
				<el-table-column prop="spec" label="规格" width="100" />
				<el-table-column prop="stock_qty" label="当前库存" width="100" align="center"><template #default="{ row }"><span style="color:#f56c6c;font-weight:700">{{ row.stock_qty }}</span></template></el-table-column>
			</el-table>
		</el-dialog>
	</div>
</template>
<script setup>
import { ref, reactive, onMounted } from 'vue'; import { ElMessage } from 'element-plus'; import api from '@/api/business.js';
const list = ref([]); const total = ref(0); const loading = ref(false);
const query = reactive({ vehicle_warehouse_id: '', keyword: '', page: 1, page_size: 20 });
const vehicleWarehouses = ref([]);
const historyVisible = ref(false); const historyList = ref([]); const historyTotal = ref(0); const historyPage = ref(1);
const warningVisible = ref(false); const warningList = ref([]);
const load = async () => { loading.value = true; try { const res = await api.vanStock.list.get(query); list.value = res.data?.list || []; total.value = res.data?.total || 0; } finally { loading.value = false; } };
const loadHistory = async () => { historyVisible.value = true; const res = await api.vanStock.history.get({ ...query, page: historyPage.value }); historyList.value = res.data?.list || []; historyTotal.value = res.data?.total || 0; };
const loadWarning = async () => { warningVisible.value = true; const res = await api.vanStock.warning.get({ vehicle_warehouse_id: query.vehicle_warehouse_id }); warningList.value = res.data?.list || []; };
const loadVehicleWarehouses = async () => { const res = await api.warehouse.list.get({ type: 'vehicle', page_size: 200 }); vehicleWarehouses.value = res.data?.list || []; };
onMounted(() => { loadVehicleWarehouses(); load(); });
</script>
<style scoped>
.page { background: #f0f2f5; min-height: 100vh; }
.header { height: 60px; background: #fff; border-bottom: 1px solid #e8e8e8; padding: 0 24px; display: flex; align-items: center; }
.title { font-size: 18px; font-weight: 700; } .card { margin: 16px 24px; } .filter { margin-bottom: 16px; display: flex; gap: 12px; flex-wrap: wrap; }
.amt { color: #f5222d; font-weight: 700; } .pager { margin-top: 16px; justify-content: flex-end; }
</style>
