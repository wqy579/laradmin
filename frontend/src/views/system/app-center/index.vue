<template>
	<div class="app-center">
		<!-- 统计条：真实计算功能数 / 分组数 / 收藏数 -->
		<div class="ac-stats">
			<div class="ac-stat">
				<div class="ac-stat__value tabular">{{ allItems.length }}</div>
				<div class="ac-stat__label">功能</div>
			</div>
			<div class="ac-stat">
				<div class="ac-stat__value tabular">{{ groups.length }}</div>
				<div class="ac-stat__label">分组</div>
			</div>
			<div class="ac-stat">
				<div class="ac-stat__value tabular">{{ favoritePaths.length }}</div>
				<div class="ac-stat__label">已收藏</div>
			</div>
			<div class="ac-search">
				<el-input v-model="keyword" placeholder="搜索功能名称或路径" clearable>
					<template #prefix>
						<el-icon><ElIconSearch /></el-icon>
					</template>
				</el-input>
			</div>
		</div>

		<!-- 常用菜单（localStorage 收藏） -->
		<div class="ac-section">
			<div class="ac-section__head">
				<span class="ac-section__title">
					<el-icon class="ac-section__icon"><ElIconStarFilled /></el-icon>
					常用菜单
				</span>
				<el-button v-if="favoriteItems.length > 0" link type="danger" size="small" @click="clearFavorites">
					<el-icon><ElIconDelete /></el-icon>
					<span>清空</span>
				</el-button>
			</div>
			<template v-if="favoriteItems.length > 0">
				<div class="ac-grid">
					<div v-for="item in favoriteItems" :key="item.path" class="ac-card" @click="go(item.path)">
						<div class="ac-card__icon">
							<el-icon><ElIconCollectionTag /></el-icon>
						</div>
						<div class="ac-card__body">
							<div class="ac-card__title">{{ item.title }}</div>
							<div class="ac-card__path">{{ item.path }}</div>
						</div>
						<el-tooltip content="取消收藏" placement="top" :show-after="300">
							<button type="button" class="ac-card__star is-active" @click.stop="toggleFavorite(item.path)">
								<el-icon><ElIconStarFilled /></el-icon>
							</button>
						</el-tooltip>
					</div>
				</div>
			</template>
			<el-empty v-else description="暂无常用菜单：在下方功能卡片上点星标收藏，收藏会保存在本机浏览器，刷新后仍在" :image-size="72" />
		</div>

		<!-- 全部功能：由 moduleRoutes 真实生成，按 path 首段分组 -->
		<div class="ac-section">
			<div class="ac-section__head">
				<span class="ac-section__title">
					<el-icon class="ac-section__icon"><ElIconFolderOpened /></el-icon>
					全部功能
				</span>
				<el-radio-group v-model="activeGroup" size="small">
					<el-radio-button label="">全部</el-radio-button>
					<el-radio-button v-for="g in groups" :key="g.key" :label="g.key">{{ g.label }}（{{ g.count }}）</el-radio-button>
				</el-radio-group>
			</div>
			<template v-if="filteredGroups.length > 0">
				<div v-for="g in filteredGroups" :key="g.key" class="ac-group">
					<div class="ac-group__title">{{ g.label }}<span class="ac-group__count tabular">{{ g.count }}</span></div>
					<div class="ac-grid">
						<div v-for="item in g.items" :key="item.path" class="ac-card" @click="go(item.path)">
							<div class="ac-card__icon">
								<el-icon><ElIconFiles /></el-icon>
							</div>
							<div class="ac-card__body">
								<div class="ac-card__title">{{ item.title }}</div>
								<div class="ac-card__path">{{ item.path }}</div>
							</div>
							<el-tooltip :content="isFavorite(item.path) ? '取消收藏' : '加入常用菜单'" placement="top" :show-after="300">
								<button type="button" class="ac-card__star" :class="{ 'is-active': isFavorite(item.path) }" @click.stop="toggleFavorite(item.path)">
									<el-icon><component :is="isFavorite(item.path) ? 'ElIconStarFilled' : 'ElIconStar'" /></el-icon>
								</button>
							</el-tooltip>
						</div>
					</div>
				</div>
			</template>
			<el-empty v-else description="没有匹配的功能，换个关键字试试" :image-size="72" />
		</div>
	</div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import moduleRoutes from '@/router/modules'

const router = useRouter()

// 收藏持久化：localStorage，读写均做容错（隐私模式/JSON 损坏不至于白屏）
const STORAGE_KEY = 'qa4:favorite-menus'

function loadFavorites() {
	try {
		const raw = localStorage.getItem(STORAGE_KEY)
		const arr = raw ? JSON.parse(raw) : []
		return Array.isArray(arr) ? arr.filter((p) => typeof p === 'string') : []
	} catch (e) {
		return []
	}
}

function saveFavorites(paths) {
	try {
		localStorage.setItem(STORAGE_KEY, JSON.stringify(paths))
	} catch (e) {
		// 写入失败（如隐私模式配额）静默忽略：收藏仅本会话有效
	}
}

const favoritePaths = ref(loadFavorites())

// 功能目录数据源：模块路由数组（元素含 path / meta.title）。注意 moduleRoutes
// 不含 systemRoutes 静态骨架（login/dashboard 等），正好只收「模块级」业务功能。
const allItems = computed(() =>
	moduleRoutes
		.filter((r) => r && r.path)
		.map((r) => ({
			path: r.path,
			title: r.meta?.title || r.name || r.path,
			groupKey: r.path.split('/')[1] || 'other',
		}))
)

const GROUP_LABELS = {
	agr: '协议管理',
	asset: '资产',
	business: '业务',
	cash: '出纳资金',
	cashier: '出纳',
	finance: '财务',
	hr: '人事',
	points: '积分',
	report: '报表',
	system: '系统',
}

const groups = computed(() => {
	const map = new Map()
	for (const item of allItems.value) {
		if (!map.has(item.groupKey)) map.set(item.groupKey, [])
		map.get(item.groupKey).push(item)
	}
	return Array.from(map.entries())
		.map(([key, items]) => ({ key, label: GROUP_LABELS[key] || key, count: items.length, items }))
		.sort((a, b) => b.count - a.count)
})

const keyword = ref('')
const activeGroup = ref('')

const matchItem = (item, kw) => item.title.toLowerCase().includes(kw) || item.path.toLowerCase().includes(kw)

const filteredGroups = computed(() => {
	const kw = keyword.value.trim().toLowerCase()
	return groups.value
		.filter((g) => !activeGroup.value || g.key === activeGroup.value)
		.map((g) => ({ ...g, items: kw ? g.items.filter((i) => matchItem(i, kw)) : g.items }))
		.filter((g) => g.items.length > 0)
})

// 收藏区按收藏顺序置顶展示；仅保留当前仍存在的路径（路由模块随时可能增删）
const favoriteItems = computed(() =>
	favoritePaths.value
		.map((p) => allItems.value.find((i) => i.path === p))
		.filter(Boolean)
)

const isFavorite = (path) => favoritePaths.value.includes(path)

function toggleFavorite(path) {
	const next = isFavorite(path) ? favoritePaths.value.filter((p) => p !== path) : [...favoritePaths.value, path]
	favoritePaths.value = next
	saveFavorites(next)
}

function clearFavorites() {
	favoritePaths.value = []
	saveFavorites([])
}

function go(path) {
	router.push(path)
}
</script>

<style scoped>
.app-center {
	height: 100%;
	padding: 20px;
	overflow-y: auto;
	background: var(--el-bg-color);
	display: flex;
	flex-direction: column;
	gap: 16px;
}

/* ---- 统计条 ---- */
.ac-stats {
	display: flex;
	align-items: center;
	gap: 12px;
}
.ac-stat {
	flex: 0 0 auto;
	min-width: 96px;
	padding: 12px 16px;
	background: var(--el-fill-color-lighter);
	border-radius: 8px;
}
.ac-stat__value {
	font-size: 22px;
	font-weight: 700;
	color: var(--el-text-color-primary);
	line-height: 1.2;
}
.ac-stat__label {
	font-size: 12px;
	color: var(--el-text-color-secondary);
	margin-top: 2px;
}
.ac-search {
	flex: 1;
	max-width: 320px;
	margin-left: auto;
}

/* ---- 分区 ---- */
.ac-section {
	background: var(--el-fill-color-lighter);
	border-radius: 8px;
	padding: 16px;
}
.ac-section__head {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	flex-wrap: wrap;
	margin-bottom: 12px;
}
.ac-section__title {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	font-size: 15px;
	font-weight: 600;
	color: var(--el-text-color-primary);
}
.ac-section__icon {
	color: var(--el-color-primary);
}

/* ---- 分组 ---- */
.ac-group {
	margin-bottom: 16px;
}
.ac-group:last-child {
	margin-bottom: 0;
}
.ac-group__title {
	display: flex;
	align-items: center;
	gap: 8px;
	font-size: 13px;
	font-weight: 600;
	color: var(--el-text-color-regular);
	margin-bottom: 8px;
}
.ac-group__count {
	min-width: 20px;
	height: 16px;
	padding: 0 5px;
	border-radius: 8px;
	background: var(--el-fill-color);
	color: var(--el-text-color-secondary);
	font-size: 12px;
	line-height: 16px;
	text-align: center;
}

/* ---- 功能卡片 ---- */
.ac-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
	gap: 12px;
}
.ac-card {
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 12px;
	background: var(--el-bg-color);
	border: 1px solid var(--el-border-color-lighter);
	border-radius: 8px;
	cursor: pointer;
	transition: all 0.2s;
}
.ac-card:hover {
	border-color: var(--el-color-primary);
	box-shadow: 0 2px 8px rgba(0, 21, 41, 0.08);
	transform: translateY(-1px);
}
.ac-card__icon {
	flex: 0 0 auto;
	display: flex;
	align-items: center;
	justify-content: center;
	width: 36px;
	height: 36px;
	border-radius: 8px;
	background: var(--el-color-primary-light-9);
	color: var(--el-color-primary);
	font-size: 18px;
}
.ac-card__body {
	flex: 1;
	min-width: 0;
}
.ac-card__title {
	font-size: 13px;
	font-weight: 500;
	color: var(--el-text-color-primary);
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}
.ac-card__path {
	font-size: 12px;
	color: var(--el-text-color-secondary);
	margin-top: 2px;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}
.ac-card__star {
	flex: 0 0 auto;
	display: flex;
	align-items: center;
	justify-content: center;
	width: 28px;
	height: 28px;
	padding: 0;
	border: none;
	border-radius: 6px;
	background: transparent;
	color: var(--el-text-color-placeholder);
	font-size: 16px;
	cursor: pointer;
	transition: all 0.2s;
}
.ac-card__star:hover {
	background: var(--el-fill-color);
	color: var(--el-color-warning);
}
.ac-card__star.is-active {
	color: var(--el-color-warning);
}
</style>
