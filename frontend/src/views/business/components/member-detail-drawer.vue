<template>
	<el-drawer v-model="visible" title="会员详情" size="640px" destroy-on-close>
		<div v-if="detail" class="detail">
			<el-descriptions title="基本信息" :column="2" border>
				<el-descriptions-item label="会员编号">{{ detail.member_no }}</el-descriptions-item>
				<el-descriptions-item label="会员名称">{{ detail.name }}</el-descriptions-item>
				<el-descriptions-item label="会员等级">
					<el-tag size="small" :color="detail.level?.color" effect="dark" style="color:#fff" v-if="detail.level">
						{{ detail.level.name }}（{{ detail.level.discount_rate }} 折）
					</el-tag>
					<span v-else>-</span>
				</el-descriptions-item>
				<el-descriptions-item label="关联客户">{{ detail.customer?.name || detail.customer_name || '-' }}</el-descriptions-item>
				<el-descriptions-item label="联系人">{{ detail.contact || '-' }}</el-descriptions-item>
				<el-descriptions-item label="手机">{{ detail.phone || '-' }}</el-descriptions-item>
				<el-descriptions-item label="性别">{{ ['未知', '男', '女'][detail.gender] || '未知' }}</el-descriptions-item>
				<el-descriptions-item label="生日">{{ detail.birthday ? String(detail.birthday).slice(0, 10) : '-' }}</el-descriptions-item>
				<el-descriptions-item label="邮箱">{{ detail.email || '-' }}</el-descriptions-item>
				<el-descriptions-item label="地址" :span="2">{{ detail.address || '-' }}</el-descriptions-item>
				<el-descriptions-item label="状态">
					<el-tag :type="detail.status === 1 ? 'success' : 'info'" size="small">
						{{ detail.status === 1 ? '正常' : '冻结' }}
					</el-tag>
				</el-descriptions-item>
				<el-descriptions-item label="注册日期">{{ detail.register_date ? String(detail.register_date).slice(0, 10) : '-' }}</el-descriptions-item>
				<el-descriptions-item label="备注" :span="2">{{ detail.remark || '-' }}</el-descriptions-item>
			</el-descriptions>

			<el-row :gutter="12" class="cards">
				<el-col :span="6"><el-card shadow="never" class="stat"><div class="num">{{ fmt(detail.points) }}</div><div class="lbl">积分</div></el-card></el-col>
				<el-col :span="6"><el-card shadow="never" class="stat"><div class="num money">{{ fmt(detail.balance) }}</div><div class="lbl">储值余额</div></el-card></el-col>
				<el-col :span="6"><el-card shadow="never" class="stat"><div class="num">{{ fmt(detail.total_consume) }}</div><div class="lbl">累计消费</div></el-card></el-col>
				<el-col :span="6"><el-card shadow="never" class="stat"><div class="num">{{ detail.total_orders }}</div><div class="lbl">累计订单</div></el-card></el-col>
			</el-row>

			<el-divider content-position="left">最近消费记录</el-divider>
			<el-table :data="detail.recent_consume_logs || []" border size="small" max-height="260">
				<el-table-column prop="related_order_no" label="关联单据" width="150" show-overflow-tooltip />
				<el-table-column label="类型" width="90" align="center">
					<template #default="{ row }">{{ typeName(row.consume_type) }}</template>
				</el-table-column>
				<el-table-column label="方向" width="60" align="center">
					<template #default="{ row }">
						<el-tag :type="row.direction === 'in' ? 'success' : 'danger'" size="small">
							{{ row.direction === 'in' ? '进' : '出' }}
						</el-tag>
					</template>
				</el-table-column>
				<el-table-column label="金额" width="90" align="right">
					<template #default="{ row }">{{ fmt(row.consume_amount) }}</template>
				</el-table-column>
				<el-table-column label="积分变动" width="100" align="center">
					<template #default="{ row }">
						<span class="pts-in" v-if="row.earn_points">+{{ row.earn_points }}</span>
						<span class="pts-out" v-if="row.use_points">-{{ row.use_points }}</span>
						<span v-if="!row.earn_points && !row.use_points">-</span>
					</template>
				</el-table-column>
				<el-table-column label="储值余额" width="90" align="right">
					<template #default="{ row }">{{ fmt(row.balance_after) }}</template>
				</el-table-column>
				<el-table-column prop="created_at" label="时间" width="150" show-overflow-tooltip />
			</el-table>
		</div>
		<el-empty v-else description="会员不存在" />
	</el-drawer>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import businessApi from '@/api/business'

const props = defineProps({ visible: Boolean, member: Object })
const emit = defineEmits(['update:visible', 'success'])

const detail = ref(null)
const visible = computed({ get: () => props.visible, set: (v) => emit('update:visible', v) })

const types = {
	order: '订单消费', recharge: '储值充值', points_deduct: '积分抵扣',
	order_refund: '订单退款', recharge_refund: '充值冲正',
}
const typeName = (t) => types[t] || t || '-'
const fmt = (n) => (n == null ? '0.00' : Number(n).toFixed(2))

watch(() => [props.visible, props.member], async ([show, m]) => {
	if (show && m) {
		const res = await businessApi.member.detail.get(m.id)
		detail.value = res.data || null
	} else {
		detail.value = null
	}
}, { immediate: true })
</script>

<style scoped>
.detail { padding: 0 4px; }
.cards { margin-top: 8px; }
.stat { text-align: center; }
.stat .num { font-size: 18px; font-weight: 700; color: #333; }
.stat .num.money { color: var(--el-color-primary); }
.stat .lbl { font-size: 12px; color: #999; margin-top: 2px; }
.pts-in { color: var(--el-color-success); font-weight: 700; }
.pts-out { color: var(--el-color-danger); font-weight: 700; }
</style>
