<template>
	<div class="rp-page">
		<div class="rc-head">
			<h2 class="rc-title">报表中心</h2>
		</div>

		<div class="rc-grid">
			<div
				v-for="card in CARDS"
				:key="card.path"
				class="rc-card"
				@click="go(card.path)"
			>
				<div class="rc-icon">
					<el-icon :size="28">
						<component :is="card.icon" />
					</el-icon>
				</div>
				<div class="rc-name">{{ card.title }}</div>
				<div class="rc-desc">{{ card.desc }}</div>
				<el-icon class="rc-arrow" :size="16">
					<ElIconRight />
				</el-icon>
			</div>
		</div>
	</div>
</template>

<script setup>
import { useRouter } from 'vue-router'

/**
 * 报表中心：连凯对标四核心报表的入口页。
 * 卡片按规格固定样式（250×140，hover 抬升），点击按 path 跳转。
 * 图标名必须是 boot.js 全局注册的 ElIcon* 前缀名——@element-plus/icons-vue
 * 没有 BarChart / MoneyCollect 导出，月报用 Histogram、利润用 Wallet 近似。
 */
const router = useRouter()

const CARDS = [
	{ title: '销售日报', desc: '每日销售数据汇总', icon: 'ElIconCalendar', path: '/business/report/daily' },
	{ title: '销售月报', desc: '月度销售数据统计', icon: 'ElIconHistogram', path: '/business/report/monthly' },
	{ title: '库存报表', desc: '库存余额与周转分析', icon: 'ElIconBox', path: '/business/report/stock' },
	{ title: '利润报表', desc: '销售利润与成本分析', icon: 'ElIconWallet', path: '/business/report/profit' },
]

function go(path) {
	router.push(path)
}
</script>

<style scoped>
.rc-head {
	flex-shrink: 0;
	padding: 20px;
}

.rc-title {
	margin: 0;
	font-size: 20px;
	font-weight: 700;
	color: #303133;
	text-align: left;
}

/* 4 列卡片网格，间距 20px */
.rc-grid {
	flex: 1;
	overflow: auto;
	padding: 0 20px 20px;
	display: grid;
	grid-template-columns: repeat(4, 250px);
	gap: 20px;
	align-content: start;
}

.rc-card {
	position: relative;
	width: 250px;
	height: 140px;
	background: #ffffff;
	border-radius: 8px;
	box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
	padding: 20px;
	box-sizing: border-box;
	cursor: pointer;
	transition: box-shadow 0.2s ease, transform 0.2s ease;
}

.rc-card:hover {
	box-shadow: 0 4px 20px rgba(0, 0, 0, 0.12);
	transform: translateY(-2px);
}

.rc-icon {
	width: 48px;
	height: 48px;
	border-radius: 8px;
	background: #ecf5ff;
	color: #409eff;
	display: flex;
	align-items: center;
	justify-content: center;
}

.rc-name {
	margin-top: 12px;
	font-size: 16px;
	font-weight: 700;
	color: #303133;
}

.rc-desc {
	margin-top: 8px;
	font-size: 13px;
	color: #909399;
}

/* 入口箭头：右侧，默认灰，hover 变蓝 */
.rc-arrow {
	position: absolute;
	right: 20px;
	bottom: 20px;
	color: #c0c4cc;
	transition: color 0.2s ease;
}

.rc-card:hover .rc-arrow {
	color: #409eff;
}
</style>
