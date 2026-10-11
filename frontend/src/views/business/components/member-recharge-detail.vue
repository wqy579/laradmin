<template>
	<el-drawer v-model="visible" title="充值单详情" size="560px" destroy-on-close>
		<el-descriptions v-if="detail" :column="2" border>
			<el-descriptions-item label="充值单号">{{ detail.recharge_no }}</el-descriptions-item>
			<el-descriptions-item label="状态">
				<el-tag :type="statusType" size="small">{{ statusName }}</el-tag>
			</el-descriptions-item>
			<el-descriptions-item label="会员">{{ detail.member?.name || detail.member_name }}</el-descriptions-item>
			<el-descriptions-item label="会员编号">{{ detail.member_no }}</el-descriptions-item>
			<el-descriptions-item label="充值金额">
				<span class="money">{{ fmt(detail.recharge_amount) }}</span>
			</el-descriptions-item>
			<el-descriptions-item label="赠送金额">{{ fmt(detail.gift_amount) }}</el-descriptions-item>
			<el-descriptions-item label="到账合计">
				<span class="money strong">{{ fmt(detail.total_amount) }}</span>
			</el-descriptions-item>
			<el-descriptions-item label="支付方式">{{ pays[detail.pay_method] || detail.pay_method }}</el-descriptions-item>
			<el-descriptions-item label="充值日期">{{ detail.recharge_date ? String(detail.recharge_date).slice(0, 10) : '-' }}</el-descriptions-item>
			<el-descriptions-item label="经办人">{{ detail.operator_name || '-' }}</el-descriptions-item>
			<el-descriptions-item label="审核人">{{ detail.auditor_name || '-' }}</el-descriptions-item>
			<el-descriptions-item label="审核时间">{{ detail.audit_time || '-' }}</el-descriptions-item>
			<el-descriptions-item label="驳回原因" :span="2">{{ detail.reject_reason || '-' }}</el-descriptions-item>
			<el-descriptions-item label="备注" :span="2">{{ detail.remark || '-' }}</el-descriptions-item>
		</el-descriptions>

		<template v-if="detail?.cash_flow">
			<el-divider content-position="left">现金流水</el-divider>
			<el-descriptions :column="2" border>
				<el-descriptions-item label="流水号">{{ detail.cash_flow.flow_no }}</el-descriptions-item>
				<el-descriptions-item label="金额">
					<span class="money">{{ fmt(detail.cash_flow.amount) }}</span>
				</el-descriptions-item>
				<el-descriptions-item label="日期">{{ detail.cash_flow.flow_date }}</el-descriptions-item>
				<el-descriptions-item label="支付方式">{{ detail.cash_flow.payment_method }}</el-descriptions-item>
				<el-descriptions-item label="备注" :span="2">{{ detail.cash_flow.remark }}</el-descriptions-item>
			</el-descriptions>
		</template>

		<template v-if="detail?.consume_logs?.length">
			<el-divider content-position="left">消费台账</el-divider>
			<el-table :data="detail.consume_logs" border size="small">
				<el-table-column prop="log_no" label="台账号" min-width="150" show-overflow-tooltip />
				<el-table-column label="积分" width="80" align="center">
					<template #default="{ row }">+{{ row.earn_points || 0 }}</template>
				</el-table-column>
				<el-table-column label="储值后" width="100" align="right">
					<template #default="{ row }">{{ fmt(row.balance_after) }}</template>
				</el-table-column>
				<el-table-column prop="remark" label="备注" min-width="160" show-overflow-tooltip />
			</el-table>
		</template>
		<el-empty v-else-if="!detail" description="充值单不存在" />
	</el-drawer>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import businessApi from '@/api/business'

const props = defineProps({ visible: Boolean, recharge: Object })
const emit = defineEmits(['update:visible'])

const detail = ref(null)
const visible = computed({ get: () => props.visible, set: (v) => emit('update:visible', v) })

const statuses = { 0: '待审核', 1: '已审核', 2: '已驳回', 3: '已取消' }
const statusName = computed(() => statuses[detail.value?.status] || '-')
const statusType = computed(() => ({ 0: 'warning', 1: 'success', 2: 'danger', 3: 'info' }[detail.value?.status] || 'info'))
const pays = { cash: '现金', wechat: '微信', alipay: '支付宝', bank: '银行卡', other: '其他' }
const fmt = (n) => (n == null ? '0.00' : Number(n).toFixed(2))

watch(() => [props.visible, props.recharge], async ([show, r]) => {
	if (show && r) {
		const res = await businessApi.memberRecharge.detail.get(r.id)
		detail.value = res.data || null
	} else {
		detail.value = null
	}
}, { immediate: true })
</script>

<style scoped>
.money { color: var(--el-color-primary); }
.money.strong { font-weight: 700; }
</style>
