<template>
	<div class="rp-page">
		<div class="rp-toolbar">
			<el-input
				v-model="keyword"
				placeholder="搜索公告标题 / 内容"
				size="small"
				style="width: 240px"
				clearable
				@keyup.enter="onSearch"
				@clear="onSearch"
			/>
			<el-button size="small" class="rp-btn-primary" @click="onSearch">搜索</el-button>
			<el-select v-model="typeFilter" placeholder="全部类型" size="small" style="width: 120px" clearable @change="onSearch">
				<el-option v-for="t in types" :key="t.value" :label="t.label" :value="t.value" />
			</el-select>
			<el-select v-model="statusFilter" placeholder="状态" size="small" style="width: 110px" @change="onSearch">
				<el-option label="已发布" value="published" />
				<el-option label="草稿" value="draft" />
			</el-select>
			<span class="rp-spacer"></span>
			<el-button size="small" type="primary" @click="openCreate">发布公告</el-button>
			<el-button size="small" circle title="刷新" @click="fetchList">
				<el-icon><Refresh /></el-icon>
			</el-button>
		</div>

		<div v-loading="loading" class="notice-scroll">
			<el-empty v-if="!list.length && !loading" description="暂无公告" />
			<div class="notice-grid">
				<div
					v-for="n in list"
					:key="n.id"
					class="notice-card"
					:class="{ 'is-top': n.is_top }"
					@click="openDetail(n)"
				>
					<div class="notice-card-head">
						<el-tag v-if="n.is_top" size="small" type="danger" effect="dark">置顶</el-tag>
						<el-tag v-if="n.is_popup" size="small" type="warning" effect="plain">登录弹窗</el-tag>
						<el-tag size="small" :type="typeTag(n.type)" effect="plain">{{ typeLabel(n.type) }}</el-tag>
						<el-tag size="small" :type="statusTag(n.status)" effect="dark">{{ statusLabel(n.status) }}</el-tag>
						<span class="notice-card-title">{{ n.title }}</span>
						<span class="notice-card-actions" @click.stop>
							<el-button v-if="n.can_edit && n.status !== 'published'" link size="small" type="primary" :loading="saving" @click="publishFromList(n)">发布</el-button>
							<el-button v-if="n.can_edit" link size="small" @click="openEdit(n)">编辑</el-button>
							<el-button v-if="n.can_edit" link size="small" type="danger" @click="remove(n)">删除</el-button>
						</span>
					</div>
					<div class="notice-card-summary">{{ n.summary || '（无内容摘要）' }}</div>
					<div class="notice-card-foot">
						<span>{{ n.author_name }}</span>
						<span>{{ n.created_at }}</span>
						<span v-if="n.attachment_count">附件 {{ n.attachment_count }}</span>
						<span>阅读 {{ n.view_count || 0 }}</span>
					</div>
				</div>
			</div>
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
				@current-change="page = $event; fetchList()"
				@update:page-size="pageSize = $event; page = 1; fetchList()"
			/>
		</div>

		<!-- 详情弹窗 800×600 -->
		<el-dialog v-model="detailVisible" :title="current.title" width="800px" top="6vh">
			<div v-loading="detailLoading" class="notice-detail">
				<div class="notice-detail-meta">
					<el-tag v-if="current.is_top" size="small" type="danger" effect="dark">置顶</el-tag>
					<el-tag size="small" :type="typeTag(current.type)" effect="plain">{{ typeLabel(current.type) }}</el-tag>
					<el-tag size="small" :type="statusTag(current.status)" effect="dark">{{ statusLabel(current.status) }}</el-tag>
					<span>{{ current.author_name }}</span>
					<span>{{ current.created_at }}</span>
					<span>阅读 {{ current.view_count || 0 }}</span>
				</div>
				<div class="notice-detail-body">{{ current.content || '（无正文）' }}</div>
			</div>
			<template #footer>
				<el-button size="small" @click="detailVisible = false">关闭</el-button>
			</template>
		</el-dialog>

		<!-- 发布 / 编辑公告 -->
		<el-dialog v-model="editVisible" :title="editing.id ? '编辑公告' : '发布公告'" width="720px" top="6vh">
			<el-form :model="form" label-width="80px" size="small">
				<el-form-item label="标题" required>
					<el-input v-model="form.title" maxlength="200" show-word-limit placeholder="公告标题" />
				</el-form-item>
				<el-form-item label="类型">
					<el-radio-group v-model="form.type">
						<el-radio v-for="t in types" :key="t.value" :value="t.value">{{ t.label }}</el-radio>
					</el-radio-group>
				</el-form-item>
				<el-form-item label="置顶">
					<el-switch v-model="form.is_top" />
				</el-form-item>
				<el-form-item label="登录弹窗">
					<el-switch v-model="form.is_popup" />
				</el-form-item>
				<el-form-item label="可见范围">
					<el-radio-group v-model="form.scope">
						<el-radio value="all">所有人</el-radio>
						<el-radio value="department">指定部门</el-radio>
						<el-radio value="user">指定人员</el-radio>
					</el-radio-group>
				</el-form-item>
				<el-form-item v-if="form.scope !== 'all'" label="选择对象">
					<el-select
						v-model="form.scope_ids"
						multiple
						filterable
						style="width: 100%"
						:placeholder="form.scope === 'department' ? '选择部门' : '选择人员'"
					>
						<el-option
							v-for="o in scopeOptions"
							:key="o.value"
							:label="o.label"
							:value="o.value"
						/>
					</el-select>
				</el-form-item>
				<el-form-item label="正文">
					<el-input v-model="form.content" type="textarea" :rows="10" placeholder="公告正文" resize="vertical" />
				</el-form-item>
			</el-form>
			<template #footer>
				<el-button size="small" @click="editVisible = false">取消</el-button>
				<el-button size="small" :loading="saving" @click="saveDraft">保存草稿</el-button>
				<el-button size="small" class="rp-btn-primary" :loading="saving" @click="publishNow">发布</el-button>
			</template>
		</el-dialog>
	</div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Refresh } from '@element-plus/icons-vue'
import businessApi from '@/api/business'

/** 类型配色：通知蓝、制度绿、活动橙、其他灰 */
const TYPE_COLORS = { notice: 'primary', rule: 'success', activity: 'warning', other: 'info' }

const list = ref([])
const types = ref([])
const total = ref(0)
const page = ref(1)
const pageSize = ref(20)
const loading = ref(false)
const keyword = ref('')
const typeFilter = ref('')
// 后端默认只回已发布；要看草稿必须显式传 status=draft，
// 否则刚存的草稿在列表里凭空消失、无从反馈。
const statusFilter = ref('published')

const detailVisible = ref(false)
const detailLoading = ref(false)
const current = ref({})
const editVisible = ref(false)
const saving = ref(false)
const editing = ref({})
const form = reactive({ title: '', type: 'notice', content: '', is_top: false, is_popup: false, scope: 'all', scope_ids: [], attachments: [] })

/** 可见范围下拉：部门 / 人员共用 mail 的通讯录接口，避免再造一个用户字典接口 */
const contacts = ref({ departments: [], users: [] })
const scopeOptions = computed(() =>
	form.scope === 'user'
		? contacts.value.users
		: (contacts.value.departments || []).map((d) => ({ value: d.id, label: d.name }))
)

const typeLabel = (v) => types.value.find((t) => t.value === v)?.label || v || '其他'
const typeTag = (v) => TYPE_COLORS[v] || 'info'

/** 发布状态：草稿灰 / 已发布绿。列表与详情共用 */
const statusLabel = (v) => (v === 'draft' ? '草稿' : '已发布')
const statusTag = (v) => (v === 'draft' ? 'info' : 'success')

async function fetchList() {
	loading.value = true
	try {
		const res = await businessApi.notice.list.get({
			keyword: keyword.value,
			type: typeFilter.value,
			status: statusFilter.value,
			page: page.value,
			page_size: pageSize.value,
		})
		if (res.code === 200) {
			list.value = res.data?.list || []
			total.value = res.data?.total || 0
			if (Array.isArray(res.data?.types) && res.data.types.length) types.value = res.data.types
		}
	} catch {
		ElMessage.error('加载公告失败')
	} finally {
		loading.value = false
	}
}

async function fetchContacts() {
	const res = await businessApi.mail.contacts.get()
	if (res.code === 200) contacts.value = res.data || contacts.value
}

async function openDetail(row) {
	detailVisible.value = true
	detailLoading.value = true
	try {
		const res = await businessApi.notice.detail.get(row.id)
		if (res.code === 200) current.value = res.data || row
	} finally {
		detailLoading.value = false
	}
	// 阅读数由后端 +1，这里乐观更新避免弹窗关闭前数字不动
	row.view_count = (row.view_count || 0) + 1
}

function blankForm() {
	form.title = ''
	form.type = 'notice'
	form.content = ''
	form.is_top = false
	form.is_popup = false
	form.scope = 'all'
	form.scope_ids = []
	form.attachments = []
}

function openCreate() {
	editing.value = {}
	blankForm()
	editVisible.value = true
}
async function openEdit(row) {
	editVisible.value = true
	// 列表接口只回 summary、不下发正文，直接拿 row 回填会让正文变空，
	// 一旦保存就把整篇公告正文覆盖成 ''。所以跟 publishFromList 一样先拉详情。
	saving.value = true
	try {
		const res = await businessApi.notice.detail.get(row.id)
		if (res.code !== 200 || !res.data) return ElMessage.error('加载公告失败')
		const detail = res.data
		editing.value = detail
		Object.assign(form, {
			title: detail.title || '',
			type: detail.type || 'notice',
			content: detail.content || '',
			is_top: !!detail.is_top,
			is_popup: !!detail.is_popup,
			scope: detail.scope || 'all',
			scope_ids: detail.scope_ids || [],
			attachments: detail.attachments || [],
		})
	} catch {
		ElMessage.error('加载公告失败')
		editVisible.value = false
	} finally {
		saving.value = false
	}
}

/**
 * 落库公共逻辑。status=draft 保存草稿（不对他人可见、不推送），
 * status=published 发布（全员可见 + 后端推一条系统公告消息）。
 * 草稿转已发布时后端会重置 published_at；已发布改回草稿则清空。
 * 新建、编辑两条弹窗路径都走这里——统一走 create/update + status，
 * 保证「发布即通知全员」这条语义只有一处实现。
 */
async function persist(status, successMsg) {
	// 标题是唯一硬校验：草稿允许正文留空（占位），但不能连标题都没有。
	if (!form.title.trim()) return ElMessage.warning('请填写公告标题')

	// saving 先置位：草稿/发布共用一个请求在途标志，同时挡住两个按钮的重复点击
	// 与列表「发布」的并发写，避免两次 update 互相覆盖。
	saving.value = true
	try {
		const id = editing.value.id
		const res = id
			? await businessApi.notice.update.put(id, { ...form, status })
			: await businessApi.notice.create.post({ ...form, status })
		if (res.code === 200 || res.code === 201) {
			ElMessage.success(successMsg)
			editVisible.value = false
			fetchList()
		}
	} catch {
		ElMessage.error('操作失败')
	} finally {
		saving.value = false
	}
}

function saveDraft() {
	return persist('draft', '草稿已保存')
}

function publishNow() {
	return persist('published', '已发布并通知全员')
}

/**
 * 列表卡片上的「发布」：把草稿直接转已发布。
 * 列表接口不下发正文（只回 summary），所以先拉详情拿全字段再发 update，
 * 否则用残缺行数据 PUT 会把正文覆盖成空。
 * saving 在拉详情之前就置位：整个「取详情 + 提交」都在途，
 * 否则这段空窗里卡片按钮可被连点，两个 update 互相覆盖。
 */
async function publishFromList(row) {
	saving.value = true
	try {
		const res = await businessApi.notice.detail.get(row.id)
		if (res.code !== 200 || !res.data) return ElMessage.error('加载公告失败')
		const detail = res.data
		const put = await businessApi.notice.update.put(detail.id, {
			title: detail.title,
			type: detail.type,
			content: detail.content,
			is_top: !!detail.is_top,
			is_popup: !!detail.is_popup,
			scope: detail.scope,
			scope_ids: detail.scope_ids || [],
			attachments: detail.attachments || [],
			status: 'published',
		})
		if (put.code === 200 || put.code === 201) {
			ElMessage.success('已发布并通知全员')
			fetchList()
		}
	} catch {
		ElMessage.error('发布失败')
	} finally {
		saving.value = false
	}
}

async function remove(row) {
	await ElMessageBox.confirm('删除后不可恢复，确定删除该公告？', '删除公告', { type: 'warning' })
	await businessApi.notice.delete.delete(row.id)
	ElMessage.success('已删除')
	fetchList()
}

function onSearch() {
	page.value = 1
	fetchList()
}

onMounted(() => {
	fetchContacts()
	fetchList()
})
</script>

<style scoped>
.notice-scroll {
	flex: 1;
	min-height: 0;
	overflow: auto;
	padding: 0 16px;
}
.notice-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
	gap: 12px;
	padding-bottom: 12px;
}
.notice-card {
	border: 1px solid #e4e7ed;
	border-radius: 4px;
	padding: 12px 14px;
	background: #fff;
	cursor: pointer;
	transition: box-shadow 0.15s ease, border-color 0.15s ease;
}
.notice-card:hover {
	border-color: #409eff;
	box-shadow: 0 2px 8px rgba(64, 158, 255, 0.12);
}
.notice-card.is-top {
	background: #fefaf6;
	border-color: #fcd3b6;
}
.notice-card-head {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
}
.notice-card-title {
	font-size: 15px;
	font-weight: 700;
	color: #303133;
	flex: 1;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
.notice-card-actions {
	display: flex;
	gap: 4px;
}
.notice-card-summary {
	margin-top: 8px;
	font-size: 13px;
	color: #606266;
	line-height: 20px;
	display: -webkit-box;
	-webkit-line-clamp: 2;
	-webkit-box-orient: vertical;
	overflow: hidden;
	min-height: 40px;
}
.notice-card-foot {
	margin-top: 10px;
	display: flex;
	gap: 14px;
	font-size: 12px;
	color: #909399;
	flex-wrap: wrap;
}
.rp-grid-page {
	display: flex;
	align-items: center;
	padding: 8px 16px;
	flex-shrink: 0;
}
.notice-detail-meta {
	display: flex;
	align-items: center;
	gap: 12px;
	font-size: 13px;
	color: #606266;
	padding-bottom: 12px;
	border-bottom: 1px solid #e4e7ed;
	margin-bottom: 12px;
}
.notice-detail-body {
	font-size: 13px;
	line-height: 22px;
	color: #303133;
	white-space: pre-wrap;
	min-height: 200px;
	max-height: 60vh;
	overflow: auto;
}
</style>
