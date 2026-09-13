<script setup></script>

<template>
	<div class="widget-loading">
		<div class="widget-loading__spinner">
			<svg viewBox="0 0 50 50" class="widget-loading__spinner-svg">
				<circle cx="25" cy="25" r="20" fill="none" stroke="var(--el-color-primary)" stroke-width="4" stroke-linecap="round" stroke-dasharray="100, 100" stroke-dashoffset="75" />
			</svg>
		</div>
		<div class="widget-loading__text">加载中...</div>
	</div>
</template>

<style scoped>
.widget-loading {
	height: 100%;
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	gap: 12px;
}

.widget-loading__spinner {
	width: 36px;
	height: 36px;
}

.widget-loading__spinner-svg {
	width: 100%;
	height: 100%;
	animation: spin 1s linear infinite;
}

@keyframes spin {
	from {
		transform: rotate(0deg);
	}
	to {
		transform: rotate(360deg);
	}
}

.widget-loading__text {
	font-size: 13px;
	color: var(--el-text-color-secondary);
}
</style>
