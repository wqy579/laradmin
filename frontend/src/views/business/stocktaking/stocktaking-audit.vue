<template>
	<el-dialog model-value="visible" title="盘点单审核" width="900px" top="6vh" @close="$emit('close')" destroy-on-close>
		<div v-loading="loading">
			<!-- 基本信息 -->
			<div class="base">
				<div><label>盘点单号：</label>{{ detail.check_no }}</div>
				<div><label>仓库：</label>{{ detail.warehouse?.name || '-' }}</div>
				<div><label>盘点日期：</label>{{ (detail.check_date || '').slice(0, 10) }}</div>
				<div><label>盘点类型：</label>{{ typeMap[detail.check_type] || detail.check_type }}</div>
				<div><label>制单人：</label>{{ detail.creator_name || '-' }}</div>
				<div><label>备注：</label>{{ detail.remark || '-' }}</div>
			</div>

			<!-- 差异卡片 -->
			<div class="cards">
				<div class="card profit">
					<div class="clabel">盘盈金额</div>
					<div class="cnum">¥{{ fmtMoney(detail.profit_amount) }}</div>
					<div class="cfoot">{{ profitCount }} 种商品</div>
				</div>
				<div class="card loss">
					<div class="clabel">盘亏金额</div>
					<div class="cnum">¥{{ fmtMoney(detail.loss_amount) }}</div>
					<div class="cfoot">{{ lossCount }} 种商品</div>
				</div>
				<div class="card net">
					<div class="clabel">净差异金额（盘盈-盘亏）</div>
					<div class="cnum">¥{{ fmtMoney(net) }}</div>
					<div class="cfoot">{{ net >= 0 ? '正数为盘盈' : '负数为盘亏' }}</div>
				</div>
			</div>

			<!-- 差异明细 -->
			<el-table :data="diffItems" size="small" border height="260" :row-class-name="rowClass">
				<el-table-column prop="product_name" label="商品名称" min-width="160" show-overflow-tooltip />
				<el-table-column prop="spec" label="规格" width="90" />
				<el-table-column prop="unit" label="单位" width="60" align="center" />
				<el-table-column prop="book_qty" label="账面数量" width="90" align="right" />
				<el-table-column prop="actual_qty" label="实盘数量" width="90" align="right" />
				<el-table-column label="差异数量" width="90" align="right">
					<template #default="{ row }"><span :class="row.diff_qty > 0 ? 'up' : 'down'">{{ row.diff_qty > 0 ? '+' + row.diff_qty : row.diff_qty }}</span></template>
				</el-table-column>
				<el-table-column label="成本单价" width="90" align="right">
					<template #default="{ row }">¥{{ fmtMoney(row.cost_price) }}</template>
				</el-table-column>
				<el-table-column label="差异金额" width="110" align="right">
					<template #default="{ row }"><span :class="row.diff_qty > 0 ? 'up' : 'down'">{{ fmtSigned(row.diff_amount) }}</span></template>
				</el-table-column>
			</el-table>

			<!-- 审核意见 -->
			<div class="opinion">
				<div class="olabel">审核意见</div>
				<el-input v-model="comment" type="textarea" :rows="3" placeholder="请输入审核意见（可选）" />
			</div>
		</div>

		<template #footer>
			<el-button @click="$emit('close')">取消</el-button>
			<el-button type="danger" plain @click="reject">驳回</el-button>
			<el-button type="success" @click="approve">通过审核</el-button>
		</template>
	</el-dialog>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import businessApi from '@/api/business'

const props = defineProps({ visible: Boolean, row: Object })
const emit = defineEmits(['close', 'done'])

const detail = ref({})
const comment = ref('')
const loading = ref(false)
const typeMap = { full: '全面盘点', sample: '抽盘', adjust: '异动盘点' }

const diffItems = computed(() => (detail.value.items || []).filter((i) => i.diff_qty !== 0))
const profitCount = computed(() => diffItems.value.filter((i) => i.diff_qty > 0).length)
const lossCount = computed(() => diffItems.value.filter((i) => i.diff_qty < 0).length)
const net = computed(() => Number(detail.value.profit_amount || 0) - Number(detail.value.loss_amount || 0))

onMounted(async () => {
	loading.value = true
	try {
		const res = await businessApi.stocktaking.detail.get(props.row.id)
		detail.value = res.data || {}
	} catch (e) {
		ElMessage.error('加载详情失败')
	} finally {
		loading.value = false
	}
})

function rowClass({ row }) { return row.diff_qty > 0 ? 'row-profit' : 'row-loss' }
function fmtMoney(v) { return Number(v ?? 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }
function fmtSigned(v) { const n = Number(v || 0); return (n >= 0 ? '+' : '-') + '¥' + Math.abs(n).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }

async function approve() {
	try { await ElMessageBox.confirm('确认通过审核？通过后将自动调整库存并生成财务凭证', '提示', { type: 'warning' }) }
	catch { return }
	try {
		await businessApi.stocktaking.approve.post(props.row.id, { approval_comment: comment.value })
		ElMessage.success('审核通过')
		emit('done')
	} catch (e) {
		ElMessage.error(e?.response?.data?.message || '审核失败')
	}
}
async function reject() {
	try { await ElMessageBox.confirm('确认驳回？驳回后回到盘点中，可继续修改', '提示', { type: 'warning' }) }
	catch { return }
	try {
		await businessApi.stocktaking.reject.post(props.row.id, { approval_comment: comment.value })
		ElMessage.success('已驳回')
		emit('done')
	} catch (e) {
		ElMessage.error(e?.response?.data?.message || '操作失败')
	}
}
</script>

<style scoped>
.base { display: grid; grid-template-columns: 1fr 1fr; gap: 8px 24px; padding: 8px 0 16px; font-size: 14px; color: #333; }
.base label { color: #999; }
.cards { display: flex; gap: 16px; padding: 0 0 16px; }
.card { flex: 1; border-radius: 4px; padding: 12px 16px; }
.card.profit { background: #f6ffed; border: 1px solid #b7eb8f; }
.card.loss { background: #fff2f0; border: 1px solid #ffccc7; }
.card.net { background: #e6f7ff; border: 1px solid #91d5ff; }
.clabel { font-size: 12px; margin-bottom: 6px; }
.profit .clabel, .profit .cnum { color: #52c41a; }
.loss .clabel, .loss .cnum { color: #f5222d; }
.net .clabel, .net .cnum { color: #1890ff; }
.cnum { font-size: 24px; font-weight: bold; }
.cfoot { font-size: 12px; color: #999; margin-top: 6px; }
.opinion { padding-top: 14px; }
.olabel { font-size: 14px; color: #333; margin-bottom: 8px; }
.up { color: #52c41a; font-weight: 500; }
.down { color: #f5222d; font-weight: 500; }
:deep(.row-profit) { background: #f6ffed !important; }
:deep(.row-loss) { background: #fff2f0 !important; }
</style>
