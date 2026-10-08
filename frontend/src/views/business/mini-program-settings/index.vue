<template>
	<div class="rp-page">
		<div class="rp-row">
			<!-- 左侧 200px：9 个设置项 -->
			<div class="rp-aside">
				<div
					v-for="g in groups"
					:key="g.key"
					class="rp-aside-item"
					:class="{ 'is-active': activeGroup === g.key }"
					@click="switchGroup(g.key)"
				>
					<span>{{ g.title }}</span>
				</div>
			</div>

			<div class="rp-main">
				<div class="rp-toolbar">
					<span class="rp-title">{{ currentTitle }}</span>
					<span class="rp-spacer"></span>
					<el-button size="small" @click="onReset">恢复默认</el-button>
					<el-button size="small" class="rp-btn-primary" :loading="saving" @click="onSave">保存设置</el-button>
				</div>

				<div v-loading="loading" class="set-body">
					<!-- 基本设置 -->
					<el-form v-if="activeGroup === 'basic'" :model="config" label-width="120px" size="small">
						<el-form-item label="小程序名称"><el-input v-model="config.name" placeholder="小程序名称" /></el-form-item>
						<el-form-item label="AppID"><el-input v-model="config.app_id" placeholder="wx 开头" /></el-form-item>
						<el-form-item label="AppSecret">
							<el-input v-model="config.app_secret" type="password" show-password placeholder="已保存的值以 ****** 显示" />
						</el-form-item>
						<el-form-item label="小程序图标">
							<el-input v-model="config.icon" placeholder="图标 URL" />
						</el-form-item>
						<el-form-item label="简介"><el-input v-model="config.intro" type="textarea" :rows="3" /></el-form-item>
						<el-form-item label="客服电话"><el-input v-model="config.service_phone" /></el-form-item>
						<el-form-item label="客服微信"><el-input v-model="config.service_wechat" /></el-form-item>
						<el-form-item label="request 域名"><el-input v-model="config.request_domain" /></el-form-item>
						<el-form-item label="socket 域名"><el-input v-model="config.socket_domain" /></el-form-item>
						<el-form-item label="uploadFile 域名"><el-input v-model="config.upload_domain" /></el-form-item>
						<el-form-item label="downloadFile 域名"><el-input v-model="config.download_domain" /></el-form-item>
					</el-form>

					<!-- 首页装修：左侧可选组件 + 中间手机预览 + 右侧已选排序 -->
					<div v-else-if="activeGroup === 'home'" class="deco">
						<div class="deco-palette">
							<div class="deco-block-title">组件库</div>
							<div
								v-for="w in WIDGETS"
								:key="w.key"
								class="deco-widget"
								draggable="true"
								@dragstart="onDragStart($event, w)"
							>
								{{ w.label }}
							</div>
							<div class="deco-hint">拖动组件到右侧预览区，或在预览区上下拖动调整顺序</div>
						</div>

						<div class="deco-phone">
							<div class="phone-frame">
								<div class="phone-nav">{{ config.nav_title || '小程序首页' }}</div>
								<div
									class="phone-body"
									@dragover.prevent
									@drop="onDrop"
								>
									<div
										v-for="(c, i) in components"
										:key="c._id"
										class="phone-comp"
										:class="{ 'is-active': dragIndex === i }"
										draggable="true"
										@dragstart="onDragStart($event, c, i)"
										@dragover.prevent="dragOverIndex = i"
										@drop.prevent="onReorder(i)"
										@dragend="onDragEnd"
									>
										<span>{{ widgetLabel(c.key) }}</span>
										<el-button link type="danger" size="small" @click="removeComponent(i)">移除</el-button>
									</div>
									<div v-if="!components.length" class="phone-empty">拖拽组件到这里</div>
								</div>
							</div>
						</div>

						<div class="deco-props">
							<div class="deco-block-title">页面设置</div>
							<el-form :model="config" label-width="90px" size="small">
								<el-form-item label="导航栏标题">
									<el-input v-model="config.nav_title" placeholder="留空显示小程序名称" />
								</el-form-item>
							</el-form>
						</div>
					</div>

					<!-- 商品分类 -->
					<div v-else-if="activeGroup === 'category'">
						<el-form :model="config" label-width="120px" size="small">
							<el-form-item label="显示分类导航">
								<el-switch v-model="config.show_category_tab" />
							</el-form-item>
						</el-form>
						<div class="list-block">
							<div class="list-block-head">
								<span class="rp-title">首页分类</span>
								<el-button size="small" type="primary" @click="addCategory">新增分类</el-button>
							</div>
							<el-table :data="config.categories" size="small" border>
								<el-table-column label="名称" min-width="160">
									<template #default="{ row }"><el-input v-model="row.name" size="small" /></template>
								</el-table-column>
								<el-table-column label="图标" min-width="200">
									<template #default="{ row }"><el-input v-model="row.icon" size="small" placeholder="图标 URL" /></template>
								</el-table-column>
								<el-table-column label="排序" width="100" align="center">
									<template #default="{ row }"><el-input-number v-model="row.sort" size="small" controls-position="right" /></template>
								</el-table-column>
								<el-table-column label="操作" width="80" align="center">
									<template #default="{ $index }">
										<el-button link type="danger" size="small" @click="config.categories.splice($index, 1)">删除</el-button>
									</template>
								</el-table-column>
							</el-table>
						</div>
					</div>

					<!-- 支付设置 -->
					<el-form v-else-if="activeGroup === 'payment'" :model="config" label-width="130px" size="small">
						<el-form-item label="微信支付商户号"><el-input v-model="config.mch_id" /></el-form-item>
						<el-form-item label="API 密钥">
							<el-input v-model="config.api_key" type="password" show-password placeholder="已保存的值以 ****** 显示" />
						</el-form-item>
						<el-form-item label="证书路径"><el-input v-model="config.cert_path" /></el-form-item>
						<el-form-item label="支付回调地址">
							<el-input :model-value="readonly.notify_url || ''" disabled />
						</el-form-item>
						<el-form-item label="启用微信支付"><el-switch v-model="config.enable_wechat" /></el-form-item>
						<el-form-item label="启用余额支付"><el-switch v-model="config.enable_balance" /></el-form-item>
						<el-form-item label="启用积分抵扣"><el-switch v-model="config.enable_points" /></el-form-item>
						<el-form-item label="积分抵扣比例">
							<el-input-number v-model="config.points_ratio" :min="1" :step="10" controls-position="right" />
							<span class="set-suffix">积分抵 1 元</span>
						</el-form-item>
					</el-form>

					<!-- 消息推送 -->
					<div v-else-if="activeGroup === 'notify'">
						<el-table :data="config.templates" size="small" border>
							<el-table-column prop="name" label="消息场景" min-width="200" />
							<el-table-column label="启用" width="90" align="center">
								<template #default="{ row }"><el-switch v-model="row.enabled" /></template>
							</el-table-column>
							<el-table-column label="模板 ID" min-width="260">
								<template #default="{ row }">
									<el-input v-model="row.template_id" size="small" placeholder="微信公众平台获取" :disabled="!row.enabled" />
								</template>
							</el-table-column>
						</el-table>
					</div>

					<!-- 配送设置 -->
					<el-form v-else-if="activeGroup === 'delivery'" :model="config" label-width="130px" size="small">
						<el-form-item label="配送方式">
							<el-radio-group v-model="config.delivery_mode">
								<el-radio value="self">自营配送</el-radio>
								<el-radio value="third">第三方配送</el-radio>
								<el-radio value="pickup">到店自提</el-radio>
							</el-radio-group>
						</el-form-item>
						<el-form-item label="包邮金额门槛">
							<el-input-number v-model="config.free_shipping_amount" :min="0" :step="10" controls-position="right" />
							<span class="set-suffix">元（0 表示不包邮）</span>
						</el-form-item>
						<el-form-item label="基础配送费">
							<el-input-number v-model="config.shipping_fee" :min="0" :step="1" controls-position="right" />
							<span class="set-suffix">元</span>
						</el-form-item>
						<el-form-item label="配送范围">
							<el-input-number v-model="config.delivery_range_km" :min="0" :step="1" controls-position="right" />
							<span class="set-suffix">公里（0 表示不限）</span>
						</el-form-item>
						<el-form-item label="支持自提"><el-switch v-model="config.support_pickup" /></el-form-item>
						<el-form-item label="自提地址"><el-input v-model="config.pickup_address" /></el-form-item>
					</el-form>

					<!-- 会员设置 -->
					<div v-else-if="activeGroup === 'member'">
						<el-form :model="config" label-width="130px" size="small">
							<el-form-item label="启用会员"><el-switch v-model="config.enable_member" /></el-form-item>
							<el-form-item label="默认等级"><el-input v-model="config.default_level" style="width: 200px" /></el-form-item>
							<el-form-item label="每元得积分">
								<el-input-number v-model="config.points_per_yuan" :min="0" :step="1" controls-position="right" />
								<span class="set-suffix">分</span>
							</el-form-item>
						</el-form>
						<div class="list-block">
							<div class="list-block-head">
								<span class="rp-title">会员等级</span>
								<el-button size="small" type="primary" @click="addLevel">新增等级</el-button>
							</div>
							<el-table :data="config.levels" size="small" border>
								<el-table-column label="等级名称" min-width="140">
									<template #default="{ row }"><el-input v-model="row.name" size="small" /></template>
								</el-table-column>
								<el-table-column label="成长值门槛" width="140">
									<template #default="{ row }"><el-input-number v-model="row.min_points" size="small" :min="0" controls-position="right" /></template>
								</el-table-column>
								<el-table-column label="折扣(%)" width="130">
									<template #default="{ row }"><el-input-number v-model="row.discount" size="small" :min="0" :max="100" controls-position="right" /></template>
								</el-table-column>
								<el-table-column label="操作" width="80" align="center">
									<template #default="{ $index }">
										<el-button link type="danger" size="small" @click="config.levels.splice($index, 1)">删除</el-button>
									</template>
								</el-table-column>
							</el-table>
						</div>
					</div>

					<!-- 优惠券设置 -->
					<div v-else-if="activeGroup === 'coupon'">
						<el-form :model="config" label-width="150px" size="small">
							<el-form-item label="启用优惠券"><el-switch v-model="config.enable_coupon" /></el-form-item>
							<el-form-item label="每单最多可用">
								<el-input-number v-model="config.max_usable_per_order" :min="1" :max="10" controls-position="right" />
								<span class="set-suffix">张</span>
							</el-form-item>
							<el-form-item label="允许叠加使用"><el-switch v-model="config.allow_stack" /></el-form-item>
						</el-form>
						<div class="list-block">
							<div class="list-block-head">
								<span class="rp-title">优惠券</span>
								<el-button size="small" type="primary" @click="addCoupon">新增优惠券</el-button>
							</div>
							<el-table :data="config.coupons" size="small" border>
								<el-table-column label="名称" min-width="140">
									<template #default="{ row }"><el-input v-model="row.name" size="small" /></template>
								</el-table-column>
								<el-table-column label="门槛(元)" width="120">
									<template #default="{ row }"><el-input-number v-model="row.min_amount" size="small" :min="0" controls-position="right" /></template>
								</el-table-column>
								<el-table-column label="面额(元)" width="120">
									<template #default="{ row }"><el-input-number v-model="row.amount" size="small" :min="0" controls-position="right" /></template>
								</el-table-column>
								<el-table-column label="有效期(天)" width="130">
									<template #default="{ row }"><el-input-number v-model="row.valid_days" size="small" :min="1" controls-position="right" /></template>
								</el-table-column>
								<el-table-column label="操作" width="80" align="center">
									<template #default="{ $index }">
										<el-button link type="danger" size="small" @click="config.coupons.splice($index, 1)">删除</el-button>
									</template>
								</el-table-column>
							</el-table>
						</div>
					</div>

					<!-- 关于我们 -->
					<el-form v-else-if="activeGroup === 'about'" :model="config" label-width="100px" size="small">
						<el-form-item label="正文内容">
							<el-input v-model="config.content" type="textarea" :rows="16" placeholder="支持纯文本，小程序端原样展示" />
						</el-form-item>
					</el-form>
				</div>
			</div>
		</div>
	</div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import businessApi from '@/api/business'

/** 首页装修可选组件：key 与后端存进 components 的 key 一致 */
const WIDGETS = [
	{ key: 'banner', label: '轮播图' },
	{ key: 'search', label: '搜索栏' },
	{ key: 'category', label: '分类导航' },
	{ key: 'notice', label: '公告栏' },
	{ key: 'coupon', label: '优惠券' },
	{ key: 'seckill', label: '秒杀专区' },
	{ key: 'recommend', label: '推荐商品' },
	{ key: 'newest', label: '新品上市' },
	{ key: 'brand', label: '品牌专区' },
]
const widgetLabel = (key) => WIDGETS.find((w) => w.key === key)?.label || key

const groups = ref([])
const activeGroup = ref('basic')
const config = reactive({})
const readonly = ref({})
const loading = ref(false)
const saving = ref(false)

const currentTitle = computed(() => groups.value.find((g) => g.key === activeGroup.value)?.title || '设置')

/** 首页装修：components 是数组，单独维护便于拖拽排序 */
const components = ref([])
const dragIndex = ref(-1)
const dragOverIndex = ref(-1)
let dragging = null

async function loadGroup() {
	loading.value = true
	try {
		const res = await businessApi.miniProgramSetting.list.get({ group: activeGroup.value })
		if (res.code !== 200) return
		const data = res.data || {}
		if (Array.isArray(data.groups) && data.groups.length) groups.value = data.groups
		readonly.value = data.readonly || {}
		Object.keys(config).forEach((k) => delete config[k])
		Object.assign(config, data.config || {})
		components.value = (config.components || []).map((c, i) => ({ ...c, _id: `${c.key || 'w'}-${i}` }))
	} catch {
		ElMessage.error('加载设置失败')
	} finally {
		loading.value = false
	}
}

function switchGroup(key) {
	activeGroup.value = key
	loadGroup()
}

// ===== 首页装修拖拽 =====
function onDragStart(e, widget, index) {
	dragging = { widget, index }
	dragIndex.value = index ?? -1
	e.dataTransfer.effectAllowed = 'move'
}
function onDrop() {
	if (!dragging) return
	// 从组件库拖入（没有 index）；已在预览区的拖放由 onReorder 处理
	if (dragging.index === undefined) {
		components.value.push({ ...dragging.widget })
	}
	onDragEnd()
}
function onReorder(targetIndex) {
	if (!dragging || dragging.index === undefined) {
		onDrop()
		return
	}
	const from = dragging.index
	if (from === targetIndex) return onDragEnd()
	const [item] = components.value.splice(from, 1)
	components.value.splice(targetIndex, 0, item)
	onDragEnd()
}
function onDragEnd() {
	dragging = null
	dragIndex.value = -1
	dragOverIndex.value = -1
}
function removeComponent(i) {
	components.value.splice(i, 1)
}

// ===== 列表型配置的行操作 =====
function addCategory() {
	config.categories = config.categories || []
	config.categories.push({ name: '', icon: '', sort: config.categories.length + 1 })
}
function addLevel() {
	config.levels = config.levels || []
	config.levels.push({ name: '', min_points: 0, discount: 100 })
}
function addCoupon() {
	config.coupons = config.coupons || []
	config.coupons.push({ name: '', min_amount: 0, amount: 0, valid_days: 7 })
}

async function onSave() {
	saving.value = true
	try {
		// 首页装修的组件顺序以预览区为准，存之前回写进 config
		if (activeGroup.value === 'home') {
			config.components = components.value.map(({ _id, ...rest }) => rest)
		}
		const res = await businessApi.miniProgramSetting.save.put(activeGroup.value, { config: JSON.parse(JSON.stringify(config)) })
		if (res.code === 200) {
			ElMessage.success('设置已保存')
			loadGroup()
		}
	} catch {
		ElMessage.error('保存失败')
	} finally {
		saving.value = false
	}
}

async function onReset() {
	await ElMessageBox.confirm(`确定将「${currentTitle.value}」恢复为默认设置？`, '恢复默认', { type: 'warning' })
	await businessApi.miniProgramSetting.reset.post(activeGroup.value)
	ElMessage.success('已恢复默认设置')
	loadGroup()
}

onMounted(loadGroup)
</script>

<style scoped>
.set-body {
	flex: 1;
	min-height: 0;
	overflow: auto;
	padding: 16px;
}
.set-suffix {
	margin-left: 8px;
	font-size: 12px;
	color: #909399;
}
.list-block {
	margin-top: 16px;
}
.list-block-head {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 8px 0;
}

/* 首页装修三栏：组件库 / 手机预览 / 属性 */
.deco {
	display: flex;
	gap: 16px;
	align-items: flex-start;
}
.deco-palette {
	width: 180px;
	flex-shrink: 0;
}
.deco-block-title {
	font-size: 13px;
	font-weight: 700;
	color: #303133;
	padding-bottom: 8px;
}
.deco-widget {
	height: 34px;
	line-height: 34px;
	padding: 0 12px;
	margin-bottom: 6px;
	border: 1px solid #e4e7ed;
	border-radius: 4px;
	background: #fff;
	font-size: 13px;
	color: #606266;
	cursor: grab;
}
.deco-widget:hover {
	border-color: #409eff;
	color: #409eff;
}
.deco-hint {
	margin-top: 8px;
	font-size: 12px;
	color: #909399;
	line-height: 18px;
}
.deco-phone {
	flex: 0 0 300px;
}
.phone-frame {
	width: 300px;
	border: 1px solid #dcdfe6;
	border-radius: 12px;
	overflow: hidden;
	background: #fff;
}
.phone-nav {
	height: 40px;
	line-height: 40px;
	text-align: center;
	background: #f5f7fa;
	font-size: 13px;
	font-weight: 700;
	color: #303133;
	border-bottom: 1px solid #e4e7ed;
}
.phone-body {
	min-height: 420px;
	padding: 8px;
	background: #fafafa;
}
.phone-comp {
	display: flex;
	align-items: center;
	justify-content: space-between;
	height: 36px;
	padding: 0 10px;
	margin-bottom: 6px;
	border: 1px dashed #dcdfe6;
	border-radius: 4px;
	background: #fff;
	font-size: 13px;
	color: #303133;
	cursor: grab;
}
.phone-comp.is-active {
	border-color: #409eff;
	background: #ecf5ff;
}
.phone-empty {
	height: 200px;
	display: flex;
	align-items: center;
	justify-content: center;
	color: #c0c4cc;
	font-size: 13px;
	border: 1px dashed #dcdfe6;
	border-radius: 4px;
}
.deco-props {
	flex: 1;
	min-width: 0;
}
</style>
