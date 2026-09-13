<script setup>
const activities = [
	{ id: 1, user: '张三', action: '提交了新订单 #1024', time: '5 分钟前', avatarColor: '#409EFF' },
	{ id: 2, user: '李四', action: '更新了商品「无线耳机」', time: '15 分钟前', avatarColor: '#67C23A' },
	{ id: 3, user: '王五', action: '审核通过了用户注册', time: '30 分钟前', avatarColor: '#E6A23C' },
	{ id: 4, user: '赵六', action: '发布了新文章「系统更新公告」', time: '1 小时前', avatarColor: '#F56C6C' },
	{ id: 5, user: '孙七', action: '修复了支付模块 Bug', time: '2 小时前', avatarColor: '#909399' },
	{ id: 6, user: '周八', action: '导出了月度数据报表', time: '3 小时前', avatarColor: '#409EFF' },
]
</script>

<template>
	<div class="activity-widget">
		<div class="activity-widget__header">
			<el-icon class="activity-widget__icon" :size="16">
				<ElIconBell />
			</el-icon>
			<span class="activity-widget__title">最近动态</span>
		</div>
		<div class="activity-widget__body">
			<div class="activity-widget__list">
				<div v-for="activity in activities" :key="activity.id" class="activity-widget__item">
					<div class="activity-widget__avatar" :style="{ backgroundColor: activity.avatarColor }">
						{{ activity.user.charAt(0) }}
					</div>
					<div class="activity-widget__content">
						<div class="activity-widget__text">
							<strong>{{ activity.user }}</strong>
							<span>{{ activity.action }}</span>
						</div>
						<div class="activity-widget__time">{{ activity.time }}</div>
					</div>
				</div>
			</div>
		</div>
	</div>
</template>

<style scoped>
.activity-widget {
	height: 100%;
	display: flex;
	flex-direction: column;
	container-type: inline-size;
}

.activity-widget__header {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 12px 16px;
	border-bottom: 1px solid var(--el-border-color-lighter);
	background: linear-gradient(to bottom, var(--el-bg-color-page), var(--el-bg-color));
}

.activity-widget__icon {
	color: var(--el-color-primary);
}

.activity-widget__title {
	font-size: 14px;
	font-weight: 600;
	color: var(--el-text-color-primary);
}

.activity-widget__body {
	flex: 1;
	padding: 12px 16px;
	overflow-y: auto;
}

.activity-widget__list {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.activity-widget__item {
	display: flex;
	align-items: flex-start;
	gap: 12px;
	padding: 0;
	transition: background-color 0.2s ease;
}

.activity-widget__item:hover {
	background-color: var(--el-fill-color-light);
}

.activity-widget__avatar {
	width: 36px;
	height: 36px;
	border-radius: 50%;
	display: flex;
	align-items: center;
	justify-content: center;
	color: white;
	font-size: 14px;
	font-weight: 600;
	flex-shrink: 0;
}

.activity-widget__content {
	flex: 1;
	min-width: 0;
}

.activity-widget__text {
	font-size: 14px;
	color: var(--el-text-color-primary);
	line-height: 1.5;
	margin-bottom: 4px;
}

.activity-widget__text strong {
	font-weight: 600;
	color: var(--el-text-color-primary);
}

.activity-widget__time {
	font-size: 12px;
	color: var(--el-text-color-secondary);
}

/* 容器查询：窄屏压缩内边距、头像与间距 */
@container (max-width: 360px) {
	.activity-widget__header {
		padding: 10px 12px;
	}
	.activity-widget__body {
		padding: 10px 12px;
	}
	.activity-widget__list {
		gap: 6px;
	}
	.activity-widget__item {
		gap: 10px;
	}
	.activity-widget__avatar {
		width: 30px;
		height: 30px;
		font-size: 12px;
	}
	.activity-widget__text {
		font-size: 13px;
	}
}

@container (max-width: 240px) {
	.activity-widget__body {
		padding: 8px 10px;
	}
	.activity-widget__avatar {
		width: 26px;
		height: 26px;
		font-size: 11px;
	}
	.activity-widget__text {
		font-size: 12px;
	}
	.activity-widget__time {
		font-size: 11px;
	}
}
</style>
