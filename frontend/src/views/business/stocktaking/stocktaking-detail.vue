<template>
	<div v-loading="loading">
		<div class="base">
			<div><label>盘点单号：</label>{{ detail.check_no }}</div>
			<div><label>仓库：</label>{{ detail.warehouse?.name || '-' }}</div>
			<div><label>盘点日期：</label>{{ (detail.check_date || '').slice(0, 10) }}</div>
			<div><label>盘点类型：</label>{{ typeMap[detail.check_type] || detail.check_type }}</div>
			<div><label>制单人：</label>{{ detail.creator_name || '-' }}</div>
			<div><label>审核人：</label>{{ detail.approver_name || '-' }}</div>
			<div><label>状态：</label>{{ statusMap[detail.status] || detail.status }}</div>
			<div><label>备注：</label>{{ detail.remark || '-' }}</div>
		</div>
		<el-table :data="detail.items || []" size="small" border height="420" :row-class-name="rowClass">
			<el-table-column type="index" label="序号" width="55" align="center" />
			<el-table-column prop="product_code" label="编码" width="110" />
			<el-table-column prop="product_name" label="商品名称" min-width="160" show-overflow-tooltip />
			<el-table-column prop="spec" label="规格" width="90" />
			<el-table-column prop="unit" label="单位" width="60" align="center" />
			<el-table-column prop="book_qty" label="账面" width="80" align="right" />
			<el-table-column prop="actual_qty" label="实盘" width="80" align="right" />
			<el-table-column label="差异" width="90" align="right">
				<template #default="{ row }">
					<span :class="row.diff_qty > 0 ? 'up' : row.diff_qty < 0 ? 'down' : ''">{{ row.diff_qty > 0 ? '+' + row.diff_qty : row.diff_qty }}</span>
				</template>
			</el-table-column>
			<el-table-column label="差异金额" width="110" align="right">
				<template #default="{ row }"><span :class="row.diff_qty > 0 ? 'up' : row.diff_qty < 0 ? 'down' : ''">{{ fmtMoney(row.diff_amount) }}</span></template>
			</el-table-column>
			<el-table-column prop="checker" label="盘点人" width="90" />
		</el-table>
	</div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import businessApi from '@/api/business'

const props = defineProps({ id: { type: [Number, String], required: true } })
const detail = ref({})
const loading = ref(false)
const typeMap = { full: '全面盘点', sample: '抽盘', adjust: '异动盘点' }
const statusMap = { draft: '待盘点', in_progress: '盘点中', pending: '待审核', approved: '已审核', cancelled: '已取消' }

onMounted(async () => {
	loading.value = true
	try {
		const res = await businessApi.stocktaking.detail.get(props.id)
		detail.value = res.data || {}
	} catch (e) {
		ElMessage.error('加载失败')
	} finally {
		loading.value = false
	}
})

function rowClass({ row }) { return row.diff_qty > 0 ? 'row-profit' : row.diff_qty < 0 ? 'row-loss' : '' }
function fmtMoney(v) { return Number(v ?? 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }
</script>

<style scoped>
.base { display: grid; grid-template-columns: 1fr 1fr; gap: 8px 24px; padding: 0 0 14px; font-size: 14px; color: #333; }
.base label { color: #999; }
.up { color: #52c41a; font-weight: 500; }
.down { color: #f5222d; font-weight: 500; }
:deep(.row-profit) { background: #f6ffed !important; }
:deep(.row-loss) { background: #fff2f0 !important; }
</style>
