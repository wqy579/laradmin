<template>
	<el-dialog :model-value="modelValue" title="促销效果报表" width="1000px" top="6vh" @close="$emit('update:modelValue', false)" @open="load">
		<div class="cards">
			<div class="card c1"><div class="cl">促销活动数</div><div class="cv">{{ summary.promotion_count || 0 }}</div></div>
			<div class="card c2"><div class="cl">参与订单数</div><div class="cv">{{ summary.order_count || 0 }}</div></div>
			<div class="card c3"><div class="cl">优惠总金额</div><div class="cv">¥{{ fmt(summary.discount_total) }}</div></div>
			<div class="card c4"><div class="cl">促销销售额</div><div class="cv">¥{{ fmt(summary.sales_total) }}</div></div>
		</div>
		<el-table :data="list" size="small" border height="380">
			<el-table-column prop="promotion_no" label="促销单号" width="150" />
			<el-table-column prop="name" label="促销名称" min-width="150" show-overflow-tooltip />
			<el-table-column prop="type" label="类型" width="100"><template #default="{row}">{{ typeMap[row.type] }}</template></el-table-column>
			<el-table-column prop="start_time" label="开始" width="100" />
			<el-table-column prop="used_orders" label="订单数" width="80" align="center" />
			<el-table-column prop="item_count" label="商品数" width="80" align="center" />
			<el-table-column label="优惠金额" width="110" align="right"><template #default="{row}">¥{{ fmt(row.discount_sum) }}</template></el-table-column>
			<el-table-column label="ROI" width="90" align="center">
				<template #default="{row}"><span :class="roiClass(row.roi)">{{ row.roi }}</span></template>
			</el-table-column>
		</el-table>
	</el-dialog>
</template>

<script setup>
import { ref } from 'vue'
import businessApi from '@/api/business'
defineProps({ modelValue: Boolean })
defineEmits(['update:modelValue'])
const list = ref([]), summary = ref({})
const typeMap = { discount: '限时折扣', full_reduction: '满减', buy_gift: '买赠', special_price: '特价' }
function fmt(v){ return Number(v??0).toLocaleString('zh-CN',{minimumFractionDigits:2,maximumFractionDigits:2}) }
function roiClass(v){ return v>5?'up':v>=2?'mid':'down' }
async function load(){
	const res = await businessApi.promotion.report.get({})
	list.value = res.data?.list || []; summary.value = res.data?.summary || {}
}
</script>

<style scoped>
.cards { display: flex; gap: 12px; margin-bottom: 16px; }
.card { flex: 1; border-radius: 4px; padding: 14px; }
.c1 { background:#e6f7ff; border:1px solid #91d5ff; } .c2 { background:#f6ffed; border:1px solid #b7eb8f; }
.c3 { background:#fff7e6; border:1px solid #ffd591; } .c4 { background:#fff1f0; border:1px solid #ffa39e; }
.cl { font-size: 12px; margin-bottom: 8px; } .cv { font-size: 24px; font-weight: bold; }
.c1 .cl,.c1 .cv { color:#1890ff; } .c2 .cl,.c2 .cv { color:#52c41a; }
.c3 .cl,.c3 .cv { color:#fa8c16; } .c4 .cl,.c4 .cv { color:#f5222d; }
.up { color:#52c41a; font-weight:bold; } .mid { color:#fa8c16; } .down { color:#f5222d; }
</style>
