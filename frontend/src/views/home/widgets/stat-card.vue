<script setup>
import { computed } from 'vue'

const props = defineProps({
	type: {
		type: String,
		default: 'primary',
		validator: (v) => ['primary', 'success', 'warning', 'danger'].includes(v),
	},
	label: String,
	value: String,
	trend: Number,
	subValue: String,
})

const trendUp = computed(() => props.trend >= 0)
const trendClass = computed(() => (trendUp.value ? 'stat-card__trend--up' : 'stat-card__trend--down'))

const colorMap = {
	primary: {
		from: 'rgba(64, 158, 255, 0.1)',
		to: 'rgba(64, 158, 255, 0.02)',
		icon: 'rgba(64, 158, 255, 1)',
	},
	success: {
		from: 'rgba(103, 194, 58, 0.1)',
		to: 'rgba(103, 194, 58, 0.02)',
		icon: 'rgba(103, 194, 58, 1)',
	},
	warning: {
		from: 'rgba(230, 162, 60, 0.1)',
		to: 'rgba(230, 162, 60, 0.02)',
		icon: 'rgba(230, 162, 60, 1)',
	},
	danger: {
		from: 'rgba(245, 108, 108, 0.1)',
		to: 'rgba(245, 108, 108, 0.02)',
		icon: 'rgba(245, 108, 108, 1)',
	},
}

const colors = computed(() => colorMap[props.type])
</script>

<template>
	<div class="stat-card" :style="{ background: `linear-gradient(135deg, ${colors.from}, ${colors.to})` }">
		<div class="stat-card__icon" :style="{ backgroundColor: colors.icon }">
			<slot name="icon">
				<el-icon :size="20">
					<component :is="props.icon" />
				</el-icon>
			</slot>
		</div>
		<div class="stat-card__content">
			<div class="stat-card__value">{{ value }}</div>
			<div class="stat-card__label">{{ label }}</div>
			<div class="stat-card__footer">
				<span v-if="subValue" class="stat-card__sub-value">{{ subValue }}</span>
				<span v-if="trend !== undefined" :class="['stat-card__trend', trendClass]">
					<el-icon :size="14">
						<ElIconTop v-if="trendUp" />
						<ElIconBottom v-else />
					</el-icon>
					{{ Math.abs(trend) }}%
				</span>
			</div>
		</div>
	</div>
</template>

<style scoped>
.stat-card {
	height: 100%;
	display: flex;
	align-items: center;
	gap: 16px;
	padding: 16px;
	border-radius: 12px;
	container-type: inline-size;
	transition:
		transform 0.2s ease,
		box-shadow 0.2s ease;
}

.stat-card:hover {
	transform: translateY(-2px);
	box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
}

.stat-card__icon {
	width: 48px;
	height: 48px;
	border-radius: 12px;
	display: flex;
	align-items: center;
	justify-content: center;
	color: white;
	flex-shrink: 0;
}

.stat-card__content {
	flex: 1;
	min-width: 0;
}

.stat-card__value {
	font-size: 24px;
	font-weight: 700;
	line-height: 1.2;
	color: var(--el-text-color-primary);
	margin-bottom: 4px;
}

.stat-card__label {
	font-size: 13px;
	color: var(--el-text-color-secondary);
	margin-bottom: 8px;
}

.stat-card__footer {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
}

.stat-card__sub-value {
	font-size: 12px;
	color: var(--el-text-color-tertiary);
}

.stat-card__trend {
	display: inline-flex;
	align-items: center;
	gap: 2px;
	font-size: 12px;
	font-weight: 600;
}

.stat-card__trend--up {
	color: var(--el-color-success);
}

.stat-card__trend--down {
	color: var(--el-color-danger);
}

/* 容器查询：卡片宽度变窄时自适应缩小内边距与字号 */
@container (max-width: 260px) {
	.stat-card {
		padding: 12px;
		gap: 10px;
	}
	.stat-card__icon {
		width: 38px;
		height: 38px;
		border-radius: 10px;
	}
	.stat-card__icon .el-icon {
		font-size: 16px;
	}
	.stat-card__value {
		font-size: 20px;
	}
	.stat-card__label {
		font-size: 12px;
	}
}

@container (max-width: 180px) {
	.stat-card {
		padding: 10px;
		gap: 8px;
	}
	.stat-card__icon {
		width: 32px;
		height: 32px;
	}
	.stat-card__icon .el-icon {
		font-size: 14px;
	}
	.stat-card__value {
		font-size: 17px;
	}
	.stat-card__footer {
		gap: 4px;
	}
}
</style>
