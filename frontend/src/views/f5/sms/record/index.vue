<template>
	<div class="f5-sms-rec-page f5-page">
		<!-- 查询区 -->
		<div class="rec-query">
			<el-form inline :model="query" @submit.prevent>
				<el-form-item label="关键字">
					<el-input v-model="query.keyword" placeholder="发送编号/手机号/接收人" clearable @keyup.enter="load" @clear="load" />
				</el-form-item>
				<el-form-item label="状态">
					<el-select v-model="query.status" placeholder="全部" clearable style="width: 110px" @change="load">
						<el-option label="成功" value="success" />
						<el-option label="发送中" value="pending" />
						<el-option label="失败" value="failed" />
					</el-select>
				</el-form-item>
				<el-form-item label="时间">
					<el-date-picker v-model="query.range" type="daterange" value-format="YYYY-MM-DD" start-placeholder="开始" end-placeholder="结束" @change="load" />
				</el-form-item>
				<el-form-item>
					<el-button type="primary" @click="load">查询</el-button>
				</el-form-item>
			</el-form>
		</div>

		<!-- 发送记录 -->
		<el-table :data="list" v-loading="loading" border>
			<template #empty><FinEmpty /></template>
			<el-table-column label="发送编号" width="170">
				<template #default="{ row }"><span class="rec-no">{{ row.send_no }}</span></template>
			</el-table-column>
			<el-table-column label="模板" width="170">
				<template #default="{ row }">{{ row.template_name }}</template>
			</el-table-column>
			<el-table-column label="接收人" width="110">
				<template #default="{ row }">{{ row.receiver_name || '—' }}</template>
			</el-table-column>
			<el-table-column label="手机号" width="130" prop="mobile" />
			<el-table-column label="内容" min-width="280">
				<template #default="{ row }">
					<span class="rec-content">{{ row.content }}</span>
				</template>
			</el-table-column>
			<el-table-column label="状态" width="90" align="center">
				<template #default="{ row }">
					<el-tag :type="row.status_tag_type" size="small">{{ row.status_label }}</el-tag>
				</template>
			</el-table-column>
			<el-table-column label="发送时间" width="160">
				<template #default="{ row }">{{ row.sent_at || '—' }}</template>
			</el-table-column>
			<el-table-column label="操作" width="90" fixed="right">
				<template #default="{ row }">
					<el-button link type="primary" size="small" @click="resend(row)">重发</el-button>
				</template>
			</el-table-column>
		</el-table>

		<div class="rec-pager" v-if="total > query.page_size">
			<el-pagination background layout="prev, pager, next, total" :total="total" :page-size="query.page_size" :current-page="query.page" @current-change="(p) => { query.page = p; load() }" />
		</div>
	</div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import FinEmpty from '../../../finance/_FinEmpty.vue'
import mallApi from '@/api/mall'

const query = reactive({ keyword: '', status: null, range: null, page: 1, page_size: 10 })
const list = ref([])
const total = ref(0)
const loading = ref(false)

async function load() {
	loading.value = true
	try {
		const res = await mallApi.sms.records({
			keyword: query.keyword,
			status: query.status ?? '',
			start_date: query.range?.[0] || '',
			end_date: query.range?.[1] || '',
			page: query.page,
			page_size: query.page_size,
		})
		if (res.code === 200) {
			list.value = res.data.list || []
			total.value = res.data.total || 0
		}
	} catch {
		ElMessage.error('加载发送记录失败')
	} finally {
		loading.value = false
	}
}

async function resend(row) {
	try {
		await ElMessageBox.confirm(`确认重发到 ${row.mobile}？`, '重发', { type: 'warning' })
	} catch { return }
	try {
		const res = await mallApi.sms.resend(row.id)
		if (res.code === 200 || res.code === 201) {
			ElMessage.success('重发成功')
			load()
		}
	} catch (e) {
		ElMessage.error(e?.response?.data?.msg || e?.message || '重发失败')
	}
}

onMounted(load)
</script>

<style>
@import '../../_shared.css';
</style>

<style scoped>
.f5-sms-rec-page { padding: 16px 20px; }
.rec-query { background: #fff; padding: 16px 20px; border-bottom: 1px solid #ebeef5; }
.rec-no { font-family: monospace; color: #606266; }
.rec-content { color: #606266; }
.rec-pager { display: flex; justify-content: flex-end; align-items: center; height: 52px; }
</style>
