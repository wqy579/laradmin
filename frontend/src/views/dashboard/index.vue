<template>
	<div ref="screenEl" class="screen">
		<header class="topbar">
			<div class="brand">智慧经营大屏</div>
			<div class="clock">{{ nowTime }}</div>
			<div class="date">{{ nowDate }} {{ weekday }}</div>
		</header>

		<main class="body">
			<!-- 左侧面板 30% -->
			<section class="col col-left">
				<div class="panel h-200">
					<div class="panel-title">销售统计</div>
					<div class="sales-stat">
						<div class="stat-line">
							<span class="stat-label">今日销售额</span>
							<span class="stat-value red-big">¥<NumberScroll :value="metrics.today_sales" /></span>
						</div>
						<div class="stat-line">
							<span class="stat-label">今日订单数</span>
							<span class="stat-value blue"><NumberScroll :value="metrics.today_order_count" :decimals="0" /></span>
						</div>
						<div class="stat-line">
							<span class="stat-label">今日客单价</span>
							<span class="stat-value green">¥<NumberScroll :value="core.avg_order_amount" /></span>
						</div>
					</div>
				</div>
				<div class="panel h-300">
					<div class="panel-title">近 7 天销售趋势</div>
					<div ref="trendEl" class="chart-fill"></div>
				</div>
				<div class="panel h-300">
					<div class="panel-title">商品销量排行 TOP10</div>
					<div ref="rankEl" class="chart-fill"></div>
				</div>
			</section>

			<!-- 中间面板 40% -->
			<section class="col col-mid">
				<div class="kpi-row">
					<div v-for="k in kpis" :key="k.key" class="kpi">
						<div class="kpi-icon" :style="{ background: k.color }">{{ k.icon }}</div>
						<div class="kpi-body">
							<div class="kpi-label">{{ k.label }}</div>
							<div class="kpi-value">
								<span v-if="k.prefix" class="kpi-prefix">{{ k.prefix }}</span>
								<NumberScroll :value="k.value" :decimals="k.decimals || 0" />
							</div>
							<div class="kpi-ratio" :class="k.ratio >= 0 ? 'up' : 'down'">
								环比 {{ k.ratio >= 0 ? '+' : '' }}{{ k.ratio }}%
							</div>
						</div>
					</div>
				</div>
				<div class="panel h-400">
					<div class="panel-title">地区销售分布</div>
					<div ref="mapEl" class="chart-fill"></div>
				</div>
				<div class="panel h-200">
					<div class="panel-title">实时订单</div>
					<div class="scroll-box">
						<div class="scroll-inner" :class="{ scrolling: realtime.length > 4 }">
							<div v-for="(o, i) in scrollList" :key="i" class="order-row">
								<span class="o-no">{{ o.order_no }}</span>
								<span class="o-cus">{{ o.customer_name }}</span>
								<span class="o-amt">¥{{ Number(o.total_amount || 0).toFixed(2) }}</span>
								<span class="o-time">{{ o.time }}</span>
							</div>
							<div v-if="!realtime.length" class="empty">暂无订单</div>
						</div>
					</div>
				</div>
			</section>

			<!-- 右侧面板 30% -->
			<section class="col col-right">
				<div class="panel h-200">
					<div class="panel-title">库存预警</div>
					<div class="warn-box">
						<div v-for="(w, i) in warnings" :key="i" class="warn-row">
							<span class="w-name">{{ w.product_name }}</span>
							<span class="w-qty">库存 {{ w.quantity }}</span>
							<span class="w-thr">阈值 {{ w.safety_stock }}</span>
						</div>
						<div v-if="!warnings.length" class="empty">库存充足</div>
					</div>
				</div>
				<div class="panel h-250">
					<div class="panel-title">配送状态</div>
					<div ref="deliveryEl" class="chart-fill"></div>
				</div>
				<div class="panel h-250">
					<div class="panel-title">财务概览</div>
					<div class="finance-grid">
						<div class="fin">
							<div class="fin-label">今日收款</div>
							<div class="fin-value up">¥<NumberScroll :value="finance.today_receive" /></div>
						</div>
						<div class="fin">
							<div class="fin-label">今日付款</div>
							<div class="fin-value down">¥<NumberScroll :value="finance.today_pay" /></div>
						</div>
						<div class="fin">
							<div class="fin-label">应收账款</div>
							<div class="fin-value warn">¥<NumberScroll :value="finance.receivable" /></div>
						</div>
						<div class="fin">
							<div class="fin-label">应付账款</div>
							<div class="fin-value warn">¥<NumberScroll :value="finance.payable" /></div>
						</div>
					</div>
				</div>
			</section>
		</main>

		<footer class="statusbar">
			<span>系统运行状态：<b class="ok">正常</b></span>
			<span>数据更新时间：{{ updatedAt }}</span>
			<span class="grow"></span>
			<el-button size="small" type="primary" plain @click="toggleFullscreen">全屏切换 (F11)</el-button>
		</footer>
	</div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, onBeforeUnmount, nextTick } from 'vue';
import * as echarts from 'echarts';
import chinaGeo from '@/assets/map/china.json';
import api from '@/api/business.js';
import NumberScroll from '@/components/NumberScroll/index.vue';

// ECharts 5 不再内置中国地图，这里用打包进来的 GeoJSON 注册（免运行时依赖外网）
echarts.registerMap('china', chinaGeo);

const screenEl = ref(null);
const metrics = reactive({ today_sales: 0, today_order_count: 0 });
const core = reactive({
	total_customer_count: 0,
	total_order_count: 0,
	total_sales_amount: 0,
	total_profit: 0,
	order_ratio: 0,
	sales_ratio: 0,
	profit_ratio: 0,
	customer_growth: 0,
	avg_order_amount: 0,
});
const realtime = ref([]);
const warnings = ref([]);
const finance = reactive({ today_receive: 0, today_pay: 0, receivable: 0, payable: 0 });
const updatedAt = ref('');

const trendEl = ref(null);
const rankEl = ref(null);
const mapEl = ref(null);
const deliveryEl = ref(null);
let trendChart = null;
let rankChart = null;
let mapChart = null;
let deliveryChart = null;

const nowTime = ref('');
const nowDate = ref('');
const weekday = ref('');
const pad = (n) => String(n).padStart(2, '0');
const tickClock = () => {
	const d = new Date();
	nowTime.value = `${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
	nowDate.value = `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
	weekday.value = ['星期日', '星期一', '星期二', '星期三', '星期四', '星期五', '星期六'][d.getDay()];
};

const kpis = computed(() => [
	{ key: 'customer', label: '总客户数', icon: '客', color: '#409EFF', value: core.total_customer_count, ratio: core.customer_growth, decimals: 0 },
	{ key: 'order', label: '总订单数', icon: '单', color: '#36CFC9', value: core.total_order_count, ratio: core.order_ratio, decimals: 0 },
	{ key: 'sales', label: '总销售额', icon: '销', color: '#ff4d4f', value: core.total_sales_amount, ratio: core.sales_ratio, decimals: 2, prefix: '¥' },
	{ key: 'profit', label: '总利润', icon: '利', color: '#67C23A', value: core.total_profit, ratio: core.profit_ratio, decimals: 2, prefix: '¥' },
]);

// 实时订单不足一屏时复制一份，保证滚动动画连续
const scrollList = computed(() => (realtime.value.length > 4 ? realtime.value.concat(realtime.value) : realtime.value));

const AXIS = { axisLine: { lineStyle: { color: '#2c4a6e' } }, axisLabel: { color: '#8fa8c8', fontSize: 11 } };

const renderTrend = (list) => {
	if (!trendEl.value) return;
	if (!trendChart) trendChart = echarts.init(trendEl.value);
	trendChart.setOption({
		tooltip: { trigger: 'axis', valueFormatter: (v) => `¥${Number(v || 0).toFixed(2)}` },
		grid: { left: 55, right: 16, top: 18, bottom: 26 },
		xAxis: { type: 'category', data: list.map((d) => d.label), ...AXIS },
		yAxis: { type: 'value', splitLine: { lineStyle: { color: 'rgba(255,255,255,.06)' } }, ...AXIS },
		series: [
			{
				type: 'line',
				smooth: true,
				data: list.map((d) => d.amount),
				itemStyle: { color: '#409EFF' },
				lineStyle: { width: 2 },
				areaStyle: {
					color: new echarts.graphic.LinearGradient(0, 0, 0, 1, [
						{ offset: 0, color: 'rgba(64,158,255,.55)' },
						{ offset: 1, color: 'rgba(64,158,255,0)' },
					]),
				},
			},
		],
	});
};

const renderRank = (list) => {
	if (!rankEl.value) return;
	if (!rankChart) rankChart = echarts.init(rankEl.value);
	const data = list.slice(0, 10).reverse();
	rankChart.setOption({
		tooltip: { trigger: 'axis', axisPointer: { type: 'shadow' } },
		grid: { left: 8, right: 40, top: 8, bottom: 8, containLabel: true },
		xAxis: { type: 'value', splitLine: { lineStyle: { color: 'rgba(255,255,255,.06)' } }, ...AXIS },
		yAxis: { type: 'category', data: data.map((d) => d.product_name), ...AXIS },
		series: [
			{
				type: 'bar',
				data: data.map((d) => d.qty),
				barWidth: 12,
				itemStyle: {
					borderRadius: [0, 6, 6, 0],
					color: new echarts.graphic.LinearGradient(0, 0, 1, 0, [
						{ offset: 0, color: '#1e6fd9' },
						{ offset: 1, color: '#4fc3f7' },
					]),
				},
				label: { show: true, position: 'right', color: '#8fa8c8', fontSize: 11 },
			},
		],
	});
};

const renderMap = (list) => {
	if (!mapEl.value) return;
	if (!mapChart) mapChart = echarts.init(mapEl.value);
	mapChart.setOption({
		tooltip: {
			trigger: 'item',
			formatter: (p) => (p.data ? `${p.name}<br/>销售额：¥${Number(p.data.value || 0).toFixed(2)}` : `${p.name}<br/>暂无销售`),
		},
		visualMap: {
			min: 0,
			max: Math.max(1, ...list.map((d) => d.value)),
			left: 12,
			bottom: 12,
			calculable: false,
			inRange: { color: ['#12314f', '#1e6fd9', '#4fc3f7'] },
			textStyle: { color: '#8fa8c8' },
		},
		series: [
			{
				type: 'map',
				map: 'china',
				roam: false,
				zoom: 1.15,
				label: { show: false },
				itemStyle: { areaColor: '#12314f', borderColor: '#2c4a6e', borderWidth: 1 },
				emphasis: { label: { show: true, color: '#fff' }, itemStyle: { areaColor: '#1e6fd9' } },
				data: list,
			},
		],
	});
};

const renderDelivery = (list) => {
	if (!deliveryEl.value) return;
	if (!deliveryChart) deliveryChart = echarts.init(deliveryEl.value);
	deliveryChart.setOption({
		tooltip: { trigger: 'item', formatter: '{b}: {c} ({d}%)' },
		legend: { bottom: 0, textStyle: { color: '#8fa8c8', fontSize: 11 } },
		series: [
			{
				type: 'pie',
				radius: ['45%', '68%'],
				center: ['50%', '45%'],
				avoidLabelOverlap: true,
				label: { color: '#c0d4ea', fontSize: 11, formatter: '{b}\n{c}' },
				labelLine: { lineStyle: { color: '#2c4a6e' } },
				data: list,
				color: ['#409EFF', '#E6A23C', '#67C23A'],
			},
		],
	});
};

const load = async () => {
	try {
		const [mRes, cRes, rRes, pRes, gRes, wRes, dRes, fRes] = await Promise.all([
			api.dashboard.metrics.get(),
			api.dashboard.coreMetrics.get(),
			api.dashboard.realtimeOrders.get({ limit: 20 }),
			api.dashboard.productRank.get({ limit: 10 }),
			api.dashboard.regionSales.get(),
			api.dashboard.inventoryWarning.get(),
			api.dashboard.deliveryStatus.get(),
			api.dashboard.financeOverview.get(),
		]);
		Object.assign(metrics, mRes.data || {});
		Object.assign(core, cRes.data || {});
		realtime.value = rRes.data?.list || [];
		warnings.value = wRes.data?.low || [];
		Object.assign(finance, fRes.data || {});

		await nextTick();
		renderTrend((await api.dashboard.salesTrend.get()).data?.list || []);
		renderRank(pRes.data?.list || []);
		renderMap(gRes.data?.list || []);
		renderDelivery(dRes.data?.list || []);

		const d = new Date();
		updatedAt.value = `${nowDate.value} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
	} catch (e) {
		// 单个接口失败不应让整屏白掉，保留上一次数据
		console.error('大屏数据刷新失败', e);
	}
};

const toggleFullscreen = () => {
	const el = screenEl.value;
	if (!el) return;
	if (document.fullscreenElement) {
		document.exitFullscreen();
	} else {
		el.requestFullscreen?.();
	}
};

const onKey = (e) => {
	if (e.key === 'F11') {
		e.preventDefault();
		toggleFullscreen();
	}
};

const onResize = () => {
	trendChart?.resize();
	rankChart?.resize();
	mapChart?.resize();
	deliveryChart?.resize();
};

let clockTimer = null;
let dataTimer = null;

onMounted(() => {
	tickClock();
	clockTimer = setInterval(tickClock, 1000);
	load();
	// 方案要求：数据每 30 秒自动刷新
	dataTimer = setInterval(load, 30000);
	window.addEventListener('resize', onResize);
	window.addEventListener('keydown', onKey);
});

onBeforeUnmount(() => {
	clearInterval(clockTimer);
	clearInterval(dataTimer);
	window.removeEventListener('resize', onResize);
	window.removeEventListener('keydown', onKey);
	trendChart?.dispose();
	rankChart?.dispose();
	mapChart?.dispose();
	deliveryChart?.dispose();
});
</script>

<style scoped>
.screen {
	position: fixed;
	inset: 0;
	display: flex;
	flex-direction: column;
	background: #0a1929;
	color: #e6f0fa;
	overflow: hidden;
}
.topbar {
	height: 64px;
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 0 24px;
	background: linear-gradient(180deg, rgba(30, 111, 217, 0.28), rgba(10, 25, 41, 0));
	border-bottom: 1px solid rgba(79, 195, 247, 0.25);
}
.brand {
	font-size: 24px;
	font-weight: 700;
	color: #fff;
	letter-spacing: 2px;
}
.clock {
	font-size: 18px;
	color: #fff;
	font-variant-numeric: tabular-nums;
}
.date {
	font-size: 16px;
	color: #fff;
}
.body {
	flex: 1;
	display: flex;
	gap: 12px;
	padding: 12px;
	min-height: 0;
}
.col {
	display: flex;
	flex-direction: column;
	gap: 12px;
	min-height: 0;
}
.col-left { width: 30%; }
.col-mid { width: 40%; }
.col-right { width: 30%; }
.panel {
	background: rgba(18, 49, 79, 0.45);
	border: 1px solid rgba(79, 195, 247, 0.18);
	border-radius: 6px;
	padding: 10px 12px;
	display: flex;
	flex-direction: column;
	min-height: 0;
}
.h-200 { height: 200px; }
.h-250 { height: 250px; }
.h-300 { height: 300px; }
.h-400 { height: 400px; }
.panel-title {
	font-size: 14px;
	font-weight: 600;
	color: #9fd0ff;
	margin-bottom: 8px;
	flex: none;
}
.chart-fill { flex: 1; min-height: 0; }
.sales-stat { display: flex; flex-direction: column; justify-content: space-around; flex: 1; }
.stat-line { display: flex; align-items: baseline; justify-content: space-between; }
.stat-label { color: #8fa8c8; font-size: 13px; }
.red-big { color: #ff4d4f; font-size: 36px; font-weight: 700; font-variant-numeric: tabular-nums; }
.blue { color: #409eff; font-size: 24px; font-weight: 700; font-variant-numeric: tabular-nums; }
.green { color: #67c23a; font-size: 24px; font-weight: 700; font-variant-numeric: tabular-nums; }

.kpi-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; height: 150px; flex: none; }
.kpi {
	background: rgba(18, 49, 79, 0.45);
	border: 1px solid rgba(79, 195, 247, 0.18);
	border-radius: 6px;
	padding: 12px;
	display: flex;
	align-items: center;
	gap: 10px;
}
.kpi-icon {
	width: 38px;
	height: 38px;
	border-radius: 50%;
	flex: none;
	display: flex;
	align-items: center;
	justify-content: center;
	color: #fff;
	font-weight: 700;
	font-size: 16px;
}
.kpi-label { color: #8fa8c8; font-size: 12px; }
.kpi-value { color: #fff; font-size: 20px; font-weight: 700; font-variant-numeric: tabular-nums; }
.kpi-prefix { font-size: 13px; margin-right: 1px; }
.kpi-ratio { font-size: 12px; margin-top: 2px; }
.kpi-ratio.up { color: #67c23a; }
.kpi-ratio.down { color: #ff4d4f; }

.scroll-box { flex: 1; overflow: hidden; position: relative; }
.scroll-inner { position: absolute; top: 0; left: 0; right: 0; }
.scroll-inner.scrolling { animation: roll 24s linear infinite; }
@keyframes roll {
	0% { transform: translateY(0); }
	100% { transform: translateY(-50%); }
}
.order-row {
	display: flex;
	align-items: center;
	gap: 8px;
	font-size: 12px;
	padding: 5px 0;
	border-bottom: 1px dashed rgba(79, 195, 247, 0.14);
}
.o-no { color: #9fd0ff; width: 34%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.o-cus { color: #c0d4ea; width: 30%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.o-amt { color: #ff7875; width: 22%; text-align: right; font-variant-numeric: tabular-nums; }
.o-time { color: #6d86a3; width: 14%; text-align: right; }

.warn-box { flex: 1; overflow: auto; }
.warn-row {
	display: flex;
	align-items: center;
	gap: 8px;
	font-size: 12px;
	padding: 5px 0;
	color: #ff7875;
	border-bottom: 1px dashed rgba(255, 77, 79, 0.16);
}
.w-name { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: #ffb0ac; }
.w-qty { font-variant-numeric: tabular-nums; }
.w-thr { color: #8fa8c8; }

.finance-grid { flex: 1; display: grid; grid-template-columns: 1fr 1fr; grid-template-rows: 1fr 1fr; gap: 10px; }
.fin {
	background: rgba(10, 32, 56, 0.6);
	border-radius: 5px;
	padding: 8px 10px;
	display: flex;
	flex-direction: column;
	justify-content: center;
}
.fin-label { color: #8fa8c8; font-size: 12px; }
.fin-value { font-size: 18px; font-weight: 700; margin-top: 4px; font-variant-numeric: tabular-nums; }
.fin-value.up { color: #67c23a; }
.fin-value.down { color: #409eff; }
.fin-value.warn { color: #e6a23c; }

.statusbar {
	height: 40px;
	display: flex;
	align-items: center;
	gap: 20px;
	padding: 0 24px;
	border-top: 1px solid rgba(79, 195, 247, 0.18);
	font-size: 13px;
	color: #8fa8c8;
}
.statusbar .ok { color: #67c23a; }
.statusbar .grow { flex: 1; }
.empty { color: #6d86a3; font-size: 12px; text-align: center; padding: 16px 0; }

/* 响应式：窄屏改为单列滚动，避免挤压成不可读 */
@media (max-width: 1280px) {
	.body { overflow: auto; flex-wrap: wrap; }
	.col { width: 100% !important; }
}
</style>
