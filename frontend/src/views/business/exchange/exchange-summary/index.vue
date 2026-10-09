<template>
	<div class="page">
		<div class="header"><span class="title">换货查询</span>
			<div class="filter">
				<el-date-picker v-model="dateRange" type="daterange" value-format="YYYY-MM-DD" range-separator="至" start-placeholder="开始" end-placeholder="结束" style="width:240px" @change="onDateChange" />
				<el-button type="primary" @click="load">查询</el-button>
				<el-button @click="reset">重置</el-button>
			</div>
		</div>
		<div class="cards">
			<div class="card-item" v-for="c in cards" :key="c.key">
				<div class="label">{{ c.label }}</div>
				<div class="value"><span v-if="c.key !== 'order_count'">¥</span>{{ format(c.value) }}</div>
			</div>
		</div>
		<el-card shadow="never" class="block">
			<template #header><div class="block-title">换货原因分布</div></template>
			<div ref="reasonEl" class="chart"></div>
		</el-card>
		<el-row :gutter="16">
			<el-col :span="12">
				<el-card shadow="never" class="block">
					<template #header><div class="block-title">换出商品 TOP10</div></template>
					<el-table :data="outRank" border size="small" max-height="360">
						<el-table-column type="index" label="#" width="40" align="center" />
						<el-table-column prop="product_name" label="商品" min-width="140" />
						<el-table-column prop="qty" label="数量" width="80" align="center" />
						<el-table-column prop="amount" label="金额" width="110" align="right" />
					</el-table>
				</el-card>
			</el-col>
			<el-col :span="12">
				<el-card shadow="never" class="block">
					<template #header><div class="block-title">换入商品 TOP10</div></template>
					<el-table :data="inRank" border size="small" max-height="360">
						<el-table-column type="index" label="#" width="40" align="center" />
						<el-table-column prop="product_name" label="商品" min-width="140" />
						<el-table-column prop="qty" label="数量" width="80" align="center" />
						<el-table-column prop="amount" label="金额" width="110" align="right" />
					</el-table>
				</el-card>
			</el-col>
		</el-row>
		<el-card shadow="never" class="block">
			<template #header><div class="block-title">客户换货明细</div></template>
			<el-table :data="detailList" border stripe v-loading="loading" size="small">
				<el-table-column prop="customer_name" label="客户" min-width="160" />
				<el-table-column prop="exchange_times" label="换货次数" width="90" align="center" />
				<el-table-column label="换出金额" width="120" align="right"><template #default="{ row }">¥{{ row.amount_out }}</template></el-table-column>
				<el-table-column label="换入金额" width="120" align="right"><template #default="{ row }">¥{{ row.amount_in }}</template></el-table-column>
				<el-table-column label="差价" width="120" align="right"><template #default="{ row }"><span :style="{ color: row.diff > 0 ? '#f5222d' : (row.diff < 0 ? '#67c23a' : '#606266') }">{{ row.diff > 0 ? '+' : '' }}¥{{ row.diff }}</span></template></el-table-column>
				<el-table-column prop="last_exchange_date" label="最近换货" width="120" align="center" />
			</el-table>
			<el-pagination class="pager" layout="total, sizes, prev, pager, next, jumper" :total="detailTotal" :page-sizes="[10, 20, 50]" :page-size="query.page_size" :current-page="query.page" @current-change="(p) => { query.page = p; loadDetail(); }" @size-change="(s) => { query.page_size = s; loadDetail(); }" />
		</el-card>
	</div>
</template>

<script setup>
import { ref, reactive, onMounted, onBeforeUnmount, nextTick } from 'vue';
import * as echarts from 'echarts';
import api from '@/api/business.js';
const cards = ref([]); const outRank = ref([]); const inRank = ref([]); const detailList = ref([]); const detailTotal = ref(0); const loading = ref(false);
const reasonEl = ref(null); let chart = null;
const query = reactive({ start_date: '', end_date: '', page: 1, page_size: 20 });
const dateRange = ref([]);
const onDateChange = (val) => { query.start_date = val?.[0] || ''; query.end_date = val?.[1] || ''; };
const format = (v) => (typeof v === 'number' ? v.toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : v);
const load = () => { loadCards(); loadReason(); loadRank(); loadDetail(); };
const loadCards = async () => { const res = await api.exchangeSummary.index.get({ start_date: query.start_date, end_date: query.end_date }); cards.value = res.data?.cards || []; };
const loadReason = async () => { const res = await api.exchangeSummary.reasonDistribution.get({ start_date: query.start_date, end_date: query.end_date }); const list = res.data?.list || []; renderReason(list); };
const renderReason = (list) => { if (!reasonEl.value) return; if (!chart) chart = echarts.init(reasonEl.value); chart.setOption({ tooltip: { trigger: 'axis' }, grid: { left: 60, right: 20, top: 20, bottom: 30 }, xAxis: { type: 'category', data: list.map(d => d.reason || '未填') }, yAxis: { type: 'value' }, series: [{ type: 'bar', data: list.map(d => d.count), itemStyle: { color: '#409eff' }, label: { show: true, position: 'top' } }] }); };
const loadRank = async () => { const res = await api.exchangeSummary.productRank.get({ start_date: query.start_date, end_date: query.end_date }); outRank.value = res.data?.out || []; inRank.value = res.data?.in || []; };
const loadDetail = async () => { loading.value = true; try { const res = await api.exchangeSummary.customerDetail.get(query); detailList.value = res.data?.list || []; detailTotal.value = res.data?.total || 0; } finally { loading.value = false; } };
const reset = () => { Object.assign(query, { start_date: '', end_date: '', page: 1 }); dateRange.value = []; load(); };
const onResize = () => chart && chart.resize();
onMounted(() => { load(); window.addEventListener('resize', onResize); });
onBeforeUnmount(() => { window.removeEventListener('resize', onResize); chart && chart.dispose(); });
</script>
<style scoped>
.page { background: #f0f2f5; padding: 16px 24px; min-height: 100vh; }
.header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
.title { font-size: 18px; font-weight: 700; }
.filter { display: flex; gap: 12px; flex-wrap: wrap; }
.cards { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 16px; }
.card-item { background: #fff; border-radius: 6px; padding: 18px 20px; box-shadow: 0 1px 4px rgba(0,0,0,.06); }
.card-item .label { color: #909399; font-size: 13px; }
.card-item .value { color: #303133; font-size: 24px; font-weight: 700; margin-top: 8px; }
.block { margin-bottom: 16px; }
.block-title { font-weight: 600; }
.chart { height: 300px; }
.pager { margin-top: 12px; justify-content: flex-end; }
</style>
