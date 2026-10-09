<template>
	<div class="screen" :class="{ full: isFullscreen }">
		<header class="topbar">
			<div class="brand">
				<span class="dot"></span>
				<span class="title">智慧大屏 · 经营实时看板</span>
			</div>
			<div class="clock">{{ nowText }}</div>
			<div class="actions">
				<span class="refresh">数据每 30 秒自动刷新 · 上次 {{ lastRefreshText }}</span>
				<el-button size="small" @click="toggleFull">{{ isFullscreen ? '退出全屏' : '全屏显示' }}</el-button>
			</div>
		</header>

		<div class="body">
			<!-- 左栏 -->
			<div class="col">
				<section class="panel">
					<h3>核心指标</h3>
					<div class="metric-grid">
						<div class="metric"><div class="m-label">今日销售额</div><div class="m-value">¥<NumberScroll :value="metrics.today_sales" /></div></div>
						<div class="metric"><div class="m-label">今日订单数</div><div class="m-value"><NumberScroll :value="metrics.today_order_count" :decimals="0" /></div></div>
						<div class="metric"><div class="m-label">今日客户数</div><div class="m-value"><NumberScroll :value="metrics.today_customer_count" :decimals="0" /></div></div>
						<div class="metric"><div class="m-label">库存总额</div><div class="m-value">¥<NumberScroll :value="metrics.stock_total_amount" /></div></div>
						<div class="metric"><div class="m-label">库存种类</div><div class="m-value"><NumberScroll :value="metrics.stock_kinds" :decimals="0" /></div></div>
						<div class="metric"><div class="m-label">库存数量</div><div class="m-value"><NumberScroll :value="metrics.stock_qty" :decimals="0" /></div></div>
					</div>
				</section>
				<section class="panel">
					<h3>销售分类（品牌）占比</h3>
					<div ref="pieEl" class="chart"></div>
				</section>
				<section class="panel">
					<h3>库存预警</h3>
					<div class="warn">
						<div class="warn-group" v-if="warn.low.length">
							<div class="wg-title danger">低库存（低于安全库存）</div>
							<div class="wg-row" v-for="w in warn.low" :key="'l'+w.product_name"><span>{{ w.product_name }}</span><span>{{ w.quantity }} / 安全 {{ w.safety_stock }}</span></div>
						</div>
						<div class="warn-group" v-if="warn.over.length">
							<div class="wg-title warn">超库存（高于最大库存）</div>
							<div class="wg-row" v-for="w in warn.over" :key="'o'+w.product_name"><span>{{ w.product_name }}</span><span>{{ w.quantity }} / 上限 {{ w.max_stock }}</span></div>
						</div>
						<div class="warn-group" v-if="warn.near.length">
							<div class="wg-title near">临期（30 天内）</div>
							<div class="wg-row" v-for="w in warn.near" :key="'n'+w.product_name"><span>{{ w.product_name }}</span><span>剩 {{ w.days }} 天（{{ w.expiry_date }}）</span></div>
						</div>
						<el-empty v-if="!warn.low.length && !warn.over.length && !warn.near.length" description="暂无预警" :image-size="60" />
					</div>
				</section>
			</div>

			<!-- 中栏 -->
			<div class="col">
				<section class="panel grow">
					<h3>实时订单</h3>
					<div class="order-scroll">
						<div class="order-track" :key="trackKey">
							<div class="order-row" v-for="(o, i) in scrollOrders" :key="i">
								<span class="o-no">{{ o.order_no }}</span>
								<span class="o-cust">{{ o.customer_name || '散客' }}</span>
								<span class="o-qty">{{ o.total_qty }} 件</span>
								<span class="o-amt">¥{{ o.total_amount }}</span>
								<span class="o-time">{{ o.time }}</span>
							</div>
						</div>
					</div>
				</section>
				<section class="panel">
					<h3>近 7 天销售趋势</h3>
					<div ref="trendEl" class="chart"></div>
				</section>
			</div>

			<!-- 右栏 -->
			<div class="col">
				<section class="panel">
					<h3>客户销售 TOP10</h3>
					<div ref="custEl" class="chart"></div>
				</section>
				<section class="panel">
					<h3>库存概览</h3>
					<div class="inv-grid">
						<div class="inv"><div class="i-label">库存种类</div><div class="i-value"><NumberScroll :value="inv.kinds" :decimals="0" /></div></div>
						<div class="inv"><div class="i-label">库存数量</div><div class="i-value"><NumberScroll :value="inv.qty" :decimals="0" /></div></div>
						<div class="inv"><div class="i-label">库存金额</div><div class="i-value">¥<NumberScroll :value="inv.amount" /></div></div>
						<div class="inv"><div class="i-label">周转率(月)</div><div class="i-value"><NumberScroll :value="inv.turnover_rate" :decimals="2" /></div></div>
						<div class="inv"><div class="i-label">周转天数</div><div class="i-value"><NumberScroll :value="inv.turnover_days" :decimals="1" /> 天</div></div>
					</div>
				</section>
				<section class="panel">
					<h3>业务员 TOP5</h3>
					<div class="rank-list">
						<div class="rank-row" v-for="s in salesmanRank" :key="s.salesman_id">
							<span class="r-no" :class="'top' + s.rank">{{ s.rank }}</span>
							<span class="r-name">{{ s.salesman_name }}</span>
							<span class="r-amt">¥{{ s.amount }}</span>
						</div>
						<el-empty v-if="!salesmanRank.length" description="暂无数据" :image-size="50" />
					</div>
				</section>
			</div>
		</div>
	</div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, onBeforeUnmount, nextTick } from 'vue';
import * as echarts from 'echarts';
import api from '@/api/business.js';
import NumberScroll from '@/components/NumberScroll/index.vue';

const metrics = reactive({ today_sales: 0, today_order_count: 0, today_customer_count: 0, stock_total_amount: 0, stock_kinds: 0, stock_qty: 0 });
const realtime = ref([]);
const category = ref([]);
const trend = ref([]);
const customerRank = ref([]);
const inv = reactive({ kinds: 0, qty: 0, amount: 0, turnover_rate: 0, turnover_days: 0 });
const warn = reactive({ low: [], over: [], near: [] });
const salesmanRank = ref([]);
const scrollOrders = computed(() => realtime.value.length ? [...realtime.value, ...realtime.value] : []);
const trackKey = ref(0);

const pieEl = ref(null); const trendEl = ref(null); const custEl = ref(null);
let pieChart = null; let trendChart = null; let custChart = null;

const nowText = ref(''); const lastRefreshText = ref('—');
const isFullscreen = ref(false);
let timer = null; let clock = null;

const fmtTime = (d) => { const p = (n) => String(n).padStart(2, '0'); return `${d.getFullYear()}-${p(d.getMonth()+1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}:${p(d.getSeconds())}`; };

const refreshAll = async () => {
	const [m, r, c, t, cr, io, iw, sr] = await Promise.all([
		api.dashboard.metrics.get(), api.dashboard.realtimeOrders.get(), api.dashboard.categoryProportion.get(),
		api.dashboard.salesTrend.get(), api.dashboard.customerRank.get(), api.dashboard.inventoryOverview.get(),
		api.dashboard.inventoryWarning.get(), api.dashboard.salesmanRank.get(),
	]);
	Object.assign(metrics, m.data || {});
	realtime.value = r.data?.list || [];
	category.value = c.data?.list || [];
	trend.value = t.data?.list || [];
	customerRank.value = cr.data?.list || [];
	Object.assign(inv, io.data || {});
	warn.low = iw.data?.low || []; warn.over = iw.data?.over || []; warn.near = iw.data?.near || [];
	salesmanRank.value = sr.data?.list || [];
	trackKey.value++;
	lastRefreshText.value = fmtTime(new Date()).slice(11);
	renderPie(); renderTrend(); renderCust();
};

const palette = ['#36cfc9', '#409eff', '#9254de', '#f759ab', '#ffa940', '#73d13d', '#ff7875', '#597ef7'];
const renderPie = () => {
	if (!pieEl.value) return;
	if (!pieChart) pieChart = echarts.init(pieEl.value);
	pieChart.setOption({
		tooltip: { trigger: 'item', formatter: '{b}: ¥{c} ({d}%)' },
		legend: { type: 'scroll', bottom: 0, textStyle: { color: '#c5d4e8' } },
		series: [{ type: 'pie', radius: ['40%', '68%'], center: ['50%', '45%'], avoidLabelOverlap: true, itemStyle: { borderColor: '#0f2038', borderWidth: 2 }, label: { color: '#c5d4e8', formatter: '{b}\n{d}%' }, data: category.value.map((d, i) => ({ name: d.name, value: d.value, itemStyle: { color: palette[i % palette.length] } })) }],
	});
};
const renderTrend = () => {
	if (!trendEl.value) return;
	if (!trendChart) trendChart = echarts.init(trendEl.value);
	trendChart.setOption({
		tooltip: { trigger: 'axis' }, grid: { left: 55, right: 20, top: 20, bottom: 30 },
		xAxis: { type: 'category', data: trend.value.map(d => d.label), axisLine: { lineStyle: { color: '#3a5a8a' } }, axisLabel: { color: '#9fb3cc' } },
		yAxis: { type: 'value', axisLabel: { color: '#9fb3cc' }, splitLine: { lineStyle: { color: 'rgba(255,255,255,.06)' } } },
		series: [{ type: 'line', smooth: true, areaStyle: { opacity: .25 }, data: trend.value.map(d => d.amount), itemStyle: { color: '#36cfc9' }, lineStyle: { width: 3 } }],
	});
};
const renderCust = () => {
	if (!custEl.value) return;
	if (!custChart) custChart = echarts.init(custEl.value);
	const top = [...customerRank.value].slice(0, 10).reverse();
	custChart.setOption({
		tooltip: { trigger: 'axis', axisPointer: { type: 'shadow' } }, grid: { left: 90, right: 30, top: 10, bottom: 20 },
		xAxis: { type: 'value', axisLabel: { color: '#9fb3cc' }, splitLine: { lineStyle: { color: 'rgba(255,255,255,.06)' } } },
		yAxis: { type: 'category', data: top.map(d => d.customer_name || '散客'), axisLabel: { color: '#9fb3cc' } },
		series: [{ type: 'bar', data: top.map(d => d.amount), itemStyle: { color: '#409eff', borderRadius: [0, 4, 4, 0] }, label: { show: true, position: 'right', color: '#c5d4e8', formatter: '¥{c}' } }],
	});
};
const resizeAll = () => { pieChart && pieChart.resize(); trendChart && trendChart.resize(); custChart && custChart.resize(); };
const onFsChange = () => { isFullscreen.value = !!document.fullscreenElement; nextTick(resizeAll); };
const toggleFull = () => { if (document.fullscreenElement) document.exitFullscreen(); else document.documentElement.requestFullscreen?.(); };

onMounted(() => {
	nowText.value = fmtTime(new Date());
	clock = setInterval(() => { nowText.value = fmtTime(new Date()); }, 1000);
	refreshAll();
	timer = setInterval(refreshAll, 30000);
	window.addEventListener('resize', resizeAll);
	document.addEventListener('fullscreenchange', onFsChange);
});
onBeforeUnmount(() => {
	clearInterval(timer); clearInterval(clock);
	window.removeEventListener('resize', resizeAll);
	document.removeEventListener('fullscreenchange', onFsChange);
	pieChart && pieChart.dispose(); trendChart && trendChart.dispose(); custChart && custChart.dispose();
});
</script>
<style scoped>
.screen { position: fixed; inset: 0; z-index: 2000; background: linear-gradient(135deg, #0a1628 0%, #1a2a4a 100%); color: #e6eefb; display: flex; flex-direction: column; font-family: -apple-system, 'Segoe UI', 'Microsoft YaHei', sans-serif; }
.topbar { height: 64px; display: flex; align-items: center; justify-content: space-between; padding: 0 28px; border-bottom: 1px solid rgba(255,255,255,.08); background: rgba(10,22,40,.6); }
.brand { display: flex; align-items: center; gap: 12px; }
.dot { width: 12px; height: 12px; border-radius: 50%; background: #36cfc9; box-shadow: 0 0 12px #36cfc9; }
.title { font-size: 22px; font-weight: 700; letter-spacing: 1px; }
.clock { font-size: 16px; color: #9fb3cc; font-variant-numeric: tabular-nums; }
.actions { display: flex; align-items: center; gap: 16px; }
.refresh { font-size: 13px; color: #7e93b0; }
.body { flex: 1; display: grid; grid-template-columns: 1fr 1.25fr 1fr; gap: 16px; padding: 16px; overflow: hidden; }
.col { display: flex; flex-direction: column; gap: 16px; overflow: hidden; }
.panel { background: rgba(255,255,255,.04); border: 1px solid rgba(255,255,255,.08); border-radius: 10px; padding: 14px 16px; display: flex; flex-direction: column; min-height: 0; }
.panel.grow { flex: 1; }
.panel h3 { margin: 0 0 12px; font-size: 15px; font-weight: 600; color: #cfe0f5; border-left: 3px solid #36cfc9; padding-left: 10px; }
.metric-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; }
.metric { background: rgba(54,207,201,.08); border-radius: 8px; padding: 12px; }
.m-label { font-size: 12px; color: #9fb3cc; }
.m-value { font-size: 20px; font-weight: 700; color: #36cfc9; margin-top: 6px; font-variant-numeric: tabular-nums; }
.chart { flex: 1; min-height: 220px; }
.warn { overflow: auto; display: flex; flex-direction: column; gap: 10px; }
.wg-title { font-size: 13px; font-weight: 600; margin-bottom: 4px; }
.wg-title.danger { color: #ff7875; } .wg-title.warn { color: #ffa940; } .wg-title.near { color: #ffc53d; }
.wg-row { display: flex; justify-content: space-between; font-size: 13px; color: #c5d4e8; padding: 4px 0; border-bottom: 1px dashed rgba(255,255,255,.06); }
.order-scroll { flex: 1; overflow: hidden; position: relative; }
.order-track { position: absolute; width: 100%; animation: scrollUp 22s linear infinite; }
.order-row { display: grid; grid-template-columns: 1.4fr 1fr .7fr 1fr .8fr; gap: 8px; align-items: center; padding: 9px 6px; border-bottom: 1px solid rgba(255,255,255,.05); font-size: 13px; }
.o-no { color: #36cfc9; } .o-cust { color: #cfe0f5; } .o-qty { color: #9fb3cc; text-align: center; } .o-amt { color: #ffa940; text-align: right; font-weight: 600; } .o-time { color: #7e93b0; text-align: right; }
@keyframes scrollUp { from { transform: translateY(0); } to { transform: translateY(-50%); } }
.inv-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; }
.inv { background: rgba(64,158,255,.08); border-radius: 8px; padding: 12px; }
.i-label { font-size: 12px; color: #9fb3cc; } .i-value { font-size: 19px; font-weight: 700; color: #409eff; margin-top: 6px; }
.rank-list { display: flex; flex-direction: column; gap: 8px; }
.rank-row { display: grid; grid-template-columns: 36px 1fr auto; align-items: center; gap: 10px; padding: 8px 4px; border-bottom: 1px solid rgba(255,255,255,.05); font-size: 14px; }
.r-no { width: 26px; height: 26px; line-height: 26px; text-align: center; border-radius: 50%; background: rgba(255,255,255,.1); color: #cfe0f5; font-weight: 700; }
.r-no.top1 { background: #f759ab; color: #fff; } .r-no.top2 { background: #9254de; color: #fff; } .r-no.top3 { background: #409eff; color: #fff; }
.r-name { color: #e6eefb; } .r-amt { color: #ffa940; font-weight: 600; }
</style>
