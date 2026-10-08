<template>
	<div class="rp-page">
		<div class="rp-toolbar">
			<el-date-picker
				v-model="dateRange"
				type="daterange"
				range-separator="至"
				start-placeholder="开始日期"
				end-placeholder="结束日期"
				value-format="YYYY-MM-DD"
				size="small"
				style="width: 240px"
				@change="onSearch"
			/>
			<el-select
				v-model="salesmanIds"
				multiple
				collapse-tags
				collapse-tags-tooltip
				filterable
				clearable
				size="small"
				style="width: 220px"
				placeholder="业务员（不选为全部）"
				@change="onSearch"
			>
				<el-option v-for="u in users" :key="u.id" :label="u.name" :value="u.id" />
			</el-select>
			<el-button size="small" class="rp-btn-primary" @click="onSearch">查询</el-button>
			<span class="rp-spacer"></span>
			<el-button size="small" :loading="exporting" @click="onExport">导出 Excel</el-button>
			<el-button size="small" @click="onPrint">打印</el-button>
			<el-button size="small" circle title="刷新" @click="fetchData">
				<el-icon><Refresh /></el-icon>
			</el-button>
		</div>

		<div class="sch-table">
			<el-table
				v-loading="loading"
				:data="list"
				size="small"
				border
				height="100%"
				:row-style="{ height: '36px' }"
				:header-cell-style="{ height: '40px', background: '#f5f7fa', color: '#909399', fontWeight: 700, fontSize: '13px' }"
			>
				<el-table-column type="index" label="#" width="50" align="center" />
				<el-table-column prop="visit_no" label="拜访单号" width="150" />
				<el-table-column prop="employee_code" label="业务员编码" width="110" />
				<el-table-column prop="employee_name" label="业务员" width="100" />
				<el-table-column prop="customer_code" label="客户编码" width="110" />
				<el-table-column prop="customer_name" label="客户名称" width="180" show-overflow-tooltip />
				<el-table-column prop="route_name" label="所属线路" width="120" show-overflow-tooltip />
				<el-table-column prop="checkin_time" label="签到时间" width="160" align="center" />
				<el-table-column prop="checkout_time" label="签退时间" width="160" align="center" />
				<el-table-column prop="visit_duration" label="停留(分钟)" width="100" align="right" />
				<el-table-column prop="checkin_address" label="签到地址" min-width="200" show-overflow-tooltip />
				<el-table-column prop="visit_result" label="拜访结果" width="100" align="center" />
				<el-table-column label="操作" width="170" align="center" fixed="right">
					<template #default="{ row }">
						<el-button link size="small" @click="openDetail(row)">详情</el-button>
						<el-button
							link
							size="small"
							type="primary"
							:disabled="!row.checkin_lat || !row.checkin_lng"
							@click="openTrajectory(row)"
						>
							轨迹
						</el-button>
					</template>
				</el-table-column>
			</el-table>
		</div>

		<div class="rp-summary">
			<span>拜访次数 <b>{{ summary.visit_count || 0 }}</b></span>
			<span>拜访客户数 <b>{{ summary.customer_count || 0 }}</b></span>
			<span>业务员数 <b>{{ summary.salesman_count || 0 }}</b></span>
			<span>总时长 <b>{{ summary.total_duration || 0 }}</b> 分钟</span>
			<span>平均时长 <b>{{ summary.avg_duration || 0 }}</b> 分钟</span>
		</div>

		<div class="rp-grid-page">
			<el-pagination
				background
				size="small"
				layout="total, sizes, prev, pager, next"
				:total="total"
				:page-size="pageSize"
				:page-sizes="[10, 20, 30, 50, 100]"
				:current-page="page"
				@current-change="page = $event; fetchData()"
				@update:page-size="pageSize = $event; page = 1; fetchData()"
			/>
		</div>

		<!-- 拜访详情 600×500 -->
		<el-dialog v-model="detailVisible" title="拜访详情" width="600px" top="8vh">
			<el-descriptions :column="2" size="small" border>
				<el-descriptions-item label="拜访单号">{{ current.visit_no }}</el-descriptions-item>
				<el-descriptions-item label="业务员">{{ current.employee_name }}（{{ current.employee_code }}）</el-descriptions-item>
				<el-descriptions-item label="客户">{{ current.customer_name }}（{{ current.customer_code }}）</el-descriptions-item>
				<el-descriptions-item label="所属线路">{{ current.route_name || '—' }}</el-descriptions-item>
				<el-descriptions-item label="签到时间">{{ current.checkin_time }}</el-descriptions-item>
				<el-descriptions-item label="签退时间">{{ current.checkout_time || '—' }}</el-descriptions-item>
				<el-descriptions-item label="停留时长">{{ current.visit_duration }} 分钟</el-descriptions-item>
				<el-descriptions-item label="拜访结果">{{ current.visit_result || '—' }}</el-descriptions-item>
				<el-descriptions-item label="签到地址" :span="2">{{ current.checkin_address || '—' }}</el-descriptions-item>
				<el-descriptions-item label="备注" :span="2">{{ current.remark || '—' }}</el-descriptions-item>
			</el-descriptions>
			<div v-if="current.checkin_photo" class="sch-photo">
				<el-image :src="current.checkin_photo" :preview-src-list="[current.checkin_photo]" fit="cover" />
			</div>
		</el-dialog>

		<!-- 地图轨迹 1000×650 -->
		<el-dialog v-model="trajVisible" :title="`${traj.employee_name} · ${traj.date} 拜访轨迹`" width="1000px" top="5vh">
			<div v-loading="trajLoading" class="traj-box">
				<div class="traj-map">
					<!-- 没有地图 SDK：把经纬度归一化后画折线，够用且零依赖 -->
					<svg viewBox="0 0 640 420" class="traj-svg" preserveAspectRatio="xMidYMid meet">
						<rect x="0" y="0" width="640" height="420" fill="#f5f7fa" />
						<polyline v-if="trajPoints.length > 1" :points="trajLine" fill="none" stroke="#409eff" stroke-width="2" stroke-dasharray="6 4" />
						<g v-for="p in trajPoints" :key="p.id">
							<circle :cx="p.x" :cy="p.y" r="9" fill="#409eff" />
							<text :x="p.x" :y="p.y + 4" text-anchor="middle" font-size="11" fill="#fff">{{ p.seq }}</text>
							<text :x="p.x" :y="p.y - 14" text-anchor="middle" font-size="11" fill="#303133">{{ p.customer_name }}</text>
						</g>
					</svg>
					<div v-if="!trajPoints.length" class="traj-empty">
						该业务员当天没有带经纬度的拜访记录
					</div>
				</div>
				<div class="traj-list">
					<div class="traj-list-head">
						<span>共 {{ traj.total_count || 0 }} 个拜访点，{{ traj.located_count || 0 }} 个有定位</span>
					</div>
					<ol class="traj-ol">
						<li v-for="p in traj.points || []" :key="p.id">
							<span class="traj-time">{{ shortTime(p.checkin_time) }}</span>
							<span class="traj-cust">{{ p.customer_name || '—' }}</span>
							<span class="traj-addr">{{ p.checkin_address || '—' }}</span>
							<el-tag v-if="!p.has_location" size="small" type="info">无定位</el-tag>
						</li>
					</ol>
				</div>
			</div>
		</el-dialog>
	</div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import { Refresh } from '@element-plus/icons-vue'
import businessApi from '@/api/business'
import { saveBlob, printTable } from '@/utils/fileDownload'

const COLUMNS = [
	{ prop: 'visit_no', label: '拜访单号', type: 'text' },
	{ prop: 'employee_code', label: '业务员编码', type: 'text' },
	{ prop: 'employee_name', label: '业务员', type: 'text' },
	{ prop: 'customer_code', label: '客户编码', type: 'text' },
	{ prop: 'customer_name', label: '客户名称', type: 'text' },
	{ prop: 'route_name', label: '所属线路', type: 'text' },
	{ prop: 'checkin_time', label: '签到时间', type: 'text' },
	{ prop: 'checkout_time', label: '签退时间', type: 'text' },
	{ prop: 'visit_duration', label: '停留时长(分钟)', type: 'int' },
	{ prop: 'checkin_address', label: '签到地址', type: 'text' },
	{ prop: 'visit_result', label: '拜访结果', type: 'text' },
]

const list = ref([])
const summary = ref({})
const total = ref(0)
const page = ref(1)
const pageSize = ref(30)
const loading = ref(false)
const exporting = ref(false)
const users = ref([])
const salesmanIds = ref([])

const now = new Date()
const first = new Date(now.getFullYear(), now.getMonth(), 1)
const fmt = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
const dateRange = ref([fmt(first), fmt(now)])

const detailVisible = ref(false)
const current = ref({})
const trajVisible = ref(false)
const trajLoading = ref(false)
const traj = reactive({ date: '', employee_name: '', points: [], located_count: 0, total_count: 0 })

function buildParams() {
	return {
		date_start: dateRange.value?.[0] || '',
		date_end: dateRange.value?.[1] || '',
		salesman_ids: salesmanIds.value,
		page: page.value,
		page_size: pageSize.value,
	}
}

async function fetchData() {
	loading.value = true
	try {
		const res = await businessApi.visit.schedule.get(buildParams())
		if (res.code === 200) {
			list.value = res.data?.list || []
			total.value = res.data?.total || 0
			summary.value = res.data?.summary || {}
		}
	} catch {
		ElMessage.error('加载行程失败')
	} finally {
		loading.value = false
	}
}

async function fetchUsers() {
	const res = await businessApi.mail.contacts.get()
	if (res.code === 200) users.value = res.data?.users?.map((u) => ({ id: u.value, name: u.label })) || []
}

function onSearch() {
	page.value = 1
	fetchData()
}

async function onExport() {
	exporting.value = true
	try {
		const blob = await businessApi.visit.scheduleExport.get(buildParams())
		saveBlob(blob, '业务员行程.csv')
	} catch {
		ElMessage.error('导出失败')
	} finally {
		exporting.value = false
	}
}
function onPrint() {
	if (!list.value.length) return ElMessage.warning('当前没有可打印的数据')
	printTable(COLUMNS, list.value, '业务员行程')
}

function openDetail(row) {
	current.value = row
	detailVisible.value = true
}

/** 轨迹点归一化：把经纬度线性映射到 SVG 画布，避免引入地图 SDK */
const trajPoints = computed(() => {
	const points = (traj.points || []).filter((p) => p.has_location && p.checkin_lat !== null && p.checkin_lng !== null)
	if (!points.length) return []
	const lats = points.map((p) => Number(p.checkin_lat))
	const lngs = points.map((p) => Number(p.checkin_lng))
	const minLat = Math.min(...lats), maxLat = Math.max(...lats)
	const minLng = Math.min(...lngs), maxLng = Math.max(...lngs)
	const spanLat = maxLat - minLat || 0.001
	const spanLng = maxLng - minLng || 0.001

	return points.map((p) => ({
		id: p.id,
		seq: p.seq,
		customer_name: p.customer_name || '—',
		// 留 40px 边距，否则首尾点会贴在边框上被裁掉
		x: 40 + ((Number(p.checkin_lng) - minLng) / spanLng) * 560,
		y: 40 + ((maxLat - Number(p.checkin_lat)) / spanLat) * 340,
	}))
})
const trajLine = computed(() => trajPoints.value.map((p) => `${p.x},${p.y}`).join(' '))
const shortTime = (t) => String(t || '').slice(11, 16)

async function openTrajectory(row) {
	trajVisible.value = true
	trajLoading.value = true
	const date = String(row.checkin_time || '').slice(0, 10)
	try {
		const res = await businessApi.visit.trajectory.get({ employee_id: row.employee_id || row.employee_code, date })
		if (res.code === 200) {
			Object.assign(traj, {
				date: res.data?.date || date,
				employee_name: res.data?.employee_name || row.employee_name,
				points: res.data?.points || [],
				located_count: res.data?.located_count || 0,
				total_count: res.data?.total_count || 0,
			})
		}
	} catch {
		ElMessage.error('加载轨迹失败')
	} finally {
		trajLoading.value = false
	}
}

onMounted(() => {
	fetchUsers()
	fetchData()
})
</script>

<style scoped>
.sch-table {
	flex: 1;
	min-height: 0;
}
.rp-grid-page {
	display: flex;
	align-items: center;
	padding: 8px 16px;
	flex-shrink: 0;
}
.sch-photo {
	margin-top: 12px;
}
.sch-photo :deep(.el-image) {
	width: 160px;
	height: 120px;
	border-radius: 4px;
	border: 1px solid #e4e7ed;
}
.traj-box {
	display: flex;
	gap: 16px;
	height: 520px;
}
.traj-map {
	flex: 1;
	position: relative;
	border: 1px solid #e4e7ed;
	border-radius: 4px;
	overflow: hidden;
}
.traj-svg {
	width: 100%;
	height: 100%;
}
.traj-empty {
	position: absolute;
	inset: 0;
	display: flex;
	align-items: center;
	justify-content: center;
	color: #909399;
	font-size: 13px;
}
.traj-list {
	width: 320px;
	display: flex;
	flex-direction: column;
	border: 1px solid #e4e7ed;
	border-radius: 4px;
	overflow: hidden;
}
.traj-list-head {
	padding: 8px 12px;
	background: #f5f7fa;
	font-size: 13px;
	color: #606266;
	border-bottom: 1px solid #e4e7ed;
}
.traj-ol {
	list-style: decimal;
	margin: 0;
	padding: 8px 12px 8px 28px;
	overflow: auto;
	flex: 1;
}
.traj-ol li {
	font-size: 12px;
	color: #606266;
	line-height: 20px;
	padding: 4px 0;
	border-bottom: 1px dashed #ebeef5;
}
.traj-time {
	color: #409eff;
	font-weight: 600;
	margin-right: 6px;
}
.traj-cust {
	color: #303133;
	margin-right: 6px;
}
.traj-addr {
	color: #909399;
}
</style>
