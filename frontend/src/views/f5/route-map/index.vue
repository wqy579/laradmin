<template>
	<div class="f5-map-page">
		<!-- 查询区 -->
		<div class="mp-query">
			<el-form inline :model="query" @submit.prevent>
				<el-form-item label="日期">
					<el-date-picker v-model="query.date" type="date" value-format="YYYY-MM-DD" :clearable="false" @change="onQuery" />
				</el-form-item>
				<el-form-item label="业务员">
					<el-select v-model="query.employee_id" placeholder="选择业务员" filterable clearable style="width: 180px" @change="onQuery">
						<el-option v-for="s in salesmen" :key="s.id" :label="`${s.name}${s.code ? '（' + s.code + '）' : ''}`" :value="s.id" />
					</el-select>
				</el-form-item>
				<el-form-item>
					<el-button type="primary" @click="onQuery">
						<el-icon><Search /></el-icon>查询
					</el-button>
					<el-button @click="resetQuery">重置</el-button>
				</el-form-item>
			</el-form>
		</div>

		<!-- 地图主体 -->
		<div class="mp-body" v-loading="loading">
			<div class="mp-map-wrap">
				<div class="mp-map">
					<svg viewBox="0 0 900 520" class="mp-svg" preserveAspectRatio="xMidYMid meet">
						<defs>
							<pattern id="mp-grid" width="30" height="30" patternUnits="userSpaceOnUse">
								<path d="M 30 0 L 0 0 0 30" fill="none" stroke="#ebeef5" stroke-width="1" />
							</pattern>
						</defs>
						<rect x="0" y="0" width="900" height="520" fill="#f7f9fc" />
						<rect x="0" y="0" width="900" height="520" fill="url(#mp-grid)" />

						<!-- 客户配送点位（静态底图） -->
						<g v-if="showCustomers">
							<g v-for="c in customerPoints" :key="'c'+c.id">
								<circle :cx="c.x" :cy="c.y" r="3.5" fill="#909399" opacity="0.55" />
								<title>{{ c.name }}</title>
							</g>
						</g>

						<!-- 轨迹折线 -->
						<polyline v-if="trajPoints.length > 1" :points="trajLine" fill="none" stroke="#409EFF" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" />

						<!-- 已走过路径（回放进度高亮） -->
						<polyline v-if="playedPoints.length > 1" :points="playedLine" fill="none" stroke="#409EFF" stroke-width="4" stroke-linejoin="round" stroke-linecap="round" opacity="0.85" />

						<!-- 轨迹点序号 -->
						<g v-for="(p, idx) in trajPoints" :key="'p'+p.id">
							<circle :cx="p.x" :cy="p.y" r="11" :fill="idx === playIndex ? '#E6A23C' : '#409EFF'" :class="{ 'mp-point-active': idx === playIndex }" />
							<text :x="p.x" :y="p.y + 4" text-anchor="middle" font-size="10" fill="#fff" font-weight="600">{{ p.seq }}</text>
							<text :x="p.x" :y="p.y - 16" text-anchor="middle" font-size="11" fill="#303133">{{ p.customer_name }}</text>
						</g>

						<!-- 回放游标 -->
						<g v-if="playCursor">
							<circle :cx="playCursor.x" :cy="playCursor.y" r="7" fill="#E6A23C" class="mp-cursor" />
						</g>
					</svg>
					<div v-if="!trajPoints.length" class="mp-empty">
						<el-empty description="该业务员当天没有带经纬度的拜访记录" :image-size="90" />
					</div>
				</div>

				<!-- 回放控件条 -->
				<div class="mp-controls" :class="{ 'mp-controls-disabled': !trajPoints.length }">
					<el-button-group>
						<el-button :disabled="!canPlay" @click="togglePlay">
							<el-icon><VideoPlay v-if="!playing" /><VideoPause v-else /></el-icon>
							{{ playing ? '暂停' : '播放' }}
						</el-button>
						<el-button :disabled="!canPlay" @click="restart">重新播放</el-button>
					</el-button-group>
					<el-radio-group v-model="speed" size="small" :disabled="!canPlay">
						<el-radio-button :value="1">1x</el-radio-button>
						<el-radio-button :value="2">2x</el-radio-button>
					</el-radio-group>
					<div class="mp-progress">
						<el-slider v-model="progress" :min="0" :max="Math.max(trajPoints.length - 1, 1)" :step="1" :disabled="!canPlay" @input="onSeek" />
					</div>
					<span class="mp-progress-text">{{ playIndex + 1 }} / {{ trajPoints.length }}</span>
				</div>
			</div>

			<!-- 右侧拜访点列表 -->
			<div class="mp-list">
				<div class="mp-list-head">
					<span>当日拜访点</span>
					<span class="mp-list-stat">共 {{ total_count }} 个，{{ located_count }} 个有定位</span>
				</div>
				<div class="mp-list-tools">
					<el-checkbox v-model="showCustomers" size="small">显示配送点位</el-checkbox>
				</div>
				<ol class="mp-ol">
					<li v-for="p in traj.points || []" :key="p.id" :class="{ 'mp-li-active': p.seq - 1 === playIndex }" @click="seekTo(p.seq - 1)">
						<span class="mp-seq">{{ p.seq }}</span>
						<div class="mp-li-main">
							<div class="mp-li-name">{{ p.customer_name || '—' }}</div>
							<div class="mp-li-addr">{{ p.checkin_address || p.customer_address || '—' }}</div>
						</div>
						<span class="mp-li-time">{{ shortTime(p.checkin_time) }}</span>
						<el-tag v-if="!p.has_location" size="small" type="info">无定位</el-tag>
					</li>
				</ol>
				<div v-if="!(traj.points || []).length" class="mp-empty-list">暂无拜访记录</div>
			</div>
		</div>
	</div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, onUnmounted } from 'vue'
import { ElMessage } from 'element-plus'
import { Search, VideoPlay, VideoPause } from '@element-plus/icons-vue'
import mallApi from '@/api/mall'

const query = reactive({ date: '', employee_id: null })
const salesmen = ref([])
const customers = ref([])
const showCustomers = ref(true)

const traj = reactive({ date: '', employee_name: '', points: [], located_count: 0, total_count: 0 })
const loading = ref(false)

// 回放状态
const playing = ref(false)
const speed = ref(1)
const progress = ref(0)
const playIndex = ref(0)
let timer = null

const locatedPoints = computed(() => (traj.points || []).filter((p) => p.has_location && p.lat !== null && p.lng !== null))

/** 归一化经纬度 → SVG 坐标（留边距） */
function normalize(points, w = 900, h = 520, pad = 44) {
	if (!points.length) return []
	const lats = points.map((p) => Number(p.lat))
	const lngs = points.map((p) => Number(p.lng))
	const minLat = Math.min(...lats), maxLat = Math.max(...lats)
	const minLng = Math.min(...lngs), maxLng = Math.max(...lngs)
	const spanLat = maxLat - minLat || 0.001
	const spanLng = maxLng - minLng || 0.001
	return points.map((p) => ({
		...p,
		x: pad + ((Number(p.lng) - minLng) / spanLng) * (w - pad * 2),
		y: pad + ((maxLat - Number(p.lat)) / spanLat) * (h - pad * 2),
	}))
}

const trajPoints = computed(() => normalize(locatedPoints.value))
const customerPoints = computed(() => normalize(customers.value.map((c) => ({ id: c.id, name: c.name, lat: c.lat, lng: c.lng }))))
const trajLine = computed(() => trajPoints.value.map((p) => `${p.x},${p.y}`).join(' '))

const playedPoints = computed(() => trajPoints.value.slice(0, playIndex.value + 1))
const playedLine = computed(() => playedPoints.value.map((p) => `${p.x},${p.y}`).join(' '))
const playCursor = computed(() => trajPoints.value[playIndex.value] || null)
const canPlay = computed(() => trajPoints.value.length > 0)

const shortTime = (t) => String(t || '').slice(11, 16)

function stopTimer() {
	if (timer) { clearInterval(timer); timer = null }
}
function tick() {
	if (playIndex.value >= trajPoints.value.length - 1) {
		playing.value = false
		stopTimer()
		return
	}
	playIndex.value += 1
	progress.value = playIndex.value
}
function togglePlay() {
	if (playing.value) { playing.value = false; stopTimer(); return }
	if (playIndex.value >= trajPoints.value.length - 1) playIndex.value = 0
	playing.value = true
	// 1x：900ms/点；2x：450ms/点
	const interval = speed.value === 2 ? 450 : 900
	stopTimer()
	timer = setInterval(tick, interval)
}
function restart() {
	playIndex.value = 0
	progress.value = 0
	if (!playing.value) togglePlay()
}
function onSeek(v) {
	playIndex.value = Number(v)
	progress.value = Number(v)
}
function seekTo(idx) {
	playIndex.value = idx
	progress.value = idx
}

async function loadSalesmen() {
	try {
		const res = await mallApi.map.salesmen({ date: query.date })
		if (res.code === 200) salesmen.value = res.data?.salesmen || []
	} catch { /* 静默 */ }
}
async function loadCustomers() {
	try {
		const res = await mallApi.map.customers()
		if (res.code === 200) customers.value = res.data?.customers || []
	} catch { /* 静默 */ }
}
async function loadTrajectory() {
	if (!query.employee_id) { traj.points = []; return }
	loading.value = true
	stopTimer()
	playing.value = false
	playIndex.value = 0
	progress.value = 0
	try {
		const res = await mallApi.map.trajectory({ employee_id: query.employee_id, date: query.date })
		if (res.code === 200) {
			Object.assign(traj, {
				date: res.data?.date || query.date,
				employee_name: res.data?.employee_name || '',
				points: res.data?.points || [],
				located_count: res.data?.located_count || 0,
				total_count: res.data?.total_count || 0,
			})
		}
	} catch {
		ElMessage.error('加载轨迹失败')
	} finally {
		loading.value = false
	}
}

function onQuery() {
	loadSalesmen()
	loadTrajectory()
}
function resetQuery() {
	query.date = ''
	query.employee_id = null
	loadSalesmen()
	loadTrajectory()
}

onMounted(() => {
	query.date = new Date().toISOString().slice(0, 10)
	loadSalesmen()
	loadCustomers()
})
onUnmounted(stopTimer)
</script>

<style scoped>
.f5-map-page { display: flex; flex-direction: column; height: 100%; }
.mp-query { background: #fff; padding: 16px 20px; border-bottom: 1px solid #ebeef5; }
.mp-body { flex: 1; display: flex; gap: 16px; padding: 16px 20px; min-height: 0; }
.mp-map-wrap { flex: 1; display: flex; flex-direction: column; min-width: 0; }
.mp-map { flex: 1; position: relative; border: 1px solid #e4e7ed; border-radius: 4px; overflow: hidden; background: #f7f9fc; }
.mp-svg { width: 100%; height: 100%; display: block; }
.mp-empty { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; }
.mp-controls { display: flex; align-items: center; gap: 14px; padding: 12px 4px 0; }
.mp-controls-disabled { opacity: 0.5; }
.mp-progress { flex: 1; }
.mp-progress :deep(.el-slider) { margin: 0 8px; }
.mp-progress-text { font-size: 12px; color: #606266; width: 52px; text-align: right; font-variant-numeric: tabular-nums; }
.mp-point-active { filter: drop-shadow(0 0 4px rgba(230, 162, 60, 0.8)); }
.mp-cursor { filter: drop-shadow(0 0 6px rgba(230, 162, 60, 0.9)); animation: mp-pulse 1.2s ease-in-out infinite; }
@keyframes mp-pulse { 0%, 100% { r: 7; } 50% { r: 10; } }
.mp-list { width: 340px; display: flex; flex-direction: column; border: 1px solid #e4e7ed; border-radius: 4px; overflow: hidden; background: #fff; }
.mp-list-head { padding: 10px 12px; background: #f5f7fa; font-size: 13px; color: #303133; font-weight: 500; border-bottom: 1px solid #e4e7ed; display: flex; justify-content: space-between; align-items: center; }
.mp-list-stat { font-size: 12px; color: #909399; font-weight: 400; }
.mp-list-tools { padding: 8px 12px; border-bottom: 1px solid #ebeef5; }
.mp-ol { list-style: none; margin: 0; padding: 0; overflow: auto; flex: 1; }
.mp-ol li { display: flex; align-items: center; gap: 8px; padding: 9px 12px; border-bottom: 1px dashed #ebeef5; cursor: pointer; }
.mp-ol li:hover { background: #f5f7fa; }
.mp-ol li.mp-li-active { background: #fdf6ec; }
.mp-seq { width: 20px; height: 20px; border-radius: 50%; background: #409eff; color: #fff; font-size: 11px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.mp-li-active .mp-seq { background: #e6a23c; }
.mp-li-main { flex: 1; min-width: 0; }
.mp-li-name { font-size: 13px; color: #303133; }
.mp-li-addr { font-size: 11px; color: #909399; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.mp-li-time { font-size: 12px; color: #606266; font-variant-numeric: tabular-nums; }
.mp-empty-list { padding: 40px 0; text-align: center; color: #909399; font-size: 13px; }
</style>
