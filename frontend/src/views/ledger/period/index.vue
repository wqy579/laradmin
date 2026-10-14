<template>
	<div class="page">
		<div class="page-header"><span class="title">财务月结</span></div>
		<el-card shadow="never" class="card">
			<el-collapse v-model="activeYears">
				<el-collapse-item v-for="y in years" :key="y.year" :title="`${y.year} 年`" :name="y.year">
					<el-table :data="y.periods" border stripe size="small">
						<el-table-column prop="period" label="期间" width="100" align="center" />
						<el-table-column prop="start_date" label="开始日期" width="120" align="center" />
						<el-table-column prop="end_date" label="结束日期" width="120" align="center" />
						<el-table-column label="状态" width="90" align="center">
							<template #default="{ row }"><el-tag :type="row.status === 'closed' ? 'success' : 'info'" size="small">{{ row.status_label }}</el-tag></template>
						</el-table-column>
						<el-table-column prop="voucher_count" label="凭证张数" width="90" align="center" />
						<el-table-column label="借方合计" width="120" align="right"><template #default="{ row }">{{ fmt(row.total_debit) }}</template></el-table-column>
						<el-table-column label="贷方合计" width="120" align="right"><template #default="{ row }">{{ fmt(row.total_credit) }}</template></el-table-column>
						<el-table-column prop="closed_by_name" label="结账人" width="100" />
						<el-table-column prop="closed_at" label="结账时间" width="160" align="center" />
						<el-table-column label="操作" width="280" align="center" fixed="right">
							<template #default="{ row }">
								<el-button link type="primary" @click="check(row)">试算平衡</el-button>
								<el-button link type="warning" @click="carryForward(row)">结转损益</el-button>
								<el-button v-if="row.status === 'open'" link type="success" @click="close(row)">结账</el-button>
								<el-button v-if="row.status === 'closed'" link type="danger" @click="reopen(row)">反结账</el-button>
								<el-button v-if="row.status === 'closed'" link type="info" @click="viewReport(row)">结账报告</el-button>
							</template>
						</el-table-column>
					</el-table>
				</el-collapse-item>
			</el-collapse>
		</el-card>

		<!-- 结账检查弹窗 -->
		<el-dialog v-model="checkVisible" title="结账检查" width="720px" destroy-on-close>
			<div class="check-list">
				<div v-for="c in checkData.checks" :key="c.key" class="check-item" :class="{ pass: c.passed, fail: !c.passed && c.blocking, warn: !c.passed && !c.blocking }">
					<el-icon><ElIconCircleCheck v-if="c.passed" /><ElIconCircleClose v-else-if="c.blocking" /><ElIconWarning v-else /></el-icon>
					<span class="check-title">{{ c.title }}</span>
					<span class="check-detail">{{ c.detail }}</span>
				</div>
			</div>
			<template #footer>
				<el-button @click="checkVisible = false">取消</el-button>
				<el-button type="primary" :disabled="!checkData.can_close" @click="confirmClose">确认结账</el-button>
			</template>
		</el-dialog>

		<!-- 结账报告弹窗 -->
		<el-dialog v-model="reportVisible" title="结账报告" width="720px" destroy-on-close>
			<div class="check-list">
				<div v-for="c in reportData.checks" :key="c.key" class="check-item" :class="{ pass: c.passed, fail: !c.passed && c.blocking, warn: !c.passed && !c.blocking }">
					<el-icon><ElIconCircleCheck v-if="c.passed" /><ElIconCircleClose v-else-if="c.blocking" /><ElIconWarning v-else /></el-icon>
					<span class="check-title">{{ c.title }}</span>
					<span class="check-detail">{{ c.detail }}</span>
				</div>
			</div>
			<template #footer><el-button @click="reportVisible = false">关闭</el-button></template>
		</el-dialog>
	</div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import ledgerApi from '@/api/ledger'

const years = ref([])
const activeYears = ref([])
const loading = ref(false)
const checkVisible = ref(false)
const reportVisible = ref(false)
const checkData = ref({ checks: [], can_close: false })
const reportData = ref({ checks: [] })
const currentPeriod = ref('')

const fmt = (v) => Number(v || 0).toFixed(2)

const load = async () => {
	loading.value = true
	try {
		const res = await ledgerApi.period.list.get()
		years.value = res.data?.years || []
		activeYears.value = years.value.map(y => y.year)
	} catch { ElMessage.error('加载失败') }
	finally { loading.value = false }
}
const check = async (row) => {
	currentPeriod.value = row.period
	try {
		const res = await ledgerApi.period.check.post(row.period)
		checkData.value = res.data
		checkVisible.value = true
	} catch { ElMessage.error('检查失败') }
}
const confirmClose = async () => {
	ElMessageBox.confirm('确认结账？结账后该期间锁定，凭证不可增删改，期初余额与科目余额固化。', '操作确认', { type: 'warning' })
		.then(async () => {
			try { await ledgerApi.period.close.post(currentPeriod.value); ElMessage.success('月结成功'); checkVisible.value = false; load() }
			catch (e) { ElMessage.error(e?.message || '月结失败') }
		}).catch(() => {})
}
const carryForward = (row) => {
	ElMessageBox.confirm('一键结转损益？将把收入、成本、费用类科目本期余额结转到本年利润，生成一张结转凭证。', '操作确认', { type: 'warning' })
		.then(async () => {
			try { const res = await ledgerApi.period.carryForward.post(row.period); ElMessage.success(`结转损益凭证已生成：${res.data?.voucher_no}`); load() }
			catch (e) { ElMessage.error(e?.message || '结转失败') }
		}).catch(() => {})
}
const close = (row) => check(row)
const reopen = (row) => {
	ElMessageBox.confirm('确认反结账？反结账后该期间重新开放，凭证可修改。只能反结账最后一个已结账期间。', '操作确认', { type: 'warning' })
		.then(async () => {
			try { await ledgerApi.period.reopen.post(row.period); ElMessage.success('反结账成功'); load() }
			catch (e) { ElMessage.error(e?.message || '反结账失败') }
		}).catch(() => {})
}
const viewReport = (row) => {
	reportData.value = row.check_report || { checks: [] }
	reportVisible.value = true
}
onMounted(() => load())
</script>

<style scoped>
.page { background: #f0f2f5; min-height: 100vh; }
.page-header { height: 50px; background: #fff; border-bottom: 1px solid #e8e8e8; padding: 0 20px; display: flex; align-items: center; }
.title { font-size: 18px; font-weight: 700; color: #303133; }
.card { margin: 16px 20px; }
.check-list { display: flex; flex-direction: column; gap: 12px; }
.check-item { display: flex; align-items: center; gap: 8px; padding: 12px 16px; border-radius: 4px; font-size: 14px; }
.check-item.pass { background: #f0f9eb; color: #67c23a; }
.check-item.fail { background: #fef0f0; color: #f56c6c; }
.check-item.warn { background: #fdf6ec; color: #e6a23c; }
.check-title { font-weight: 700; }
.check-detail { color: #606266; margin-left: 8px; }
</style>
