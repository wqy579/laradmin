<template>
	<sPageSplit side-title="文件分类" :side-width="'200px'">
		<template #side>
			<!-- 左侧分类导航 -->
			<div class="attachment-sidebar">
				<div class="sidebar-nav">
					<div v-for="cat in categories" :key="cat.value" class="sidebar-nav__item" :class="{ 'is-active': activeType === cat.value }" @click="handleTypeChange(cat.value)">
						<el-icon><component :is="cat.icon" /></el-icon>
						<span class="sidebar-nav__label">{{ cat.label }}</span>
						<el-badge v-if="typeCounts[cat.value]" :value="typeCounts[cat.value]" :max="999" class="sidebar-nav__badge" />
					</div>
				</div>

				<!-- 存储统计 -->
				<div class="sidebar-stats">
					<div class="stats-item">
						<span class="stats-label">总文件数</span>
						<span class="stats-value">{{ statistics.total || 0 }}</span>
					</div>
					<div class="stats-item">
						<span class="stats-label">总大小</span>
						<span class="stats-value">{{ formatSize(statistics.total_size) }}</span>
					</div>
				</div>
			</div>
		</template>

		<!-- 右侧内容区 -->
		<div class="attachment-main">
			<!-- 工具栏 -->
			<div class="main-toolbar">
				<div class="toolbar-left">
					<span class="toolbar-title">附件管理</span>
					<el-button v-if="currentDate" @click="handleBatchDelete" :disabled="selectedIds.length === 0" type="danger" plain> 批量删除 ({{ selectedIds.length }}) </el-button>
				</div>
				<div class="toolbar-right">
					<el-input v-if="currentDate" v-model="keyword" placeholder="搜索文件名..." clearable style="width: 220px" @clear="handleSearch" @keyup.enter="handleSearch">
						<template #prefix>
							<el-icon><ElIconSearch /></el-icon>
						</template>
					</el-input>
				</div>
			</div>

			<!-- 面包屑导航 -->
			<div class="main-breadcrumb" v-if="currentDate">
				<el-breadcrumb>
					<el-breadcrumb-item>
						<a href="#" @click.prevent="backToRoot">全部文件</a>
					</el-breadcrumb-item>
					<el-breadcrumb-item>{{ currentDate }}</el-breadcrumb-item>
				</el-breadcrumb>
			</div>

			<!-- 文件夹网格 -->
			<div v-if="!currentDate" class="main-content">
				<div v-if="directories.length === 0 && !loadingDirs" class="empty-wrapper">
					<el-empty description="暂无文件" />
				</div>
				<div v-else v-loading="loadingDirs" class="folder-grid">
					<div v-for="dir in directories" :key="dir.date" class="folder-card" @click="enterFolder(dir.date)">
						<div class="folder-card__icon">
							<el-icon :size="40"><ElIconFolderOpened /></el-icon>
						</div>
						<div class="folder-card__info">
							<div class="folder-card__date">{{ dir.date }}</div>
							<div class="folder-card__meta">
								<span>{{ dir.count }} 个文件</span>
								<span>{{ formatSize(dir.total_size) }}</span>
							</div>
						</div>
					</div>
				</div>
			</div>

			<!-- 文件卡片网格 -->
			<div v-else class="main-content">
				<div v-if="list.length === 0 && !loading" class="empty-wrapper">
					<el-empty description="暂无文件" />
				</div>
				<div v-else v-loading="loading" class="attachment-grid">
					<div v-for="item in list" :key="item.id" class="attachment-card" :class="{ 'is-selected': selectedIds.includes(item.id) }" @click="handleSelect(item)">
						<div class="card-preview" @dblclick="handlePreview(item)">
							<el-image v-if="item.type === 'image' && item.url" :src="item.url" fit="cover" class="card-preview__img" :preview-src-list="previewList" :initial-index="previewIndex(item)">
								<template #error>
									<div class="card-preview__placeholder">
										<el-icon :size="32"><ElIconPicture /></el-icon>
									</div>
								</template>
							</el-image>
							<div v-else class="card-preview__placeholder">
								<el-icon :size="40">
									<component :is="getFileIcon(item)" />
								</el-icon>
								<span class="card-preview__ext">{{ item.extension?.toUpperCase() }}</span>
							</div>

							<div v-if="selectedIds.includes(item.id)" class="card-preview__check">
								<el-icon><ElIconCheck /></el-icon>
							</div>
						</div>

						<div class="card-info">
							<div class="card-info__name" :title="item.name">{{ item.name }}</div>
							<div class="card-info__meta">
								<span>{{ item.formatted_size }}</span>
								<span>{{ formatTime(item.created_at) }}</span>
							</div>
						</div>

						<div class="card-actions" @click.stop>
							<el-button type="primary" link size="small" @click="handlePreview(item)">
								<el-icon><ElIconView /></el-icon>
							</el-button>
							<el-button type="primary" link size="small" @click="handleCopyUrl(item)">
								<el-icon><ElIconCopyDocument /></el-icon>
							</el-button>
							<el-button type="primary" link size="small" @click="handleDownload(item)">
								<el-icon><ElIconDownload /></el-icon>
							</el-button>
							<el-button type="danger" link size="small" @click="handleDelete(item)">
								<el-icon><ElIconDelete /></el-icon>
							</el-button>
						</div>
					</div>
				</div>

				<!-- 分页 -->
				<div class="main-pagination" v-if="total > 0">
					<el-pagination v-model:current-page="page" v-model:page-size="pageSize" :total="total" :page-sizes="[20, 40, 60, 80]" layout="total, sizes, prev, pager, next" @change="loadList" />
				</div>
			</div>
		</div>
	</sPageSplit>

	<!-- 预览弹窗 -->
	<el-dialog v-model="previewVisible" :title="previewItem?.name" width="700px" destroy-on-close>
		<div class="preview-dialog" v-if="previewItem">
			<el-image v-if="previewItem.type === 'image' && previewItem.url" :src="previewItem.url" fit="contain" class="preview-dialog__img" />
			<div v-else class="preview-dialog__file">
				<el-icon :size="64"><component :is="getFileIcon(previewItem)" /></el-icon>
				<p>{{ previewItem.name }}</p>
				<p class="preview-dialog__meta">大小: {{ previewItem.formatted_size }} | 类型: {{ previewItem.mime_type }} | 存储: {{ previewItem.storage_driver }}</p>
			</div>
		</div>
	</el-dialog>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import systemApi from '@/api/system'
import sPageSplit from '@/components/sPageSplit/index.vue'

const categories = [
	{ label: '全部', value: '', icon: 'ElIconFiles' },
	{ label: '图片', value: 'image', icon: 'ElIconPictureFilled' },
	{ label: '文档', value: 'document', icon: 'ElIconDocument' },
	{ label: '视频', value: 'video', icon: 'ElIconVideoCamera' },
	{ label: '音频', value: 'audio', icon: 'ElIconHeadset' },
	{ label: '压缩包', value: 'archive', icon: 'ElIconFolderOpened' },
	{ label: '其他', value: 'other', icon: 'ElIconFiles' },
]

const loading = ref(false)
const list = ref([])
const total = ref(0)
const page = ref(1)
const pageSize = ref(40)
const keyword = ref('')
const activeType = ref('')
const selectedIds = ref([])
const typeCounts = ref({})
const statistics = ref({})
const previewVisible = ref(false)
const previewItem = ref(null)

const currentDate = ref(null)
const directories = ref([])
const loadingDirs = ref(false)

const previewList = computed(() => list.value.filter((i) => i.type === 'image' && i.url).map((i) => i.url))

const previewIndex = (item) => previewList.value.indexOf(item.url)

const getFileIcon = (item) => {
	const iconMap = {
		image: PictureFilled,
		document: Document,
		video: VideoCamera,
		audio: Headset,
		archive: FolderOpened,
		other: Files,
	}
	return iconMap[item.type] || Files
}

const formatTime = (time) => {
	if (!time) return ''
	const d = new Date(time)
	return `${d.getMonth() + 1}/${d.getDate()} ${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`
}

const formatSize = (bytes) => {
	if (!bytes) return '0 B'
	const units = ['B', 'KB', 'MB', 'GB', 'TB']
	let i = 0
	let size = Number(bytes)
	while (size >= 1024 && i < units.length - 1) {
		size /= 1024
		i++
	}
	return `${size.toFixed(i === 0 ? 0 : 1)} ${units[i]}`
}

const loadList = async () => {
	loading.value = true
	try {
		const params = {
			page: page.value,
			page_size: pageSize.value,
		}
		if (activeType.value) params.type = activeType.value
		if (keyword.value) params.keyword = keyword.value
		if (currentDate.value) params.date = currentDate.value

		const res = await systemApi.attachment.list.get(params)
		if (res.code === 200) {
			const data = res.data
			list.value = data.list || []
			total.value = data.total || 0
		}
	} finally {
		loading.value = false
	}
}

const loadDirectories = async () => {
	loadingDirs.value = true
	try {
		const params = {}
		if (activeType.value) params.type = activeType.value
		const res = await systemApi.attachment.directories.get(params)
		if (res.code === 200) {
			directories.value = res.data || []
		}
	} finally {
		loadingDirs.value = false
	}
}

const enterFolder = (date) => {
	currentDate.value = date
	page.value = 1
	selectedIds.value = []
	loadList()
}

const backToRoot = () => {
	currentDate.value = null
	page.value = 1
	selectedIds.value = []
	keyword.value = ''
	loadDirectories()
	loadStatistics()
	loadTypeDistribution()
}

const loadStatistics = async (type = '') => {
	try {
		const params = {}
		if (type) params.type = type
		const res = await systemApi.attachment.statistics.get(params)
		if (res.code === 200) {
			statistics.value = res.data || {}
		}
	} catch {
		// ignore
	}
}

const loadTypeDistribution = async () => {
	try {
		const res = await systemApi.attachment.typeDistribution.get()
		if (res.code === 200) {
			const counts = {}
			;(res.data || []).forEach((item) => {
				counts[item.type] = item.count
			})
			typeCounts.value = counts
		}
	} catch {
		// ignore
	}
}

const handleTypeChange = (type) => {
	activeType.value = type
	page.value = 1
	selectedIds.value = []
	if (currentDate.value) {
		loadList()
	} else {
		loadDirectories()
	}
	loadStatistics(type)
}

const handleSearch = () => {
	page.value = 1
	loadList()
}

const handleSelect = (item) => {
	const idx = selectedIds.value.indexOf(item.id)
	if (idx === -1) {
		selectedIds.value.push(item.id)
	} else {
		selectedIds.value.splice(idx, 1)
	}
}

const handlePreview = (item) => {
	if (item.type === 'image' && item.url) {
		// el-image 内置预览
		return
	}
	previewItem.value = item
	previewVisible.value = true
}

const handleCopyUrl = (item) => {
	if (item.url) {
		navigator.clipboard.writeText(item.url).then(() => {
			ElMessage.success('已复制链接')
		})
	} else {
		ElMessage.warning('该文件无访问链接')
	}
}

const handleDownload = (item) => {
	if (item.url) {
		window.open(item.url, '_blank')
	}
}

const handleDelete = async (item) => {
	try {
		await ElMessageBox.confirm(`确定要删除文件「${item.name}」吗？`, '确认删除', { type: 'warning' })
		const res = await systemApi.attachment.delete.delete(item.id)
		if (res.code === 200) {
			ElMessage.success('删除成功')
			selectedIds.value = selectedIds.value.filter((id) => id !== item.id)
			await Promise.allSettled([loadList(), loadDirectories(), loadStatistics(), loadTypeDistribution()])
		}
	} catch {
		// 取消
	}
}

const handleBatchDelete = async () => {
	if (selectedIds.value.length === 0) return
	try {
		await ElMessageBox.confirm(`确定要删除选中的 ${selectedIds.value.length} 个文件吗？`, '确认删除', { type: 'warning' })
		const res = await systemApi.attachment.batchDelete.post({ ids: selectedIds.value })
		if (res.code === 200) {
			ElMessage.success('批量删除成功')
			selectedIds.value = []
			await Promise.allSettled([loadList(), loadDirectories(), loadStatistics(), loadTypeDistribution()])
		}
	} catch {
		// 取消
	}
}

onMounted(() => {
	loadDirectories()
	loadStatistics()
	loadTypeDistribution()
})
</script>

<style scoped>
/* 左侧边栏（容器由 sPageSplit 提供，这里只管内部布局） */
.attachment-sidebar {
	height: 100%;
	background: var(--el-bg-color);
	display: flex;
	flex-direction: column;
}

.sidebar-nav {
	flex: 1;
	overflow-y: auto;
	padding: 8px;
}

.sidebar-nav__item {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 10px 12px;
	border-radius: 6px;
	cursor: pointer;
	font-size: 13px;
	color: var(--el-text-color-regular);
	transition: all 0.2s;
	margin-bottom: 2px;
}

.sidebar-nav__item:hover {
	background: var(--el-fill-color-light);
	color: var(--el-text-color-primary);
}

.sidebar-nav__item.is-active {
	background: var(--el-color-primary-light-9);
	color: var(--el-color-primary);
	font-weight: 500;
}

.sidebar-nav__label {
	flex: 1;
}

.sidebar-nav__badge {
	flex-shrink: 0;
}

.sidebar-stats {
	padding: 16px;
	border-top: 1px solid var(--el-border-color-lighter);
}

.stats-item {
	display: flex;
	justify-content: space-between;
	font-size: 12px;
	margin-bottom: 6px;
}

.stats-label {
	color: var(--el-text-color-secondary);
}

.stats-value {
	color: var(--el-text-color-primary);
	font-weight: 500;
}

/* 右侧主区域 */
.attachment-main {
	flex: 1;
	display: flex;
	flex-direction: column;
	overflow: hidden;
	min-height: 0;
}

.main-content {
	flex: 1;
	display: flex;
	flex-direction: column;
	overflow: hidden;
	min-height: 0;
}

.main-toolbar {
	height: 52px;
	padding: 0 16px;
	display: flex;
	justify-content: space-between;
	align-items: center;
	border-bottom: 1px solid var(--el-border-color-lighter);
	background: var(--el-bg-color);
}

.toolbar-left {
	display: flex;
	align-items: center;
	gap: 12px;
}

.toolbar-title {
	font-size: 14px;
	font-weight: 600;
	color: var(--el-text-color-primary);
}

.toolbar-right {
	display: flex;
	align-items: center;
	gap: 8px;
}

/* 空状态居中 */
.empty-wrapper {
	flex: 1;
	display: flex;
	align-items: center;
	justify-content: center;
	min-height: 0;
}

/* 面包屑 */
.main-breadcrumb {
	padding: 12px 16px 0;
	font-size: 13px;
}

/* 文件夹网格 */
.folder-grid {
	flex: 1;
	overflow-y: auto;
	padding: 16px;
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
	gap: 12px;
	align-content: start;
}

.folder-card {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 16px;
	border: 1px solid var(--el-border-color-lighter);
	border-radius: 8px;
	cursor: pointer;
	transition: all 0.2s;
	background: var(--el-bg-color);
}

.folder-card:hover {
	border-color: var(--el-color-primary-light-5);
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
	background: var(--el-color-primary-light-9);
}

.folder-card__icon {
	flex-shrink: 0;
	color: var(--el-color-warning);
}

.folder-card__info {
	flex: 1;
	min-width: 0;
}

.folder-card__date {
	font-size: 14px;
	font-weight: 600;
	color: var(--el-text-color-primary);
	margin-bottom: 4px;
}

.folder-card__meta {
	display: flex;
	gap: 8px;
	font-size: 12px;
	color: var(--el-text-color-secondary);
}

/* 卡片网格 */
.attachment-grid {
	flex: 1;
	overflow-y: auto;
	padding: 16px;
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
	gap: 12px;
	align-content: start;
}

.attachment-card {
	border: 1px solid var(--el-border-color-lighter);
	border-radius: 8px;
	overflow: hidden;
	cursor: pointer;
	transition: all 0.2s;
	background: var(--el-bg-color);
}

.attachment-card:hover {
	border-color: var(--el-color-primary-light-5);
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
}

.attachment-card.is-selected {
	border-color: var(--el-color-primary);
	box-shadow: 0 0 0 2px var(--el-color-primary-light-8);
}

.card-preview {
	position: relative;
	width: 100%;
	padding-bottom: 75%;
	background: var(--el-fill-color-lighter);
	overflow: hidden;
}

.card-preview__img {
	position: absolute;
	top: 0;
	left: 0;
	width: 100%;
	height: 100%;
}

.card-preview__placeholder {
	position: absolute;
	top: 0;
	left: 0;
	width: 100%;
	height: 100%;
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	color: var(--el-text-color-placeholder);
	gap: 4px;
}

.card-preview__ext {
	font-size: 11px;
	font-weight: 600;
	color: var(--el-text-color-secondary);
}

.card-preview__check {
	position: absolute;
	top: 8px;
	right: 8px;
	width: 22px;
	height: 22px;
	border-radius: 50%;
	background: var(--el-color-primary);
	color: #fff;
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: 12px;
}

.card-info {
	padding: 8px 10px;
}

.card-info__name {
	font-size: 12px;
	color: var(--el-text-color-primary);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.card-info__meta {
	display: flex;
	justify-content: space-between;
	font-size: 11px;
	color: var(--el-text-color-placeholder);
	margin-top: 4px;
}

.card-actions {
	display: flex;
	justify-content: center;
	gap: 2px;
	padding: 4px 8px 8px;
	opacity: 0;
	transition: opacity 0.2s;
}

.attachment-card:hover .card-actions {
	opacity: 1;
}

/* 分页 */
.main-pagination {
	padding: 12px 16px;
	display: flex;
	justify-content: flex-end;
	border-top: 1px solid var(--el-border-color-lighter);
	background: var(--el-bg-color);
}

/* 预览弹窗 */
.preview-dialog {
	display: flex;
	justify-content: center;
	align-items: center;
	min-height: 300px;
}

.preview-dialog__img {
	max-width: 100%;
	max-height: 500px;
}

.preview-dialog__file {
	text-align: center;
	color: var(--el-text-color-regular);
}

.preview-dialog__meta {
	font-size: 12px;
	color: var(--el-text-color-placeholder);
	margin-top: 8px;
}

@media (max-width: 768px) {
	.main-toolbar {
		height: auto;
		min-height: 52px;
		flex-wrap: wrap;
		padding: 8px 12px;
		gap: 8px;
	}
	.toolbar-left {
		gap: 8px;
	}
	.toolbar-right {
		width: 100%;
	}
	.toolbar-right .el-input {
		width: 100% !important;
	}
	.folder-grid {
		grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
		padding: 12px;
		gap: 8px;
	}
	.folder-card {
		padding: 12px;
	}
	.folder-card__icon {
		font-size: 28px !important;
	}
	.attachment-grid {
		grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
		padding: 12px;
		gap: 8px;
	}
	.card-actions {
		opacity: 1;
	}
	.main-pagination {
		padding: 8px 12px;
	}
	.main-pagination :deep(.el-pagination) {
		flex-wrap: wrap;
		justify-content: center;
	}
}
</style>
