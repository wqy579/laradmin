<template>
	<div v-loading="loading">
		<el-descriptions :column="2" border>
			<el-descriptions-item label="调整单号">{{ data.adjust_no }}</el-descriptions-item>
			<el-descriptions-item label="状态">
				<el-tag :type="statusMeta[data.status]?.type" size="small">{{ statusMeta[data.status]?.label || data.status }}</el-tag>
			</el-descriptions-item>
			<el-descriptions-item label="调整日期">{{ data.adjust_date }}</el-descriptions-item>
			<el-descriptions-item label="调整类型">
				<el-tag :type="typeMeta[data.adjust_type]?.type" size="small">{{ typeMeta[data.adjust_type]?.label || data.adjust_type }}</el-tag>
			</el-descriptions-item>
			<el-descriptions-item label="仓库">{{ data.warehouse?.name || '-' }}</el-descriptions-item>
			<el-descriptions-item label="创建人">{{ data.creator_name || '-' }}</el-descriptions-item>
			<el-descriptions-item label="调整数量">{{ qtyFormat(data.total_qty) }}</el-descriptions-item>
			<el-descriptions-item label="调整金额">¥{{ fmtMoney(Math.abs(data.total_amount)) }}</el-descriptions-item>
			<el-descriptions-item label="审核人">{{ data.approver_name || '-' }}</el-descriptions-item>
			<el-descriptions-item label="审核时间">{{ data.approved_at || '-' }}</el-descriptions-item>
			<el-descriptions-item label="调整原因" :span="2">{{ data.reason || '-' }}</el-descriptions-item>
			<el-descriptions-item label="审核意见" :span="2">{{ data.approval_comment || '-' }}</el-descriptions-item>
		</el-descriptions>

		<h3 style="margin: 20px 0 10px; font-size: 16px; color: #303133">明细列表</h3>
		<el-table :data="data.items || []" border size="small">
			<el-table-column type="index" label="序号" width="55" align="center" />
			<el-table-column prop="product_code" label="商品编码" width="100" />
			<el-table-column prop="product_name" label="商品名称" min-width="150" show-overflow-tooltip />
			<el-table-column prop="spec" label="规格" width="80" />
			<el-table-column prop="unit" label="单位" width="60" align="center" />
			<el-table-column prop="before_qty" label="调整前库存" width="100" align="right" />
			<el-table-column prop="adjust_qty" label="调整数量" width="100" align="right">
				<template #default="{ row }">
					<span :class="qtyClass(row.adjust_qty)">{{ qtyFormat(row.adjust_qty) }}</span>
				</template>
			</el-table-column>
			<el-table-column prop="after_qty" label="调整后库存" width="100" align="right" />
			<el-table-column prop="unit_cost" label="单位成本" width="100" align="right">
				<template #default="{ row }">¥{{ fmtMoney(row.unit_cost) }}</template>
			</el-table-column>
			<el-table-column prop="total_cost" label="总成本" width="100" align="right">
				<template #default="{ row }">¥{{ fmtMoney(Math.abs(row.total_cost)) }}</template>
			</el-table-column>
			<el-table-column prop="remark" label="备注" min-width="120" show-overflow-tooltip />
		</el-table>
	</div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import businessApi from '@/api/business'

const props = defineProps({
	id: { type: [Number, String], required: true },
})

const loading = ref(false)
const data = ref({})

const typeMeta = {
	stock_loss: { label: '库存损耗', type: 'danger' },
	stock_gain: { label: '库存溢余', type: 'success' },
	other: { label: '其他', type: 'info' },
}

const statusMeta = {
	draft: { label: '草稿', type: 'info' },
	pending: { label: '待审核', type: 'warning' },
	approved: { label: '已审核', type: 'success' },
	cancelled: { label: '已取消', type: 'info' },
}

async function fetchData() {
	loading.value = true
	try {
		const res = await businessApi.stockAdjust.detail.get(props.id)
		data.value = res.data || {}
	} catch (e) {
		ElMessage.error('加载详情失败')
	} finally {
		loading.value = false
	}
}

function qtyFormat(v) {
	const n = Number(v || 0)
	return n > 0 ? `+${n}` : `${n}`
}

function qtyClass(v) {
	const n = Number(v || 0)
	return n > 0 ? 'num-up' : n < 0 ? 'num-down' : 'num-zero'
}

function fmtMoney(v) {
	return Number(v ?? 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

onMounted(fetchData)
</script>

<style scoped>
.num-up { color: #52c41a; font-weight: 500; }
.num-down { color: #f5222d; font-weight: 500; }
.num-zero { color: #999; }
</style>
