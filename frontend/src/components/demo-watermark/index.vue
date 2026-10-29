<script setup>
/**
 * BUG-04 过渡期「演示数据」水印 + 顶部提示条。
 *
 * 未接真实后端前，finance 各页数据为演示假数据。本组件在页面上方铺一层
 * 斜向 45° 平铺的「演示数据」水印，并加一条顶部 warning 提示条。
 * 接真实后端后（配置开关 FINANCE_DEMO_WATERMARK 置 false），组件整体消失。
 *
 * D7 扩展：同时覆盖 D 三域（出纳 cash / 固定资产 asset / 积分 points），
 * 由 DOMAIN_DEMO_WATERMARK 开关控制；正式数据上线后置 false 自动消失。
 *
 * 设计（遵循 DS 规范）：
 *  - 水印斜向 45° 平铺，文字「演示数据」橙色 #E6A23C、12px、透明度 0.15、
 *    pointer-events:none —— 不可误点、不拦截任何交互。
 *  - 顶部提示条 warning 色（#E6A23C，浅底 #FDF6EC）。
 */
import config from '../../config'

const show = config.FINANCE_DEMO_WATERMARK !== false && config.DOMAIN_DEMO_WATERMARK !== false
</script>

<template>
	<template v-if="show">
		<!-- 顶部提示条：warning 底 + 图标 + 文案（正常流，将页面内容下推） -->
		<div class="demo-watermark-bar">
			<el-icon :size="14"><ElIconWarning /></el-icon>
			<span>当前为演示数据，正式数据上线前请勿作为决策依据</span>
		</div>
		<!-- 水印层：斜向 45° 平铺，覆盖整块内容区；pointer-events:none 不可误点 -->
		<div class="demo-watermark-layer" aria-hidden="true" />
	</template>
</template>

<style scoped>
/* ===== 顶部提示条（warning 色 #E6A23C，浅底 #FDF6EC）===== */
.demo-watermark-bar {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 8px;
	height: 32px;
	font-size: 12px;
	color: #e6a23c;
	background: #fdf6ec;
	border-bottom: 1px solid #e6a23c;
	position: relative;
	z-index: 2; /* 叠在水印之上，文案不被盖住 */
}

/* ===== 水印层 =====
 * 用内联 SVG 作平铺背景：一段斜 45° 的「演示数据」文字，SVG 单元可无缝平铺。
 * position:absolute + inset:0 锚定到定位祖先（el-main.layout-content，
 * 已在布局里置 position:relative），从而覆盖整个内容区（含顶部提示条与下方页面）。
 * pointer-events:none 保证不拦截点击/滚动，仅视觉水印。
 */
.demo-watermark-layer {
	position: absolute;
	inset: 0;
	z-index: 1;
	pointer-events: none;
	background-repeat: repeat;
	background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='240' height='160'%3E%3Cg fill='%23E6A23C' fill-opacity='0.15' font-size='12' font-family='sans-serif'%3E%3Ctext x='120' y='80' transform='rotate(-45 120 80)' text-anchor='middle' dominant-baseline='middle'%3E演示数据%3C/text%3E%3C/g%3E%3C/svg%3E");
}
</style>