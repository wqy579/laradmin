<template>
	<el-drawer v-model="visible" title="兑换订单详情" size="560px" destroy-on-close>
		<el-descriptions v-if="detail" :column="2" border>
			<el-descriptions-item label="兑换单号">{{ detail.order_no }}</el-descriptions-item>
			<el-descriptions-item label="状态">
				<el-tag :type="statusType" size="small">{{ statusName }}</el-tag>
			</el-descriptions-item>
			<el-descriptions-item label="会员">{{ detail.member_name }}</el-descriptions-item>
			<el-descriptions-item label="会员编号">{{ detail.member_no }}</el-descriptions-item>
			<el-descriptions-item label="商品" :span="2">{{ detail.product_name }}<span class="muted"> {{ detail.product_spec || '' }}</span></el-descriptions-item>
			<el-descriptions-item label="单价积分">{{ detail.points_unit }}</el-descriptions-item>
			<el-descriptions-item label="数量">{{ detail.quantity }}</el-descriptions-item>
			<el-descriptions-item label="总积分">
				<span class="pts">{{ detail.total_points }}</span>
			</el-descriptions-item>
			<el-descriptions-item label="收货手机">{{ detail.contact_phone || '-' }}</el-descriptions-item>
			<el-descriptions-item label="收货地址" :span="2">{{ detail.shipping_address || '-' }}</el-descriptions-item>
			<el-descriptions-item label="审核人">{{ detail.auditor_name || '-' }}</el-descriptions-item>
			<el-descriptions-item label="审核时间">{{ detail.audit_time || '-' }}</el-descriptions-item>
			<el-descriptions-item label="发货单号">{{ detail.ship_no || '-' }}</el-descriptions-item>
			<el-descriptions-item label="发货时间">{{ detail.ship_time || '-' }}</el-descriptions-item>
			<el-descriptions-item label="完成时间" :span="2">{{ detail.complete_time || '-' }}</el-descriptions-item>
			<el-descriptions-item label="驳回原因" :span="2">{{ detail.reject_reason || '-' }}</el-descriptions-item>
			<el-descriptions-item label="备注" :span="2">{{ detail.remark || '-' }}</el-descriptions-item>
		</el-descriptions>

		<template v-if="detail?.consume_logs?.length">
			<el-divider content-position="left">积分变动台账</el-divider>
			<el-table :data="detail.consume_logs" border size="small">
				<el-table-column prop="log_no" label="台账号" min-width="160" show-overflow-tooltip />
				<el-table-column label="消耗积分" width="100" align="center">
					<template #default="{ row }">-{{ row.use_points || 0 }}</template>
				</el-table-column>
				<el-table-column label="积分余额" width="100" align="right">
					<template #default="{ row }">{{ row.points_after }}</template>
				</el-table-column>
				<el-table-column prop="remark" label="备注" min-width="160" show-overflow-tooltip />
			</el-table>
		</template>
		<el-empty v-else-if="!detail" description="订单不存在" />
	</el-drawer>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import businessApi from '@/api/business'

const props = defineProps({ visible: Boolean, order: Object })
const emit = defineEmits(['update:visible'])

const detail = ref(null)
const visible = computed({ get: () => props.visible, set: (v) => emit('update:visible', v) })

const statuses = { 0: '待审核', 1: '已审核待发货', 2: '已发货', 3: '已完成', 4: '已驳回', 5: '已取消' }
const statusName = computed(() => statuses[detail.value?.status] || '-')
const statusType = computed(() => ({ 0: 'warning', 1: 'primary', 2: '', 3: 'success', 4: 'danger', 5: 'info' }[detail.value?.status] || 'info'))

watch(() => [props.visible, props.order], async ([show, o]) => {
	if (show && o) {
		const res = await businessApi.pointOrder.detail.get(o.id)
		detail.value = res.data || null
	} else {
		detail.value = null
	}
}, { immediate: true })
</script>

<style scoped>
.pts { color: var(--el-color-primary); font-weight: 700; font-size: 15px; }
.muted { color: #c0c4cc; font-size: 12px; }
</style>
