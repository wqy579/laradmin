<template>
	<div class="page">
		<div class="header"><span class="title">借还货查询</span>
			<div class="filter">
				<el-input v-model="query.customer_name" placeholder="客户" style="width:140px" clearable @keyup.enter="load" />
				<el-date-picker v-model="dateRange" type="daterange" value-format="YYYY-MM-DD" range-separator="至" start-placeholder="开始" end-placeholder="结束" style="width:240px" @change="onDateChange" />
				<el-button type="primary" @click="load">查询</el-button>
				<el-button @click="reset">重置</el-button>
			</div>
		</div>
		<div class="cards">
			<div class="card-item" v-for="c in cards" :key="c.key">
				<div class="label">{{ c.label }}</div>
				<div class="value"><span v-if="c.key !== 'customer_count'">¥</span>{{ format(c.value) }}</div>
			</div>
		</div>
		<el-card shadow="never" class="block">
			<template #header><div class="block-title">近 30 天借货 / 还货金额趋势</div></template>
			<div ref="trendEl" class="chart"></div>
		</el-card>
		<el-card shadow="never" class="block">
			<template #header><div class="block-title">客户借还明细（按客户汇总借货 / 已还 / 待还）</div></template>
			<el-table :data="detailList" border stripe v-loading="loading" size="small">
				<el-table-column prop="customer_name" label="客户" min-width="160" />
				<el-table-column prop="contact" label="联系人" width="120" />
				<el-table-column prop="contact_phone" label="电话" width="140" />
				<el-table-column prop="borrow_times" label="借货次数" width="90" align="center" />
				<el-table-column prop="borrow_qty" label="借货数量" width="90" align="center" />
				<el-table-column prop="returned_qty" label="已还数量" width="90" align="center" />
				<el-table-column label="待还数量" width="90" align="center"><template #default="{ row }"><span class="amt">{{ row.unreturned_qty }}</span></template></el-table-column>
				<el-table-column label="借货金额" width="120" align="right"><template #default="{ row }"><span class="amt">¥{{ row.borrow_total }}</span></template></el-table-column>
				<el-table-column label="已还金额" width="120" align="right"><template #default="{ row }">¥{{ row.returned_amount }}</template></el-table-column>
				<el-table-column label="未还金额" width="120" align="right"><template #default="{ row }"><span class="amt">¥{{ row.unreturned_amount }}</span></template></el-table-column>
				<el-table-column prop="last_borrow_date" label="最近借货" width="120" align="center" />
			</el-table>
			<el-pagination class="pager" layout="total, sizes, prev, pager, next, jumper" :total="detailTotal" :page-sizes="[10, 20, 50]" :page-size="query.page_size" :current-page="query.page" @current-change="(p) => { query.page = p; loadDetail(); }" @size-change="(s) => { query.page_size = s; loadDetail(); }" />
		</el-card>
	</div>
</template>

<script setup>
import { ref, reactive, onMounted, onBeforeUnmount, nextTick } from 'vue';
import * as echarts from 'echarts';
import api from '@/api/business.js';
const cards = ref([]); const detailList = ref([]); const detailTotal = ref(0); const loading = ref(false);
const trendEl = ref(null); let chart = null;
const query = reactive({ customer_name: '', start_date: '', end_date: '', page: 1, page_size: 20 });
const dateRange = ref([]);
const onDateChange = (val) => { query.start_date = val?.[0] || ''; query.end_date = val?.[1] || ''; };
const format = (v) => (typeof v === 'number' ? v.toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : v);
const load = () => { loadCards(); loadDetail(); loadTrend(); };
const loadCards = async () => { const res = await api.borrowSummary.index.get({ customer_name: query.customer_name, start_date: query.start_date, end_date: query.end_date }); cards.value = res.data?.cards || []; };
const loadDetail = async () => { loading.value = true; try { const res = await api.borrowSummary.customerDetail.get(query); detailList.value = res.data?.list || []; detailTotal.value = res.data?.total || 0; } finally { loading.value = false; } };
const loadTrend = async () => { const res = await api.borrowSummary.trend.get({ days: 30 }); const list = res.data?.list || []; renderTrend(list); };
const renderTrend = (list) => {
	if (!trendEl.value) return;
	if (!chart) chart = echarts.init(trendEl.value);
	chart.setOption({
		tooltip: { trigger: 'axis' },
		legend: { data: ['借货', '还货'], bottom: 0 },
		grid: { left: 50, right: 20, top: 20, bottom: 40 },
		xAxis: { type: 'category', data: list.map(d => d.date.slice(5)) },
		yAxis: { type: 'value' },
		series: [
			{ name: '借货', type: 'line', smooth: true, areaStyle: {}, data: list.map(d => d.borrow), itemStyle: { color: '#409eff' } },
			{ name: '还货', type: 'line', smooth: true, areaStyle: {}, data: list.map(d => d.return), itemStyle: { color: '#67c23a' } },
		],
	});
};
const reset = () => { Object.assign(query, { customer_name: '', start_date: '', end_date: '', page: 1 }); dateRange.value = []; load(); };
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
.amt { color: #f5222d; font-weight: 700; }
.pager { margin-top: 12px; justify-content: flex-end; }
</style>
